<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthDate = DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01') ?: new DateTimeImmutable('first day of this month');
$start = $monthDate->format('Y-m-01');
$end = $monthDate->format('Y-m-t');

function pdf_cash_filter_sql(): string
{
    return "
      AND COALESCE(m.is_check_adjustment,0)=0
      AND (m.check_id IS NULL OR m.check_id=0)
      AND UPPER(COALESCE(m.payment_method,'')) NOT LIKE '%ÇEK%'
      AND UPPER(COALESCE(m.payment_method,'')) NOT LIKE '%CEK%'";
}

function pdf_source_label($source): string
{
    return [
        'manual' => 'Manuel',
        'movement' => 'Cari hareket',
        'check' => 'Cek',
        'transfer' => 'Virman',
    ][$source] ?? (string)$source;
}

function pdf_money($amount): string
{
    return number_format((float)$amount, 2, ',', '.') . ' TL';
}

function pdf_clean_text($text): string
{
    $map = [
        'Ğ'=>'G','Ü'=>'U','Ş'=>'S','İ'=>'I','I'=>'I','Ö'=>'O','Ç'=>'C',
        'ğ'=>'g','ü'=>'u','ş'=>'s','ı'=>'i','ö'=>'o','ç'=>'c','₺'=>'TL',
        '–'=>'-','—'=>'-','“'=>'"','”'=>'"','’'=>"'",'‘'=>"'",'…'=>'...',
    ];
    $text = strtr((string)$text, $map);
    $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    return $text ?? '';
}

function pdf_short($text, $max): string
{
    $text = pdf_clean_text($text);
    if (strlen($text) <= $max) return $text;
    return rtrim(substr($text, 0, max(0, $max - 3))) . '...';
}

class SimpleStatementPdf
{
    private $pages = [];
    private $current = '';
    private $w = 842;
    private $h = 595;
    private $pageNo = 0;

