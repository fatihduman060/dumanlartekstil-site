<?php
require_once __DIR__ . '/layout.php';
require_admin();

function mukerrer_norm(string $value): string
{
    $map = [
        'Ç'=>'C','Ğ'=>'G','İ'=>'I','I'=>'I','Ö'=>'O','Ş'=>'S','Ü'=>'U',
        'ç'=>'C','ğ'=>'G','ı'=>'I','i'=>'I','ö'=>'O','ş'=>'S','ü'=>'U',
        'Â'=>'A','Î'=>'I','Û'=>'U','â'=>'A','î'=>'I','û'=>'U',
    ];
    $value = strtoupper(strtr(trim($value), $map));
    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?: $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
}

function mukerrer_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function mukerrer_table_exists(string $table): bool
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function mukerrer_cari_meta(int $cariId): array
{
    $meta = ['movement_count'=>0, 'invoice_count'=>0, 'check_count'=>0, 'balance'=>0.0];
    $stmt = db()->prepare("SELECT COUNT(*) FROM movements WHERE cari_id=? AND COALESCE(is_cancelled,0)=0");
    $stmt->execute([$cariId]);
    $meta['movement_count'] = (int)$stmt->fetchColumn();
    if (mukerrer_table_exists('invoices')) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM invoices WHERE cari_id=? AND COALESCE(is_cancelled,0)=0");
        $stmt->execute([$cariId]);
        $meta['invoice_count'] = (int)$stmt->fetchColumn();
    }
    $stmt = db()->prepare("SELECT COUNT(*) FROM checks WHERE cari_id=? AND COALESCE(is_cancelled,0)=0");
    $stmt->execute([$cariId]);
    $meta['check_count'] = (int)$stmt->fetchColumn();
    $balance = cari_balance($cariId);
    $meta['balance'] = (float)($balance['net'] ?? 0);
    return $meta;
}

$cariler = db()->query("SELECT * FROM cariler ORDER BY id ASC")->fetchAll();
$nameGroups = [];
$taxGroups = [];
foreach ($cariler as $cari) {
    $nameKey = mukerrer_norm((string)($cari['name'] ?? ''));
    if ($nameKey !== '') {
        if (!isset($nameGroups[$nameKey])) $nameGroups[$nameKey] = [];
        $nameGroups[$nameKey][] = $cari;
    }
    $taxKey = mukerrer_digits((string)($cari['tax_no'] ?? ''));
    if ($taxKey !== '') {
        if (!isset($taxGroups[$taxKey])) $taxGroups[$taxKey] = [];
        $taxGroups[$taxKey][] = $cari;
    }
}
$nameGroups = array_filter($nameGroups, function ($rows) { return count($rows) > 1; });
$taxGroups = array_filter($taxGroups, function ($rows) { return count($rows) > 1; });

$duplicateMovementRows = [];
if (mukerrer_table_exists('movements')) {
    $sql = "SELECT
        a.id AS first_id, b.id AS second_id,
        a.cari_id, COALESCE(c.name,'Cari yok') AS cari_name,
        a.movement_type, a.amount, COALESCE(a.currency,'TL') AS currency,
        a.movement_date, a.account_id, COALESCE(ac.name,'Hesap yok') AS account_name,
        COALESCE(a.description,'') AS first_description,
        COALESCE(b.description,'') AS second_description,
        a.created_at AS first_created_at, b.created_at AS second_created_at
      FROM movements a
      JOIN movements b ON b.id>a.id
        AND COALESCE(b.is_cancelled,0)=0
        AND b.movement_type=a.movement_type
        AND ABS(b.amount-a.amount)<0.005
        AND COALESCE(b.currency,'TL')=COALESCE(a.currency,'TL')
        AND b.movement_date=a.movement_date
        AND COALESCE(b.account_id,0)=COALESCE(a.account_id,0)
        AND COALESCE(b.cari_id,0)=COALESCE(a.cari_id,0)
      LEFT JOIN cariler c ON c.id=a.cari_id
      LEFT JOIN accounts ac ON ac.id=a.account_id
      WHERE COALESCE(a.is_cancelled,0)=0
        AND a.movement_type IN ('tahsilat','odeme')
        AND (
          (COALESCE(a.description,'') LIKE 'Vade kapatma #%'
           AND COALESCE(b.description,'') NOT LIKE 'Vade kapatma #%')
          OR
          (COALESCE(b.description,'') LIKE 'Vade kapatma #%'
           AND COALESCE(a.description,'') NOT LIKE 'Vade kapatma #%')
          OR
          (COALESCE(a.description,'') LIKE 'Vade kapatma #%'
           AND COALESCE(b.description,'') LIKE 'Vade kapatma #%'
           AND COALESCE(a.description,'')=COALESCE(b.description,''))
        )
      ORDER BY a.movement_date DESC, cari_name ASC, a.id DESC";
    $duplicateMovementRows = db()->query($sql)->fetchAll();
}

