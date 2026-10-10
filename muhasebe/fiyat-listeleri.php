<?php
require_once __DIR__.'/layout.php';
require_once __DIR__.'/fiyat-listeleri-lib.php';
require_login();
fiyat_listeleri_ensure();
header('Cache-Control: private, no-store');
$error='';
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
    require_write(); require_csrf();
    $pdo=db(); $savedPath=null;
    try {
        $action=(string)($_POST['action']??'');
        $pdo->beginTransaction();
        $pdo->exec('UPDATE price_lists SET is_current=is_current WHERE is_current=1');
        if($action==='upload') {
            $file=$_FILES['document']??[];
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']??'')) throw new RuntimeException('Dosya seçin.');
            if(filesize($file['tmp_name'])>MAX_UPLOAD_BYTES) throw new RuntimeException('Dosya en fazla 10 MB olabilir.');
            $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
            if(!in_array($ext,['csv','xlsx','pdf'],true)) throw new RuntimeException('CSV, XLSX veya PDF yükleyin.');
            $items=[]; $dataHash=null;
            if($ext==='pdf') {
                if((new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])!=='application/pdf') throw new RuntimeException('Geçerli bir PDF seçin.');
                $data=$_FILES['prices']??[];
                if(($data['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                    $dataExt=strtolower(pathinfo($data['name']??'',PATHINFO_EXTENSION));
                    if(($data['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($data['tmp_name']??'') || filesize($data['tmp_name'])>MAX_UPLOAD_BYTES || !in_array($dataExt,['csv','xlsx'],true)) throw new RuntimeException('Fiyat tablosu en fazla 10 MB, CSV veya XLSX olmalı.');
                    $items=fiyat_listeleri_items(fiyat_listeleri_rows($data['tmp_name'],$dataExt));
                    $dataHash=hash_file('sha256',$data['tmp_name']);
                }
            } else { $items=fiyat_listeleri_items(fiyat_listeleri_rows($file['tmp_name'],$ext)); }
            $title=trim((string)($_POST['title']??''));
            if($title==='' || strlen($title)>250) throw new RuntimeException('Liste adını girin (en fazla 250 bayt).');
            $sub='price-lists';$dir=ensure_upload_dir($sub);$savedPath=$sub.'/'.bin2hex(random_bytes(16)).'.'.$ext;
            $hash=hash_file('sha256',$file['tmp_name']);
            if(!move_uploaded_file($file['tmp_name'],UPLOAD_DIR.'/'.$savedPath)) throw new RuntimeException('Dosya saklanamadı.');
            $pdo->prepare('INSERT INTO price_lists(title,file_path,file_name,file_hash,created_by,created_at) VALUES(?,?,?,?,?,?)')
                ->execute([$title,$savedPath,preg_replace('/[\x00-\x1F\x7F"\\\\]/', '_', basename(str_replace('\\','/',$file['name']))),$hash,current_user()['id'],now()]);
            $id=(int)$pdo->lastInsertId();
            $insert=$pdo->prepare('INSERT INTO price_list_items(list_id,product_id,product_name,barcode,unit_price) VALUES(?,?,?,?,?)');
            foreach($items as $item) $insert->execute([$id,$item['product_id'],$item['product_name'],$item['barcode'],$item['unit_price']]);
            fiyat_listeleri_audit($id,'eklendi',null,['title'=>$title,'file_hash'=>$hash,'price_table_hash'=>$dataHash,'file_bytes'=>(int)$file['size'],'file_name'=>$file['name'],'items'=>$items]);
            if(!empty($_POST['current'])) fiyat_listeleri_activate($id);
        } elseif($action==='current') {
            fiyat_listeleri_activate((int)($_POST['id']??0));
        } elseif($action==='delete') {
            $id=(int)($_POST['id']??0);
            $s=$pdo->prepare('SELECT * FROM price_lists WHERE id=? AND deleted_at IS NULL');$s->execute([$id]);$old=$s->fetch();
            if(!$old) throw new RuntimeException('Liste bulunamadı.');
            if($old['is_current']) $pdo->prepare('UPDATE offer_products SET list_unit_price=NULL,updated_at=?')->execute([now()]);
            $pdo->prepare('UPDATE price_lists SET is_current=0,deleted_at=? WHERE id=?')->execute([now(),$id]);
            // Tombstone and immutable item snapshots remain for audit; downloads are denied.
            fiyat_listeleri_audit($id,'silindi',$old,['deleted_at'=>now(),'current_prices_cleared'=>(bool)$old['is_current']]);
        } else { throw new RuntimeException('Geçersiz işlem.'); }
        $pdo->commit();
        flash('success','Fiyat listesi işlemi kaydedildi. Açık teklif ve depo çıkış ekranlarını yenileyin.');
        redirect('fiyat-listeleri.php');
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        if($savedPath!==null) delete_uploaded_file($savedPath);
        $error=$e instanceof PDOException?'İşlem kaydedilemedi. Lütfen tekrar deneyin.':$e->getMessage();
    }
}
if(isset($_GET['view'])) {
    $s=db()->prepare('SELECT file_path FROM price_lists WHERE id=? AND deleted_at IS NULL');$s->execute([(int)$_GET['view']]);$r=$s->fetch();
    if(!$r || strtolower(pathinfo($r['file_path'],PATHINFO_EXTENSION))!=='pdf') { http_response_code(404);exit('PDF bulunamadı.'); }
    $path=UPLOAD_DIR.'/'.$r['file_path'];
    if(!is_file($path)) { http_response_code(404);exit('PDF dosyası bulunamadı.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="fiyat-listesi.pdf"');
    header('Content-Length: '.filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);exit;
}
if(isset($_GET['preview'])) {
    $id=(int)$_GET['preview'];
    $s=db()->prepare('SELECT title,file_name FROM price_lists WHERE id=? AND deleted_at IS NULL');$s->execute([$id]);$list=$s->fetch();
    if(!$list) { http_response_code(404);exit('Liste bulunamadı.'); }
    $s=db()->prepare('SELECT product_name,barcode,unit_price FROM price_list_items WHERE list_id=? ORDER BY product_name COLLATE NOCASE');$s->execute([$id]);$items=$s->fetchAll();
    page_header('Fiyat Listesi Önizleme','fiyat_listeleri');
    echo '<section class="panel-card"><h2>'.e($list['title']).'</h2><p>'.e($list['file_name']).' · '.count($items).' ürün</p><p><a href="fiyat-listeleri.php">Fiyat listelerine dön</a></p><div class="table-wrap"><table><thead><tr><th>Ürün</th><th>Barkod</th><th>Birim fiyat</th></tr></thead><tbody>';
    foreach($items as $item) echo '<tr><td>'.e($item['product_name']).'</td><td>'.e($item['barcode']).'</td><td>'.e(teklif_money((float)$item['unit_price'])).' TL</td></tr>';
    if(!$items) echo '<tr><td colspan="3">Bu PDF arşivlenmiş; fiyat tablosu eklenmediği için ürün fiyatı bulunmuyor.</td></tr>';
    echo '</tbody></table></div></section>';
    page_footer();exit;
}
if(isset($_GET['download'])) {
    $s=db()->prepare('SELECT * FROM price_lists WHERE id=? AND deleted_at IS NULL');$s->execute([(int)$_GET['download']]);$r=$s->fetch();
    if(!$r){http_response_code(404);exit('Liste bulunamadı.');}
    header('X-Content-Type-Options: nosniff');
    download_file(UPLOAD_DIR.'/'.$r['file_path'],$r['file_name'],'application/octet-stream');exit;
}
$rows=db()->query('SELECT p.*, (SELECT COUNT(*) FROM price_list_items i WHERE i.list_id=p.id) AS item_count FROM price_lists p WHERE deleted_at IS NULL ORDER BY is_current DESC,id DESC')->fetchAll();
page_header('Fiyat Listeleri','fiyat_listeleri');
?>
<style>.pl-wrap{display:grid;gap:18px;min-width:0}.pl-actions{display:flex;flex-wrap:wrap;gap:10px}.pl-wrap .panel-card{min-width:0}.pl-wrap input[type=file]{max-width:100%}.pl-wrap td{overflow-wrap:anywhere}.pl-wrap .pl-current{display:flex;align-items:center;gap:10px}.pl-wrap input[type=checkbox]{width:20px!important;height:20px!important;min-height:20px!important;flex:0 0 20px;margin:0}@media(max-width:600px){.pl-wrap .panel-card{padding:14px}.pl-actions>*{max-width:100%}}</style>
<div class="pl-wrap">
<section class="panel-card">
<h2>Fiyat listesi arşivi</h2>
<p>Güncel liste, müşterinin ürüne ait geçmiş fiyatı yoksa Teklif Ver ve Depo Çıkış’ta kullanılır. Fiyatlar TL, KDV hariç ve fişteki birimle aynı olmalıdır (ör. DZ / düzine).</p>
<p>Yalnızca bir liste güncel olabilir. Yeni listede bulunmayan ürünün otomatik liste fiyatı boş kalır. Geçmiş müşteri fiyatları ve kaydedilmiş fişler değişmez.</p>
<div class="pl-actions"><?php if(!is_murat_limited_user()): ?><a href="urun-fiyat-listesi.php">Ürün tanımları ve fiyatlar</a><?php endif; ?></div>
</section>
<?php if(can_write()): ?>
<section class="panel-card">
<h3>Yeni fiyat listesi yükle</h3>
<?php if($error!==''): ?><p class="alert alert-error" role="alert"><?php echo e($error); ?></p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="stack-form">
<?php echo csrf_field(); ?><input type="hidden" name="action" value="upload">
<label>Liste adı<input name="title" required maxlength="120" placeholder="Örn. 2026 Kışlık fiyatları"></label>
<label>Fiyat listesi (CSV, XLSX veya PDF; en fazla 10 MB)<input type="file" name="document" accept=".csv,.xlsx,.pdf" required></label>
<p>CSV UTF-8 veya Excel’in ilk sayfası: ilk satırda <strong>Barkod</strong>, <strong>Artikel</strong> veya <strong>Ürün Adı</strong> ve <strong>Birim Fiyat</strong> başlıklarını kullanın. Örnek: <code>Artikel;Birim Fiyat</code> / <code>6000;444,50</code>. Formülleri değer olarak kaydedin. Eşleşmeyen veya yinelenen ürün varsa dosyanın tamamı reddedilir.</p>
<label>PDF için fiyat tablosu (isteğe bağlı CSV / XLSX)<input type="file" name="prices" accept=".csv,.xlsx"></label>
<p>PDF tek başına arşivlenir; güncel yapılabilmesi için fiyat tablosunu birlikte yükleyin.</p>
<label class="pl-current"><input type="checkbox" name="current" value="1"> Yükleyince Güncel yap (önceki liste arşivde kalır)</label>
<button class="btn btn-primary" type="submit">Listeyi yükle</button>
</form>
</section>
<?php endif; ?>
<section class="panel-card"><h3>Liste arşivi</h3>
<div class="table-wrap"><table><thead><tr><th>Liste</th><th>Durum</th><th>Ürün</th><th>Yükleme</th><th>İşlemler</th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
<tr><td><strong><?php echo e($r['title']); ?></strong><br><?php if(strtolower(pathinfo($r['file_path'],PATHINFO_EXTENSION))==='pdf'): ?><a href="fiyat-listeleri.php?view=<?php echo (int)$r['id']; ?>" target="_blank" rel="noopener">PDF’yi görüntüle</a><?php elseif((int)$r['item_count']>0): ?><a href="fiyat-listeleri.php?preview=<?php echo (int)$r['id']; ?>"><?php echo e($r['file_name']); ?> · Fiyatları görüntüle</a><?php else: ?><span><?php echo e($r['file_name']); ?></span><?php endif; ?></td>
<td><?php echo $r['is_current']?'Güncel':'Arşiv'; ?></td><td><?php echo (int)$r['item_count']; ?></td><td><?php echo e($r['created_at']); ?></td>
<td><div class="pl-actions"><a href="fiyat-listeleri.php?download=<?php echo (int)$r['id']; ?>">İndir</a><?php if(strtolower(pathinfo($r['file_path'],PATHINFO_EXTENSION))==='pdf' && (int)$r['item_count']>0): ?><a href="fiyat-listeleri.php?preview=<?php echo (int)$r['id']; ?>">Fiyatları görüntüle</a><?php endif; ?><?php if(can_write()): ?>
<?php if(!$r['is_current'] && $r['item_count']): ?><form method="post" onsubmit="return confirm('Bu liste güncel yapılsın mı? Listede olmayan ürünlerin liste fiyatları boş kalır.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="current"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-secondary">Güncel yap</button></form><?php endif; ?>
<form method="post" onsubmit="return confirm('Liste silinsin mi? Güncelse otomatik liste fiyatları kaldırılır. İşlem geçmişi korunur.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-secondary">Sil</button></form>
<?php endif; ?></div></td></tr>
<?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="5">Henüz fiyat listesi yüklenmemiş.</td></tr><?php endif; ?>
</tbody></table></div><p>Silinen listeler erişime kapatılır; dosya, ürün fiyatları ve işlem izi denetim amacıyla korunur.</p>
</section></div>
<?php page_footer(); ?>
