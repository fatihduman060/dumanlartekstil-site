<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/maas-puantaj-lib.php';
require_once __DIR__ . '/maas-aylik-kayit-lib.php';
require_once __DIR__ . '/maas-avans-lib.php';
require_salary_access();
maas_aylik_kayit_db_ensure();
maas_avans_db_ensure();

$period = maas_puantaj_period((string)($_GET['period'] ?? date('Y-m')));
$daysInMonth = (int)date('t', strtotime($period . '-01'));
$employees = db()->query("SELECT * FROM salary_employees WHERE is_active=1 ORDER BY full_name ASC")->fetchAll() ?: [];
$statusMap = maas_puantaj_statuses();

function maas_toplu_excel_xml($value): string
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function maas_toplu_excel_col(int $number): string
{
    $result = '';
    while ($number > 0) {
        $number--;
        $result = chr(65 + ($number % 26)) . $result;
        $number = intdiv($number, 26);
    }
    return $result;
}

function maas_toplu_excel_text_cell(int $col, int $row, $value, int $style = 5): string
{
    $ref = maas_toplu_excel_col($col) . $row;
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
        . maas_toplu_excel_xml($value) . '</t></is></c>';
}

function maas_toplu_excel_number_cell(int $col, int $row, $value, int $style = 7): string
{
    $ref = maas_toplu_excel_col($col) . $row;
    $number = is_numeric($value) ? (float)$value : 0;
    return '<c r="' . $ref . '" s="' . $style . '"><v>' . rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.') . '</v></c>';
}

function maas_toplu_excel_status_style(string $status): int
{
    if ($status === 'calisti') return 9;
    if ($status === 'gelmedi') return 10;
    if (in_array($status, ['izinli', 'raporlu'], true)) return 11;
    if (in_array($status, ['hafta_tatili', 'resmi_tatil'], true)) return 12;
    return 13;
}

function maas_toplu_excel_rows(string $period, int $daysInMonth, array $employees, array $statusMap): array
{
    $headers = ['Sıra', 'Personel', 'Bölüm', 'Görev', 'Dönem'];
    for ($day = 1; $day <= $daysInMonth; $day++) $headers[] = (string)$day;
    $headers = array_merge($headers, [
        'Bordro Günü', 'Devamsızlık', 'Eksik Saat', 'Fazla Mesai', 'Çalıştı', 'İzinli', 'Raporlu',
        'Hafta Tatili', 'Resmî Tatil', 'Aylık Maaş', 'Günlük Yevmiye', 'Saatlik Ücret',
        'Avans Toplamı', 'Haciz Kesintisi', 'Diğer Kesinti', 'Net Ödenecek', 'Kayıt Kaynağı', 'Açıklama'
    ]);

    $rows = [];
    foreach ($employees as $index => $employee) {
        $employeeId = (int)$employee['id'];
        $entries = maas_puantaj_entries($employeeId, $period);
        $summary = maas_aylik_kayit_effective_summary($employeeId, $period);
        $basis = maas_puantaj_salary_basis($employeeId, $period);
        $payroll = maas_puantaj_payroll($employeeId, $period);
        $record = maas_aylik_kayit_record($employeeId, $period);
        $advanceTotal = maas_avans_period_total($employeeId, $period);
        $source = $record && (int)($record['attendance_override_enabled'] ?? 0) === 1 ? 'Aylık maaş kaydı özeti' : 'Günlük puantaj';

        $row = [
            $index + 1,
            (string)$employee['full_name'],
            (string)($employee['department'] ?? ''),
            (string)($employee['position'] ?? ''),
            $period,
        ];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = sprintf('%s-%02d', $period, $day);
            $entry = $entries[$date] ?? null;
            if (!$entry) {
                $row[] = '';
                continue;
            }
            $status = (string)($entry['status'] ?? '');
            $text = (string)($statusMap[$status]['short'] ?? '');
            $details = [];
            if ((float)($entry['missing_hours'] ?? 0) > 0) {
                $details[] = '-' . number_format((float)$entry['missing_hours'], 1, ',', '.') . 's';
            }
            if ((float)($entry['overtime_hours'] ?? 0) > 0) {
                $details[] = '+' . number_format((float)$entry['overtime_hours'], 1, ',', '.') . 's';
            }
            if ($details) $text .= ' ' . implode(' ', $details);
            $row[] = $text;
        }

        $garnishment = (float)($payroll['garnishment_amount'] ?? $record['garnishment_amount'] ?? 0);
        $otherDeduction = (float)($payroll['other_deduction_amount'] ?? $record['manual_deduction_amount'] ?? 0);
        $baseSalary = (float)($basis['base_salary'] ?? 0);
        $netPayable = (float)($payroll['net_payable'] ?? 0);
        if (!$payroll) {
            $absence = (float)($summary['absent_days'] ?? 0) * ($baseSalary / 30);
            $hourCut = (float)($summary['missing_hours'] ?? 0) * (($baseSalary / 30) / 9);
            $netPayable = max(0, $baseSalary - $absence - $hourCut - $advanceTotal - $garnishment - $otherDeduction);
        }

        $row = array_merge($row, [
            (float)($summary['paid_days'] ?? 30),
            (float)($summary['absent_days'] ?? 0),
            (float)($summary['missing_hours'] ?? 0),
            (float)($summary['overtime_hours'] ?? 0),
            (float)($summary['work_days'] ?? 0),
            (float)($summary['paid_leave_days'] ?? 0),
            (float)($summary['report_days'] ?? 0),
            (float)($summary['weekly_off_days'] ?? 0),
            (float)($summary['holiday_days'] ?? 0),
            $baseSalary,
            (float)($basis['daily_rate'] ?? ($baseSalary / 30)),
            (float)($basis['hourly_rate'] ?? (($baseSalary / 30) / 9)),
            $advanceTotal,
            $garnishment,
            $otherDeduction,
            $netPayable,
            $source,
            (string)($payroll['note'] ?? $record['note'] ?? ''),
        ]);
        $rows[] = [
            'values' => $row,
            'entries' => $entries,
        ];
    }

    return [$headers, $rows];
}

