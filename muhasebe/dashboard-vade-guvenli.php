<?php
require_once __DIR__ . '/layout.php';
require_login();

ensure_column(db(), 'movements', 'reminder_status', "TEXT NOT NULL DEFAULT 'bekliyor'");
ensure_column(db(), 'movements', 'reminder_completed_at', 'TEXT');
ensure_column(db(), 'movements', 'reminder_completed_by', 'INTEGER');
ensure_column(db(), 'movements', 'reminder_settlement_movement_id', 'INTEGER');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function vade_guvenli_account(int $accountId): ?array
{
    if ($accountId <= 0) return null;
    $stmt = db()->prepare('SELECT * FROM accounts WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$accountId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function vade_guvenli_source(int $id): ?array
{
    $stmt = db()->prepare("SELECT m.*, c.name AS cari_name
        FROM movements m
        LEFT JOIN cariler c ON c.id=m.cari_id
        WHERE m.id=? AND COALESCE(m.is_cancelled,0)=0
          AND m.movement_type IN ('alacak','verecek')
        LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function vade_guvenli_existing_exact(int $sourceId): ?array
{
    $stmt = db()->prepare("SELECT * FROM movements
        WHERE COALESCE(is_cancelled,0)=0
          AND description LIKE ?
          AND movement_type IN ('tahsilat','odeme')
        ORDER BY id DESC LIMIT 1");
    $stmt->execute(['Vade kapatma #' . $sourceId . '%']);
    $row = $stmt->fetch();
    return $row ?: null;
}

function vade_guvenli_candidate(array $source, string $settlementType, int $accountId): ?array
{
    $cariId = (int)($source['cari_id'] ?? 0);
    $where = [
        "COALESCE(is_cancelled,0)=0",
        "movement_type=?",
        "(account_id=? OR account_id IS NULL)",
        "ABS(amount-?)<0.005",
        "COALESCE(currency,'TL')='TL'",
    ];
    $params = [
        $settlementType,
        $accountId,
        (float)$source['amount'],
    ];
    if ($cariId > 0) {
        $where[] = 'cari_id=?';
        $params[] = $cariId;
    } else {
        $where[] = 'cari_id IS NULL';
    }
    $where[] = "description NOT LIKE ?";
    $params[] = 'Vade kapatma #' . (int)$source['id'] . '%';

    $stmt = db()->prepare('SELECT * FROM movements WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function vade_guvenli_candidate_by_id(int $candidateId, array $source, string $settlementType, int $accountId): ?array
{
    if ($candidateId <= 0) return null;
    $stmt = db()->prepare("SELECT * FROM movements
        WHERE id=? AND COALESCE(is_cancelled,0)=0
          AND movement_type=? AND account_id=?
          AND ABS(amount-?)<0.005
          AND COALESCE(currency,'TL')='TL'
        LIMIT 1");
    $stmt->execute([$candidateId, $settlementType, $accountId, (float)$source['amount']]);
    $row = $stmt->fetch();
    if (!$row) return null;

    $sourceCariId = (int)($source['cari_id'] ?? 0);
    $rowCariId = (int)($row['cari_id'] ?? 0);
    if ($sourceCariId !== $rowCariId) return null;
    return $row;
}

function vade_guvenli_complete_source(int $sourceId, int $settlementId): void
{
    db()->prepare("UPDATE movements
        SET reminder_status='tamamlandi', reminder_completed_at=?, reminder_completed_by=?,
            reminder_settlement_movement_id=?, updated_at=?
        WHERE id=?")
        ->execute([now(), current_user()['id'] ?? null, $settlementId, now(), $sourceId]);
}

function vade_guvenli_create_settlement(array $source, string $settlementType, int $accountId): int
{
    $description = 'Vade kapatma #' . (int)$source['id'];
    if (trim((string)($source['description'] ?? '')) !== '') {
        $description .= ' / ' . trim((string)$source['description']);
    }

    db()->prepare("INSERT INTO movements (
        cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date,
        payment_method, description, document_type, created_by, created_at, updated_at
    ) VALUES (?, NULL, ?, ?, ?, 'TL', ?, NULL, 'Vade kapatma', ?, NULL, ?, ?, ?)")
        ->execute([
            !empty($source['cari_id']) ? (int)$source['cari_id'] : null,
            $accountId,
            $settlementType,
            (float)$source['amount'],
            date('Y-m-d'),
            $description,
            current_user()['id'] ?? null,
            now(),
            now(),
        ]);
    $settlementId = (int)db()->lastInsertId();
    sync_movement_account_transaction($settlementId);
    return $settlementId;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['ok'=>true, 'csrf_token'=>csrf_token()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    require_write();
    require_csrf();

    $sourceId = (int)($_POST['id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);
    $useExisting = (int)($_POST['use_existing'] ?? 0) === 1;
    $existingMovementId = (int)($_POST['existing_movement_id'] ?? 0);

    if ($sourceId <= 0) throw new RuntimeException('Vadeli hareket bulunamadı.');
    $source = vade_guvenli_source($sourceId);
    if (!$source) throw new RuntimeException('Vadeli hareket bulunamadı veya iptal edilmiş.');

    $incoming = (string)$source['movement_type'] === 'alacak';
    $settlementType = $incoming ? 'tahsilat' : 'odeme';
    $label = $incoming ? 'Alındı' : 'Ödendi';

    if (strtoupper((string)($source['currency'] ?? 'TL')) !== 'TL') {
        throw new RuntimeException('Dövizli vadeler otomatik kapatılamaz; Hareketler ekranından işlem yapmalısın.');
    }

    $account = vade_guvenli_account($accountId);
    if (!$account) throw new RuntimeException('Aktif bir kasa veya banka hesabı seçmelisin.');

    if ((string)($source['reminder_status'] ?? 'bekliyor') === 'tamamlandi'
        && (int)($source['reminder_settlement_movement_id'] ?? 0) > 0) {
        echo json_encode(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label, 'already_completed'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $source = vade_guvenli_source($sourceId);
        if (!$source) throw new RuntimeException('Vadeli hareket bulunamadı veya iptal edilmiş.');
        if ((string)($source['reminder_status'] ?? 'bekliyor') === 'tamamlandi'
            && (int)($source['reminder_settlement_movement_id'] ?? 0) > 0) {
            $pdo->commit();
            echo json_encode(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label, 'already_completed'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $exact = vade_guvenli_existing_exact($sourceId);
        if ($exact) {
            vade_guvenli_complete_source($sourceId, (int)$exact['id']);
            $pdo->commit();
            echo json_encode(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label, 'linked_existing'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($useExisting) {
            $candidate = vade_guvenli_candidate_by_id($existingMovementId, $source, $settlementType, (int)$account['id']);
            if (!$candidate) throw new RuntimeException('Bağlanacak mevcut tahsilat/ödeme hareketi bulunamadı.');
            vade_guvenli_complete_source($sourceId, (int)$candidate['id']);
            $pdo->commit();
            log_action('Vade mevcut harekete bağlandı', '#' . $sourceId . ' → Hareket #' . (int)$candidate['id']);
            audit_action('hareket', $sourceId, 'vade_mevcut_harekete_baglandi', $source, [
                'reminder_status'=>'tamamlandi',
                'reminder_settlement_movement_id'=>(int)$candidate['id'],
                'account_id'=>(int)$account['id'],
            ], $label);
            echo json_encode(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label, 'linked_existing'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $candidate = vade_guvenli_candidate($source, $settlementType, (int)$account['id']);
        if (!$candidate) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'ok'=>false,
                'code'=>'payment_not_found',
                'error'=>'Bu vadeye ait geçmiş tahsilat/ödeme bulunamadı. Yeni hareket oluşturulmadı; kayıt beklemeye devam ediyor.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $settlementId = (int)$candidate['id'];
        if ((int)($candidate['account_id'] ?? 0) <= 0) {
            $pdo->prepare('UPDATE movements SET account_id=?, updated_at=? WHERE id=?')
                ->execute([(int)$account['id'], now(), $settlementId]);
            sync_movement_account_transaction($settlementId);
        }
        vade_guvenli_complete_source($sourceId, $settlementId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    log_action('Vade ödemesi kaydedildi', '#' . $sourceId . ' ' . $label . ' / ' . (string)$account['name']);
    audit_action('hareket', $sourceId, 'vade_tamamlandi', $source, [
        'reminder_status'=>'tamamlandi',
        'reminder_settlement_movement_id'=>$settlementId,
        'account_id'=>(int)$account['id'],
    ], $label);
    echo json_encode(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
