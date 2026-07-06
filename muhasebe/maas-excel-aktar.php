<?php
require_once __DIR__ . '/layout.php';
require_admin();

function salary_import_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        department TEXT,
        position TEXT,
        phone TEXT,
        start_date TEXT,
        base_salary REAL NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        period TEXT NOT NULL,
        salary_amount REAL NOT NULL DEFAULT 0,
        advance_amount REAL NOT NULL DEFAULT 0,
        deduction_amount REAL NOT NULL DEFAULT 0,
        paid_amount REAL NOT NULL DEFAULT 0,
        remaining_amount REAL NOT NULL DEFAULT 0,
        payment_date TEXT,
        account_id INTEGER,
        account_transaction_id INTEGER,
        status TEXT NOT NULL DEFAULT 'bekliyor',
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(employee_id) REFERENCES salary_employees(id) ON DELETE CASCADE,
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        FOREIGN KEY(account_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
}

function salary_import_norm(string $text): string
{
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $map = ['ı'=>'i','ğ'=>'g','ü'=>'u','ş'=>'s','ö'=>'o','ç'=>'c','İ'=>'i'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/u', '', $text);
    return $text ?: '';
}

function salary_import_money($value): float
{
    if (is_numeric($value)) return (float)$value;
    return decimal_from_input((string)$value);
}

function salary_import_clean($value): string
{
    $value = (string)$value;
    $value = preg_replace('/\x{00A0}/u', ' ', $value);
    return trim($value);
}

function salary_import_csv(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) return $rows;
    $first = fgets($fh);
    if ($first === false) { fclose($fh); return $rows; }
    $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    rewind($fh);
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        $rows[] = array_map('salary_import_clean', $row);
    }
    fclose($fh);
    return $rows;
}

function salary_import_xlsx_cell_value(SimpleXMLElement $cell, array $shared): string
{
    $type = (string)($cell['t'] ?? '');
    if ($type === 'inlineStr') {
        return salary_import_clean((string)($cell->is->t ?? ''));
    }
    $raw = (string)($cell->v ?? '');
    if ($type === 's') {
        $idx = (int)$raw;
        return salary_import_clean($shared[$idx] ?? '');
    }
    return salary_import_clean($raw);
}

function salary_import_col_index(string $cellRef): int
{
    if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) return 0;
    $letters = strtoupper($m[1]);
    $num = 0;
    for ($i=0; $i<strlen($letters); $i++) {
        $num = $num * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $num - 1);
}

function salary_import_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Sunucuda Excel okuma modülü yok. Dosyayı CSV olarak yükleyin.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Excel dosyası açılamadı.');

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = simplexml_load_string($sharedXml);
        if ($sx) {
            foreach ($sx->si as $si) {
                $parts = [];
                if (isset($si->t)) $parts[] = (string)$si->t;
                if (isset($si->r)) foreach ($si->r as $r) $parts[] = (string)$r->t;
                $shared[] = salary_import_clean(implode('', $parts));
            }
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml !== false && $relsXml !== false) {
        $wb = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if ($wb && $rels && isset($wb->sheets->sheet[0])) {
            $rid = (string)$wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = (string)$rel['Target'];
                    $sheetPath = 'xl/' . ltrim($target, '/');
                    break;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('Excel içinde çalışma sayfası bulunamadı.');
    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet) throw new RuntimeException('Excel sayfası okunamadı.');

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $line = [];
        foreach ($row->c as $cell) {
            $ref = (string)($cell['r'] ?? '');
            $idx = salary_import_col_index($ref);
            $line[$idx] = salary_import_xlsx_cell_value($cell, $shared);
        }
        if ($line) {
            ksort($line);
            $max = max(array_keys($line));
            $filled = [];
            for ($i=0; $i<=$max; $i++) $filled[] = $line[$i] ?? '';
            $rows[] = $filled;
        }
    }
    return $rows;
}

function salary_import_find_columns(array $headers): array
{
    $nameKeys = ['adsoyad','adisoyadi','adsoyadi','personel','personeladi','isim','ad','calisan','calisanadi','personelisim'];
    $salaryKeys = ['maas','netmaas','ucret','aylikmaas','maastutari','maastutar','netucret','aylikucret'];
    $deptKeys = ['bolum','departman','birim'];
    $posKeys = ['gorev','pozisyon','unvan'];
    $phoneKeys = ['telefon','tel','gsm'];

    $cols = ['name'=>-1,'salary'=>-1,'department'=>-1,'position'=>-1,'phone'=>-1];
    foreach ($headers as $i => $h) {
        $n = salary_import_norm((string)$h);
        if ($cols['name'] < 0 && in_array($n, $nameKeys, true)) $cols['name'] = $i;
        if ($cols['salary'] < 0 && in_array($n, $salaryKeys, true)) $cols['salary'] = $i;
        if ($cols['department'] < 0 && in_array($n, $deptKeys, true)) $cols['department'] = $i;
        if ($cols['position'] < 0 && in_array($n, $posKeys, true)) $cols['position'] = $i;
        if ($cols['phone'] < 0 && in_array($n, $phoneKeys, true)) $cols['phone'] = $i;
    }
    return $cols;
}

