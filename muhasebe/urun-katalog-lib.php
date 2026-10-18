<?php
// Shared product identity; customer documents never set the live base price.
function urun_norm(string $value): string
{
    $value = strtr(trim($value), ['İ'=>'i', 'I'=>'ı']);
    return mb_strtolower(preg_replace('/\s+/u', ' ', $value), 'UTF-8');
}

function urun_article(array $product): string
{
    return teklif_article_from_text((string)($product['article'] ?? ''))
        ?: teklif_article_from_text((string)($product['name'] ?? ''))
        ?: teklif_article_from_text((string)($product['barcode'] ?? ''))
        ?: teklif_article_from_text((string)($product['product_type'] ?? ''));
}

function urun_sort(array $products): array
{
    usort($products, function ($a, $b) {
        $aa = urun_article($a); $bb = urun_article($b);
        if (($aa === '') !== ($bb === '')) return $aa === '' ? 1 : -1;
        return strnatcasecmp($aa, $bb)
            ?: strnatcasecmp(urun_norm($a['name']), urun_norm($b['name']))
            ?: strcmp((string)($a['barcode'] ?? ''), (string)($b['barcode'] ?? ''))
            ?: ((int)$a['id'] <=> (int)$b['id']);
    });
    return $products;
}

// Return only an unambiguous identity. A conflicting explicit barcode must never
// inherit a similarly named product's customer history.
function urun_match(array $products, string $name, string $type = '', string $barcode = ''): ?array
{
    $barcode = trim($barcode);
    $article = urun_article(['name'=>$name, 'barcode'=>$barcode, 'product_type'=>$type]);
    $normalized = teklif_normalize_barcode($barcode, $name, $type);
    $generated = $article !== '' && $normalized === teklif_barcode_from_article($article);
    foreach (['barcode', 'article', 'name'] as $key) {
        $matches = [];
        foreach ($products as $p) {
            if ($key === 'barcode') $ok = $normalized !== '' && trim((string)$p['barcode']) === $normalized;
            elseif ($key === 'article') $ok = $article !== '' && urun_article($p) === $article;
            else $ok = $name !== '' && urun_norm($p['name']) === urun_norm($name)
                && ($type === '' || trim((string)$p['product_type']) === '' || urun_norm($p['product_type']) === urun_norm($type));
            if ($ok) $matches[] = $p;
        }
        if (count($matches) === 1) return $matches[0];
        if (count($matches) > 1) return null;
        if ($key === 'barcode' && $barcode !== '' && !$generated) return null;
    }
    return null;
}

function urun_audit(int $id, string $action, $before, $after): void
{
    $u = current_user();
    db()->prepare('INSERT INTO audit_logs(user_id,username,entity_type,entity_id,action,old_value,new_value,detail,ip,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([$u['id'] ?? null, $u['username'] ?? '', 'offer_product', $id, $action,
            json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'Tanımlı Ürün Listesi', client_ip(), now()]);
}

// Teklif / Depo Çıkış kaydı yapılırken ürün kartı senkronu yardımcı bir işlemdir.
// Eski bir belge satırı yeni katalog kimliğiyle çakışıyorsa finansal/ticari belgeyi
// bloke etme; yalnız katalog senkronunu atla. Manuel ürün tanımında koruma devam eder.
function urun_document_sync_context(): bool
{
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8) as $frame) {
        if (($frame['function'] ?? '') === 'teklif_save_product_suggestion') return true;
    }
    return false;
}

