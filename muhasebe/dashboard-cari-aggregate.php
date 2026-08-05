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

function dashboard_cari_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function dashboard_cari_find(array &$parent, int $id): int
{
    if (!isset($parent[$id])) $parent[$id] = $id;
    if ($parent[$id] !== $id) $parent[$id] = dashboard_cari_find($parent, (int)$parent[$id]);
    return (int)$parent[$id];
}

function dashboard_cari_union(array &$parent, int $firstId, int $secondId): void
{
    $firstRoot = dashboard_cari_find($parent, $firstId);
    $secondRoot = dashboard_cari_find($parent, $secondId);
    if ($firstRoot === $secondRoot) return;
    if ($firstRoot < $secondRoot) $parent[$secondRoot] = $firstRoot;
    else $parent[$firstRoot] = $secondRoot;
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

function dashboard_cari_aggregate(): array
{
    ensure_column(db(), 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");

    $cariler = db()->query("SELECT id, name, city, tax_no FROM cariler ORDER BY id ASC")->fetchAll();
    $parent = [];
    $nameOwners = [];
    $taxOwners = [];
    $cariRows = [];

    foreach ($cariler as $cari) {
        $id = (int)$cari['id'];
        if ($id <= 0) continue;
        $parent[$id] = $id;
        $cariRows[$id] = $cari;

        $nameKey = dashboard_cari_norm((string)($cari['name'] ?? ''));
        if ($nameKey !== '') {
            if (isset($nameOwners[$nameKey])) dashboard_cari_union($parent, $id, (int)$nameOwners[$nameKey]);
            else $nameOwners[$nameKey] = $id;
        }

        $taxKey = dashboard_cari_digits((string)($cari['tax_no'] ?? ''));
        if ($taxKey !== '') {
            if (isset($taxOwners[$taxKey])) dashboard_cari_union($parent, $id, (int)$taxOwners[$taxKey]);
            else $taxOwners[$taxKey] = $id;
        }
    }

    $groups = [];
    $cariToGroup = [];
    foreach ($cariRows as $id => $cari) {
        $root = dashboard_cari_find($parent, (int)$id);
        $cariToGroup[(int)$id] = $root;
        if (!isset($groups[$root])) {
            $groups[$root] = [
                'id'=>$root,
                'name'=>(string)($cari['name'] ?? ''),
                'city'=>(string)($cari['city'] ?? ''),
                'cari_ids'=>[],
            ];
        }
        $groups[$root]['cari_ids'][] = (int)$id;
        if ($groups[$root]['name'] === '' && trim((string)($cari['name'] ?? '')) !== '') {
            $groups[$root]['name'] = (string)$cari['name'];
        }
        if ($groups[$root]['city'] === '' && trim((string)($cari['city'] ?? '')) !== '') {
            $groups[$root]['city'] = (string)$cari['city'];
        }
    }

    $stmt = db()->query("SELECT m.id, m.cari_id, m.movement_type, m.amount,
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
      ORDER BY m.id ASC");
    $movements = $stmt->fetchAll();

    $buckets = [];
    foreach ($movements as $movement) {
        $cariId = (int)($movement['cari_id'] ?? 0);
        $groupId = $cariId > 0 && isset($cariToGroup[$cariId]) ? (int)$cariToGroup[$cariId] : 0;
        $currency = strtoupper(trim((string)($movement['currency'] ?? 'TL')));
        if (!in_array($currency, ['TL','USD','EUR'], true)) $currency = 'TL';
        $movement['group_id'] = $groupId;
        $movement['currency'] = $currency;

        $signature = implode('|', [
            $groupId,
            (string)$movement['movement_type'],
            number_format((float)$movement['amount'], 2, '.', ''),
            $currency,
            (string)$movement['movement_date'],
            (int)($movement['account_id'] ?? 0),
        ]);
        if (!isset($buckets[$signature])) $buckets[$signature] = [];
        $buckets[$signature][] = $movement;
    }

    $kept = [];
    $ignoredIds = [];
    foreach ($buckets as $bucketRows) {
        if (count($bucketRows) === 1) {
            $kept[] = $bucketRows[0];
            continue;
        }

        $type = (string)($bucketRows[0]['movement_type'] ?? '');
        if (!in_array($type, ['tahsilat','odeme'], true)) {
            foreach ($bucketRows as $row) $kept[] = $row;
            continue;
        }

        $dueRows = [];
        $normalRows = [];
        foreach ($bucketRows as $row) {
            if (dashboard_cari_due_source_id((string)($row['description'] ?? '')) > 0) $dueRows[] = $row;
            else $normalRows[] = $row;
        }

        if ($dueRows && $normalRows) {
            $keepDue = array_shift($dueRows);
            $ignoreNormal = array_shift($normalRows);
            $kept[] = $keepDue;
            $ignoredIds[] = (int)$ignoreNormal['id'];
            $bucketRows = array_merge($dueRows, $normalRows);
            if (!$bucketRows) continue;
        }

        $exactGroups = [];
        foreach ($bucketRows as $row) {
            $detailKey = implode('|', [
                dashboard_cari_norm((string)($row['description'] ?? '')),
                dashboard_cari_norm((string)($row['payment_method'] ?? '')),
                trim((string)($row['due_date'] ?? '')),
                trim((string)($row['document_type'] ?? '')),
            ]);
            if (!isset($exactGroups[$detailKey])) $exactGroups[$detailKey] = [];
            $exactGroups[$detailKey][] = $row;
        }

        foreach ($exactGroups as $exactRows) {
            if (count($exactRows) === 1) {
                $kept[] = $exactRows[0];
                continue;
            }

            $keepRow = $exactRows[0];
            $kept[] = $keepRow;
            foreach (array_slice($exactRows, 1) as $candidate) {
                $gap = dashboard_cari_created_gap_seconds($keepRow, $candidate);
                if ($gap !== null && $gap <= 1800) $ignoredIds[] = (int)$candidate['id'];
                else $kept[] = $candidate;
            }
        }
    }

    $positions = [];
    foreach ($kept as $movement) {
        $groupId = (int)($movement['group_id'] ?? 0);
        if ($groupId <= 0 || !isset($groups[$groupId])) continue;
        $currency = (string)$movement['currency'];
        $key = $groupId . '|' . $currency;
        if (!isset($positions[$key])) {
            $positions[$key] = [
                'id'=>$groupId,
                'name'=>(string)$groups[$groupId]['name'],
                'city'=>(string)$groups[$groupId]['city'],
                'currency'=>$currency,
                'cari_ids'=>$groups[$groupId]['cari_ids'],
                'alacak'=>0.0,
                'tahsilat'=>0.0,
                'verecek'=>0.0,
                'odeme'=>0.0,
            ];
        }
        $type = (string)$movement['movement_type'];
        if (isset($positions[$key][$type])) $positions[$key][$type] += (float)$movement['amount'];
    }

    return [
        'positions'=>array_values($positions),
        'duplicate_cari_group_count'=>count(array_filter($groups, function ($group) {
            return count($group['cari_ids']) > 1;
        })),
        'ignored_duplicate_movement_ids'=>array_values(array_unique($ignoredIds)),
    ];
}
