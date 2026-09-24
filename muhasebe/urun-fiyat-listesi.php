<?php
require_once __DIR__.'/layout.php';
require_once __DIR__.'/teklif-db.php';
require_login();
if (!can_write() && !can_access_warehouse_dispatch()) redirect('dashboard.php');
header('Cache-Control: private, no-store');
teklif_db_ensure();
$canEdit = can_write();
$error = '';
$edit = ['id'=>0, 'name'=>'', 'barcode'=>'', 'product_type'=>'', 'list_unit_price'=>null];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_write();
    require_csrf();
    $edit = ['id'=>(int)($_POST['id'] ?? 0), 'name'=>trim((string)($_POST['name'] ?? '')),
        'barcode'=>trim((string)($_POST['barcode'] ?? '')), 'product_type'=>trim((string)($_POST['product_type'] ?? '')),
        'list_unit_price'=>trim((string)($_POST['list_unit_price'] ?? ''))];
    try {
        if ($edit['name'] === '') throw new RuntimeException('Ürün adı gerekli.');
        $rawPrice = $edit['list_unit_price'];
        if ($rawPrice !== '' && !preg_match('/^[0-9]+(?:[.,][0-9]+)*$/', $rawPrice)) {
            throw new RuntimeException('Fiyatı sayı olarak yazın. Örn: 444 veya 444,50.');
        }
        $price = $rawPrice === '' ? null : round(teklif_decimal($rawPrice), 2);
        if ($price !== null && (!is_finite($price) || $price <= 0)) throw new RuntimeException('Liste fiyatı sıfırdan büyük olmalı. Tanımı kaldırmak için boş bırakın.');
        $barcode = teklif_normalize_barcode($edit['barcode'], $edit['name'], $edit['product_type']);
        $pdo = db();
        if ($edit['id'] > 0) {
            $stmt = $pdo->prepare('SELECT id FROM offer_products WHERE id=? AND is_active=1');
            $stmt->execute([$edit['id']]);
            if (!$stmt->fetchColumn()) throw new RuntimeException('Ürün bulunamadı.');
            $pdo->prepare('UPDATE offer_products SET name=?,barcode=?,product_type=?,list_unit_price=?,updated_at=? WHERE id=?')
                ->execute([$edit['name'],$barcode,$edit['product_type'],$price,now(),$edit['id']]);
        } else {
            $pdo->prepare('INSERT INTO offer_products(name,barcode,product_type,list_unit_price,default_unit_price,is_active,created_at,updated_at) VALUES(?,?,?,?,0,1,?,?)')
                ->execute([$edit['name'],$barcode,$edit['product_type'],$price,now(),now()]);
        }
        flash('success', 'Ürün fiyat listesi kaydedildi. Açık teklif veya depo çıkış ekranını yenileyerek yeni fiyatı kullanabilirsiniz.');
        redirect('urun-fiyat-listesi.php');
    } catch (Throwable $e) {
        $error = $e instanceof PDOException ? 'Bu ürün adı zaten kayıtlı olabilir. Listedeki ürünü düzenleyin.' : $e->getMessage();
    }
} elseif ((int)($_GET['edit'] ?? 0) > 0 && $canEdit) {
    $stmt = db()->prepare('SELECT * FROM offer_products WHERE id=? AND is_active=1');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: $edit;
}
$products = teklif_products_for_select();
page_header('Ürün Fiyat Listesi');
?>
<style>
.price-list{display:grid!important;gap:18px;max-width:1200px;margin:auto}.price-list .price-card{padding:22px;background:#fff;border:1px solid #e5dccf;border-radius:18px}.price-list h2,.price-list h3{margin-top:0}.price-list .price-links{display:flex;gap:12px;flex-wrap:wrap}.price-list .price-fields{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:12px}.price-list label{display:grid;align-content:start;gap:6px;font-weight:700}.price-list input{width:100%;padding:10px;border:1px solid #ddd2c1;border-radius:8px}.price-list button,.price-list .price-button{display:inline-block;background:#16482e;color:#fff;padding:10px 16px;border:0;border-radius:8px;text-decoration:none;cursor:pointer}.price-list table{width:100%;border-collapse:collapse}.price-list td,.price-list th{text-align:left;padding:12px;border-bottom:1px solid #e5dccf}.price-list .muted{color:#776f64}.price-list .price-scroll{overflow:auto}.price-list .price-error{color:#a52d2d}.price-list .price-actions{display:flex;gap:12px;align-items:center;margin-top:14px}@media(max-width:800px){.price-list .price-fields{grid-template-columns:1fr 1fr}}@media(max-width:500px){.price-list .price-fields{grid-template-columns:1fr}.price-list .price-card{padding:14px}}
</style>
<div class="price-list">
<section class="price-card">
<h2>Ürün Fiyat Listesi</h2>
<p>Teklif Ver ve Depo Çıkış için ortak TL fiyatları. Müşterinin o üründe kayıtlı fiyatı varsa önce o kullanılır; yoksa liste fiyatı gelir. Fişte fiyatı değiştirebilirsiniz.</p>
<div class="price-links"><a href="teklif-ver.php">Teklif Ver</a><a href="depo-cikis.php">Depo Çıkış</a></div>
</section>
<?php if ($canEdit): ?>
<section class="price-card">
<h3><?php echo $edit['id'] ? 'Ürünü ve liste fiyatını düzenle' : 'Listeye ürün ekle'; ?></h3>
<?php if ($error !== ''): ?><p class="price-error" role="alert"><?php echo e($error); ?></p><?php endif; ?>
<form method="post">
<?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>">
<div class="price-fields">
<label>Ürün adı<input name="name" required value="<?php echo e($edit['name']); ?>" placeholder="Örn: 6000 Modal Çorap"></label>
<label>Barkod<input name="barcode" value="<?php echo e($edit['barcode'] ?? ''); ?>"></label>
<label>Ürün cinsi / açıklama<input name="product_type" value="<?php echo e($edit['product_type'] ?? ''); ?>"></label>
<label>Liste birim fiyatı (TL)<input name="list_unit_price" inputmode="decimal" value="<?php echo e($edit['list_unit_price'] === null ? '' : str_replace('.', ',', (string)$edit['list_unit_price'])); ?>" placeholder="Fiyat tanımlanmamış"></label>
</div>
<div class="price-actions"><button type="submit">Kaydet</button><?php if ($edit['id']): ?><a href="urun-fiyat-listesi.php">Vazgeç / Yeni ürün</a><?php endif; ?></div>
</form>
</section>
<?php endif; ?>
<section class="price-card">
<label>Ürün ara<input id="priceSearch" type="search" placeholder="Ürün adı veya barkod"></label>
<div class="price-scroll"><table><thead><tr><th>Ürün</th><th>Barkod</th><th>Liste birim fiyatı (TL)</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead><tbody id="priceRows">
<?php foreach ($products as $product): ?>
<tr><td><strong><?php echo e($product['name']); ?></strong><br><small class="muted"><?php echo e($product['product_type'] ?? ''); ?></small></td><td><?php echo e($product['barcode'] ?? ''); ?></td><td><?php echo $product['list_unit_price'] === null ? '<span class="muted">Tanımlanmamış</span>' : e(teklif_money((float)$product['list_unit_price']).' TL'); ?></td><?php if ($canEdit): ?><td><a class="price-button" href="urun-fiyat-listesi.php?edit=<?php echo (int)$product['id']; ?>">Düzenle</a></td><?php endif; ?></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if (!$products): ?><p>Henüz ürün eklenmemiş.</p><?php endif; ?>
</section>
</div>
<script>
document.getElementById('priceSearch').addEventListener('input',function(){
  var q=this.value.trim().toLocaleLowerCase('tr-TR');
  document.querySelectorAll('#priceRows tr').forEach(function(row){row.hidden=!row.textContent.toLocaleLowerCase('tr-TR').includes(q);});
});
</script>
<?php page_footer(); ?>
