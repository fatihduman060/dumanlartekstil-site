<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$type = (string)($_GET['type'] ?? 'alacak');
if (!in_array($type, ['alacak','verecek'], true)) $type = 'alacak';

try {
    ensure_column(db(), 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");
    $stmt = db()->query("SELECT c.id, c.name, c.city, COALESCE(m.currency,'TL') AS currency,
        COALESCE(SUM(CASE WHEN m.movement_type='alacak' THEN m.amount ELSE 0 END),0) AS alacak,
        COALESCE(SUM(CASE WHEN m.movement_type='tahsilat' THEN m.amount ELSE 0 END),0) AS tahsilat,
        COALESCE(SUM(CASE WHEN m.movement_type='verecek' THEN m.amount ELSE 0 END),0) AS verecek,
        COALESCE(SUM(CASE WHEN m.movement_type='odeme' THEN m.amount ELSE 0 END),0) AS odeme
        FROM cariler c
        LEFT JOIN movements m ON m.cari_id=c.id AND COALESCE(m.is_cancelled,0)=0
        GROUP BY c.id, COALESCE(m.currency,'TL')
        ORDER BY c.name ASC");
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $currency = strtoupper(trim((string)($row['currency'] ?? 'TL')));
        if (!in_array($currency, ['TL','USD','EUR'], true)) $currency = 'TL';
        $amount = $type === 'alacak'
            ? ((float)$row['alacak'] - (float)$row['tahsilat'])
            : ((float)$row['verecek'] - (float)$row['odeme']);
        if (abs(round($amount, 2)) < 0.005) continue;
        if ($amount <= 0) continue;
        $rows[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'city' => (string)($row['city'] ?? ''),
            'currency' => $currency,
            'amount' => round($amount, 2),
        ];
    }
    usort($rows, function($a, $b) {
        $curOrder = ['TL'=>0, 'USD'=>1, 'EUR'=>2];
        $c = ($curOrder[$a['currency']] ?? 9) <=> ($curOrder[$b['currency']] ?? 9);
        if ($c !== 0) return $c;
        return ((float)$b['amount']) <=> ((float)$a['amount']);
    });
    echo json_encode(['ok'=>true, 'type'=>$type, 'rows'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'Cari pozisyon listesi okunamadı.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
