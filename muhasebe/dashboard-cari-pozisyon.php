<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dashboard-cari-aggregate.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$type = (string)($_GET['type'] ?? 'alacak');
if (!in_array($type, ['alacak','verecek'], true)) $type = 'alacak';

try {
    $aggregate = dashboard_cari_aggregate();
    $rows = [];

    foreach ($aggregate['positions'] as $row) {
        $currency = strtoupper(trim((string)($row['currency'] ?? 'TL')));
        if (!in_array($currency, ['TL','USD','EUR'], true)) $currency = 'TL';

        $netAlacak = (float)$row['alacak'] - (float)$row['tahsilat'];
        $netVerecek = (float)$row['verecek'] - (float)$row['odeme'];
        $net = $netAlacak - $netVerecek;

        if ($type === 'alacak') {
            if ($net <= 0 || abs(round($net, 2)) < 0.005) continue;
            $amount = $net;
        } else {
            if ($net >= 0 || abs(round($net, 2)) < 0.005) continue;
            $amount = abs($net);
        }

        $baseName = (string)$row['name'];
        $mergedCount = count($row['cari_ids'] ?? []);
        $rows[] = [
            'id' => (int)$row['id'],
            'name' => $baseName . ' · ' . $currency,
            'base_name' => $baseName,
            'city' => trim((string)($row['city'] ?? '')),
            'currency' => $currency,
            'amount' => round($amount, 2),
            'net_alacak' => round($netAlacak, 2),
            'net_verecek' => round($netVerecek, 2),
            'cari_ids' => array_values(array_map('intval', $row['cari_ids'] ?? [])),
            'merged_cari_count' => $mergedCount,
            'merged_label' => $mergedCount > 1 ? $mergedCount . ' cari kartı tek toplamda birleştirildi' : '',
        ];
    }

    usort($rows, function($a, $b) {
        $curOrder = ['TL'=>0, 'USD'=>1, 'EUR'=>2];
        $c = ($curOrder[$a['currency']] ?? 9) <=> ($curOrder[$b['currency']] ?? 9);
        if ($c !== 0) return $c;
        return ((float)$b['amount']) <=> ((float)$a['amount']);
    });

    echo json_encode([
        'ok'=>true,
        'type'=>$type,
        'rows'=>$rows,
        'duplicate_cari_group_count'=>(int)($aggregate['duplicate_cari_group_count'] ?? 0),
        'ignored_duplicate_movement_count'=>count($aggregate['ignored_duplicate_movement_ids'] ?? []),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>'Cari pozisyon listesi okunamadı.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
