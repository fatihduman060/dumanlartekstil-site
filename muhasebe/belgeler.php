<?php
require_once __DIR__ . '/layout.php';
require_login();

$q = trim($_GET['q'] ?? '');
$cariId = trim($_GET['cari_id'] ?? '');
$kind = trim($_GET['kind'] ?? '');
$docType = trim($_GET['document_type'] ?? '');
$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');
$cariler = cariler_for_select();

$rows = [];
if ($kind === '' || $kind === 'movement') {
    $where = ["m.document_path IS NOT NULL", "m.document_path != ''"];
    $params = [];
    if ($q !== '') { $where[] = '(m.description LIKE ? OR m.document_name LIKE ? OR c.name LIKE ? OR cat.name LIKE ?)'; array_push($params,"%$q%","%$q%","%$q%","%$q%"); }
    if ($cariId !== '') { $where[]='m.cari_id=?'; $params[]=(int)$cariId; }
    if ($docType !== '') { $where[]='m.document_type=?'; $params[]=$docType; }
    if ($start !== '') { $where[]='m.movement_date>=?'; $params[]=$start; }
    if ($end !== '') { $where[]='m.movement_date<=?'; $params[]=$end; }
    $sql="SELECT 'movement' AS kind, m.id, m.movement_date AS doc_date, m.document_type, m.document_name, m.document_mime, m.description, m.amount, m.movement_type AS source_label, c.id AS cari_id, c.name AS cari_name, cat.name AS category_name FROM movements m LEFT JOIN cariler c ON c.id=m.cari_id LEFT JOIN categories cat ON cat.id=m.category_id WHERE " . implode(' AND ', $where);
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows = array_merge($rows, $stmt->fetchAll());
}
if ($kind === '' || $kind === 'check') {
    $where = ["COALESCE(ch.is_cancelled,0)=0", "ch.document_path IS NOT NULL", "ch.document_path != ''"];
    $params = [];
    if ($q !== '') { $where[] = '(ch.description LIKE ? OR ch.document_name LIKE ? OR ch.bank_name LIKE ? OR ch.check_no LIKE ? OR c.name LIKE ?)'; array_push($params,"%$q%","%$q%","%$q%","%$q%","%$q%"); }
    if ($cariId !== '') { $where[]='ch.cari_id=?'; $params[]=(int)$cariId; }
    if ($docType !== '' && $docType !== 'cek_gorseli') { $where[]='1=0'; }
    if ($start !== '') { $where[]='ch.due_date>=?'; $params[]=$start; }
    if ($end !== '') { $where[]='ch.due_date<=?'; $params[]=$end; }
    $sql="SELECT 'check' AS kind, ch.id, ch.due_date AS doc_date, 'cek_gorseli' AS document_type, ch.document_name, ch.document_mime, ch.description, ch.amount, ch.status AS source_label, c.id AS cari_id, c.name AS cari_name, ch.bank_name AS category_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE " . implode(' AND ', $where);
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows = array_merge($rows, $stmt->fetchAll());
}
if ($kind === '' || $kind === 'private') {
    $where = ["pr.document_path IS NOT NULL", "pr.document_path != ''"];
    $params = [];
    if ($q !== '') { $where[] = '(pr.description LIKE ? OR pr.document_name LIKE ? OR c.name LIKE ?)'; array_push($params,"%$q%","%$q%","%$q%"); }
    if ($cariId !== '') { $where[]='pr.cari_id=?'; $params[]=(int)$cariId; }
    if ($docType !== '') { $where[]='pr.document_type=?'; $params[]=$docType; }
    if ($start !== '') { $where[]='pr.receivable_date>=?'; $params[]=$start; }
    if ($end !== '') { $where[]='pr.receivable_date<=?'; $params[]=$end; }
    $sql="SELECT 'private' AS kind, pr.id, pr.receivable_date AS doc_date, pr.document_type, pr.document_name, pr.document_mime, pr.description, pr.amount, pr.status AS source_label, c.id AS cari_id, c.name AS cari_name, 'Özel Alacak' AS category_name FROM private_receivables pr LEFT JOIN cariler c ON c.id=pr.cari_id WHERE " . implode(' AND ', $where);
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows = array_merge($rows, $stmt->fetchAll());
}
if ($kind === '' || $kind === 'standalone') {
    $where = ["sd.document_path IS NOT NULL", "sd.document_path != ''"];
    $params = [];
    if ($q !== '') { $where[] = '(sd.description LIKE ? OR sd.document_name LIKE ? OR c.name LIKE ?)'; array_push($params,"%$q%","%$q%","%$q%"); }
    if ($cariId !== '') { $where[]='sd.cari_id=?'; $params[]=(int)$cariId; }
    if ($docType !== '') { $where[]='sd.document_type=?'; $params[]=$docType; }
    if ($start !== '') { $where[]='sd.document_date>=?'; $params[]=$start; }
    if ($end !== '') { $where[]='sd.document_date<=?'; $params[]=$end; }
    $sql="SELECT 'standalone' AS kind, sd.id, sd.document_date AS doc_date, sd.document_type, sd.document_name, sd.document_mime, sd.description, 0 AS amount, 'Serbest belge' AS source_label, c.id AS cari_id, c.name AS cari_name, 'Serbest belge' AS category_name FROM standalone_documents sd LEFT JOIN cariler c ON c.id=sd.cari_id WHERE " . implode(' AND ', $where);
    $stmt=db()->prepare($sql); $stmt->execute($params); $rows = array_merge($rows, $stmt->fetchAll());
}
usort($rows, fn($a,$b)=>strcmp($b['doc_date'] ?? '', $a['doc_date'] ?? ''));
$docCount = count($rows);
page_header('Belgeler', 'belgeler');
?>
<section class="hero-card">
  <div>
    <span class="status-pill">Belge arşivi</span>
    <h2>Fatura, dekont, makbuz ve çek görselleri tek listede.</h2>
    <p>Hareket, çek, özel alacak ve serbest belgeleri cari, tarih ve belge türüne göre süzebilirsiniz.</p>
  </div>
  <div class="hero-actions"><a class="btn btn-primary" href="hareketler.php">Hareket belgesi ekle</a><a class="btn btn-secondary" href="cekler.php">Çek belgesi ekle</a></div>