$totalDuplicateCariGroups = count($nameGroups) + count($taxGroups);
$totalMovementCandidates = count($duplicateMovementRows);

page_header('Mükerrer Kontrolü', 'raporlar');
?>
<style>
.mk-wrap{display:grid;gap:16px;max-width:1500px;margin:0 auto}.mk-hero{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:20px 22px;border-radius:22px;background:linear-gradient(135deg,#5d321f,#aa7138);color:#fff}.mk-hero h2{margin:4px 0 6px;color:#fff}.mk-hero p{margin:0;color:#fff2e3}.mk-hero a{display:inline-flex;padding:9px 13px;border-radius:999px;background:#fff;color:#5d321f;text-decoration:none;font-weight:900}.mk-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.mk-stats article,.mk-card{background:#fff;border:1px solid #e5dccf;border-radius:18px;box-shadow:0 10px 28px rgba(25,20,15,.06)}.mk-stats article{padding:15px}.mk-stats span{font-size:10px;font-weight:950;color:#8a5b27;text-transform:uppercase}.mk-stats strong{display:block;margin-top:6px;font-size:23px;color:#3b2619}.mk-card{overflow:hidden}.mk-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 17px;background:#fff7ec;border-bottom:1px solid #e5dccf}.mk-head h3{margin:0;color:#3b2619}.mk-body{padding:15px 17px}.mk-note{margin:0;padding:12px 13px;border-radius:13px;background:#fff6df;color:#735424;line-height:1.55;font-size:12px}.mk-group{display:grid;gap:9px;margin-top:12px}.mk-group article{border:1px solid #eadfce;border-radius:14px;padding:12px;background:#fff}.mk-group h4{margin:0 0 9px;color:#3b2619}.mk-cari-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:8px}.mk-cari{padding:10px;border-radius:11px;background:#faf7f1}.mk-cari strong,.mk-cari small{display:block}.mk-cari small{margin-top:4px;color:#75685a}.mk-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}.mk-actions a{display:inline-flex;padding:6px 9px;border:1px solid #d7cab8;border-radius:999px;background:#fff;color:#4f321f;text-decoration:none;font-size:11px;font-weight:900}.mk-table-wrap{overflow:auto}.mk-table{width:100%;min-width:1050px;border-collapse:separate;border-spacing:0}.mk-table th{padding:10px;background:#5d321f;color:#fff;text-align:left;font-size:10px;text-transform:uppercase}.mk-table td{padding:10px;border-bottom:1px solid #eee4d7;vertical-align:top;font-size:12px}.mk-table small{display:block;margin-top:3px;color:#75685a}.mk-empty{padding:24px!important;text-align:center;color:#75685a}.mk-badge{display:inline-flex;padding:4px 7px;border-radius:999px;background:#fff0d2;color:#8a5f16;font-size:10px;font-weight:900}@media(max-width:760px){.mk-hero{display:block}.mk-hero a{margin-top:12px}.mk-stats{grid-template-columns:1fr}}
</style>
<div class="mk-wrap">
  <section class="mk-hero">
    <div><span>GÜVENLİ VERİ KONTROLÜ</span><h2>Cari ve tahsilat mükerrerlerini ayır.</h2><p>Bu ekran yalnızca şüpheli kayıtları gösterir; hiçbir cari veya finansal hareketi otomatik silmez.</p></div>
    <a href="dashboard.php">← Genel bakışa dön</a>
  </section>

  <section class="mk-stats">
    <article><span>Aynı isimli cari grubu</span><strong><?php echo e(count($nameGroups)); ?></strong></article>
    <article><span>Aynı vergi numaralı grup</span><strong><?php echo e(count($taxGroups)); ?></strong></article>
    <article><span>Olası çift tahsilat / ödeme</span><strong><?php echo e($totalMovementCandidates); ?></strong></article>
  </section>

  <section class="mk-card">
    <div class="mk-head"><h3>Mükerrer cari kartları</h3><span class="mk-badge"><?php echo e($totalDuplicateCariGroups); ?> grup</span></div>
    <div class="mk-body">
      <p class="mk-note">Aynı ünvan veya aynı vergi numarasıyla birden fazla cari kartı açılmışsa burada görünür. Birleştirmeden önce her kartın hareket, fatura ve çek sayısını kontrol et.</p>
      <?php if (!$nameGroups && !$taxGroups): ?><p class="mk-empty">Aynı isim veya vergi numarasıyla açılmış cari grubu bulunmadı.</p><?php endif; ?>
      <div class="mk-group">
        <?php foreach ($nameGroups as $key=>$rows): ?>
          <article><h4>Aynı isim: <?php echo e($key); ?></h4><div class="mk-cari-grid">
            <?php foreach ($rows as $row): $meta=mukerrer_cari_meta((int)$row['id']); ?>
              <div class="mk-cari"><strong>#<?php echo e($row['id']); ?> · <?php echo e($row['name']); ?></strong><small>Vergi no: <?php echo e($row['tax_no'] ?: '-'); ?> · Şehir: <?php echo e($row['city'] ?: '-'); ?></small><small>Hareket: <?php echo e($meta['movement_count']); ?> · Fatura: <?php echo e($meta['invoice_count']); ?> · Çek: <?php echo e($meta['check_count']); ?> · Net: <?php echo e(money($meta['balance'])); ?></small><div class="mk-actions"><a href="cari-detay.php?id=<?php echo e($row['id']); ?>">Cariyi aç</a><a href="cariler.php?edit=<?php echo e($row['id']); ?>">Düzenle</a></div></div>
            <?php endforeach; ?>
          </div></article>
        <?php endforeach; ?>
        <?php foreach ($taxGroups as $key=>$rows): ?>
          <article><h4>Aynı vergi numarası: <?php echo e($key); ?></h4><div class="mk-cari-grid">
            <?php foreach ($rows as $row): $meta=mukerrer_cari_meta((int)$row['id']); ?>
              <div class="mk-cari"><strong>#<?php echo e($row['id']); ?> · <?php echo e($row['name']); ?></strong><small>Vergi no: <?php echo e($row['tax_no'] ?: '-'); ?> · Şehir: <?php echo e($row['city'] ?: '-'); ?></small><small>Hareket: <?php echo e($meta['movement_count']); ?> · Fatura: <?php echo e($meta['invoice_count']); ?> · Çek: <?php echo e($meta['check_count']); ?> · Net: <?php echo e(money($meta['balance'])); ?></small><div class="mk-actions"><a href="cari-detay.php?id=<?php echo e($row['id']); ?>">Cariyi aç</a><a href="cariler.php?edit=<?php echo e($row['id']); ?>">Düzenle</a></div></div>
            <?php endforeach; ?>
          </div></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="mk-card">
    <div class="mk-head"><h3>Vade kapatmadan doğmuş olası mükerrer hareketler</h3><span class="mk-badge"><?php echo e($totalMovementCandidates); ?> eşleşme</span></div>
    <div class="mk-body"><p class="mk-note">Aynı gün, aynı cari, aynı tutar, aynı hesap ve aynı işlem türünde iki kayıt varsa; kayıtlardan biri “Vade kapatma” açıklamalıysa burada gösterilir. Bu, Hektaş’ta gördüğümüz tekerrür tipidir.</p></div>
    <div class="mk-table-wrap"><table class="mk-table"><thead><tr><th>Tarih</th><th>Cari</th><th>Tür</th><th>Hesap</th><th>Birinci kayıt</th><th>İkinci kayıt</th><th>Tutar</th><th>İncele</th></tr></thead><tbody>
      <?php if(!$duplicateMovementRows): ?><tr><td colspan="8" class="mk-empty">Vade kapatmayla eşleşen olası mükerrer tahsilat veya ödeme bulunmadı.</td></tr><?php endif; ?>
      <?php foreach($duplicateMovementRows as $row): ?><tr><td><?php echo e(tr_date($row['movement_date'])); ?></td><td><strong><?php echo e($row['cari_name']); ?></strong><small>Cari #<?php echo e($row['cari_id'] ?: '-'); ?></small></td><td><?php echo e(movement_label($row['movement_type'])); ?></td><td><?php echo e($row['account_name']); ?></td><td><strong>#<?php echo e($row['first_id']); ?></strong><small><?php echo e($row['first_description'] ?: '-'); ?></small><small><?php echo e(tr_datetime($row['first_created_at'])); ?></small></td><td><strong>#<?php echo e($row['second_id']); ?></strong><small><?php echo e($row['second_description'] ?: '-'); ?></small><small><?php echo e(tr_datetime($row['second_created_at'])); ?></small></td><td><strong><?php echo e(number_format((float)$row['amount'],2,',','.').' '.$row['currency']); ?></strong></td><td><div class="mk-actions"><a href="hareketler.php?q=<?php echo e(urlencode((string)$row['first_id'])); ?>">#<?php echo e($row['first_id']); ?></a><a href="hareketler.php?q=<?php echo e(urlencode((string)$row['second_id'])); ?>">#<?php echo e($row['second_id']); ?></a><?php if(!empty($row['cari_id'])): ?><a href="cari-detay.php?id=<?php echo e($row['cari_id']); ?>">Cari detay</a><?php endif; ?></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
</div>
<?php page_footer(); ?>
