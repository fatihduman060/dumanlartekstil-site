<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/magaza-kullanici.php';
require_once __DIR__ . '/barkod-satis-lib.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!can_manage_store_sales()) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'products'=>[],'error'=>'Barkodlu satış yetkisi gerekiyor.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pos_word_search_normalize($value): string
{
    $value = strtr((string)$value, [
        'Ç'=>'c','Ğ'=>'g','İ'=>'i','I'=>'i','Ö'=>'o','Ş'=>'s','Ü'=>'u',
        'ç'=>'c','ğ'=>'g','ı'=>'i','i'=>'i','ö'=>'o','ş'=>'s','ü'=>'u',
        'Â'=>'a','Î'=>'i','Û'=>'u','â'=>'a','î'=>'i','û'=>'u',
    ]);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?: $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
}

try {
    pos_db_ensure();
    $queryRaw = trim((string)($_GET['q'] ?? ''));
    $query = pos_word_search_normalize($queryRaw);
    if ($query === '') {
        echo json_encode(['ok'=>true,'products'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $rows = db()->query("SELECT * FROM pos_products WHERE is_active=1 AND COALESCE(product_source,'pos')='pos' ORDER BY name ASC, COALESCE(variant_name,'') ASC LIMIT 1500")->fetchAll() ?: [];
    $rows = pos_products_with_barcodes($rows);
    $tokens = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $matches = [];

    foreach ($rows as $product) {
        $barcodes = $product['barcodes'] ?? [(string)($product['barcode'] ?? '')];
        $haystack = pos_word_search_normalize(
            (string)($product['name'] ?? '') . ' '
            . (string)($product['variant_name'] ?? '') . ' '
            . implode(' ', $barcodes)
        );
        $compact = str_replace(' ', '', $haystack);
        $ok = true;
        foreach ($tokens as $token) {
            $compactToken = str_replace(' ', '', $token);
            if (strpos($haystack, $token) === false && strpos($compact, $compactToken) === false) {
                $ok = false;
                break;
            }
        }
        if (!$ok) continue;

        $matchedBarcode = (string)($product['barcode'] ?? '');
        $rank = 4;
        foreach ($barcodes as $barcode) {
            $barcodeText = (string)$barcode;
            if ($barcodeText === $queryRaw) {
                $matchedBarcode = $barcodeText;
                $rank = 0;
                break;
            }
            if ($queryRaw !== '' && strpos($barcodeText, $queryRaw) !== false) {
                $matchedBarcode = $barcodeText;
                $rank = min($rank, 1);
            }
        }
        $nameNormalized = pos_word_search_normalize((string)($product['name'] ?? '') . ' ' . (string)($product['variant_name'] ?? ''));
        if ($rank > 1 && strpos($nameNormalized, $query) === 0) $rank = 2;
        elseif ($rank > 2 && strpos($nameNormalized, $query) !== false) $rank = 3;

        $product['matched_barcode'] = $matchedBarcode;
        $product['_search_rank'] = $rank;
        $matches[] = $product;
    }

    usort($matches, static function ($a, $b) {
        $rank = ((int)($a['_search_rank'] ?? 9)) <=> ((int)($b['_search_rank'] ?? 9));
        if ($rank !== 0) return $rank;
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    $matches = array_slice($matches, 0, 50);
    foreach ($matches as &$match) unset($match['_search_rank']);
    unset($match);

    echo json_encode(['ok'=>true,'products'=>$matches], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'products'=>[],'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
