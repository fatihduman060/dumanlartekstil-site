<?php
require_once __DIR__ . '/layout.php';
require_login();
require_private_finance_modules();

function arsiv_belge_label(?string $type): string
{
    $labels = [
        'ana_belge'=>'Ana belge', 'cek_on_gorseli'=>'Ön görsel', 'cek_arka_gorseli'=>'Arka görsel',
        'cek_gorseli'=>'Çek görseli', 'senet_gorseli'=>'Senet görseli', 'tahsil_dekontu'=>'Tahsil dekontu',
        'odeme_dekontu'=>'Ödeme dekontu', 'ciro_belgesi'=>'Ciro belgesi', 'iade_belgesi'=>'İade belgesi',
        'protesto_belgesi'=>'Protesto belgesi', 'diger'=>'Diğer belge',
    ];
    return $labels[$type ?: ''] ?? ($type ?: 'Belge');
}

function arsiv_gorsel_mi(?string $mime, ?string $name): bool
{
    if (strpos((string)$mime, 'image/') === 0) return true;
    return preg_match('/\.(jpe?g|png|webp|gif|heic)$/i', (string)$name) === 1;
}

$q = trim((string)($_GET['q'] ?? ''));
$instrument = trim((string)($_GET['instrument'] ?? ''));
$direction = trim((string)($_GET['direction'] ?? ''));
$cariId = trim((string)($_GET['cari_id'] ?? ''));
$cariler = cariler_for_select();

$where = ["COALESCE(ch.is_cancelled,0)=0"];
$params = [];
if ($direction !== '' && in_array($direction, ['alinacak','verilecek'], true)) { $where[] = 'ch.direction=?'; $params[] = $direction; }
if ($cariId !== '') { $where[] = 'ch.cari_id=?'; $params[] = (int)$cariId; }
$sql = "SELECT ch.*, c.name AS cari_name, m.payment_method
    FROM checks ch
    LEFT JOIN cariler c ON c.id=ch.cari_id
    LEFT JOIN movements m ON m.id=ch.movement_id
    WHERE " . implode(' AND ', $where) . " ORDER BY ch.due_date DESC, ch.id DESC LIMIT 500";
$stmt = db()->prepare($sql); $stmt->execute($params); $checks = $stmt->fetchAll();

$extraRows = db()->query("SELECT * FROM standalone_documents WHERE description LIKE 'Çek #% | %' ORDER BY document_date DESC, id DESC")->fetchAll();
$extras = [];
foreach ($extraRows as $doc) {
    if (preg_match('/^Çek #(\d+) \| /u', (string)($doc['description'] ?? ''), $match) !== 1) continue;
    $extras[(int)$match[1]][] = $doc;
}

$archive = [];
$documentCount = 0;
foreach ($checks as $check) {
    $id = (int)$check['id'];
    $isNote = strcasecmp(trim((string)($check['bank_name'] ?? '')), 'Senet') === 0
        || stripos((string)($check['payment_method'] ?? ''), 'SENET') !== false;
    $kind = $isNote ? 'senet' : 'cek';
    if ($instrument !== '' && $instrument !== $kind) continue;

    $docs = [];
    if (!empty($check['document_path'])) {
        $docs[] = ['type'=>'ana_belge','name'=>$check['document_name'] ?: ($isNote ? 'Senet belgesi' : 'Çek belgesi'),'mime'=>$check['document_mime'],'url'=>'cek-belge-indir.php?id='.$id,'preview_url'=>'cek-senet-belge-goruntule.php?source=check&id='.$id,'description'=>'Ana kayıt belgesi'];
    }
    foreach ($extras[$id] ?? [] as $doc) {
        $docs[] = ['type'=>$doc['document_type'],'name'=>$doc['document_name'] ?: 'Ek belge','mime'=>$doc['document_mime'],'url'=>'serbest-belge-indir.php?id='.(int)$doc['id'],'preview_url'=>'cek-senet-belge-goruntule.php?source=extra&id='.(int)$doc['id'],'description'=>preg_replace('/^Çek #\d+ \| /u', '', (string)($doc['description'] ?? ''))];
    }
    if (!$docs) continue;

    if ($q !== '') {
        $haystack = implode(' ', [$check['cari_name'] ?? '', $check['bank_name'] ?? '', $check['check_no'] ?? '', $check['drawer'] ?? '', $check['description'] ?? '']);
        foreach ($docs as $doc) $haystack .= ' ' . $doc['name'] . ' ' . $doc['description'];
        if (stripos($haystack, $q) === false) continue;
    }
    $check['archive_kind'] = $kind;
    $check['archive_docs'] = $docs;
    $archive[] = $check;
    $documentCount += count($docs);
}

