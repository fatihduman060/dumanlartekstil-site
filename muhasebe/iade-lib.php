<?php
// Returns are goods sent back to the counterparty. Their balance effect is
// resolved from the account position immediately before the return.
const RETURN_PAYABLE_REDUCTION_TYPE = 'iade_borc_azalt';
function customer_return_category(string $name): bool
{
    return strtolower(strtr(trim($name), ['İ'=>'i', 'I'=>'i', 'ı'=>'i'])) === 'iade';
}

function customer_return_entry_type(string $type, int $categoryId, ?int $cariId): string
{
    $category = '';
    if ($categoryId > 0) {
        $stmt = db()->prepare('SELECT name FROM categories WHERE id=?');
        $stmt->execute([$categoryId]);
        $category = (string)$stmt->fetchColumn();
    }
    if (!in_array($type, ['iade', RETURN_PAYABLE_REDUCTION_TYPE], true) && !customer_return_category($category)) return $type;
    if (!$cariId || $type === 'ozel_alacak') throw new RuntimeException('Ürün iadesi için normal bir cari seçilmeli.');
    return in_array($type, ['iade', RETURN_PAYABLE_REDUCTION_TYPE], true) ? $type : 'iade';
}

function return_balance_effect(string $type, float $amount): float
{
    if (in_array($type, ['alacak', 'odeme', RETURN_PAYABLE_REDUCTION_TYPE], true)) return $amount;
    if (in_array($type, ['tahsilat', 'ciro_primi', 'iade', 'verecek'], true)) return -$amount;
    return 0.0;
}

