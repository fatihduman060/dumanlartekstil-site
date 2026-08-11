<?php
ob_start();
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function vade_guvenli_json(array $payload, int $status = 200): void
{
    if (ob_get_level() > 0 && ob_get_length()) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function vade_guvenli_require_ajax_access(): void
{
    if (!is_logged_in() || !current_user()) {
        throw new RuntimeException('Oturum süresi dolmuş olabilir. Sayfayı yenileyip tekrar deneyin.');
    }

    $last = (int)($_SESSION['last_activity'] ?? time());
    if (time() - $last > SESSION_TIMEOUT_SECONDS) {
        destroy_session_cookie();
        throw new RuntimeException('Oturum süresi doldu. Sayfayı yenileyip tekrar giriş yapın.');
    }
    $_SESSION['last_activity'] = time();

    if (!can_write()) {
        throw new RuntimeException('Bu işlem için düzenleme yetkiniz yok.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Güvenlik doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.');
    }
}

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
          AND COALESCE(m.check_id,0)=0
          AND COALESCE(m.is_check_adjustment,0)=0
          AND m.movement_type IN ('alacak','verecek')
        LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function vade_guvenli_is_instrument(array $source): bool
{
    $method = strtoupper(trim((string)($source['payment_method'] ?? '')));
    $documentType = trim((string)($source['document_type'] ?? ''));

    return strpos($method, 'ÇEK') !== false
        || strpos($method, 'CEK') !== false
        || strpos($method, 'SENET') !== false
        || in_array($documentType, ['cek_gorseli','senet_gorseli'], true);
}

function vade_guvenli_existing_exact(int $sourceId): ?array
{
    $base = 'Vade kapatma #' . $sourceId;
    $stmt = db()->prepare("SELECT * FROM movements
        WHERE COALESCE(is_cancelled,0)=0
          AND (description=? OR description LIKE ?)
          AND movement_type IN ('tahsilat','odeme')
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$base, $base . ' / %']);
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
        "movement_date=?",
        "COALESCE(description,'') NOT LIKE 'Vade kapatma #%'
    ];
    $params = [
        $settlementType,
        $accountId,
        (float)$source['amount'],
        date('Y-m-d'),
    ];
    if ($cariId > 0) {
        $where[] = 'cari_id=?';
        $params[] = $cariId;
    } else {
        $where[] = 'cari_id IS NULL';
    }

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
          AND COALESCE(description,'') NOT LIKE 'Vade kapatma #%'
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
    vade_guvenli_require_ajax_access();

    ensure_column(db(), 'movements', 'account_id', 'INTEGER');
    ensure_column(db(), 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");
    ensure_column(db(), 'movements', 'check_id', 'INTEGER');
    ensure_column(db(), 'movements', 'is_check_adjustment', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column(db(), 'movements', 'document_type', 'TEXT');
    ensure_column(db(), 'movements', 'is_cancelled', 'INTEGER NOT NULL DEFAULT 0');
    ensure_column(db(), 'movements', 'reminder_status', "TEXT NOT NULL DEFAULT 'bekliyor'");
    ensure_column(db(), 'movements', 'reminder_completed_at', 'TEXT');
    ensure_column(db(), 'movements', 'reminder_completed_by', 'INTEGER');
    ensure_column(db(), 'movements', 'reminder_settlement_movement_id', 'INTEGER');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        vade_guvenli_json(['ok'=>true, 'csrf_token'=>csrf_token()]);
    }

    $sourceId = (int)($_POST['id'] ?? 0);
    $accountId = (int)($_POST['account_id'] ?? 0);
    $useExisting = (int)($_POST['use_existing'] ?? 0) === 1;
    $existingMovementId = (int)($_POST['existing_movement_id'] ?? 0);

    if ($sourceId <= 0) throw new RuntimeException('Vadeli hareket bulunamadı.');
    $source = vade_guvenli_source($sourceId);
    if (!$source) throw new RuntimeException('Açık hesap vadesi bulunamadı veya bu kayıt çek/senet akışına bağlı.');
    if (vade_guvenli_is_instrument($source)) {
        throw new RuntimeException('Çek ve senet vadeleri açık hesap gibi kapatılamaz. Çek/senet satırındaki işlemi kullanmalısın.');
    }

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
        vade_guvenli_json(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label, 'already_completed'=>true]);
    }

    $pdo = db();
    $createdNew = false;
    $linkedExisting = false;
    $settlementId = 0;

    $pdo->beginTransaction();
    try {
        $source = vade_guvenli_source($sourceId);
        if (!$source) throw new RuntimeException('Açık hesap vadesi bulunamadı veya bu kayıt çek/senet akışına bağlı.');
        if (vade_guvenli_is_instrument($source)) {
            throw new RuntimeException('Çek ve senet vadeleri açık hesap gibi kapatılamaz.');
        }

        if ((string)($source['reminder_status'] ?? 'bekliyor') === 'tamamlandi'
            && (int)($source['reminder_settlement_movement_id'] ?? 0) > 0) {
            $pdo->commit();
            vade_guvenli_json(['ok'=>true, 'status'=>'tamamlandi', 'label'=>$label, 'already_completed'=>true]);
        }

        $exact = vade_guvenli_existing_exact($sourceId);
        if ($exact) {
            $settlementId = (int)$exact['id'];
            if ((int)($exact['account_id'] ?? 0) !== (int)$account['id']) {
                $pdo->prepare('UPDATE movements SET account_id=?, updated_at=? WHERE id=?')
                    ->execute([(int)$account['id'], now(), $settlementId]);
                sync_movement_account_transaction($settlementId);
            }
            vade_guvenli_complete_source($sourceId, $settlementId);
            $linkedExisting = true;
            $pdo->commit();
        } elseif ($useExisting) {
            $candidate = vade_guvenli_candidate_by_id($existingMovementId, $source, $settlementType, (int)$account['id']);
            if (!$candidate) throw new RuntimeException('Bağlanacak mevcut tahsilat/ödeme hareketi bulunamadı.');
            $settlementId = (int)$candidate['id'];
            vade_guvenli_complete_source($sourceId, $settlementId);
            $linkedExisting = true;
            $pdo->commit();
        } else {
            $candidate = vade_guvenli_candidate($source, $settlementType, (int)$account['id']);
            if ($candidate) {
                $settlementId = (int)$candidate['id'];
                if ((int)($candidate['account_id'] ?? 0) <= 0) {
                    $pdo->prepare('UPDATE movements SET account_id=?, updated_at=? WHERE id=?')
                        ->execute([(int)$account['id'], now(), $settlementId]);
                    sync_movement_account_transaction($settlementId);
                }
                vade_guvenli_complete_source($sourceId, $settlementId);
                $linkedExisting = true;
            } else {
                $settlementId = vade_guvenli_create_settlement($source, $settlementType, (int)$account['id']);
                vade_guvenli_complete_source($sourceId, $settlementId);
                $createdNew = true;
            }
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($createdNew) {
        log_action('Açık hesap vadesi kapatıldı', '#' . $sourceId . ' ' . $label . ' / ' . (string)$account['name'] . ' / Hareket #' . $settlementId);
        audit_action('hareket', $sourceId, 'vade_tahsilat_odeme_olusturuldu', $source, [
            'reminder_status'=>'tamamlandi',
            'reminder_settlement_movement_id'=>$settlementId,
            'account_id'=>(int)$account['id'],
            'settlement_type'=>$settlementType,
        ], $label);
    } else {
        log_action('Açık hesap vadesi mevcut harekete bağlandı', '#' . $sourceId . ' → Hareket #' . $settlementId);
        audit_action('hareket', $sourceId, 'vade_mevcut_harekete_baglandi', $source, [
            'reminder_status'=>'tamamlandi',
            'reminder_settlement_movement_id'=>$settlementId,
            'account_id'=>(int)$account['id'],
        ], $label);
    }

    vade_guvenli_json([
        'ok'=>true,
        'status'=>'tamamlandi',
        'label'=>$label,
        'settlement_movement_id'=>$settlementId,
        'created_new'=>$createdNew,
        'linked_existing'=>$linkedExisting,
    ]);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    vade_guvenli_json(['ok'=>false, 'error'=>$e->getMessage()], 400);
}
