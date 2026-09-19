<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function kom_norm_name(string $value): string
{
    $value = trim($value);
    $value = strtr($value, [
        'İ'=>'I','I'=>'I','ı'=>'I','i'=>'I','Ş'=>'S','ş'=>'S','Ğ'=>'G','ğ'=>'G',
        'Ü'=>'U','ü'=>'U','Ö'=>'O','ö'=>'O','Ç'=>'C','ç'=>'C'
    ]);
    $value = strtoupper($value);
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?: '';
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Bu bakım işlemi yalnızca güvenli POST isteğiyle çalıştırılabilir.');
    }
    if (!can_write()) throw new RuntimeException('Bu işlem için düzenleme yetkin yok.');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyin.');
    }

    $settingKey = 'repair_ilsan_card_duplicate_20260917_v1';
    if (setting_get($settingKey, '0') === '1') {
        echo json_encode(['ok'=>true,'status'=>'already_done'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo = db();
    ensure_column($pdo, 'movements', 'card_key', 'TEXT');
    ensure_column($pdo, 'movements', 'report_excluded', 'INTEGER NOT NULL DEFAULT 0');

    $matches = [];
    foreach ($pdo->query('SELECT id,name FROM cariler ORDER BY id')->fetchAll() ?: [] as $cari) {
        $norm = kom_norm_name((string)($cari['name'] ?? ''));
        if ($norm !== '' && strpos($norm, 'ILSAN') !== false) $matches[] = $cari;
    }

    if (count($matches) !== 1) {
        echo json_encode(['ok'=>true,'status'=>'cari_not_unique','count'=>count($matches)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $cari = $matches[0];
    $cariId = (int)$cari['id'];
    $since = date('Y-m-d', strtotime('-7 days'));
    $stmt = $pdo->prepare("SELECT * FROM movements
        WHERE cari_id=?
          AND movement_type='odeme'
          AND COALESCE(is_cancelled,0)=0
          AND COALESCE(card_key,'')<>''
          AND COALESCE(report_excluded,0)=1
          AND movement_date>=?
        ORDER BY id DESC");
    $stmt->execute([$cariId, $since]);
    $rows = $stmt->fetchAll() ?: [];

    $groups = [];
    foreach ($rows as $row) {
        $key = implode('|', [
            (string)($row['movement_date'] ?? ''),
            number_format((float)($row['amount'] ?? 0), 2, '.', ''),
            (string)($row['card_key'] ?? ''),
            trim((string)($row['payment_method'] ?? '')),
            trim((string)($row['description'] ?? '')),
            (string)($row['created_by'] ?? ''),
        ]);
        $groups[$key][] = $row;
    }

    $repairPair = null;
    foreach ($groups as $items) {
        if (count($items) < 2) continue;
        usort($items, fn($a,$b) => (int)$a['id'] <=> (int)$b['id']);
        for ($i=1; $i<count($items); $i++) {
            $prev = $items[$i-1];
            $curr = $items[$i];
            $t1 = strtotime((string)($prev['created_at'] ?? ''));
            $t2 = strtotime((string)($curr['created_at'] ?? ''));
            if (!$t1 || !$t2 || abs($t2-$t1) > 180) continue;
            if ($repairPair === null || (int)$curr['id'] > (int)$repairPair[1]['id']) {
                $repairPair = [$prev, $curr];
            }
        }
    }

    if (!$repairPair) {
        echo json_encode(['ok'=>true,'status'=>'no_safe_duplicate','cari_id'=>$cariId,'cari_name'=>$cari['name']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    [$keep, $duplicate] = $repairPair;
    $duplicateId = (int)$duplicate['id'];
    $now = now();
    $userId = current_user()['id'] ?? null;
    $reason = 'Aynı kartlı ödeme isteğinin mükerrer kaydı; tek kayıt bırakıldı';

    $pdo->beginTransaction();
    $upd = $pdo->prepare('UPDATE movements SET is_cancelled=1, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $upd->execute([$now, $userId, $reason, $now, $duplicateId]);
    if ($upd->rowCount() !== 1) throw new RuntimeException('Mükerrer hareket güvenli biçimde iptal edilemedi.');

    sync_movement_account_transaction($duplicateId);
    audit_action('hareket', $duplicateId, 'mukerrer_kartli_odeme_iptal', $duplicate, [
        'is_cancelled'=>1,
        'cancel_reason'=>$reason,
        'kept_movement_id'=>(int)$keep['id'],
    ], (string)$cari['name']);
    log_action('Mükerrer kartlı ödeme düzeltildi', (string)$cari['name'] . ' / ' . money((float)$duplicate['amount']) . ' / kalan #' . (int)$keep['id'] . ' / iptal #' . $duplicateId);
    setting_set($settingKey, '1');
    $pdo->commit();

    echo json_encode([
        'ok'=>true,
        'status'=>'repaired',
        'cari_id'=>$cariId,
        'cari_name'=>(string)$cari['name'],
        'amount'=>(float)$duplicate['amount'],
        'kept_movement_id'=>(int)$keep['id'],
        'cancelled_movement_id'=>$duplicateId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
