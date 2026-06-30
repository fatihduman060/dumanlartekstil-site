<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
try {
    ensure_column(db(), 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");
    $stmt = db()->query("SELECT cari_id, COALESCE(currency,'TL') AS currency, movement_type, SUM(amount) AS total FROM movements WHERE cari_id IS NOT NULL AND COALESCE(is_cancelled,0)=0 GROUP BY cari_id, COALESCE(currency,'TL'), movement_type");
    $raw = [];
    foreach ($stmt->fetchAll() as $row) {
        $cariId = (int)($row['cari_id'] ?? 0);
        if ($cariId <= 0) continue;
        $cur = strtoupper(trim((string)($row['currency'] ?? 'TL')));
        if (!in_array($cur, ['TL','USD','EUR'], true)) $cur = 'TL';
        if (!isset($raw[$cariId])) $raw[$cariId] = [];
        if (!isset($raw[$cariId][$cur])) $raw[$cariId][$cur] = ['alacak'=>0,'tahsilat'=>0,'verecek'=>0,'odeme'=>0,'gelir'=>0,'gider'=>0];
        $type = (string)($row['movement_type'] ?? '');
        if (array_key_exists($type, $raw[$cariId][$cur])) $raw[$cariId][$cur][$type] += (float)($row['total'] ?? 0);
    }
    $out = [];
    foreach ($raw as $cariId => $byCurrency) {
        $items = [];
        foreach (['TL','USD','EUR'] as $cur) {
            $t = $byCurrency[$cur] ?? ['alacak'=>0,'tahsilat'=>0,'verecek'=>0,'odeme'=>0,'gelir'=>0,'gider'=>0];
            $net = ($t['alacak'] - $t['tahsilat']) - ($t['verecek'] - $t['odeme']);
            if (abs(round($net, 2)) >= 0.005 || $cur === 'TL') $items[] = ['currency'=>$cur, 'net'=>$net];
        }
        $out[(string)$cariId] = $items;
    }
    echo json_encode(['ok'=>true, 'balances'=>$out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'Bakiye okunamadı'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
