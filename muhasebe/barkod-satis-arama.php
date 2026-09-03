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

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!verify_csrf($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Oturum doğrulaması yenilenmeli. Sayfayı yenileyip tekrar deneyin.');
        }
        if (trim((string)($_POST['action'] ?? '')) !== 'muhtelif') {
            throw new RuntimeException('Geçersiz işlem.');
        }

        $amount = round(decimal_from_input($_POST['amount'] ?? '0'), 2);
        if ($amount <= 0) throw new RuntimeException('Muhtelif satış tutarı sıfırdan büyük olmalı.');
        if ($amount > 1000000) throw new RuntimeException('Muhtelif satış tutarı çok yüksek. Tutarı kontrol edin.');

        $cents = (int)round($amount * 100);
        $barcode = 'MUHTELIF-' . $cents;
        $variant = number_format($amount, 2, ',', '.') . ' TL';
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id FROM pos_products WHERE barcode=? LIMIT 1");
        $stmt->execute([$barcode]);
        $productId = (int)($stmt->fetchColumn() ?: 0);
        $now = now();
        $userId = current_user()['id'] ?? null;

        if ($productId > 0) {
            $pdo->prepare("UPDATE pos_products SET name='Muhtelif Satış',variant_name=?,sale_price=?,vat_rate=10,stock_quantity=0,track_stock=0,is_active=1,product_source='pos',updated_at=? WHERE id=?")
                ->execute([$variant,$amount,$now,$productId]);
        } else {
            $pdo->prepare("INSERT INTO pos_products (barcode,name,variant_name,unit,sale_price,vat_rate,stock_quantity,track_stock,is_active,product_source,created_by,created_at,updated_at) VALUES (?,'Muhtelif Satış',?,'Adet',?,10,0,0,1,'pos',?,?,?)")
                ->execute([$barcode,$variant,$amount,$userId,$now,$now]);
            $productId = (int)$pdo->lastInsertId();
        }

        audit_action('pos_product', $productId, 'muhtelif_satis_hazirlandi', null, [
            'barcode'=>$barcode,
            'name'=>'Muhtelif Satış',
            'amount'=>$amount,
            'track_stock'=>0,
        ], 'Muhtelif Satış');

        echo json_encode([
            'ok'=>true,
            'barcode'=>$barcode,
            'amount'=>$amount,
            'message'=>'Muhtelif satış sepete eklenmeye hazır.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $queryRaw = trim((string)($_GET['q'] ?? ''));
    $query = pos_word_search_normalize($queryRaw);
    if ($query === '') {
        echo json_encode(['ok'=>true,'products'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $rows = db()->query("SELECT * FROM pos_products WHERE is_active=1 AND COALESCE(product_source,'pos')='pos' AND barcode NOT LIKE 'MUHTELIF-%' ORDER BY name ASC, COALESCE(variant_name,'') ASC LIMIT 1500")->fetchAll() ?: [];
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
        foreach ($barcodes as $barcodeItem) {
            $barcodeText = (string)$barcodeItem;
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
    http_response_code(422);
    echo json_encode(['ok'=>false,'products'=>[],'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