function maas_toplu_excel_csv(string $period, array $headers, array $rows): void
{
    while (ob_get_level()) ob_end_clean();
    $filename = 'toplu-puantaj-' . $period . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');
    foreach ($rows as $row) fputcsv($out, $row['values'], ';');
    fclose($out);
    exit;
}

[$headers, $rows] = maas_toplu_excel_rows($period, $daysInMonth, $employees, $statusMap);

if (!class_exists('ZipArchive')) {
    maas_toplu_excel_csv($period, $headers, $rows);
}

$lastCol = maas_toplu_excel_col(count($headers));
$lastRow = 4 + count($rows);
$title = month_label($period) . ' Toplu Puantaj Cetveli';
$subtitle = 'Kodlar: Ç=Çalıştı, G=Gelmedi, İ=İzinli, R=Raporlu, H=Hafta tatili, T=Resmî tatil. -1,0s eksik saat; +1,0s fazla mesai.';

$sheetRows = [];
$sheetRows[] = '<row r="1" ht="28" customHeight="1">' . maas_toplu_excel_text_cell(1, 1, $title, 1) . '</row>';
$sheetRows[] = '<row r="2" ht="23" customHeight="1">' . maas_toplu_excel_text_cell(1, 2, $subtitle, 2) . '</row>';
$sheetRows[] = '<row r="3" ht="8" customHeight="1"></row>';

$headerCells = '';
foreach ($headers as $index => $header) {
    $col = $index + 1;
    $style = 3;
    if ($col >= 6 && $col < 6 + $daysInMonth) {
        $day = $col - 5;
        $weekday = (int)date('N', strtotime(sprintf('%s-%02d', $period, $day)));
        if ($weekday >= 6) $style = 4;
    }
    $headerCells .= maas_toplu_excel_text_cell($col, 4, $header, $style);
}
$sheetRows[] = '<row r="4" ht="34" customHeight="1">' . $headerCells . '</row>';

foreach ($rows as $rowIndex => $rowData) {
    $excelRow = 5 + $rowIndex;
    $values = $rowData['values'];
    $cells = '';
    foreach ($values as $index => $value) {
        $col = $index + 1;
        if ($col === 1) {
            $cells .= maas_toplu_excel_number_cell($col, $excelRow, $value, 6);
            continue;
        }
        if ($col >= 6 && $col < 6 + $daysInMonth) {
            $day = $col - 5;
            $date = sprintf('%s-%02d', $period, $day);
            $status = (string)($rowData['entries'][$date]['status'] ?? '');
            $cells .= maas_toplu_excel_text_cell($col, $excelRow, $value, maas_toplu_excel_status_style($status));
            continue;
        }
        $summaryStart = 6 + $daysInMonth;
        $moneyStart = $summaryStart + 9;
        $moneyEnd = $moneyStart + 6;
        if ($col >= $moneyStart && $col <= $moneyEnd) {
            $cells .= maas_toplu_excel_number_cell($col, $excelRow, $value, 8);
        } elseif ($col >= $summaryStart && $col < $moneyStart) {
            $cells .= maas_toplu_excel_number_cell($col, $excelRow, $value, 7);
        } elseif (is_numeric($value) && $col > 5) {
            $cells .= maas_toplu_excel_number_cell($col, $excelRow, $value, 7);
        } elseif ($col === 5) {
            $cells .= maas_toplu_excel_text_cell($col, $excelRow, $value, 6);
        } else {
            $cells .= maas_toplu_excel_text_cell($col, $excelRow, $value, 5);
        }
    }
    $sheetRows[] = '<row r="' . $excelRow . '" ht="25" customHeight="1">' . $cells . '</row>';
}

