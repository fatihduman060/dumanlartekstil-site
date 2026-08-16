<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/dashboard-cari-aggregate.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function rd_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rd_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function rd_add_rows(array &$target, array $rows, string $kind): void
{
    foreach ($rows as $row) {
        $target[] = [
            'date' => (string)($row['date'] ?? ''),
            'source' => (string)($row['source'] ?? '-'),
            'description' => trim($kind . (($row['description'] ?? '') !== '' ? ' · ' . $row['description'] : '')),
            'amount' => (float)($row['amount'] ?? 0),
        ];
    }
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($year < 2000 || $year > 2100) rd_response(['ok'=>false, 'message'=>'Rapor yılı geçersiz.'], 422);
$type = trim((string)($_GET['type'] ?? ''));
$start = sprintf('%04d-01-01', $year);
$end = sprintf('%04d-12-31', $year);
$periodStart = sprintf('%04d-01', $year);
$periodEnd = sprintf('%04d-12', $year);
$pdo = db();
$rows = [];
$title = '';

try {
    if (in_array($type, ['collection','other_income','other_payments'], true)) {
        $movementType = ['collection'=>'tahsilat','other_income'=>'gelir','other_payments'=>'odeme'][$type];
        $title = ['collection'=>'Nakit / banka tahsilatları','other_income'=>'Diğer gelir hareketleri','other_payments'=>'Diğer ödemeler'][$type];
        $stmt = $pdo->prepare("SELECT m.movement_date AS date, COALESCE(c.name,'Cari belirtilmedi') AS source, COALESCE(m.description,'') AS description, m.amount AS amount FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id WHERE COALESCE(m.is_cancelled,0)=0 AND m.movement_type=? AND m.movement_date BETWEEN ? AND ? ORDER BY m.movement_date DESC,m.id DESC LIMIT 1000");
        $stmt->execute([$movementType, $start, $end]);
        rd_add_rows($rows, $stmt->fetchAll(), 'Hareket');
    } elseif ($type === 'store_sales') {
        $title = 'Mağaza satışları';
        $stmt = $pdo->prepare("SELECT sale_date AS date, 'Mağaza' AS source, COALESCE(note,'Günlük mağaza satışı') AS description, gross_amount AS amount FROM store_daily_sales WHERE sale_date BETWEEN ? AND ? ORDER BY sale_date DESC,id DESC LIMIT 1000");
        $stmt->execute([$start, $end]);
        rd_add_rows($rows, $stmt->fetchAll(), 'Satış');
    } elseif (in_array($type, ['incoming_checks','outgoing_checks'], true)) {
        $direction = $type === 'incoming_checks' ? 'alinacak' : 'verilecek';
        $title = $type === 'incoming_checks' ? 'Yıllık gelen çekler' : 'Yıllık verilen çekler';
        $stmt = $pdo->prepare("SELECT ch.due_date AS date, COALESCE(c.name,'Cari belirtilmedi') AS source, TRIM(COALESCE(ch.bank_name,'') || CASE WHEN COALESCE(ch.check_no,'')<>'' THEN ' · No: ' || ch.check_no ELSE '' END || CASE WHEN COALESCE(ch.description,'')<>'' THEN ' · ' || ch.description ELSE '' END) AS description, ch.amount AS amount FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE COALESCE(ch.is_cancelled,0)=0 AND ch.direction=? AND ch.due_date BETWEEN ? AND ? ORDER BY ch.due_date DESC,ch.id DESC LIMIT 1000");
        $stmt->execute([$direction, $start, $end]);
        rd_add_rows($rows, $stmt->fetchAll(), 'Çek');
    } elseif ($type === 'open_receivables') {
        $title = 'Açıkta kalan alacaklar';
        $aggregate = dashboard_cari_aggregate(null, $end);
        foreach ($aggregate['positions'] as $positionRow) {
            $amount = (float)$positionRow['alacak'] - (float)$positionRow['tahsilat']
                - (float)$positionRow['verecek'] + (float)$positionRow['odeme'];
            if ($amount <= 0.005) continue;
            $rows[] = [
                'date'=>(string)($positionRow['last_date'] ?? ''),
                'source'=>(string)$positionRow['name'],
                'description'=>'Açık alacak · Cari bazında net mahsup',
                'amount'=>$amount,
            ];
        }
        usort($rows, function ($a, $b) { return (float)$b['amount'] <=> (float)$a['amount']; });
    } elseif (in_array($type, ['paid_sgk','paid_taxes'], true)) {
        $isSgk = $type === 'paid_sgk';
        $title = $isSgk ? 'Ödenen SSK / SGK' : 'Ödenen diğer vergiler';
        $sgkSql = "(LOWER(COALESCE(tax_type,'')) LIKE '%sgk%' OR LOWER(COALESCE(tax_type,'')) LIKE '%ssk%' OR LOWER(COALESCE(tax_type,'')) LIKE '%sosyal güvenlik%')";
        $stmt = $pdo->prepare("SELECT paid_date AS date, tax_type AS source, COALESCE(description,'') AS description, amount FROM tax_payments WHERE status='odendi' AND paid_date BETWEEN ? AND ? AND " . ($isSgk ? $sgkSql : 'NOT ' . $sgkSql) . " ORDER BY paid_date DESC,id DESC LIMIT 1000");
        $stmt->execute([$start, $end]);
        rd_add_rows($rows, $stmt->fetchAll(), 'Vergi');
    } elseif ($type === 'personnel') {
        $title = 'Maaş ve personel ödemeleri';
        if (rd_table_exists($pdo, 'salary_manual_monthly_totals')) {
            $stmt = $pdo->prepare("SELECT period AS date, 'Aylık maaş toplamı' AS source, 'Manuel aylık ödeme toplamı' AS description, amount FROM salary_manual_monthly_totals WHERE period BETWEEN ? AND ? ORDER BY period DESC");
            $stmt->execute([$periodStart, $periodEnd]);
            rd_add_rows($rows, $stmt->fetchAll(), 'Maaş');
            $stmt = $pdo->prepare("SELECT sr.period AS date, COALESCE(se.full_name,'Personel') AS source, COALESCE(sr.note,'Maaş ödemesi') AS description, sr.paid_amount AS amount FROM salary_records sr LEFT JOIN salary_employees se ON se.id=sr.employee_id WHERE sr.period BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM salary_manual_monthly_totals mt WHERE mt.period=sr.period) AND sr.paid_amount>0 ORDER BY sr.period DESC,sr.id DESC");
        } else {
            $stmt = $pdo->prepare("SELECT sr.period AS date, COALESCE(se.full_name,'Personel') AS source, COALESCE(sr.note,'Maaş ödemesi') AS description, sr.paid_amount AS amount FROM salary_records sr LEFT JOIN salary_employees se ON se.id=sr.employee_id WHERE sr.period BETWEEN ? AND ? AND sr.paid_amount>0 ORDER BY sr.period DESC,sr.id DESC");
        }
        $stmt->execute([$periodStart, $periodEnd]);
        rd_add_rows($rows, $stmt->fetchAll(), 'Maaş');

        $personnelTables = [
            ['salary_advances','advance_date','Maaş avansı',''],
            ['salary_garnishment_payments','payment_date','Maaş haczi', 'COALESCE(p.is_cancelled,0)=0 AND '],
            ['salary_compensation_payments','payment_date','Tazminat / personel', 'COALESCE(p.is_cancelled,0)=0 AND '],
        ];
        foreach ($personnelTables as $meta) {
            [$table, $dateColumn, $kind, $activeSql] = $meta;
            if (!rd_table_exists($pdo, $table)) continue;
            $stmt = $pdo->prepare('SELECT p.' . $dateColumn . " AS date, COALESCE(se.full_name,'Personel') AS source, COALESCE(p.note,'') AS description, p.amount AS amount FROM " . $table . ' p LEFT JOIN salary_employees se ON se.id=p.employee_id WHERE ' . $activeSql . 'p.' . $dateColumn . ' BETWEEN ? AND ? ORDER BY p.' . $dateColumn . ' DESC,p.id DESC');
            $stmt->execute([$start, $end]);
            rd_add_rows($rows, $stmt->fetchAll(), $kind);
        }
    } else {
        rd_response(['ok'=>false, 'message'=>'Detay türü bulunamadı.'], 404);
    }

    $total = 0.0;
    foreach ($rows as $row) $total += (float)$row['amount'];
    rd_response(['ok'=>true, 'title'=>$title, 'year'=>$year, 'total'=>$total, 'rows'=>$rows]);
} catch (Throwable $e) {
    rd_response(['ok'=>false, 'message'=>'Detay kayıtları şu anda okunamadı.'], 500);
}
