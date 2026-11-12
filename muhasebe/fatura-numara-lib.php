<?php

// Giden e-Fatura numarası, red veya iptal sonrasında da kullanılmış sayılır.
function giden_fatura_numara_anahtari(string $invoiceNo): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($invoiceNo))) ?: '';
}

function giden_fatura_numara_kayit_id(string $invoiceNo, int $excludeId = 0): int
{
    $key = giden_fatura_numara_anahtari($invoiceNo);
    if ($key === '') return 0;

    $stmt = db()->prepare("SELECT id, invoice_no FROM invoices WHERE direction='giden' AND id<>? AND invoice_no IS NOT NULL");
    $stmt->execute([$excludeId]);
    foreach ($stmt->fetchAll() as $row) {
        if (giden_fatura_numara_anahtari((string)$row['invoice_no']) === $key) {
            return (int)$row['id'];
        }
    }
    return 0;
}