$colsXml = '<cols>'
    . '<col min="1" max="1" width="6" customWidth="1"/>'
    . '<col min="2" max="2" width="25" customWidth="1"/>'
    . '<col min="3" max="4" width="18" customWidth="1"/>'
    . '<col min="5" max="5" width="12" customWidth="1"/>'
    . '<col min="6" max="' . (5 + $daysInMonth) . '" width="6.5" customWidth="1"/>'
    . '<col min="' . (6 + $daysInMonth) . '" max="' . (14 + $daysInMonth) . '" width="13" customWidth="1"/>'
    . '<col min="' . (15 + $daysInMonth) . '" max="' . (21 + $daysInMonth) . '" width="17" customWidth="1"/>'
    . '<col min="' . (22 + $daysInMonth) . '" max="' . (22 + $daysInMonth) . '" width="22" customWidth="1"/>'
    . '<col min="' . (23 + $daysInMonth) . '" max="' . (23 + $daysInMonth) . '" width="30" customWidth="1"/>'
    . '</cols>';

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
    . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
    . '<sheetViews><sheetView workbookViewId="0"><pane xSplit="5" ySplit="4" topLeftCell="F5" activePane="bottomRight" state="frozen"/><selection pane="bottomRight" activeCell="F5" sqref="F5"/></sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="18"/>'
    . $colsXml
    . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
    . '<mergeCells count="2"><mergeCell ref="A1:' . $lastCol . '1"/><mergeCell ref="A2:' . $lastCol . '2"/></mergeCells>'
    . '<autoFilter ref="A4:' . $lastCol . $lastRow . '"/>'
    . '<printOptions horizontalCentered="1"/>'
    . '<pageMargins left="0.2" right="0.2" top="0.35" bottom="0.35" header="0.15" footer="0.15"/>'
    . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>'
    . '</worksheet>';

$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#.##0,00 \&quot;TL\&quot;"/><numFmt numFmtId="165" formatCode="0,00"/></numFmts>'
    . '<fonts count="4">'
    . '<font><sz val="10"/><name val="Calibri"/><family val="2"/></font>'
    . '<font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Calibri"/></font>'
    . '<font><i/><color rgb="FF526257"/><sz val="10"/><name val="Calibri"/></font>'
    . '<font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="9">'
    . '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF16482E"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF9A722A"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7F5EB"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFE3E0"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FA"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFF6EEDA"/><bgColor indexed="64"/></patternFill></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="2"><border/><border><left style="thin"><color rgb="FFD7DED9"/></left><right style="thin"><color rgb="FFD7DED9"/></right><top style="thin"><color rgb="FFD7DED9"/></top><bottom style="thin"><color rgb="FFD7DED9"/></bottom><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="14">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
    . '<xf numFmtId="0" fontId="0" fillId="8" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
    . '<sheets><sheet name="Toplu Puantaj" sheetId="1" r:id="rId1"/></sheets>'
    . '<calcPr calcId="191029"/></workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
    . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
    . '</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
    . '</Relationships>';

$nowIso = gmdate('Y-m-d\TH:i:s\Z');
$coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    . '<dc:title>' . maas_toplu_excel_xml($title) . '</dc:title><dc:creator>Dumanlar Muhasebe</dc:creator><cp:lastModifiedBy>Dumanlar Muhasebe</cp:lastModifiedBy>'
    . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $nowIso . '</dcterms:modified></cp:coreProperties>';

$appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
    . '<Application>Dumanlar Muhasebe</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
    . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>'
    . '<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Toplu Puantaj</vt:lpstr></vt:vector></TitlesOfParts><Company>Dumanlar Tekstil</Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>16.0300</AppVersion></Properties>';

$tmp = tempnam(sys_get_temp_dir(), 'puantaj_xlsx_');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    maas_toplu_excel_csv($period, $headers, $rows);
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('docProps/core.xml', $coreXml);
$zip->addFromString('docProps/app.xml', $appXml);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $stylesXml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

while (ob_get_level()) ob_end_clean();
$filename = 'toplu-puantaj-' . $period . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($tmp);
@unlink($tmp);
exit;
