<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/stok-lib.php';
require_login();
stok_db_ensure();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_write();
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_product') {
        $id = (int)($_POST['id'] ?? 0);
        $article = trim((string)($_POST['article_code'] ?? ''));
        $name = trim((string)($_POST['product_name'] ?? ''));
        $info = trim((string)($_POST['product_info'] ?? ''));
        if ($article === '' || $name === '') {
            flash('error', 'Artikel kodu ve ürün adı zorunludur.');
            redirect('stok-takibi.php');
        }
        try {
            if ($id > 0) {
                $old = stok_urun($id);
                db()->prepare('UPDATE stock_products SET article_code=?, product_name=?, product_info=?, is_active=?, updated_at=? WHERE id=?')
                    ->execute([$article, $name, $info ?: null, isset($_POST['is_active']) ? 1 : 0, now(), $id]);
                audit_action('stok_urun', $id, 'guncellendi', $old, stok_urun($id), $article);
                flash('success', 'Ürün kartı güncellendi.');
            } else {
                db()->prepare('INSERT INTO stock_products (article_code,product_name,product_info,unit,is_active,created_by,created_at,updated_at) VALUES (?,?,?,\'DZ\',1,?,?,?)')
                    ->execute([$article, $name, $info ?: null, current_user()['id'] ?? null, now(), now()]);
                $newId = (int)db()->lastInsertId();
                audit_action('stok_urun', $newId, 'eklendi', null, stok_urun($newId), $article);
                flash('success', 'Ürün kartı eklendi.');
            }
        } catch (Throwable $e) {
            flash('error', 'Ürün kaydedilemedi. Aynı artikel kodu daha önce eklenmiş olabilir.');
        }
        redirect('stok-takibi.php');
    }

    if ($action === 'stock_entry') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $direction = (string)($_POST['direction'] ?? 'in');
        $quantity = max(0, decimal_from_input($_POST['quantity_dozen'] ?? 0));
        $date = trim((string)($_POST['movement_date'] ?? date('Y-m-d')));
        $description = trim((string)($_POST['description'] ?? ''));
        if (!in_array($direction, ['in','out'], true)) $direction = 'in';
        $product = stok_urun($productId);
        if (!$product || $quantity <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash('error', 'Ürün, tarih ve düzine miktarını kontrol edin.');
            redirect('stok-takibi.php#stok-hareketi');
        }
        if ($direction === 'out' && $quantity > stok_bakiye($productId) + 0.004) {
            flash('error', 'Stok çıkışı mevcut miktarı aşamaz.');
            redirect('stok-takibi.php#stok-hareketi');
        }
        db()->prepare('INSERT INTO stock_movements (product_id,direction,quantity_dozen,movement_date,source_type,source_id,description,created_by,created_at,updated_at) VALUES (?,?,?,?,\'manual\',NULL,?,?,?,?)')
            ->execute([$productId, $direction, $quantity, $date, $description ?: ($direction === 'in' ? 'Manuel stok girişi' : 'Manuel stok düzeltme çıkışı'), current_user()['id'] ?? null, now(), now()]);
        $movementId = (int)db()->lastInsertId();
        audit_action('stok_hareketi', $movementId, 'eklendi', null, ['product_id'=>$productId,'direction'=>$direction,'quantity_dozen'=>$quantity,'date'=>$date,'description'=>$description], $product['article_code'] ?? '');
        flash('success', ($direction === 'in' ? 'Stok eklendi: ' : 'Stoktan düşüldü: ') . number_format($quantity, 2, ',', '.') . ' DZ');
        redirect('stok-takibi.php#stok-hareketi');
    }

    if ($action === 'cancel_movement') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM stock_movements WHERE id=? AND source_type='manual' AND is_cancelled=0 LIMIT 1");
        $stmt->execute([$id]);
        $movement = $stmt->fetch() ?: null;
        if ($movement) {
            db()->prepare('UPDATE stock_movements SET is_cancelled=1,cancelled_at=?,cancelled_by=?,cancel_reason=?,updated_at=? WHERE id=?')
                ->execute([now(), current_user()['id'] ?? null, 'Liste üzerinden iptal edildi', now(), $id]);
            audit_action('stok_hareketi', $id, 'iptal', $movement, ['is_cancelled'=>1], 'manuel stok');
            flash('success', 'Stok hareketi iptal edildi; kayıt geçmişte korundu.');
        }
        redirect('stok-takibi.php#stok-hareketi');
    }
}

