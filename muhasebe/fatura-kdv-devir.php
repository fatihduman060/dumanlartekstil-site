<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/masraf-fisi-lib.php';
require_once __DIR__ . '/magaza-satis-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

db()->exec("CREATE TABLE IF NOT EXISTS vat_carryovers (
    period TEXT PRIMARY KEY,
    amount REAL NOT NULL DEFAULT 0,
    note TEXT,
    created_by INTEGER,
    created_at TEXT,
    updated_by INTEGER,
    updated_at TEXT
)");

function kdv_devir_period(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}$/', $value) ? $value : date('Y-m');
}

function kdv_devir_summary(string $period): array
{
    $periodStart = $period . '-01';
    $periodEnd = date('Y-m-t', strtotime($periodStart));

    $tableExists = (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='invoices'")->fetchColumn() > 0;
    $invoiceIncomingVat = 0.0;
    $invoiceOutgoingVat = 0.0;

    if ($tableExists) {
        $stmt = db()->prepare("SELECT
            COALESCE(SUM(CASE WHEN direction='gelen' AND currency='TL' THEN vat_amount ELSE 0 END),0) AS incoming_vat,
            COALESCE(SUM(CASE WHEN direction='giden' AND currency='TL' THEN vat_amount ELSE 0 END),0) AS outgoing_vat
            FROM invoices
            WHERE COALESCE(is_cancelled,0)=0 AND invoice_date BETWEEN ? AND ?");
        $stmt->execute([$periodStart, $periodEnd]);
        $row = $stmt->fetch() ?: [];
        $invoiceIncomingVat = (float)($row['incoming_vat'] ?? 0);
        $invoiceOutgoingVat = (float)($row['outgoing_vat'] ?? 0);
    }

    $expenseSummary = masraf_fisi_ozeti($period);
    $expenseVat = (float)($expenseSummary['vat'] ?? 0);
    $incomingVat = $invoiceIncomingVat + $expenseVat;

    $storeSummary = magaza_satis_ozeti($period);
    $storeSalesVat = (float)($storeSummary['vat'] ?? 0);
    $outgoingVat = $invoiceOutgoingVat + $storeSalesVat;

    $stmt = db()->prepare('SELECT amount, note, updated_at FROM vat_carryovers WHERE period=?');
    $stmt->execute([$period]);
    $carry = $stmt->fetch() ?: [];
    $carryover = (float)($carry['amount'] ?? 0);

    $net = $outgoingVat - $incomingVat - $carryover;
    $label = $net > 0.009
        ? 'Tahmini ödenecek KDV'
        : ($net < -0.009 ? 'Sonraki döneme devreden KDV' : 'KDV dengede');

    return [
        'period' => $period,
        'period_label' => date('m.Y', strtotime($periodStart)),
        'incoming_vat' => $incomingVat,
        'invoice_incoming_vat' => $invoiceIncomingVat,
        'expense_vat' => $expenseVat,
        'expense_count' => (int)($expenseSummary['count'] ?? 0),
        'outgoing_vat' => $outgoingVat,
        'invoice_outgoing_vat' => $invoiceOutgoingVat,
        'store_sales_vat' => $storeSalesVat,
        'store_sales_gross' => (float)($storeSummary['gross'] ?? 0),
        'store_sales_count' => (int)($storeSummary['count'] ?? 0),
        'carryover' => $carryover,
        'note' => (string)($carry['note'] ?? ''),
        'updated_at' => (string)($carry['updated_at'] ?? ''),
        'net' => $net,
        'net_abs' => abs($net),
        'net_label' => $label,
        'net_tone' => $net > 0.009 ? 'danger' : 'success',
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_write();
        require_csrf();

        $period = kdv_devir_period((string)($_POST['period'] ?? ''));
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $note = trim((string)($_POST['note'] ?? ''));

        if ($amount < 0) {
            throw new RuntimeException('Devreden KDV tutarı negatif olamaz.');
        }

        $stmt = db()->prepare('SELECT period FROM vat_carryovers WHERE period=?');
        $stmt->execute([$period]);
        $exists = (bool)$stmt->fetchColumn();
        $userId = current_user()['id'] ?? null;

        if ($exists) {
            db()->prepare('UPDATE vat_carryovers SET amount=?, note=?, updated_by=?, updated_at=? WHERE period=?')
                ->execute([$amount, $note, $userId, now(), $period]);
        } else {
            db()->prepare('INSERT INTO vat_carryovers (period, amount, note, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$period, $amount, $note, $userId, now(), $userId, now()]);
        }

        log_action('KDV devri güncellendi', $period . ' · ' . number_format($amount, 2, ',', '.') . ' TL');
    }

    $period = kdv_devir_period((string)($_REQUEST['period'] ?? ''));
    $summary = kdv_devir_summary($period);
    $summary['ok'] = true;
    $summary['can_write'] = can_write();
    $summary['csrf_token'] = csrf_token();

    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
