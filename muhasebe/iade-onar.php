<?php
require_once __DIR__ . '/layout.php';
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    try {
        $expected = $_POST['expected'] ?? [];
        if (!is_array($expected)) throw new RuntimeException('Geçersiz önizleme.');
        $count = customer_return_repair(db(), $expected, current_user());
        flash('success', $count . ' iade hareketi düzeltildi. Tutarlar ve belgeler korundu; kasa/banka değişmedi. Eski ve yeni değerler denetim kaydına yazıldı.');
    } catch (Throwable $e) { flash('error', $e->getMessage()); }
    redirect('iade-onar.php');
}
$rows = customer_return_candidates(db());
page_header('Eski ürün iadelerini düzelt', 'hareketler');
?>
<section class="panel-card">
<h2>İade önizlemesi</h2>
<p>Yalnızca İade kategorisindeki aktif Alacak/Gider kayıtları düzeltilir. Kasa/banka veya çek bağlantısı olanlar inceleme için bırakılır. Kayıt silinmez; tutar, tarih, açıklama ve belgeler korunur.</p>
<form method="post">
<?php echo csrf_field(); $ready = 0; ?>
<div class="table-wrap"><table><thead><tr><th>Kayıt / Cari</th><th>Tarih / Açıklama</th><th>Eski tür</th><th>İade tutarı</th><th>Bakiye değişimi</th><th>Durum</th></tr></thead><tbody>
<?php foreach ($rows as $row): $safe = $row['blocked_reason'] === ''; if ($safe) $ready++; ?>
<tr><td>#<?php echo e($row['id']); ?> / <?php echo e($row['cari_name']); ?></td><td><?php echo e(tr_date($row['movement_date']) . ' / ' . $row['description']); ?></td><td><?php echo e(movement_label($row['movement_type'])); ?></td><td><?php echo e(number_format((float)$row['amount'],2,',','.') . ' ' . ($row['currency'] ?? 'TL')); ?></td><td><?php echo $safe ? e(number_format(-(float)$row['amount'] * ($row['movement_type'] === 'alacak' ? 2 : 1),2,',','.') . ' ' . ($row['currency'] ?? 'TL')) : '—'; ?></td><td><?php echo e($safe ? 'İade olarak düzeltilecek; kasa/banka etkisi 0' : $row['blocked_reason']); ?><?php if ($safe): ?><input type="hidden" name="expected[<?php echo (int)$row['id']; ?>]" value="<?php echo e($row['fingerprint']); ?>"><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6">Düzeltilecek eski iade kaydı yok.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($ready): ?><button class="btn btn-primary" type="submit"><?php echo $ready; ?> iadeyi denetim kaydıyla düzelt</button><?php endif; ?>
</form></section>
<?php page_footer(); ?>
