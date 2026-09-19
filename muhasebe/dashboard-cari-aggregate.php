<?php

function dashboard_cari_norm(string $value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U',
        'Â'=>'A','Î'=>'I','Û'=>'U','â'=>'A','î'=>'I','û'=>'U',
    ];
    $value = strtoupper(strtr(trim($value), $map));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?: $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
}

function dashboard_cari_due_source_id(string $description): int
{
    if (preg_match('/Vade\s+kapatma\s*#\s*(\d+)/iu', $description, $match)) {
        return (int)$match[1];
    }
    return 0;
}

function dashboard_cari_created_gap_seconds(array $first, array $second): ?int
{
    $firstValue = trim((string)($first['created_at'] ?? ''));
    $secondValue = trim((string)($second['created_at'] ?? ''));
    if ($firstValue === '' || $secondValue === '') return null;

    $firstTime = strtotime($firstValue);
    $secondTime = strtotime($secondValue);
    if ($firstTime === false || $secondTime === false) return null;

    return abs($firstTime - $secondTime);
}

function dashboard_cari_aggregate(?string $startDate = null, ?string $endDate = null): array
{
    ensure_column(db(), 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");

    $cariler = [];
    foreach (db()->query("SELECT id, name, city FROM cariler ORDER BY id ASC")->fetchAll() as $cari) {
        $id = (int)($cari['id'] ?? 0);
        if ($id <= 0) continue;
        $cariler[$id] = [
            'id'=>$id,
            'name'=>(string)($cari['name'] ?? ''),
            'city'=>(string)($cari['city'] ?? ''),
        ];
    }

    $dateSql = '';
    $dateParams = [];
    if ($startDate !== null) {
        $dateSql .= ' AND m.movement_date >= ?';
        $dateParams[] = $startDate;
    }
    if ($endDate !== null) {
        $dateSql .= ' AND m.movement_date <= ?';
        $dateParams[] = $endDate;
    }

    $stmt = db()->prepare("SELECT m.id, m.cari_id, m.movement_type, m.amount,
        COALESCE(m.currency,'TL') AS currency, m.movement_date,
        COALESCE(m.account_id,0) AS account_id,
        COALESCE(m.description,'') AS description,
        COALESCE(m.payment_method,'') AS payment_method,
        COALESCE(m.due_date,'') AS due_date,
        COALESCE(m.document_type,'') AS document_type,
        COALESCE(m.created_at,'') AS created_at
      FROM movements m
      WHERE COALESCE(m.is_cancelled,0)=0
        AND m.movement_type IN ('alacak','tahsilat','verecek','odeme')
        " . $dateSql . "
      ORDER BY m.id ASC");
    $stmt->execute($dateParams);

    // Genel Bakış, cari detayla aynı aktif hareketleri esas alır.
    // Zaman/tutar benzerliğine bakarak kayıt gizlemek gerçek iki işlemi yanlışlıkla tek işlem sayabilir.
    $kept = [];
    foreach ($stmt->fetchAll() as $movement) {
        $cariId = (int)($movement['cari_id'] ?? 0);
        if ($cariId <= 0 || !isset($cariler[$cariId])) continue;

        $currency = strtoupper(trim((string)($movement['currency'] ?? 'TL')));
        if (!in_array($currency, ['TL','USD','EUR'], true)) $currency = 'TL';

        $movement['cari_id'] = $cariId;
        $movement['currency'] = $currency;
        $kept[] = $movement;
    }

    $positions = [];
    foreach ($kept as $movement) {
        $cariId = (int)($movement['cari_id'] ?? 0);
        if ($cariId <= 0 || !isset($cariler[$cariId])) continue;

        $currency = (string)$movement['currency'];
        $key = $cariId . '|' . $currency;

        if (!isset($positions[$key])) {
            $positions[$key] = [
                'id'=>$cariId,
                'name'=>(string)$cariler[$cariId]['name'],
                'city'=>(string)$cariler[$cariId]['city'],
                'currency'=>$currency,
                'cari_ids'=>[$cariId],
                'alacak'=>0.0,
                'tahsilat'=>0.0,
                'verecek'=>0.0,
                'odeme'=>0.0,
                'last_date'=>'',
            ];
        }

        $movementType = (string)$movement['movement_type'];
        if (isset($positions[$key][$movementType])) {
            $positions[$key][$movementType] += (float)$movement['amount'];
        }
        if ((string)$movement['movement_date'] > (string)$positions[$key]['last_date']) {
            $positions[$key]['last_date'] = (string)$movement['movement_date'];
        }
    }

    return [
        'positions'=>array_values($positions),
        'duplicate_cari_group_count'=>0,
        'ignored_duplicate_movement_ids'=>[],
    ];
}