    public function addPage()
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }
        $this->current = '';
        $this->pageNo++;
    }

    private function cmd($command)
    {
        $this->current .= $command . "\n";
    }

    private function esc($text)
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], pdf_clean_text($text));
    }

    public function text($x, $y, $text, $size = 8, $bold = false, $align = 'left', $maxChars = null)
    {
        $text = pdf_clean_text($text);
        if ($maxChars !== null) $text = pdf_short($text, $maxChars);
        $font = $bold ? 'F2' : 'F1';
        $approxWidth = strlen($text) * $size * 0.47;
        if ($align === 'right') $x -= $approxWidth;
        if ($align === 'center') $x -= $approxWidth / 2;
        $py = $this->h - $y;
        $this->cmd(sprintf('BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $font, $size, $x, $py, $this->esc($text)));
    }

    public function rect($x, $y, $w, $h, $fill = null, $stroke = null)
    {
        $py = $this->h - $y - $h;
        if ($fill) {
            [$r,$g,$b] = $fill;
            $this->cmd(sprintf('q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q', $r, $g, $b, $x, $py, $w, $h));
        }
        if ($stroke) {
            [$r,$g,$b] = $stroke;
            $this->cmd(sprintf('q %.3F %.3F %.3F RG %.2F %.2F %.2F %.2F re S Q', $r, $g, $b, $x, $py, $w, $h));
        }
    }

    public function line($x1, $y1, $x2, $y2, $color = [0.78,0.72,0.62])
    {
        [$r,$g,$b] = $color;
        $this->cmd(sprintf('q %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S Q', $r, $g, $b, $x1, $this->h - $y1, $x2, $this->h - $y2));
    }

    public function output($filename)
    {
        if ($this->current !== '') $this->pages[] = $this->current;
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $kids = [];
        foreach ($this->pages as $content) {
            $contentId = max(array_keys($objects)) + 1;
            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $pageId = $contentId + 1;
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->w . ' ' . $this->h . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
            $kids[] = $pageId . ' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $obj) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $obj . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}

$cashStmt = db()->prepare("SELECT m.movement_type, SUM(m.amount) AS total
    FROM movements m
    WHERE COALESCE(m.is_cancelled,0)=0
      AND m.movement_date BETWEEN ? AND ?
      AND m.movement_type IN ('tahsilat','gelir','odeme','gider')
      " . pdf_cash_filter_sql() . "
    GROUP BY m.movement_type");
$cashStmt->execute([$start, $end]);
$cashTotals = ['tahsilat'=>0,'gelir'=>0,'odeme'=>0,'gider'=>0];
foreach ($cashStmt->fetchAll() as $row) {
    if (isset($cashTotals[$row['movement_type']])) $cashTotals[$row['movement_type']] = (float)$row['total'];
}
$cashIn = $cashTotals['tahsilat'] + $cashTotals['gelir'];
$cashOut = $cashTotals['odeme'] + $cashTotals['gider'];
$cashNet = $cashIn - $cashOut;
$accountSummary = account_summary();

$accountStmt = db()->prepare("SELECT a.id, a.name, a.account_type, a.bank_name,
      SUM(CASE WHEN at.direction='in' THEN at.amount ELSE 0 END) AS total_in,
      SUM(CASE WHEN at.direction='out' THEN at.amount ELSE 0 END) AS total_out
    FROM accounts a
    LEFT JOIN account_transactions at ON at.account_id=a.id AND at.transaction_date BETWEEN ? AND ?
    GROUP BY a.id
    ORDER BY a.account_type ASC, a.name ASC");
$accountStmt->execute([$start, $end]);
$accountRows = $accountStmt->fetchAll();

$transactionStmt = db()->prepare("SELECT at.*, a.name AS account_name, a.account_type
    FROM account_transactions at
    JOIN accounts a ON a.id=at.account_id
    WHERE at.transaction_date BETWEEN ? AND ?
    ORDER BY at.transaction_date ASC, at.id ASC");
$transactionStmt->execute([$start, $end]);
$accountTransactions = $transactionStmt->fetchAll();

$movementStmt = db()->prepare("SELECT m.*, c.name AS cari_name
    FROM movements m
    LEFT JOIN cariler c ON c.id=m.cari_id
    WHERE COALESCE(m.is_cancelled,0)=0
      AND m.movement_date BETWEEN ? AND ?
      AND m.movement_type IN ('alacak','tahsilat','verecek','odeme','gelir','gider')
    ORDER BY m.movement_date ASC, m.id ASC");
$movementStmt->execute([$start, $end]);
$movements = $movementStmt->fetchAll();

$checkStmt = db()->prepare("SELECT ch.*, c.name AS cari_name
    FROM checks ch
    LEFT JOIN cariler c ON c.id=ch.cari_id
    WHERE COALESCE(ch.is_cancelled,0)=0
      AND ch.due_date BETWEEN ? AND ?
    ORDER BY ch.due_date ASC, ch.id ASC");
$checkStmt->execute([$start, $end]);
$checks = $checkStmt->fetchAll();
$checkTotals = ['alinacak'=>0,'verilecek'=>0,'alinacak_count'=>0,'verilecek_count'=>0];
foreach ($checks as $ch) {
    $dir = $ch['direction'] === 'verilecek' ? 'verilecek' : 'alinacak';
    $checkTotals[$dir] += (float)$ch['amount'];
    $checkTotals[$dir . '_count']++;
}

$pdf = new SimpleStatementPdf();
$pdf->addPage();
$y = 28;
$left = 28;
$right = 814;
$bottom = 560;

$header = function() use ($pdf, $month, $start, $end, &$y, $left, $right) {
    $pdf->rect(22, 18, 798, 42, [0.96,0.93,0.87], [0.78,0.72,0.62]);
    $pdf->text($left, 36, 'BITKE MUHASEBE - HESAP DOKUMLERI', 13, true);
    $pdf->text(814, 36, 'Donem: ' . month_label($month) . ' (' . tr_date($start) . ' - ' . tr_date($end) . ')', 8, false, 'right');
    $pdf->text(814, 51, 'Olusturma: ' . date('d.m.Y H:i'), 7, false, 'right');
    $y = 76;
};

$newPageIfNeeded = function($needed = 28) use ($pdf, &$y, $bottom, $header) {
    if ($y + $needed <= $bottom) return;
    $pdf->addPage();
    $header();
};

$section = function($title) use ($pdf, &$y, $left, $right, $newPageIfNeeded) {
    $newPageIfNeeded(28);
    $pdf->rect($left, $y, $right - $left, 18, [0.93,0.88,0.79], null);
    $pdf->text($left + 8, $y + 12, $title, 8.5, true);
    $y += 24;
};

$tableHeader = function($cols) use ($pdf, &$y, $left, $right, $newPageIfNeeded) {
    $newPageIfNeeded(24);
    $x = $left;
    $pdf->rect($left, $y, $right - $left, 15, [0.20,0.32,0.24], null);
    foreach ($cols as $c) {
        $pdf->text($x + 4, $y + 10, $c[0], 6.8, true, 'left', $c[2] ?? null);
        $x += $c[1];
    }
    $y += 15;
};

$tableRow = function($cols, $values, $shade = false) use ($pdf, &$y, $left, $right, $newPageIfNeeded) {
    $newPageIfNeeded(17);
    if ($shade) $pdf->rect($left, $y, $right - $left, 14, [0.985,0.975,0.955], null);
    $x = $left;
    foreach ($cols as $idx => $c) {
        $align = $c[3] ?? 'left';
        $max = $c[2] ?? null;
        $textX = $align === 'right' ? $x + $c[1] - 4 : $x + 4;
        $pdf->text($textX, $y + 10, $values[$idx] ?? '', 6.6, false, $align, $max);
        $x += $c[1];
    }
    $pdf->line($left, $y + 14, $right, $y + 14, [0.86,0.82,0.74]);
    $y += 14;
};

$header();
$boxW = 126;
$boxes = [
    ['Giren', pdf_money($cashIn)],
    ['Cikan', pdf_money($cashOut)],
    ['Net nakit', pdf_money($cashNet)],
    ['Hesap bakiyesi', pdf_money($accountSummary['total'])],
    ['Alinacak cek', pdf_money($checkTotals['alinacak']) . ' / ' . $checkTotals['alinacak_count'] . ' adet'],
    ['Verilecek cek', pdf_money($checkTotals['verilecek']) . ' / ' . $checkTotals['verilecek_count'] . ' adet'],
];
$x = $left;
foreach ($boxes as $b) {
    $pdf->rect($x, $y, $boxW - 8, 38, [1,1,1], [0.84,0.80,0.72]);
    $pdf->text($x + 8, $y + 14, $b[0], 6.8, true);
    $pdf->text($x + 8, $y + 30, $b[1], 9, true, 'left', 22);
    $x += $boxW;
}
$y += 52;

$section('1) Hesap bazli aylik dokum');
$cols = [['Hesap',160,24],['Tip',60,10],['Giris',105,18,'right'],['Cikis',105,18,'right'],['Net',105,18,'right'],['Guncel bakiye',125,20,'right']];
$tableHeader($cols);
$i=0;
foreach ($accountRows as $a) {
    $in = (float)$a['total_in']; $out = (float)$a['total_out']; $net = $in - $out;
    $tableRow($cols, [
        $a['name'], account_type_label($a['account_type']), pdf_money($in), pdf_money($out), pdf_money($net), pdf_money(account_balance((int)$a['id']))
    ], $i++ % 2 === 1);
}

$section('2) Kasa / banka hareketleri');
$cols = [['Tarih',58,10],['Hesap',135,23],['Kaynak',80,16],['Aciklama',282,52],['Giris',100,18,'right'],['Cikis',100,18,'right']];
$tableHeader($cols);
$i=0;
if (!$accountTransactions) $tableRow($cols, ['-', 'Kayit yok', '-', '-', '-', '-']);
foreach ($accountTransactions as $tr) {
    $tableRow($cols, [
        tr_date($tr['transaction_date']),
        $tr['account_name'],
        pdf_source_label($tr['source_type']),
        $tr['description'] ?: '-',
        $tr['direction'] === 'in' ? pdf_money($tr['amount']) : '-',
        $tr['direction'] === 'out' ? pdf_money($tr['amount']) : '-',
    ], $i++ % 2 === 1);
}

$section('3) Cari hareket dokumu');
$cols = [['Tarih',58,10],['Cari',190,35],['Tip',75,14],['Aciklama',330,64],['Tutar',105,18,'right']];
$tableHeader($cols);
$i=0;
if (!$movements) $tableRow($cols, ['-', 'Kayit yok', '-', '-', '-']);
foreach ($movements as $m) {
    $tableRow($cols, [
        tr_date($m['movement_date']),
        $m['cari_name'] ?: '-',
        movement_label($m['movement_type']),
        $m['description'] ?: '-',
        pdf_money($m['amount']),
    ], $i++ % 2 === 1);
}

$section('4) Cek vade dokumu');
$cols = [['Vade',58,10],['Yon',95,18],['Cari',230,42],['Banka / No',270,54],['Tutar',105,18,'right']];
$tableHeader($cols);
$i=0;
if (!$checks) $tableRow($cols, ['-', 'Bu ay vadesi gelen cek yok', '-', '-', '-']);
foreach ($checks as $ch) {
    $tableRow($cols, [
        tr_date($ch['due_date']),
        check_direction_label($ch['direction']),
        $ch['cari_name'] ?: '-',
        trim(($ch['bank_name'] ?: '-') . ' / ' . ($ch['check_no'] ?: '-')),
        pdf_money($ch['amount']),
    ], $i++ % 2 === 1);
}

$pdf->output('bitke-hesap-dokumleri-' . $month . '.pdf');
