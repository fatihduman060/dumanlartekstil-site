<?php
require_once __DIR__.'/layout.php';
require_once __DIR__.'/fiyat-listeleri-lib.php';
require_login();
if (!can_write() && !can_access_warehouse_dispatch()) redirect('dashboard.php');
header('Cache-Control: private, no-store');
fiyat_listeleri_ensure();
$canEdit = can_write();
$error = '';
$edit = ['id'=>0, 'name'=>'', 'barcode'=>'', 'product_type'=>'', 'list_unit_price'=>null];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_write();
    require_csrf();
    try {
        if (($_POST['action'] ?? '') === 'price') {
            urun_base_price_update((int)($_POST['id'] ?? 0), trim((string)($_POST['new_price'] ?? '')), (string)($_POST['expected_price'] ?? ''));
        } elseif (($_POST['action'] ?? '') === 'delete') {
            urun_deactivate((int)($_POST['id'] ?? 0));
        } else {
            urun_define(trim((string)($_POST['name'] ?? '')), '', trim((string)($_POST['barcode'] ?? '')));
        }
        flash('success', ($_POST['action'] ?? '') === 'delete' ? 'Ürün pasif yapıldı; yeni Teklif ve Depo Çıkış satırlarında görünmeyecek.' : 'Tanımlı ürün kaydedildi. Ana fiyat değişikliği müşteri geçmiş fiyatlarını değiştirmez.');
        redirect('urun-fiyat-listesi.php');
    } catch (Throwable $e) {
        $error = $e instanceof PDOException ? 'İşlem kaydedilemedi. Lütfen tekrar deneyin.' : $e->getMessage();
    }
}
$products = teklif_products_for_select();
page_header('Tanımlı Ürün Listesi', 'fiyat_listeleri');
?>
<style>
.price-list{display:grid!important;gap:18px;min-width:0;width:100%;max-width:1200px;margin:auto}.price-list .price-card{min-width:0;padding:22px;background:#fff;border:1px solid #e5dccf;border-radius:18px}.price-list h2,.price-list h3{margin-top:0}.price-list .price-links{display:flex;gap:12px;flex-wrap:wrap}.price-list .price-fields{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:12px}.price-list label{display:grid;align-content:start;gap:6px;font-weight:700}.price-list input{width:100%;padding:10px;border:1px solid #ddd2c1;border-radius:8px}.price-list button,.price-list .price-button{display:inline-block;background:#16482e;color:#fff;padding:10px 16px;border:0;border-radius:8px;text-decoration:none;cursor:pointer}.price-list table{width:100%;border-collapse:collapse}.price-list td,.price-list th{text-align:left;padding:12px;border-bottom:1px solid #e5dccf}.price-list .muted{color:#776f64}.price-list .price-scroll{overflow:auto}.price-list .price-error{color:#a52d2d}.price-list .price-actions{display:flex;gap:12px;align-items:center;margin-top:14px}@media(max-width:800px){.price-list .price-fields{grid-template-columns:1fr 1fr}}@media(max-width:500px){.price-list .price-fields{grid-template-columns:1fr}.price-list .price-card{min-width:0;padding:14px}}
</style>
<div class="price-list">
<section class="price-card">
<h2>Tanımlı Ürün Listesi</h2>
<p>Eski Fiyat, ürünün güncel ana fiyatıdır (TL, KDV hariç). Müşterinin aynı üründe geçmiş fiyatı varsa önce o kullanılır; yoksa ana fiyat gelir. İkisi de yoksa fiyat boş kalır.</p>
<p>Yeni Fiyat alanına tutarı yazıp Güncelle’ye basın. Arşiv dosyaları ana fiyatları değiştirmez.</p>
<div class="price-links"><strong>Tanımlı Ürün Listesi</strong><a href="fiyat-listeleri.php">Fiyat Listeleri Arşivi</a><a href="teklif-ver.php">Teklif Ver</a><a href="depo-cikis.php">Depo Çıkış</a></div>
</section>
<?php if ($canEdit): ?>
<section class="price-card">
<h3>Ürün tanımla</h3><p>Teklif Ver ve Depo Çıkış’ta kullanılan yeni ürünler buraya otomatik eklenir.</p>
<?php if ($error !== ''): ?><p class="price-error" role="alert"><?php echo e($error); ?></p><?php endif; ?>
<form method="post">
<?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>">
<div class="price-fields">
<label>Ürün adı<input name="name" required value="<?php echo e($edit['name']); ?>" placeholder="Örn: 6000 Modal Çorap"></label>
<label>Barkod<input name="barcode" value="<?php echo e($edit['barcode'] ?? ''); ?>"></label>
<p class="muted">Ürün açıklamaları yalnızca Teklif veya Depo Çıkış satırında saklanır; ürün kartına aktarılmaz.</p>

</div>
<div class="price-actions"><button type="submit">Kaydet</button><?php if ($edit['id']): ?><a href="urun-fiyat-listesi.php">Vazgeç / Yeni ürün</a><?php endif; ?></div>
</form>
</section>
<?php endif; ?>
<section class="price-card">
<label>Ürün ara<input id="priceSearch" type="search" placeholder="Ürün adı veya barkod"></label>
<div class="price-scroll"><table><thead><tr><th>Artikel</th><th>Barkod</th><th>Ürün Adı</th><th>Eski Fiyat (TL)</th><th>Yeni Fiyat (TL)</th><th>İşlem</th></tr></thead><tbody id="priceRows">
<?php foreach ($products as $product): $formId = 'price-'.(int)$product['id']; ?>
<tr>
<td><?php echo e(urun_article($product)); ?></td>
<td><?php echo e($product['barcode'] ?? ''); ?></td>
<td><strong><?php echo e($product['name']); ?></strong></td>
<td><?php echo $product['list_unit_price'] === null ? '<span class="muted">Tanımlanmamış</span>' : e(teklif_money((float)$product['list_unit_price']).' TL'); ?></td>
<td><?php if ($canEdit): ?><input form="<?php echo $formId; ?>" name="new_price" inputmode="decimal" required aria-label="<?php echo e($product['name'].' yeni fiyat'); ?>" placeholder="Yeni fiyat" style="min-width:110px"><?php else: ?>—<?php endif; ?></td>
<td><?php if ($canEdit): ?><div class="price-actions"><form id="<?php echo $formId; ?>" method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="price"><input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>"><input type="hidden" name="expected_price" value="<?php echo e((string)$product['list_unit_price']); ?>"><button type="submit">Güncelle</button></form><form method="post" onsubmit="return confirm('Bu ürün pasif yapılsın mı? Eski teklif ve depo kayıtları korunur; yeni satırlarda görünmez.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>"><button type="submit" aria-label="<?php echo e($product['name']); ?> ürününü pasif yap">×</button></form></div><?php else: ?>—<?php endif; ?></td>
</tr>
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