page_header('Çek / Senet Arşivi', 'cekler');
?>
<style>
.instrument-archive{display:grid;gap:16px;max-width:1540px;margin:0 auto}.archive-hero{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:22px 24px;border-radius:24px;background:linear-gradient(135deg,#102818,#23613c);color:#fff}.archive-hero h2{margin:5px 0;color:#fff}.archive-hero p{margin:0;color:#e9f5ed}.archive-hero a{display:inline-flex;border-radius:999px;padding:10px 14px;background:#fff;color:#16482e;text-decoration:none;font-weight:900}.archive-tabs{display:flex;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #e5dccf;border-radius:18px;padding:8px}.archive-tabs a{flex:1 1 220px;text-align:center;text-decoration:none;border-radius:14px;padding:13px 16px;font-weight:950;color:#16482e;background:#fbf6ed}.archive-tabs a.active{background:#16482e;color:#fff}.archive-tabs small{display:block;margin-top:3px;font-weight:700;opacity:.72}.archive-filter{display:grid;grid-template-columns:minmax(220px,1.5fr) repeat(3,minmax(150px,.7fr)) auto;gap:9px;padding:14px;background:#fff;border:1px solid #e5dccf;border-radius:18px}.archive-filter input,.archive-filter select,.archive-filter button{min-height:42px;border:1px solid #e5dccf;border-radius:12px;padding:8px 11px;background:#fff}.archive-groups{display:grid;gap:16px}.archive-card{border:1px solid #e5dccf;border-radius:20px;background:#fff;overflow:hidden;box-shadow:0 12px 30px rgba(7,27,63,.06)}.archive-card-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;padding:15px 17px;background:#fbf6ed}.archive-card-head h3{margin:3px 0;color:#102818}.archive-card-head p{margin:0;color:#776b5c}.archive-card-actions{display:flex;gap:7px;flex-wrap:wrap}.archive-card-actions a{border:1px solid #d8cdbb;border-radius:999px;padding:7px 10px;background:#fff;color:#16482e;text-decoration:none;font-size:11px;font-weight:900}.archive-docs{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;padding:14px}.archive-doc{display:grid;grid-template-rows:150px auto;border:1px solid #e5dccf;border-radius:15px;overflow:hidden;text-decoration:none;background:#fff;color:#102818}.archive-preview{display:grid;place-items:center;background:#f4f1eb;overflow:hidden}.archive-preview img{width:100%;height:100%;object-fit:cover}.archive-preview span{font-size:34px}.archive-doc-copy{display:grid;gap:3px;padding:10px 11px}.archive-doc-copy strong{font-size:12px}.archive-doc-copy small{color:#776b5c;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.archive-empty{padding:30px;text-align:center;border:1px dashed #cfc3b1;border-radius:18px;background:#fff;color:#776b5c}@media(max-width:900px){.archive-filter{grid-template-columns:1fr 1fr}.archive-hero{display:block}.archive-hero a{margin-top:12px}}@media(max-width:600px){.archive-filter{grid-template-columns:1fr}.archive-docs{grid-template-columns:1fr 1fr}.archive-card-head{display:block}.archive-card-actions{margin-top:10px}}@media(max-width:420px){.archive-docs{grid-template-columns:1fr}}
</style>
<div class="instrument-archive">
  <section class="archive-hero"><div><span>ÇEKLER BÖLÜMÜ</span><h2>Çek / Senet Arşivi</h2><p>Ön, arka, ana belge, dekont ve diğer ekleri ilgili kayıtla birlikte görüntüle.</p></div><a href="cekler.php">Çeklere dön</a></section>
  <nav class="archive-tabs">
    <a href="cekler.php?direction=alinacak">Alınan Çekler<small>Müşteriden aldığımız çekler</small></a>
    <a href="cekler.php?direction=verilecek">Verilen Çekler<small>Bizim verdiğimiz çekler</small></a>
    <a class="active" href="cek-senet-arsivi.php">Çek / Senet Arşivi<small><?php echo e($documentCount); ?> belge</small></a>
  </nav>
  <form class="archive-filter" method="get"><input name="q" value="<?php echo e($q); ?>" placeholder="Cari, banka, çek no veya dosya ara"><select name="instrument"><option value="">Çek ve senetler</option><option value="cek" <?php echo $instrument==='cek'?'selected':''; ?>>Çekler</option><option value="senet" <?php echo $instrument==='senet'?'selected':''; ?>>Senetler</option></select><select name="direction"><option value="">Alınan ve verilen</option><option value="alinacak" <?php echo $direction==='alinacak'?'selected':''; ?>>Alınanlar</option><option value="verilecek" <?php echo $direction==='verilecek'?'selected':''; ?>>Verilenler</option></select><select name="cari_id"><option value="">Tüm cariler</option><?php foreach($cariler as $c): ?><option value="<?php echo e($c['id']); ?>" <?php echo $cariId!==''&&(int)$cariId===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select><button class="btn btn-secondary" type="submit">Filtrele</button></form>
  <div class="archive-groups">
    <?php if (!$archive): ?><div class="archive-empty">Filtreye uygun çek veya senet belgesi bulunamadı.</div><?php endif; ?>
    <?php foreach ($archive as $check): $id=(int)$check['id']; ?>
    <section class="archive-card">
      <header class="archive-card-head"><div><span><?php echo badge($check['archive_kind']==='senet'?'Senet':'Çek', $check['archive_kind']==='senet'?'warning':'info'); ?> <?php echo badge(check_direction_label($check['direction']), check_direction_tone($check['direction'])); ?></span><h3><?php echo e($check['cari_name'] ?: $check['drawer'] ?: 'Cari seçilmedi'); ?></h3><p><?php echo e($check['bank_name'] ?: ($check['archive_kind']==='senet'?'Senet':'Banka yok')); ?> · <?php echo e($check['check_no'] ?: '#'.$id); ?> · Vade <?php echo e(tr_date($check['due_date'])); ?> · <?php echo e(money($check['amount'])); ?></p></div><div class="archive-card-actions"><a href="cek-ek-belge.php?id=<?php echo e($id); ?>">Belge ekle</a><a href="cekler.php?direction=<?php echo e($check['direction']); ?>&edit=<?php echo e($id); ?>#cek-form">Kaydı düzenle</a></div></header>
      <div class="archive-docs">
        <?php foreach ($check['archive_docs'] as $doc): ?>
        <a class="archive-doc" href="<?php echo e($doc['url']); ?>" target="_blank"><span class="archive-preview"><?php if(arsiv_gorsel_mi($doc['mime'],$doc['name'])): ?><img src="<?php echo e($doc['preview_url']); ?>" alt="<?php echo e(arsiv_belge_label($doc['type'])); ?>" loading="lazy"><?php else: ?><span>📄</span><?php endif; ?></span><span class="archive-doc-copy"><strong><?php echo e(arsiv_belge_label($doc['type'])); ?></strong><small><?php echo e($doc['name']); ?></small><small><?php echo e($doc['description'] ?: 'Belgeyi aç'); ?></small></span></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
</div>
<?php page_footer(); ?>