function salary_import_upsert_employee(array $row, array $cols): string
{
    $name = salary_import_clean($row[$cols['name']] ?? '');
    $salary = salary_import_money($row[$cols['salary']] ?? 0);
    $department = $cols['department'] >= 0 ? salary_import_clean($row[$cols['department']] ?? '') : '';
    $position = $cols['position'] >= 0 ? salary_import_clean($row[$cols['position']] ?? '') : '';
    $phone = $cols['phone'] >= 0 ? salary_import_clean($row[$cols['phone']] ?? '') : '';

    if ($name === '' || $salary <= 0) return 'skip';
    $stmt = db()->prepare('SELECT * FROM salary_employees WHERE lower(full_name)=lower(?) LIMIT 1');
    $stmt->execute([$name]);
    $old = $stmt->fetch();
    if ($old) {
        db()->prepare('UPDATE salary_employees SET base_salary=?, department=CASE WHEN ?<>\'\' THEN ? ELSE department END, position=CASE WHEN ?<>\'\' THEN ? ELSE position END, phone=CASE WHEN ?<>\'\' THEN ? ELSE phone END, is_active=1, updated_at=? WHERE id=?')
            ->execute([$salary, $department, $department, $position, $position, $phone, $phone, now(), (int)$old['id']]);
        audit_action('maas_personel', (int)$old['id'], 'guncellendi', $old, ['full_name'=>$name,'base_salary'=>$salary,'department'=>$department,'position'=>$position,'phone'=>$phone], 'Excel aktarım');
        return 'update';
    }
    db()->prepare('INSERT INTO salary_employees (full_name, department, position, phone, base_salary, is_active, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)')
        ->execute([$name, $department, $position, $phone, $salary, 'Excel aktarımı', current_user()['id'] ?? null, now(), now()]);
    $newId = (int)db()->lastInsertId();
    audit_action('maas_personel', $newId, 'eklendi', null, ['full_name'=>$name,'base_salary'=>$salary,'department'=>$department,'position'=>$position,'phone'=>$phone], 'Excel aktarım');
    return 'insert';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('maaslar.php');
require_csrf();
salary_import_db_ensure();

if (empty($_FILES['salary_excel']) || ($_FILES['salary_excel']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash('error', 'Excel dosyası yüklenemedi.');
    redirect('maaslar.php');
}

$file = $_FILES['salary_excel'];
if ((int)$file['size'] > 8 * 1024 * 1024) {
    flash('error', 'Dosya çok büyük. En fazla 8 MB yükleyin.');
    redirect('maaslar.php');
}

$ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
try {
    if ($ext === 'xlsx') {
        $rows = salary_import_xlsx($file['tmp_name']);
    } elseif (in_array($ext, ['csv','txt'], true)) {
        $rows = salary_import_csv($file['tmp_name']);
    } else {
        throw new RuntimeException('Sadece .xlsx veya .csv dosyası yükleyin.');
    }

    $rows = array_values(array_filter($rows, fn($r) => count(array_filter($r, fn($v) => trim((string)$v) !== '')) > 0));
    if (count($rows) < 2) throw new RuntimeException('Dosyada aktarılacak satır bulunamadı.');

    $cols = salary_import_find_columns($rows[0]);
    if ($cols['name'] < 0 || $cols['salary'] < 0) {
        throw new RuntimeException('Başlıklar bulunamadı. Excel’de en az "Ad Soyad" ve "Maaş" sütunları olmalı.');
    }

    $insert = $update = $skip = 0;
    for ($i=1; $i<count($rows); $i++) {
        $result = salary_import_upsert_employee($rows[$i], $cols);
        if ($result === 'insert') $insert++;
        elseif ($result === 'update') $update++;
        else $skip++;
    }
    log_action('Maaş Excel aktarımı', "Eklenen: $insert, Güncellenen: $update, Atlanan: $skip");
    flash('success', "Excel aktarımı tamamlandı. Eklenen: $insert, güncellenen: $update, atlanan: $skip.");
} catch (Throwable $e) {
    flash('error', 'Excel aktarımı yapılamadı: ' . $e->getMessage());
}

redirect('maaslar.php');
