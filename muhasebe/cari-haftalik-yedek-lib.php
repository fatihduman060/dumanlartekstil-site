<?php
require_once __DIR__ . '/bootstrap.php';

function cari_haftalik_yedek_dir(): string
{
    return rtrim(BACKUP_DIR, '/\\') . '/cari-haftalik';
}

function cari_haftalik_yedek_ensure_dir(): string
{
    $dir = cari_haftalik_yedek_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cari haftalık yedek klasörü oluşturulamadı.');
    }
    if (!is_writable($dir)) throw new RuntimeException('Cari haftalık yedek klasörü yazılabilir değil.');
    return $dir;
}

function cari_haftalik_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function cari_haftalik_col_name(int $index): string
{
    $name = '';
    while ($index >= 0) {
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26) - 1;
    }
    return $name;
}

function cari_haftalik_rows(): array
{
    $rows = db()->query('SELECT id, name, cari_type, city, authorized_person, phone FROM cariler ORDER BY name ASC')->fetchAll() ?: [];
    $out = [];
    $totalReceivable = 0.0;
    $totalPayable = 0.0;
    foreach ($rows as $row) {
        $balance = cari_balance((int)$row['id']);
        $net = round((float)($balance['net'] ?? ((float)($balance['net_alacak'] ?? 0) - (float)($balance['net_verecek'] ?? 0))), 2);
        $receivable = max(0, $net);
        $payable = max(0, -$net);
        $totalReceivable += $receivable;
        $totalPayable += $payable;
        $status = $net == 0 ? 'Kapalı' : ($net > 0 ? 'Biz alacaklıyız' : 'Biz borçluyuz');
        $out[] = [
            'id'=>(int)$row['id'],
            'name'=>(string)($row['name'] ?? ''),
            'type'=>(string)($row['cari_type'] ?? ''),
            'city'=>(string)($row['city'] ?? ''),
            'authorized'=>(string)($row['authorized_person'] ?? ''),
            'phone'=>(string)($row['phone'] ?? ''),
            'receivable'=>$receivable,
            'payable'=>$payable,
            'net'=>$net,
            'status'=>$status,
        ];
    }
    return [$out, round($totalReceivable, 2), round($totalPayable, 2)];
}