</section>

<section class="panel-card">
  <div class="card-head"><h3>Belge listesi</h3><span><?php echo e($docCount); ?> belge</span></div>
  <form class="filterbar multi ultra" method="get">
    <input name="q" placeholder="Belge adı, açıklama, cari, çek no ara" value="<?php echo e($q); ?>">
    <select name="kind"><option value="">Tüm kaynaklar</option><option value="movement" <?php echo $kind==='movement'?'selected':''; ?>>Hareket belgeleri</option><option value="check" <?php echo $kind==='check'?'selected':''; ?>>Çek belgeleri</option><option value="private" <?php echo $kind==='private'?'selected':''; ?>>Özel alacak belgeleri</option><option value="standalone" <?php echo $kind==='standalone'?'selected':''; ?>>Serbest belgeler</option></select>
    <select name="cari_id"><option value="">Tüm cariler</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo $cariId!=='' && (int)$cariId===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select>
    <select name="document_type"><option value="">Tüm belge türleri</option><?php foreach(document_types() as $key=>$label): ?><option value="<?php echo e($key); ?>" <?php echo $docType===$key?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select>
    <input type="date" name="start" value="<?php echo e($start); ?>"><input type="date" name="end" value="<?php echo e($end); ?>">
    <button class="btn btn-secondary" type="submit">Filtrele</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tarih</th><th>Kaynak</th><th>Belge türü</th><th>Cari</th><th>Açıklama</th><th>Dosya</th><th class="right">Tutar</th></tr></thead>
      <tbody>
        <?php if(!$rows): ?><tr><td colspan="7" class="empty">Belge bulunamadı.</td></tr><?php endif; ?>
        <?php foreach($rows as $r):
          if ($r['kind']==='movement') { $url = 'belge-indir.php?id=' . $r['id']; $sourceBadge = badge('Hareket','success'); }
          elseif ($r['kind']==='check') { $url = 'cek-belge-indir.php?id=' . $r['id']; $sourceBadge = badge('Çek','info'); }
          elseif ($r['kind']==='private') { $url = 'ozel-belge-indir.php?id=' . $r['id']; $sourceBadge = badge('Özel Alacak','special'); }
          else { $url = 'serbest-belge-indir.php?id=' . $r['id']; $sourceBadge = badge('Serbest','neutral'); }
        ?>
        <tr>
          <td><?php echo e(tr_date($r['doc_date'])); ?></td>
          <td><?php echo $sourceBadge; ?><small><?php echo e($r['source_label'] ?: ''); ?></small></td>
          <td><?php echo badge(document_type_label($r['document_type']), 'neutral'); ?></td>
          <td><?php echo $r['cari_id'] ? '<a href="cari-detay.php?id='.e($r['cari_id']).'">'.e($r['cari_name']).'</a>' : '-'; ?></td>
          <td><?php echo e($r['description'] ?: $r['category_name'] ?: '-'); ?></td>
          <td><a href="<?php echo e($url); ?>" target="_blank"><?php echo e($r['document_name'] ?: 'Belgeyi aç'); ?></a><small><?php echo e($r['document_mime'] ?: ''); ?></small></td>
          <td class="right"><strong><?php echo ((float)$r['amount'] > 0) ? e(money($r['amount'])) : '-'; ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_footer(); ?>
