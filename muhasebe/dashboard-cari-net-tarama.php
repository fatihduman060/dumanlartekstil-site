<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

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

    $totals = [
        'TL' => ['receivable'=>0.0, 'payable'=>0.0, 'net'=>0.0],
        'USD' => ['receivable'=>0.0, 'payable'=>0.0, 'net'=>0.0],
        'EUR' => ['receivable'=>0.0, 'payable'=>0.0, 'net'=>0.0],
    ];
    $mixed = [];
    $positions = [];
    $closedCount = 0;

    foreach ($stmt->fetchAll() as $row) {
        $currency = strtoupper(trim((string)($row['currency'] ?? 'TL')));
        if (!isset($totals[$currency])) $currency = 'TL';

        $netAlacak = round((float)$row['alacak'] - (float)$row['tahsilat'], 2);
        $netVerecek = round((float)$row['verecek'] - (float)$row['odeme'], 2);
        $net = round($netAlacak - $netVerecek, 2);
        $receivable = $net > 0.004 ? $net : 0.0;
        $payable = $net < -0.004 ? abs($net) : 0.0;
        $isClosed = abs($net) < 0.005;
        $isMixed = $netAlacak > 0.004 && $netVerecek > 0.004;
        $offset = $isMixed ? min($netAlacak, $netVerecek) : 0.0;

        $totals[$currency]['receivable'] += $receivable;
        $totals[$currency]['payable'] += $payable;
        $totals[$currency]['net'] += $net;
        if ($isClosed && ($netAlacak > 0.004 || $netVerecek > 0.004)) $closedCount++;

        $position = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'city' => (string)($row['city'] ?? ''),
            'currency' => $currency,
            'net_alacak' => $netAlacak,
            'net_verecek' => $netVerecek,
            'net' => $net,
            'receivable' => $receivable,
            'payable' => $payable,
            'offset' => round($offset, 2),
            'closed' => $isClosed,
            'mixed' => $isMixed,
        ];
        $positions[] = $position;
        if ($isMixed) $mixed[] = $position;
    }

    foreach ($totals as &$currencyTotal) {
        foreach ($currencyTotal as $key => $value) $currencyTotal[$key] = round((float)$value, 2);
    }
    unset($currencyTotal);

    usort($mixed, function ($a, $b) {
        if ($a['currency'] !== $b['currency']) {
            $order = ['TL'=>0, 'USD'=>1, 'EUR'=>2];
            return ($order[$a['currency']] ?? 9) <=> ($order[$b['currency']] ?? 9);
        }
        if ((float)$a['offset'] === (float)$b['offset']) return strcmp((string)$a['name'], (string)$b['name']);
        return ((float)$b['offset'] <=> (float)$a['offset']);
    });

    echo json_encode([
        'ok' => true,
        'calculation' => 'cari_net',
        'totals' => $totals,
        'mixed_count' => count($mixed),
        'closed_count' => $closedCount,
        'mixed' => array_slice($mixed, 0, 200),
        'positions' => $positions,
        'scanned_at' => now(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Cari net taraması yapılamadı.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