function urun_define(string $name, string $type = '', string $barcode = ''): array
{
    $name = trim($name); $type = trim($type); $barcode = trim($barcode);
    if ($name === '') $name = teklif_article_from_text($barcode) ?: $barcode;
    if ($name === '' || mb_strlen($name) > 250 || mb_strlen($type) > 250 || strlen($barcode) > 80) throw new RuntimeException('Geçerli ürün bilgisi gerekli.');
    $pdo = db(); $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        // Acquire the SQLite write lock before identity lookup, including concurrent requests.
        $pdo->exec('UPDATE offer_products SET id=id WHERE id=-1');
        $products = $pdo->query('SELECT * FROM offer_products ORDER BY id')->fetchAll();
        $found = urun_match($products, $name, $type, $barcode);
        if ($found) {
            if (!(int)$found['is_active']) throw new RuntimeException('Ürün pasif. Ürün kaydını kontrol edin.');
            if ($owns) $pdo->commit();
            return $found;
        }
        // Refuse ambiguous identifiers instead of manufacturing another duplicate.
        $article = urun_article(['name'=>$name,'barcode'=>$barcode,'product_type'=>$type]);
        $barcode = teklif_normalize_barcode($barcode, $name, $type);
        foreach ($products as $p) {
            if (($barcode !== '' && $barcode === (string)$p['barcode']) || ($article !== '' && $article === urun_article($p)) || urun_norm($name) === urun_norm($p['name'])) {
                if (urun_document_sync_context()) {
                    if (function_exists('log_action')) {
                        log_action('Tanımlı ürün eşleştirmesi atlandı', $name . ($barcode !== '' ? ' / ' . $barcode : ''));
                    }
                    if ($owns) $pdo->commit();
                    // teklif_save_product_suggestion yalnız id alanını kullanır; 0 no-op'tur.
                    return ['id'=>0, 'name'=>$name, 'barcode'=>$barcode, 'product_type'=>$type, 'is_active'=>1];
                }
                throw new RuntimeException('Ürün bilgileri birden fazla veya çelişen kayıtla eşleşiyor. Tanımlı Ürün Listesi’ni kontrol edin.');
            }
        }
        $pdo->prepare('INSERT INTO offer_products(name,barcode,product_type,default_unit_price,is_active,created_at,updated_at) VALUES(?,?,?,0,1,?,?)')->execute([$name,$barcode,$type,now(),now()]);
        $id = (int)$pdo->lastInsertId();
        $s = $pdo->prepare('SELECT * FROM offer_products WHERE id=?'); $s->execute([$id]); $found = $s->fetch();
        urun_audit($id, 'eklendi', null, $found);
        if ($owns) $pdo->commit();
        return $found;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function urun_base_price_update(int $id, string $raw, string $expected): void
{
    if (!preg_match('/^(?:[0-9]+(?:[.,][0-9]{1,2})?|[0-9]{1,3}(?:\.[0-9]{3})+,[0-9]{1,2})$/D', $raw)) throw new RuntimeException('Geçerli yeni fiyat girin (ör. 480,50).');
    $price = round(teklif_decimal($raw), 2);
    if (!is_finite($price) || $price <= 0 || $price > 100000000) throw new RuntimeException('Fiyat sıfırdan büyük olmalı.');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE offer_products SET id=id WHERE id=-1');
        $s = $pdo->prepare('SELECT * FROM offer_products WHERE id=? AND is_active=1'); $s->execute([$id]); $old = $s->fetch();
        if (!$old) throw new RuntimeException('Ürün bulunamadı.');
        if ((string)$old['list_unit_price'] !== $expected) throw new RuntimeException('Fiyat başka bir işlemde değişti. Sayfayı yenileyip tekrar kontrol edin.');
        $pdo->prepare('UPDATE offer_products SET list_unit_price=?,updated_at=? WHERE id=?')->execute([$price,now(),$id]);
        urun_audit($id, 'guncellendi', ['list_unit_price'=>$old['list_unit_price']], ['list_unit_price'=>$price]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function urun_deactivate(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('UPDATE offer_products SET id=id WHERE id=-1');
        $s = $pdo->prepare('SELECT * FROM offer_products WHERE id=? AND is_active=1');
        $s->execute([$id]);
        $old = $s->fetch();
        if (!$old) throw new RuntimeException('Ürün bulunamadı veya zaten pasif.');
        $pdo->prepare('UPDATE offer_products SET is_active=0, updated_at=? WHERE id=?')->execute([now(), $id]);
        $after = $old;
        $after['is_active'] = 0;
        urun_audit($id, 'pasif_yapildi', $old, $after);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