function cari_haftalik_sheet_xml(array $rows, string $snapshotAt, string $title, string $amountLabel, string $amountKey, float $total): string
{
    $headers = ['Cari','Tür','Şehir','Yetkili','Telefon',$amountLabel];
    $sheetRows = [];
    $sheetRows[] = [$title,'','','','',''];
    $sheetRows[] = ['Yedek zamanı',$snapshotAt,'','','',''];
    $sheetRows[] = ['','','','','',''];
    $sheetRows[] = $headers;
    foreach ($rows as $row) {
        $sheetRows[] = [
            $row['name'],$row['type'],$row['city'],$row['authorized'],$row['phone'],
            $row[$amountKey]
        ];
    }
    $sheetRows[] = ['TOPLAM','','','','',$total];

    $xmlRows = '';
    $lastRowIndex = count($sheetRows) - 1;
    foreach ($sheetRows as $rIndex => $row) {
        $cells = '';
        foreach ($row as $cIndex => $value) {
            $isNumeric = $rIndex >= 4 && $cIndex === 5 && is_numeric($value);
            if ($isNumeric) {
                $cells .= '<c r="' . cari_haftalik_col_name($cIndex) . ($rIndex + 1) . '" s="' . ($rIndex === $lastRowIndex ? '4' : '2') . '"><v>' . number_format((float)$value, 2, '.', '') . '</v></c>';
            } else {
                $style = $rIndex === 0 ? ' s="3"' : ($rIndex === 3 || $rIndex === $lastRowIndex ? ' s="1"' : '');
                $cells .= '<c r="' . cari_haftalik_col_name($cIndex) . ($rIndex + 1) . '" t="inlineStr"' . $style . '><is><t>' . cari_haftalik_xml((string)$value) . '</t></is></c>';
            }
        }
        $xmlRows .= '<row r="' . ($rIndex + 1) . '">' . $cells . '</row>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols><col min="1" max="1" width="34" customWidth="1"/><col min="2" max="2" width="14" customWidth="1"/><col min="3" max="3" width="18" customWidth="1"/><col min="4" max="5" width="22" customWidth="1"/><col min="6" max="6" width="22" customWidth="1"/></cols>'
        . '<sheetData>' . $xmlRows . '</sheetData><mergeCells count="1"><mergeCell ref="A1:F1"/></mergeCells><autoFilter ref="A4:F' . max(4, $lastRowIndex) . '"/></worksheet>';
}

function cari_haftalik_make_xlsx(string $path, string $snapshotAt): void
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Sunucuda ZipArchive aktif değil; XLSX üretilemedi.');
    [$rows, $totalReceivable, $totalPayable] = cari_haftalik_rows();
    $receivables = array_values(array_filter($rows, fn($row) => $row['receivable'] > 0));
    $payables = array_values(array_filter($rows, fn($row) => $row['payable'] > 0));
    $receivableSheet = cari_haftalik_sheet_xml($receivables, $snapshotAt, 'HAFTALIK CARİ BAKİYE YEDEĞİ - ALACAKLI OLDUKLARIMIZ', 'Bizim Alacağımız', 'receivable', $totalReceivable);
    $payableSheet = cari_haftalik_sheet_xml($payables, $snapshotAt, 'HAFTALIK CARİ BAKİYE YEDEĞİ - BORÇLU OLDUKLARIMIZ', 'Bizim Borcumuz', 'payable', $totalPayable);

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Alacaklı Olduklarımız" sheetId="1" r:id="rId1"/><sheet name="Borçlu Olduklarımız" sheetId="2" r:id="rId2"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Geçici XLSX dosyası oluşturulamadı.');
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $receivableSheet);
    $zip->addFromString('xl/worksheets/sheet2.xml', $payableSheet);
    $zip->close();
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('XLSX yedeği kalıcı dosyaya taşınamadı.'); }
}

function cari_haftalik_week_key(?int $time = null): string
{
    $time = $time ?? time();
    $monday = strtotime('monday this week 00:00:00', $time);
    return date('Y-m-d', $monday);
}

function cari_haftalik_create(bool $force = false): ?string
{
    $dir = cari_haftalik_yedek_ensure_dir();
    $weekKey = cari_haftalik_week_key();
    $pattern = $dir . '/Cari_Bakiye_' . str_replace('-', '', $weekKey) . '_*.xlsx';
    $existing = glob($pattern) ?: [];
    if (!$force && $existing) return $existing[0];

    $stamp = date('Ymd_His');
    $path = $dir . '/Cari_Bakiye_' . str_replace('-', '', $weekKey) . '_' . $stamp . '.xlsx';
    cari_haftalik_make_xlsx($path, date('d.m.Y H:i'));

    if ($force && $existing) {
        foreach ($existing as $old) {
            if ($old !== $path) @unlink($old);
        }
    }
    cari_haftalik_cleanup(52);
    return $path;
}

function cari_haftalik_cleanup(int $keep = 52): void
{
    $files = cari_haftalik_list();
    foreach (array_slice($files, max(1, $keep)) as $file) @unlink($file['path']);
}

function cari_haftalik_list(): array
{
    $dir = cari_haftalik_yedek_ensure_dir();
    $items = [];
    foreach (glob($dir . '/Cari_Bakiye_*.xlsx') ?: [] as $path) {
        if (!is_file($path)) continue;
        $items[] = ['name'=>basename($path), 'path'=>$path, 'time'=>filemtime($path) ?: 0, 'size'=>filesize($path) ?: 0];
    }
    usort($items, fn($a,$b) => $b['time'] <=> $a['time']);
    return $items;
}

function cari_haftalik_auto_if_due(): ?string
{
    $now = time();
    $monday = strtotime('monday this week 08:00:00', $now);
    if ($now < $monday) return null;
    return cari_haftalik_create(false);
}
