<?php

function fatura_no_dosya_adindan(?string $fileName): string
{
    $fileName = strtoupper((string)$fileName);
    $fileName = preg_replace('/\.(PDF|XML|XSLT?)$/i', '', $fileName) ?: $fileName;

    // DMN2026000000218 gibi harfle başlayıp uzun rakam dizisiyle devam eden gerçek belge numaraları.
    if (preg_match('/\b([A-Z]{2,8}[0-9]{10,30})\b/', $fileName, $m)) {
        return $m[1];
    }

    return '';
}

function fatura_no_vergi_no_gibi(?string $value): bool
{
    $value = preg_replace('/\s+/', '', trim((string)$value)) ?: '';
    return (bool)preg_match('/^\d{10,11}$/', $value);
}

function fatura_no_onar_post_verisi(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($script === 'fatura-toplu-yukle.php') {
        $items = json_decode((string)($_POST['items_json'] ?? '[]'), true);
        if (!is_array($items)) return;

        $names = $_FILES['documents']['name'] ?? [];
        if (!is_array($names)) return;

        foreach ($items as $index => &$item) {
            if (!is_array($item)) continue;
            $current = trim((string)($item['invoice_no'] ?? ''));
            $fromFile = fatura_no_dosya_adindan((string)($names[$index] ?? ''));
            if ($fromFile !== '' && ($current === '' || fatura_no_vergi_no_gibi($current))) {
                $item['invoice_no'] = $fromFile;
            }
        }
        unset($item);

        $_POST['items_json'] = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if ($script === 'faturalar.php') {
        $current = trim((string)($_POST['invoice_no'] ?? ''));
        $fileName = (string)($_FILES['document']['name'] ?? '');
        $fromFile = fatura_no_dosya_adindan($fileName);
        if ($fromFile !== '' && ($current === '' || fatura_no_vergi_no_gibi($current))) {
            $_POST['invoice_no'] = $fromFile;
        }
    }
}

function fatura_no_onar_mevcut_kayitlar(): void
{
    $tableExists = (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='invoices'")->fetchColumn() > 0;
    if (!$tableExists) return;

    $rows = db()->query("SELECT id, invoice_no, document_name FROM invoices
        WHERE COALESCE(is_cancelled,0)=0
          AND COALESCE(document_name,'')!=''
          AND (COALESCE(invoice_no,'')='' OR LENGTH(TRIM(invoice_no)) IN (10,11))
        ORDER BY id ASC")->fetchAll();

    if (!$rows) return;

    $stmt = db()->prepare('UPDATE invoices SET invoice_no=?, updated_at=? WHERE id=?');
    foreach ($rows as $row) {
        $current = trim((string)($row['invoice_no'] ?? ''));
        if ($current !== '' && !fatura_no_vergi_no_gibi($current)) continue;
        $fromFile = fatura_no_dosya_adindan((string)($row['document_name'] ?? ''));
        if ($fromFile === '') continue;
        $stmt->execute([$fromFile, now(), (int)$row['id']]);
    }
}

fatura_no_onar_post_verisi();

if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'faturalar.php') {
    try {
        fatura_no_onar_mevcut_kayitlar();
    } catch (Throwable $e) {
        // Fatura ekranı onarım hatası yüzünden açılmaz hâle gelmesin.
    }
}