$editProduct = !empty($_GET['edit']) ? stok_urun((int)$_GET['edit']) : null;
$products = stok_urunler(false);
$summary = ['products'=>count($products),'active'=>0,'total'=>0.0,'negative'=>0];
foreach ($products as $product) {
    if ((int)$product['is_active'] === 1) $summary['active']++;
    $summary['total'] += (float)$product['stock_dozen'];
    if ((float)$product['stock_dozen'] < -0.004) $summary['negative']++;
}
$movementRows = db()->query("SELECT sm.*, p.article_code, p.product_name, u.display_name AS user_name
    FROM stock_movements sm JOIN stock_products p ON p.id=sm.product_id
    LEFT JOIN users u ON u.id=sm.created_by
    ORDER BY sm.movement_date DESC, sm.id DESC LIMIT 100")->fetchAll() ?: [];

page_header('Stok Takibi', 'stok_takibi');
?>
<style>
.stock-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:16px}.stock-head h2{margin:0;color:#173f29}.stock-head p{margin:5px 0 0;color:#66736b}.stock-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.stock-summary article{padding:15px;border:1px solid #dbe5de;border-radius:15px;background:#fff}.stock-summary span{font-size:11px;font-weight:850;color:#68756d}.stock-summary strong{display:block;margin-top:6px;font-size:23px;color:#173f29}.stock-grid{display:grid;grid-template-columns:minmax(300px,.7fr) minmax(460px,1.3fr);gap:16px;margin-bottom:16px}.stock-form{display:grid;gap:11px}.stock-form label{display:grid;gap:5px;font-size:12px;font-weight:850}.stock-form input,.stock-form select,.stock-form textarea{width:100%;min-height:42px;border:1px solid #d8e1da;border-radius:11px;padding:8px 10px}.stock-form .two{display:grid;grid-template-columns:1fr 1fr;gap:10px}.stock-table td small{display:block;color:#6b776f;margin-top:3px}.stock-negative{color:#a33f35}.stock-positive{color:#176536}.stock-import-note{padding:13px;border:1px dashed #c6d6ca;border-radius:13px;background:#f7fbf8;color:#526158}.stock-row-cancelled{opacity:.58;background:#faf8f4}@media(max-width:850px){.stock-grid,.stock-summary{grid-template-columns:1fr 1fr}}@media(max-width:600px){.stock-grid,.stock-summary,.stock-form .two{grid-template-columns:1fr}}
</style>
<section class="stock-head"><div><h2>Ürün ve stok takibi</h2><p>Artikel bazında stokları düzine olarak takip et; giden faturalardaki satışlar stoktan otomatik düşsün.</p></div><a class="btn btn-secondary" href="uretim-takibi.php">Üretim Takibine dön</a></section>
<section class="stock-summary">
  <article><span>Ürün kartı</span><strong><?php echo e($summary['products']); ?></strong></article>
  <article><span>Aktif ürün</span><strong><?php echo e($summary['active']); ?></strong></article>
  <article><span>Toplam stok</span><strong><?php echo e(number_format($summary['total'],2,',','.')); ?> DZ</strong></article>
  <article><span>Eksi stok</span><strong class="<?php echo $summary['negative']?'stock-negative':'stock-positive'; ?>"><?php echo e($summary['negative']); ?></strong></article>
</section>
<section class="stock-grid">
  <article class="panel-card">
    <div class="card-head"><h3><?php echo $editProduct?'Ürün kartını düzenle':'Yeni ürün kartı'; ?></h3></div>
    <form method="post" class="stock-form" style="padding:16px"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?php echo e($editProduct['id'] ?? 0); ?>">
      <label>Artikel kodu<input name="article_code" required value="<?php echo e($editProduct['article_code'] ?? ''); ?>" placeholder="Ürün artikeli"></label>
      <label>Ürün adı<input name="product_name" required value="<?php echo e($editProduct['product_name'] ?? ''); ?>" placeholder="Ürün adı"></label>
      <label>Ürün bilgileri<textarea name="product_info" rows="3" placeholder="Renk, beden grubu veya diğer bilgiler"><?php echo e($editProduct['product_info'] ?? ''); ?></textarea></label>
      <?php if($editProduct): ?><label class="check"><input type="checkbox" name="is_active" <?php echo (int)$editProduct['is_active']===1?'checked':''; ?>> Aktif ürün</label><?php endif; ?>
      <button class="btn btn-primary" type="submit"><?php echo $editProduct?'Ürünü güncelle':'Ürün ekle'; ?></button>
    </form>
    <div class="stock-import-note"><strong>Excel başlangıç aktarımı</strong><br>Göndereceğin Excel’deki artikel, ürün adı/bilgileri ve başlangıç DZ stokları ürün kartlarına ve “Excel başlangıç stoku” hareketine dönüştürülecek.</div>
  </article>
  <article class="panel-card">
    <div class="card-head"><h3>Ürün stokları</h3><span><?php echo e(count($products)); ?> ürün</span></div>
    <div class="table-wrap"><table class="stock-table"><thead><tr><th>Artikel</th><th>Ürün</th><th>Bilgi</th><th class="right">Stok</th><th>Durum</th><th></th></tr></thead><tbody>
      <?php if(!$products): ?><tr><td colspan="6" class="empty">Excel aktarımı veya manuel ürün girişi bekleniyor.</td></tr><?php endif; ?>
      <?php foreach($products as $product): ?><tr><td><strong><?php echo e($product['article_code']); ?></strong></td><td><?php echo e($product['product_name']); ?></td><td><?php echo e($product['product_info'] ?: '-'); ?></td><td class="right"><strong class="<?php echo (float)$product['stock_dozen']<0?'stock-negative':'stock-positive'; ?>"><?php echo e(number_format((float)$product['stock_dozen'],2,',','.')); ?> DZ</strong></td><td><?php echo (int)$product['is_active']===1?badge('Aktif','success'):badge('Pasif','neutral'); ?></td><td><a href="stok-takibi.php?edit=<?php echo e($product['id']); ?>">Düzenle</a></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </article>
</section>
<section class="panel-card" id="stok-hareketi">
  <div class="card-head"><div><h3>Tek tek stok girişi</h3><span>Ürünü seç, düzine miktarını ekle veya düzeltme çıkışı yap.</span></div></div>
  <form method="post" class="stock-form" style="padding:16px"><?php echo csrf_field(); ?><input type="hidden" name="action" value="stock_entry">
    <div class="two"><label>Ürün<select name="product_id" required><option value="">Ürün seç</option><?php foreach($products as $product): if((int)$product['is_active']!==1)continue; ?><option value="<?php echo e($product['id']); ?>"><?php echo e($product['article_code'].' · '.$product['product_name'].' · '.number_format((float)$product['stock_dozen'],2,',','.').' DZ'); ?></option><?php endforeach; ?></select></label><label>İşlem<select name="direction"><option value="in">Stok ekle</option><option value="out">Düzeltme çıkışı</option></select></label></div>
    <div class="two"><label>Miktar (DZ)<input name="quantity_dozen" inputmode="decimal" placeholder="0,00" required></label><label>Tarih<input type="date" name="movement_date" value="<?php echo e(date('Y-m-d')); ?>" required></label></div>
    <label>Açıklama<input name="description" placeholder="Üretimden giriş, sayım farkı veya açıklama"></label>
    <button class="btn btn-primary" type="submit">Stok hareketini kaydet</button>
  </form>
  <div class="table-wrap"><table class="stock-table"><thead><tr><th>Tarih</th><th>Artikel / Ürün</th><th>Kaynak</th><th>Açıklama</th><th class="right">Giriş</th><th class="right">Çıkış</th><th>İşlem</th></tr></thead><tbody>
    <?php if(!$movementRows): ?><tr><td colspan="7" class="empty">Henüz stok hareketi yok.</td></tr><?php endif; ?>
    <?php foreach($movementRows as $movement): $cancelled=(int)$movement['is_cancelled']===1; ?><tr class="<?php echo $cancelled?'stock-row-cancelled':''; ?>"><td><?php echo e(tr_date($movement['movement_date'])); ?></td><td><strong><?php echo e($movement['article_code']); ?></strong><small><?php echo e($movement['product_name']); ?></small></td><td><?php echo e($movement['source_type']==='invoice_sale'?'Satış faturası':'Manuel'); ?></td><td><?php echo e($movement['description'] ?: '-'); ?></td><td class="right"><?php echo !$cancelled&&$movement['direction']==='in'?'<strong class="stock-positive">'.e(number_format((float)$movement['quantity_dozen'],2,',','.')).' DZ</strong>':'-'; ?></td><td class="right"><?php echo !$cancelled&&$movement['direction']==='out'?'<strong class="stock-negative">'.e(number_format((float)$movement['quantity_dozen'],2,',','.')).' DZ</strong>':'-'; ?></td><td><?php if($cancelled): ?><?php echo badge('İptal','neutral'); ?><?php elseif($movement['source_type']==='manual'&&can_write()): ?><form method="post" onsubmit="return confirm('Bu stok hareketi iptal edilsin mi? Kayıt silinmez.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel_movement"><input type="hidden" name="id" value="<?php echo e($movement['id']); ?>"><button>İptal</button></form><?php else: ?><small>Faturaya bağlı</small><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php page_footer(); ?>
