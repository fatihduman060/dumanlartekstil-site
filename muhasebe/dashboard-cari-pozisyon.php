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
    $invoiceDates = [];
    $dispatchDates = [];
    if ($type === 'alacak') {
        $tables = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('invoices','warehouse_dispatches')")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('invoices', $tables, true)) {
            $stmt = db()->query("SELECT cari_id, UPPER(COALESCE(NULLIF(TRIM(currency),''),'TL')) AS currency, MAX(invoice_date) AS latest_date
                FROM invoices WHERE direction='giden' AND COALESCE(is_cancelled,0)=0 AND cari_id IS NOT NULL
                GROUP BY cari_id, UPPER(COALESCE(NULLIF(TRIM(currency),''),'TL'))");
            foreach ($stmt->fetchAll() as $dateRow) {
                $invoiceDates[(int)$dateRow['cari_id'] . '|' . (string)$dateRow['currency']] = (string)$dateRow['latest_date'];
            }
        }
        if (in_array('warehouse_dispatches', $tables, true)) {
            $stmt = db()->query("SELECT cari_id, UPPER(COALESCE(NULLIF(TRIM(currency),''),'TL')) AS currency, MAX(dispatch_date) AS latest_date
                FROM warehouse_dispatches WHERE COALESCE(processed,0)=1 AND COALESCE(is_cancelled,0)=0 AND cari_id IS NOT NULL
                GROUP BY cari_id, UPPER(COALESCE(NULLIF(TRIM(currency),''),'TL'))");
            foreach ($stmt->fetchAll() as $dateRow) {
                $dispatchDates[(int)$dateRow['cari_id'] . '|' . (string)$dateRow['currency']] = (string)$dateRow['latest_date'];
            }
        }
    }

    foreach ($aggregate['positions'] as $row) {
        $currency = strtoupper(trim((string)($row['currency'] ?? 'TL')));
        if (!in_array($currency, ['TL','USD','EUR'], true)) $currency = 'TL';

        $netAlacak = (float)$row['alacak'] - (float)$row['tahsilat'] - (float)($row['ciro_primi'] ?? 0) - (float)($row['iade'] ?? 0);
        $netVerecek = (float)$row['verecek'] - (float)$row['odeme'] - (float)($row['iade_borc_azalt'] ?? 0);
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
        $invoiceDate = '';
        $dispatchDate = '';
        foreach ($row['cari_ids'] ?? [$row['id']] as $cariId) {
            $key = (int)$cariId . '|' . $currency;
            $invoiceDate = max($invoiceDate, $invoiceDates[$key] ?? '');
            $dispatchDate = max($dispatchDate, $dispatchDates[$key] ?? '');
        }
        $rows[] = [
            'id' => (int)$row['id'],
            'name' => $baseName . ' · ' . $currency,
            'base_name' => $baseName,
            'city' => trim((string)($row['city'] ?? '')),
            'currency' => $currency,
            'amount' => round($amount, 2),
            'source_date' => $type === 'alacak' ? ($invoiceDate !== '' ? $invoiceDate : $dispatchDate) : '',
            'source_date_label' => $type === 'alacak' ? ($invoiceDate !== '' ? 'Son giden fatura' : ($dispatchDate !== '' ? 'Son depo çıkışı' : '')) : '',
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
