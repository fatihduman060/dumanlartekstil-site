<?php
require_once __DIR__ . '/layout.php';
require_admin();
$logs=db()->query('SELECT * FROM logs ORDER BY id DESC LIMIT 500')->fetchAll();
page_header('Loglar', 'loglar');
?>
<section class="panel-card"><div class="card-head"><h3>İşlem geçmişi</h3><span>Son 500 kayıt</span></div><div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Kullanıcı</th><th>İşlem</th><th>Detay</th><th>IP</th></tr></thead><tbody><?php if(!$logs): ?><tr><td colspan="5" class="empty">Log kaydı yok.</td></tr><?php endif; ?><?php foreach($logs as $l): ?><tr><td><?php echo e(tr_datetime($l['created_at'])); ?></td><td><?php echo e($l['username'] ?: '-'); ?></td><td><strong><?php echo e($l['action']); ?></strong></td><td><?php echo e($l['detail']); ?></td><td><?php echo e($l['ip']); ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php page_footer(); ?>
