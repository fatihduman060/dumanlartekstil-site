<?php
require_once __DIR__ . '/layout.php';
require_login();
ensure_column(db(), 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");

header('Content-Type: application/json; charset=utf-8');

function nakliye_gider_norm(string $value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U',
    ];
    $value = strtoupper(strtr(trim($value), $map));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function nakliye_gider_cari(int $cariId): array
{
    if ($cariId <= 0) throw new RuntimeException('Cari seçimi geçersiz.');
    $stmt = db()->prepare('SELECT * FROM cariler WHERE id=? LIMIT 1');
    $stmt->execute([$cariId]);
    $cari = $stmt->fetch();
    if (!$cari) throw new RuntimeException('Cari bulunamadı.');
    if (nakliye_gider_norm((string)$cari['name']) !== 'NAKLIYE') {
        throw new RuntimeException('Bu işlem yalnızca NAKLİYE gider carisi için kullanılabilir.');
    }
    return $cari;
}

function nakliye_gider_category_id(): int
{
    $stmt = db()->prepare('SELECT id FROM categories WHERE LOWER(name)=LOWER(?) LIMIT 1');
    $stmt->execute(['Nakliye']);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    db()->prepare('INSERT INTO categories (name, type, created_at) VALUES (?, ?, ?)')
        ->execute(['Nakliye', 'gider', now()]);
    return (int)db()->lastInsertId();
}

function nakliye_gider_aciklama(string $description, string $note): string
{
    $description = trim($description);
    if (stripos(nakliye_gider_norm($description), nakliye_gider_norm($note)) !== false) return $description;
    return trim($description . ($description !== '' ? ' / ' : '') . $note);
}

function nakliye_gider_normalize_legacy(int $cariId): int
{
    $pdo = db();
    $categoryId = nakliye_gider_category_id();
    $stmt = $pdo->prepare("SELECT * FROM movements WHERE cari_id=? AND COALESCE(is_cancelled,0)=0 AND movement_type IN ('verecek','odeme') ORDER BY movement_date ASC, id ASC");
    $stmt->execute([$cariId]);
    $rows = $stmt->fetchAll();
    if (!$rows) return 0;

    $verecekRows = [];
    $odemeRows = [];
    foreach ($rows as $row) {
        if ($row['movement_type'] === 'verecek') $verecekRows[(int)$row['id']] = $row;
        if ($row['movement_type'] === 'odeme') $odemeRows[] = $row;
    }

    $usedVerecek = [];
    $changedIds = [];
    $cancelledIds = [];

    $pdo->beginTransaction();
    try {
        foreach ($odemeRows as $odeme) {
            $matchId = 0;
            foreach ($verecekRows as $verecekId => $verecek) {
                if (isset($usedVerecek[$verecekId])) continue;
                if (abs((float)$verecek['amount'] - (float)$odeme['amount']) >= 0.01) continue;
                if ((string)$verecek['movement_date'] > (string)$odeme['movement_date']) continue;
                $matchId = $verecekId;
            }

            if ($matchId > 0) {
                $usedVerecek[$matchId] = true;
                $pdo->prepare('UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')
                    ->execute([now(), current_user()['id'] ?? null, 'Nakliye carisi gider takip düzenine çevrildi; bağlı ödeme gider kaydı olarak kullanıldı.', now(), $matchId]);
                $cancelledIds[] = $matchId;
            }

            $description = nakliye_gider_aciklama((string)($odeme['description'] ?? ''), 'Nakliye gideri');
            $pdo->prepare("UPDATE movements SET category_id=?, movement_type='gider', currency='TL', due_date=NULL, description=?, updated_at=? WHERE id=?")
                ->execute([$categoryId, $description, now(), (int)$odeme['id']]);
            $changedIds[] = (int)$odeme['id'];
        }

        foreach ($verecekRows as $verecekId => $verecek) {
            if (isset($usedVerecek[$verecekId])) continue;
            $description = nakliye_gider_aciklama((string)($verecek['description'] ?? ''), 'Eski kayıttan nakliye giderine dönüştürüldü');
            $pdo->prepare("UPDATE movements SET category_id=?, movement_type='gider', currency='TL', due_date=NULL, description=?, updated_at=? WHERE id=?")
                ->execute([$categoryId, $description, now(), $verecekId]);
            $changedIds[] = $verecekId;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    foreach (array_unique(array_merge($changedIds, $cancelledIds)) as $movementId) {
        sync_movement_account_transaction((int)$movementId);
        sync_movement_to_check((int)$movementId, false);
    }

    if ($changedIds || $cancelledIds) {
        log_action('Nakliye carisi gider düzenine çevrildi', 'Cari #' . $cariId . ' · ' . count($changedIds) . ' gider · ' . count($cancelledIds) . ' eşleşen borç iptal');
        audit_action('cari', $cariId, 'nakliye_gider_duzeni', null, ['gider_hareketleri'=>$changedIds,'iptal_edilenler'=>$cancelledIds], 'Nakliye carisi alacak/borç yerine gider takibine çevrildi.');
    }

    return count($changedIds) + count($cancelledIds);
}

function nakliye_gider_summary(int $cariId): array
{
    $stmt = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN movement_type='gider' THEN amount ELSE 0 END),0) AS total,
        COALESCE(SUM(CASE WHEN movement_type='gider' AND substr(movement_date,1,7)=? THEN amount ELSE 0 END),0) AS month_total,
        COALESCE(SUM(CASE WHEN movement_type='gider' AND account_id IS NULL THEN 1 ELSE 0 END),0) AS missing_account,
        COALESCE(SUM(CASE WHEN movement_type='gider' THEN 1 ELSE 0 END),0) AS movement_count
        FROM movements WHERE cari_id=? AND COALESCE(is_cancelled,0)=0");
    $stmt->execute([date('Y-m'), $cariId]);
    $summary = $stmt->fetch() ?: [];

    $lastStmt = db()->prepare("SELECT m.amount, m.movement_date, m.description, a.name AS account_name
        FROM movements m LEFT JOIN accounts a ON a.id=m.account_id
        WHERE m.cari_id=? AND m.movement_type='gider' AND COALESCE(m.is_cancelled,0)=0
        ORDER BY m.movement_date DESC, m.id DESC LIMIT 1");
    $lastStmt->execute([$cariId]);
    $last = $lastStmt->fetch() ?: null;

    return [
        'total'=>(float)($summary['total'] ?? 0),
        'month_total'=>(float)($summary['month_total'] ?? 0),
        'missing_account'=>(int)($summary['missing_account'] ?? 0),
        'movement_count'=>(int)($summary['movement_count'] ?? 0),
        'last'=>$last ? [
            'amount'=>(float)$last['amount'],
            'movement_date'=>(string)$last['movement_date'],
            'description'=>(string)($last['description'] ?? ''),
            'account_name'=>(string)($last['account_name'] ?? ''),
        ] : null,
    ];
}

try {
    $cariId = (int)($_REQUEST['cari_id'] ?? 0);
    $cari = nakliye_gider_cari($cariId);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!can_write()) throw new RuntimeException('Bu işlem için düzenleme yetkiniz yok.');
        require_csrf();
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'normalize') {
            $changed = nakliye_gider_normalize_legacy($cariId);
            echo json_encode([
                'ok'=>true,
                'changed'=>$changed,
                'summary'=>nakliye_gider_summary($cariId),
                'csrf_token'=>csrf_token(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'add_expense') {
            $amount = decimal_from_input($_POST['amount'] ?? '0');
            $date = trim((string)($_POST['movement_date'] ?? date('Y-m-d')));
            $accountId = (int)($_POST['account_id'] ?? 0);
            $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $documentType = trim((string)($_POST['document_type'] ?? '')) ?: null;

            if ($amount <= 0) throw new RuntimeException('Nakliye gideri için geçerli bir tutar girmelisin.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('İşlem tarihini kontrol et.');
            if ($accountId <= 0) throw new RuntimeException('Paranın düşeceği kasa veya banka hesabını seçmelisin.');

            $accountStmt = db()->prepare('SELECT id, name FROM accounts WHERE id=? AND is_active=1 LIMIT 1');
            $accountStmt->execute([$accountId]);
            $account = $accountStmt->fetch();
            if (!$account) throw new RuntimeException('Seçilen kasa/banka hesabı bulunamadı veya aktif değil.');

            try {
                $doc = handle_upload('document');
            } catch (Throwable $e) {
                throw new RuntimeException($e->getMessage());
            }

            $description = nakliye_gider_aciklama($description, 'Nakliye gideri');
            $categoryId = nakliye_gider_category_id();
            db()->prepare("INSERT INTO movements (
                cari_id, category_id, account_id, movement_type, amount, currency, movement_date, due_date,
                payment_method, description, document_type, document_path, document_name, document_mime,
                created_by, created_at, updated_at
            ) VALUES (?, ?, ?, 'gider', ?, 'TL', ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $cariId, $categoryId, $accountId, $amount, $date, $paymentMethod, $description,
                    $documentType, $doc['path'], $doc['name'], $doc['mime'],
                    current_user()['id'] ?? null, now(), now(),
                ]);
            $movementId = (int)db()->lastInsertId();
            sync_movement_account_transaction($movementId);

            log_action('Nakliye gideri eklendi', $cari['name'] . ' · ' . money($amount) . ' · ' . $account['name']);
            audit_action('hareket', $movementId, 'eklendi', null, [
                'cari_id'=>$cariId,
                'movement_type'=>'gider',
                'category_id'=>$categoryId,
                'account_id'=>$accountId,
                'amount'=>$amount,
                'date'=>$date,
            ], 'Nakliye gideri');

            echo json_encode([
                'ok'=>true,
                'movement_id'=>$movementId,
                'message'=>'Nakliye gideri kaydedildi ve ' . $account['name'] . ' hesabından düşüldü.',
                'summary'=>nakliye_gider_summary($cariId),
                'csrf_token'=>csrf_token(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        throw new RuntimeException('İşlem türü geçersiz.');
    }

    echo json_encode([
        'ok'=>true,
        'cari'=>['id'=>$cariId,'name'=>(string)$cari['name']],
        'summary'=>nakliye_gider_summary($cariId),
        'can_write'=>can_write(),
        'csrf_token'=>csrf_token(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