function return_position_before(PDO $pdo, int $cariId, string $currency, string $date, int $excludeId = 0): array
{
    $columns = array_column($pdo->query('PRAGMA table_info(movements)')->fetchAll(), 'name');
    $currencySql = in_array('currency', $columns, true) ? "COALESCE(NULLIF(UPPER(TRIM(currency)),''),'TL')=?" : "'TL'=?";
    $sql = "SELECT movement_type, amount FROM movements
        WHERE cari_id=? AND COALESCE(is_cancelled,0)=0 AND $currencySql
          AND (movement_date<? OR (movement_date=? AND (?=0 OR id<?)))";
    $params = [$cariId, strtoupper($currency), $date, $date, $excludeId, $excludeId];
    if ($excludeId > 0) {
        $sql .= ' AND id<>?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY movement_date ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $net = 0.0;
    $lastDirection = '';
    foreach ($stmt->fetchAll() as $row) {
        $type = (string)$row['movement_type'];
        $net += return_balance_effect($type, (float)$row['amount']);
        if (in_array($type, ['alacak', 'verecek'], true)) $lastDirection = $type;
    }
    return ['net'=>round($net, 2), 'last_direction'=>$lastDirection];
}

function resolve_return_movement_type(PDO $pdo, int $cariId, string $currency, string $date, int $excludeId = 0, string $zeroDirection = ''): string
{
    $position = return_position_before($pdo, $cariId, $currency, $date, $excludeId);
    if ($position['net'] < -0.005) return RETURN_PAYABLE_REDUCTION_TYPE;
    if ($position['net'] > 0.005) return 'iade';
    if ($position['last_direction'] === 'verecek') return RETURN_PAYABLE_REDUCTION_TYPE;
    if ($position['last_direction'] === 'alacak') return 'iade';
    if ($zeroDirection === 'borc') return RETURN_PAYABLE_REDUCTION_TYPE;
    if ($zeroDirection === 'alacak') return 'iade';
    throw new RuntimeException('Cari bakiyesi sıfır ve önceki alış/satış yönü bulunamadı. İade yönünü seçin.');
}

function customer_return_assert_unlinked(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("SELECT m.*,
        EXISTS(SELECT 1 FROM account_transactions a WHERE a.source_type='movement' AND a.source_id=m.id) AS cash_link,
        EXISTS(SELECT 1 FROM checks c WHERE c.movement_id=m.id OR c.adjustment_movement_id=m.id OR c.unpaid_movement_id=m.id) AS check_link
        FROM movements m WHERE m.id=?");
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m || !empty($m['is_cancelled'])) throw new RuntimeException('Aktif hareket bulunamadı.');
    if (!empty($m['cash_link']) || !empty($m['check_link']) || !empty($m['check_id']) || !empty($m['account_id'])
        || !empty($m['is_check_unpaid_adjustment']) || movement_is_check_like($m)) {
        throw new RuntimeException('Kasa/banka veya çek bağlantısı olan hareket otomatik iadeye çevrilemez; bağlantı ayrıca incelenmeli.');
    }
}

function customer_return_candidates(PDO $pdo): array
{
    $rows = $pdo->query("SELECT m.*, cat.name AS category_name, c.name AS cari_name
        FROM movements m JOIN categories cat ON cat.id=m.category_id
        LEFT JOIN cariler c ON c.id=m.cari_id
        WHERE COALESCE(m.is_cancelled,0)=0 AND m.movement_type NOT IN ('iade','iade_borc_azalt') ORDER BY m.id")->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        if (!customer_return_category((string)$row['category_name'])) continue;
        $row['blocked_reason'] = '';
        try {
            if (empty($row['cari_name']) || (float)$row['amount'] <= 0 || !in_array($row['movement_type'], ['gider','alacak'], true)) {
                throw new RuntimeException('Cari/tutar veya eski işlem türü ayrıca incelenmeli.');
            }
            customer_return_assert_unlinked($pdo, (int)$row['id']);
            $row['resolved_type'] = resolve_return_movement_type($pdo, (int)$row['cari_id'], strtoupper((string)($row['currency'] ?? 'TL')), (string)$row['movement_date'], (int)$row['id']);
        } catch (Throwable $e) { $row['blocked_reason'] = $e->getMessage(); }
        $row['fingerprint'] = hash('sha256', json_encode($row));
        $out[] = $row;
    }
    return $out;
}

function customer_return_repair(PDO $pdo, array $expected, array $user): int
{
    $fixed = 0;
    // Serialize the read/check/update/audit sequence against concurrent edits.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $candidates = [];
        foreach (customer_return_candidates($pdo) as $row) $candidates[(int)$row['id']] = $row;
        foreach ($expected as $id => $fingerprint) {
            $id = (int)$id;
            $row = $candidates[$id] ?? null;
            if (!$row) {
                $stmt = $pdo->prepare("SELECT movement_type FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0");
                $stmt->execute([$id]);
                if (in_array($stmt->fetchColumn(), ['iade', RETURN_PAYABLE_REDUCTION_TYPE], true)) continue; // Repeat submission is harmless.
                throw new RuntimeException('Kayıt değişti; önizlemeyi yenileyin.');
            }
            if ($row['blocked_reason'] !== '' || !hash_equals($row['fingerprint'], (string)$fingerprint)) {
                throw new RuntimeException('Kayıt veya bağlantıları değişti; önizlemeyi yenileyin.');
            }
            unset($row['fingerprint'], $row['blocked_reason']);
            $new = $row;
            $new['movement_type'] = (string)$row['resolved_type'];
            $new['account_id'] = null;
            $new['due_date'] = null;
            $new['payment_method'] = '';
            $new['updated_at'] = now();
            $pdo->prepare("UPDATE movements SET movement_type=?, account_id=NULL, due_date=NULL, payment_method='', updated_at=? WHERE id=?")
                ->execute([$new['movement_type'], $new['updated_at'], $id]);
            // Strict audit: if logging fails the whole repair rolls back. No financial row is deleted.
            $pdo->prepare('INSERT INTO audit_logs (user_id,username,entity_type,entity_id,action,old_value,new_value,detail,ip,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$user['id'] ?? null,$user['username'] ?? null,'hareket',$id,'iade_duzeltildi',json_encode($row, JSON_UNESCAPED_UNICODE),json_encode($new, JSON_UNESCAPED_UNICODE),'Ürün iadesi cari bakiyesinin yönüne göre işlendi; kasa/banka etkisi yok.',client_ip(),now()]);
            $fixed++;
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return $fixed;
}
