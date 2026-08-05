<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dashboard-cari-aggregate.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $aggregate = dashboard_cari_aggregate();

    $totals = [
        'TL' => ['receivable'=>0.0, 'payable'=>0.0, 'net'=>0.0],
        'USD' => ['receivable'=>0.0, 'payable'=>0.0, 'net'=>0.0],
        'EUR' => ['receivable'=>0.0, 'payable'=>0.0, 'net'=>0.0],
    ];
    $mixed = [];
    $positions = [];
    $closedCount = 0;

    foreach ($aggregate['positions'] as $row) {
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
            'cari_ids' => array_values(array_map('intval', $row['cari_ids'] ?? [])),
            'merged_cari_count' => count($row['cari_ids'] ?? []),
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
        'calculation' => 'cari_net_deduplicated',
        'totals' => $totals,
        'mixed_count' => count($mixed),
        'closed_count' => $closedCount,
        'mixed' => array_slice($mixed, 0, 200),
        'positions' => $positions,
        'duplicate_cari_group_count' => (int)($aggregate['duplicate_cari_group_count'] ?? 0),
        'ignored_duplicate_movement_count' => count($aggregate['ignored_duplicate_movement_ids'] ?? []),
        'ignored_duplicate_movement_ids' => $aggregate['ignored_duplicate_movement_ids'] ?? [],
        'scanned_at' => now(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Cari net taraması yapılamadı.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
