<?php
require_once __DIR__.'/layout.php';
require_login();
require_once __DIR__.'/iplik-lib.php';
iplik_db_ensure();
$cari=(int)($_GET['cari_id']??0);
$cariler=cariler_for_select(); $company=null;
foreach($cariler as $c) if((int)$c['id']===$cari) $company=$c;
$error=''; $edit=null;
if($_SERVER['REQUEST_METHOD']==='POST') {
    require_write(); require_csrf();
    try {
        if(!$company || (int)($_POST['cari_id']??0)!==$cari) throw new InvalidArgumentException('Firma seçimi geçersiz.');
        if(($_POST['action']??'')==='save') iplik_save($_POST,$cari);
        elseif(($_POST['action']??'')==='cancel') iplik_cancel($_POST,$cari);
        else throw new InvalidArgumentException('Geçersiz işlem.');
        flash('success','İplik hareketi kaydedildi.');
        redirect('iplik-alisverisi.php?cari_id='.$cari);
    } catch(Throwable $e) {
        $error=$e instanceof PDOException?'Kayıt tamamlanamadı. Sayfayı yenileyip tekrar deneyin.':$e->getMessage();
        if(($_POST['action']??'')==='save') $edit=$_POST;
    }
}
if(!$edit && !empty($_GET['edit']) && $company) {
    $edit=iplik_load((int)$_GET['edit'],$cari);
    if(!$edit || $edit['is_cancelled']) { $edit=null; $error='Düzenlenebilir kayıt bulunamadı.'; }
}
$filters=[]; foreach(['from','to','color','lot'] as $key) $filters[$key]=trim((string)($_GET[$key]??''));
$rows=[]; $balances=[]; $summary=['in_kg'=>0,'out_kg'=>0,'in_tl'=>0,'out_tl'=>0]; $count=0;
$page=max(1,(int)($_GET['page']??1));
if($company) try {
    list($where,$params)=iplik_where($cari,$filters);
    $totals="SUM(CASE WHEN direction='aldik' THEN kg ELSE 0 END) in_kg,SUM(CASE WHEN direction='verdik' THEN kg ELSE 0 END) out_kg,SUM(CASE WHEN direction='aldik' THEN total ELSE 0 END) in_tl,SUM(CASE WHEN direction='verdik' THEN total ELSE 0 END) out_tl";
    $s=db()->prepare('SELECT '.$totals.' FROM yarn_movements WHERE '.$where.' AND is_cancelled=0'); $s->execute($params); $summary=$s->fetch();
    $s=db()->prepare('SELECT yarn_name,yarn_number,'.$totals.' FROM yarn_movements WHERE '.$where.' AND is_cancelled=0 GROUP BY yarn_name,yarn_number ORDER BY yarn_name,yarn_number'); $s->execute($params); $balances=$s->fetchAll();
    $s=db()->prepare('SELECT COUNT(*) FROM yarn_movements WHERE '.$where); $s->execute($params); $count=(int)$s->fetchColumn();
    $page=min($page,max(1,(int)ceil($count/100)));
    $s=db()->prepare('SELECT * FROM yarn_movements WHERE '.$where.' ORDER BY movement_date DESC,id DESC LIMIT 100 OFFSET '.(($page-1)*100)); $s->execute($params); $rows=$s->fetchAll();
} catch(InvalidArgumentException $e) { $error=$e->getMessage(); }
page_header('İplik Alışverişi','iplik_alisverisi');
?>
<style>
.yarn{display:grid;gap:16px;min-width:0}.yarn .panel-card{padding:18px;min-width:0}.yarn-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.yarn label{display:grid;gap:6px}.yarn input,.yarn select,.yarn textarea{width:100%;min-width:0;min-height:44px}.yarn .table-wrap{overflow-x:auto}.yarn table{min-width:650px}.yarn .ledger{min-width:1700px}.yarn .cancelled{opacity:.65}.yarn-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}.yarn small{display:block}.yarn summary{cursor:pointer;padding:10px}.yarn .cards strong{display:block;font-size:20px;margin-top:8px}@media(max-width:800px){.yarn-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:520px){.yarn-grid{grid-template-columns:1fr}}
</style>
<div class="yarn">
<section class="panel-card"><h2>İplik Alışverişi</h2><p>Operasyonel iplik takibidir. Normal cari muhasebe bakiyelerini otomatik etkilemez.</p>
<?php if($error): ?><p class="alert alert-error" role="alert"><?php echo e($error); ?></p><?php endif; ?>
<form method="get" class="yarn-grid">
<label>Firma<select name="cari_id" required><option value="">Firma seçin</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo (int)$c['id']===$cari?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></label>
<?php foreach(['from'=>'Başlangıç tarihi','to'=>'Bitiş tarihi','color'=>'Renk (tam eşleşme)','lot'=>'Lot (tam eşleşme)'] as $key=>$label): ?><label><?php echo e($label); ?><input type="<?php echo in_array($key,['from','to'],true)?'date':'text'; ?>" name="<?php echo e($key); ?>" value="<?php echo e($filters[$key]); ?>"></label><?php endforeach; ?>
<div class="yarn-actions"><button class="btn btn-primary">Göster</button><a class="btn btn-secondary" href="iplik-alisverisi.php?cari_id=<?php echo $cari; ?>">Filtreleri temizle</a></div></form></section>
<?php if(!$company): ?><section class="panel-card">Hareketleri, özetleri ve iplik bakiyelerini görmek için bir firma seçin.</section><?php else: ?>
<section class="panel-card"><h3><?php echo e($company['name']); ?> — Firma özeti</h3><p>Özetler seçili tarih, renk ve lot filtrelerine göre hesaplanır. İptal kayıtları dahil değildir.</p><div class="yarn-grid cards">
<?php foreach(['in_kg'=>'Toplam Aldığımız Kg','out_kg'=>'Toplam Verdiğimiz Kg','net_kg'=>'Net Kg Bakiyesi','in_tl'=>'Toplam Aldığımız TL','out_tl'=>'Toplam Verdiğimiz TL','net_tl'=>'Net TL Bakiyesi'] as $key=>$label): ?><div><?php echo e($label); ?><strong><?php echo e(strpos($key,'net_')===0?iplik_net(($summary['in_'.substr($key,4)]??0)-($summary['out_'.substr($key,4)]??0),$key==='net_kg'?'kg':'TL'):iplik_number($summary[$key]??0,substr($key,-2)==='kg'?3:2)); ?></strong></div><?php endforeach; ?>
</div><p><strong>Genel kg neti yalnız hareket toplamıdır.</strong> Farklı iplikler birbirine mahsup edilmez; ürün bazında bakiye için aşağıdaki tabloyu kullanın.</p></section>
<?php if(can_write()): ?>
<section class="panel-card"><h3><?php echo !empty($edit['id'])?'Hareket düzenle':'Yeni hareket'; ?></h3>
<form method="post" id="yarn-form" action="iplik-alisverisi.php?cari_id=<?php echo $cari; ?>">
<?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id']??0); ?>"><input type="hidden" name="version" value="<?php echo e($edit['version']??0); ?>"><input type="hidden" name="cari_id" value="<?php echo $cari; ?>">
<div class="yarn-grid"><label>Firma<input value="<?php echo e($company['name']); ?>" readonly></label>
<label>Tarih<input type="date" name="movement_date" value="<?php echo e($edit['movement_date']??date('Y-m-d')); ?>" required></label>
<label>İşlem Türü<select name="direction"><?php foreach(['aldik'=>'İplik Aldık','verdik'=>'İplik Verdik'] as $key=>$label): ?><option value="<?php echo $key; ?>" <?php echo ($edit['direction']??'')===$key?'selected':''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
<?php foreach(['yarn_name'=>'İplik Cinsi / Adı','yarn_number'=>'İplik Numarası (örn 30/1)','color'=>'Renk','lot'=>'Lot No','document_no'=>'İrsaliye/Fatura No'] as $key=>$label): ?><label><?php echo $label; ?><input name="<?php echo $key; ?>" maxlength="250" value="<?php echo e($edit[$key]??''); ?>" <?php echo $key==='yarn_name'?'required':''; ?>></label><?php endforeach; ?>
<?php foreach(['kg'=>'Miktar kg','unit_price'=>'Birim Fiyat (TL)','total'=>'Toplam Tutar (TL)'] as $key=>$label): ?><label><?php echo $label; ?><input type="text" inputmode="decimal" <?php echo $key!=='kg'?'data-money-input':''; ?> name="<?php echo $key; ?>" value="<?php echo e(isset($edit[$key])?($_SERVER['REQUEST_METHOD']==='POST'?$edit[$key]:iplik_number($edit[$key],$key==='kg'?3:($key==='unit_price'?4:2))):''); ?>" <?php echo $key==='kg'?'required':''; ?>></label><?php endforeach; ?>
<label>Açıklama<textarea name="description" maxlength="4000"><?php echo e($edit['description']??''); ?></textarea></label></div>
<p>Birim fiyat doluysa toplam otomatik hesaplanır. Manuel toplam için birim fiyatı boş bırakın. Kg örneği: 1.250,500.</p>
<div class="yarn-actions"><button class="btn btn-primary">Kaydet</button><a class="btn btn-secondary" href="iplik-alisverisi.php?cari_id=<?php echo $cari; ?>">Yeni hareket / Vazgeç</a></div></form></section>
<?php endif; ?>
<section class="panel-card"><h3>İplik Bakiyeleri</h3><div class="table-wrap"><table><thead><tr><th>İplik</th><th>No</th><th>Aldık Kg</th><th>Verdik Kg</th><th>Net Kg</th></tr></thead><tbody>
<?php foreach($balances as $b): ?><tr><td><?php echo e($b['yarn_name']); ?></td><td><?php echo e($b['yarn_number']); ?></td><td><?php echo iplik_number($b['in_kg']); ?></td><td><?php echo iplik_number($b['out_kg']); ?></td><td><?php echo iplik_net($b['in_kg']-$b['out_kg'],'kg'); ?></td></tr><?php endforeach; ?>
<?php if(!$balances): ?><tr><td colspan="5">Aktif hareket yok.</td></tr><?php endif; ?></tbody></table></div></section>
<section class="panel-card"><h3>Hareket listesi — <?php echo $count; ?> kayıt</h3><div class="table-wrap"><table class="ledger"><thead><tr><?php foreach(['Tarih','Firma','İşlem','İplik','No','Renk','Lot','Aldık Kg','Verdik Kg','Birim Fiyat','Tutar','İrsaliye/Fatura No','Açıklama','İşlem'] as $label): ?><th><?php echo $label; ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr class="<?php echo $r['is_cancelled']?'cancelled':''; ?>"><td><?php echo e(tr_date($r['movement_date'])); ?></td><td><?php echo e($company['name']); ?></td><td><?php echo $r['direction']==='aldik'?'İplik Aldık':'İplik Verdik'; ?><?php if($r['is_cancelled']): ?><strong>İPTAL</strong><small><?php echo e($r['cancel_reason']); ?></small><?php endif; ?></td>
<?php foreach(['yarn_name','yarn_number','color','lot'] as $key): ?><td><?php echo e($r[$key]); ?></td><?php endforeach; ?>
<td><?php echo $r['direction']==='aldik'?iplik_number($r['kg']):'—'; ?></td><td><?php echo $r['direction']==='verdik'?iplik_number($r['kg']):'—'; ?></td><td><?php echo $r['unit_price']===null?'—':iplik_number($r['unit_price'],4); ?></td><td><?php echo iplik_number($r['total'],2); ?> TL</td><td><?php echo e($r['document_no']); ?></td><td><?php echo e($r['description']); ?></td><td>
<?php if(can_write() && !$r['is_cancelled']): ?><a href="iplik-alisverisi.php?cari_id=<?php echo $cari; ?>&amp;edit=<?php echo (int)$r['id']; ?>">Düzenle</a><details><summary>İptal et</summary><form method="post" action="iplik-alisverisi.php?cari_id=<?php echo $cari; ?>" onsubmit="return confirm('Bu hareket iptal edilsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="cari_id" value="<?php echo $cari; ?>"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><input type="hidden" name="version" value="<?php echo (int)$r['version']; ?>"><label>İptal nedeni<input name="cancel_reason" required maxlength="1000"></label><button class="btn btn-secondary">İptali kaydet</button></form></details><?php endif; ?></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="14">Hareket bulunamadı.</td></tr><?php endif; ?></tbody></table></div>
<div class="yarn-actions"><?php foreach([-1=>'Önceki',1=>'Sonraki'] as $delta=>$label): if($page+$delta<1 || $page+$delta>ceil($count/100)) continue; ?><a class="btn btn-secondary" href="?<?php echo e(http_build_query(array_merge($filters,['cari_id'=>$cari,'page'=>$page+$delta]))); ?>"><?php echo $label; ?></a><?php endforeach; ?><span>Sayfa <?php echo $page; ?> / <?php echo max(1,(int)ceil($count/100)); ?></span></div></section>
<?php endif; ?></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
 var f=document.getElementById('yarn-form'); if(!f||!window.BitkeMoney)return;
 var kg=f.elements.kg,price=f.elements.unit_price,total=f.elements.total;
 function calculate(){total.readOnly=price.value.trim()!=='';total.required=!total.readOnly;if(total.readOnly){total.value=(Math.round((BitkeMoney.parse(kg.value)*BitkeMoney.parse(price.value)+Number.EPSILON)*100)/100).toLocaleString('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});}}
 kg.addEventListener('input',calculate);price.addEventListener('input',calculate);calculate();
});
</script>
<?php page_footer(); ?>
