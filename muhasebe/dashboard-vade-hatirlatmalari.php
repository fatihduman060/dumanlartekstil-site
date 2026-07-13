<?php
require_once __DIR__ . '/layout.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function dashboard_reminder_money($amount, ?string $currency = 'TL'): string
{
    $currency = strtoupper(trim((string)($currency ?: 'TL')));
    if (!in_array($currency, ['TL', 'USD', 'EUR'], true)) $currency = 'TL';
    return number_format((float)$amount, 2, ',', '.') . ' ' . $currency;
}

function dashboard_reminder_bucket(string $dueDate, string $today): string
{
    if ($dueDate < $today) return 'overdue';
    if ($dueDate === $today) return 'today';
    return 'week';
}

function dashboard_reminder_due_text(string $dueDate, string $today): string
{
    $diff = (int)round((strtotime($dueDate) - strtotime($today)) / 86400);
    if ($diff < 0) return abs($diff) . ' gün gecikti';
    if ($diff === 0) return 'Bugün';
    if ($diff === 1) return 'Yarın';
    return $diff . ' gün kaldı';
}

try {
    $today = date('Y-m-d');
    $weekAhead = date('Y-m-d', strtotime('+7 days'));
    $groups = [
        'overdue' => ['key'=>'overdue', 'label'=>'Geciken', 'tone'=>'danger', 'items'=>[]],
        'today' => ['key'=>'today', 'label'=>'Bugün', 'tone'=>'warning', 'items'=>[]],
        'week' => ['key'=>'week', 'label'=>'7 gün içinde', 'tone'=>'info', 'items'=>[]],
    ];

    // Çeklerden oluşan bağlı hareketler ayrıca listelenmesin; çek kendi başlığıyla tek kez gösterilsin.
    $movementStmt = db()->prepare("SELECT m.id, m.cari_id, m.movement_type, m.amount, COALESCE(m.currency,'TL') AS currency,
            m.due_date, m.description, c.name AS cari_name
        FROM movements m
        LEFT JOIN cariler c ON c.id=m.cari_id
        WHERE COALESCE(m.is_cancelled,0)=0
          AND COALESCE(m.is_check_adjustment,0)=0
          AND COALESCE(m.check_id,0)=0
          AND m.due_date IS NOT NULL
          AND m.due_date != ''
          AND m.due_date <= ?
          AND m.movement_type IN ('alacak','verecek')
        ORDER BY m.due_date ASC, m.id DESC
        LIMIT 200");
    $movementStmt->execute([$weekAhead]);
    foreach ($movementStmt->fetchAll() as $row) {
        $dueDate = (string)$row['due_date'];
        $incoming = (string)$row['movement_type'] === 'alacak';
        $bucket = dashboard_reminder_bucket($dueDate, $today);
        $groups[$bucket]['items'][] = [
            'source' => 'movement',
            'id' => (int)$row['id'],
            'kind' => $incoming ? 'Vadeli alacak' : 'Vadeli borç / ödeme',
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'tone' => $incoming ? 'success' : 'danger',
            'cari_name' => (string)($row['cari_name'] ?: 'Cari seçilmedi'),
            'description' => trim((string)($row['description'] ?? '')),
            'amount' => (float)$row['amount'],
            'currency' => (string)$row['currency'],
            'amount_text' => dashboard_reminder_money($row['amount'], $row['currency'] ?? 'TL'),
            'due_date' => $dueDate,
            'due_text' => tr_date($dueDate),
            'state_text' => dashboard_reminder_due_text($dueDate, $today),
            'url' => 'hareketler.php?edit=' . (int)$row['id'],
        ];
    }

    $checkStmt = db()->prepare("SELECT ch.id, ch.cari_id, ch.direction, ch.amount, ch.due_date, ch.bank_name,
            ch.check_no, ch.drawer, ch.description, c.name AS cari_name
        FROM checks ch
        LEFT JOIN cariler c ON c.id=ch.cari_id
        WHERE COALESCE(ch.is_cancelled,0)=0
          AND ch.status IN ('bekliyor','bankaya_verildi')
          AND ch.due_date IS NOT NULL
          AND ch.due_date != ''
          AND ch.due_date <= ?
        ORDER BY ch.due_date ASC, ch.id DESC
        LIMIT 200");
    $checkStmt->execute([$weekAhead]);
    foreach ($checkStmt->fetchAll() as $row) {
        $dueDate = (string)$row['due_date'];
        $incoming = (string)$row['direction'] === 'alinacak';
        $bucket = dashboard_reminder_bucket($dueDate, $today);
        $checkInfo = trim(implode(' · ', array_filter([
            trim((string)($row['bank_name'] ?? '')),
            trim((string)($row['check_no'] ?? '')),
            trim((string)($row['description'] ?? '')),
        ])));
        $groups[$bucket]['items'][] = [
            'source' => 'check',
            'id' => (int)$row['id'],
            'kind' => $incoming ? 'Alınacak çek' : 'Verilecek çek',
            'direction' => $incoming ? 'incoming' : 'outgoing',
            'tone' => $incoming ? 'success' : 'danger',
            'cari_name' => (string)($row['cari_name'] ?: ($row['drawer'] ?: 'Cari seçilmedi')),
            'description' => $checkInfo,
            'amount' => (float)$row['amount'],
            'currency' => 'TL',
            'amount_text' => dashboard_reminder_money($row['amount'], 'TL'),
            'due_date' => $dueDate,
            'due_text' => tr_date($dueDate),
            'state_text' => dashboard_reminder_due_text($dueDate, $today),
            'url' => 'cekler.php?direction=' . urlencode((string)$row['direction']) . '&edit=' . (int)$row['id'] . '#cek-form',
        ];
    }

    $totalCount = 0;
    foreach ($groups as &$group) {
        usort($group['items'], function(array $a, array $b): int {
            $dateCompare = strcmp((string)$a['due_date'], (string)$b['due_date']);
            if ($dateCompare !== 0) return $dateCompare;
            return ((int)$b['id']) <=> ((int)$a['id']);
        });
        $group['count'] = count($group['items']);
        $group['incoming_count'] = count(array_filter($group['items'], fn($item) => ($item['direction'] ?? '') === 'incoming'));
        $group['outgoing_count'] = $group['count'] - $group['incoming_count'];
        $totalCount += $group['count'];
    }
    unset($group);

    echo json_encode([
        'ok' => true,
        'today' => $today,
        'week_ahead' => $weekAhead,
        'count' => $totalCount,
        'groups' => array_values($groups),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
