<?php
require_once __DIR__ . '/layout.php';
require_admin();

$db = db();

$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');
$entity = trim($_GET['entity'] ?? '');
$action = trim($_GET['action'] ?? '');
$username = trim($_GET['username'] ?? '');
$q = trim($_GET['q'] ?? '');

$validEntities = [
    'cari', 'hareket', 'cek', 'ozel_alacak', 'hesap', 'hesap_hareketi', 'kullanici', 'yedek', 'kategori'
];
$validActions = [
    'eklendi', 'guncellendi', 'durum_guncellendi', 'silindi', 'iptal', 'virman', 'geri_yukleme'
];
if ($entity !== '' && !in_array($entity, $validEntities, true)) $entity = '';
if ($action !== '' && !in_array($action, $validActions, true)) $action = '';

$where = [];
$params = [];
if ($start !== '') { $where[] = 'date(created_at) >= ?'; $params[] = $start; }
if ($end !== '') { $where[] = 'date(created_at) <= ?'; $params[] = $end; }
if ($entity !== '') { $where[] = 'entity_type = ?'; $params[] = $entity; }
if ($action !== '') { $where[] = 'action = ?'; $params[] = $action; }
if ($username !== '') { $where[] = 'username = ?'; $params[] = $username; }
if ($q !== '') {
    $where[] = '(detail LIKE ? OR old_value LIKE ? OR new_value LIKE ? OR entity_type LIKE ? OR action LIKE ? OR username LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $db->prepare('SELECT COUNT(*) FROM audit_logs' . $whereSql);
$countStmt->execute($params);
$auditCount = (int)$countStmt->fetchColumn();

$stmt = $db->prepare('SELECT * FROM audit_logs' . $whereSql . ' ORDER BY id DESC LIMIT 500');
$stmt->execute($params);
$audit = $stmt->fetchAll();

$userRows = $db->query("SELECT username FROM audit_logs WHERE username IS NOT NULL AND username <> '' GROUP BY username ORDER BY username ASC")->fetchAll();

$today = date('Y-m-d');
$todayAudit = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE date(created_at) = date('now','localtime')")->fetchColumn();
$lastAuditAt = $db->query('SELECT created_at FROM audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();

$sysWhere = [];
$sysParams = [];
if ($start !== '') { $sysWhere[] = 'date(created_at) >= ?'; $sysParams[] = $start; }
if ($end !== '') { $sysWhere[] = 'date(created_at) <= ?'; $sysParams[] = $end; }
if ($username !== '') { $sysWhere[] = 'username = ?'; $sysParams[] = $username; }
if ($q !== '') {
    $sysWhere[] = '(action LIKE ? OR detail LIKE ? OR username LIKE ?)';
    $like = '%' . $q . '%';
    array_push($sysParams, $like, $like, $like);
}
$sysWhereSql = $sysWhere ? (' WHERE ' . implode(' AND ', $sysWhere)) : '';
$sysStmt = $db->prepare('SELECT * FROM logs' . $sysWhereSql . ' ORDER BY id DESC LIMIT 200');
$sysStmt->execute($sysParams);
$logs = $sysStmt->fetchAll();

page_header('Loglar', 'loglar');
?>

<section class="hero-card compact-hero audit-hero">
  <div>
    <span class="status-pill">Minimal işlem izi</span>
    <h2>Muhasebe sonucunu değiştiren işlemler burada.</h2>
    <p>Sayfa gezme, arama, filtreleme ve rapor açma loglanmaz. Sadece cari, hareket, çek, kasa/banka, özel alacak, kullanıcı ve yedek gibi kritik değişiklikler tutulur.</p>
  </div>
</section>

<section class="stats-grid three" style="margin-top:0">
  <article class="stat-card"><span>Filtrelenen kayıt</span><strong><?php echo e($auditCount); ?></strong><small>En fazla son 500 satır gösterilir</small></article>
  <article class="stat-card soft"><span>Bugünkü kritik işlem</span><strong><?php echo e($todayAudit); ?></strong><small><?php echo e(tr_date($today)); ?></small></article>
  <article class="stat-card special"><span>Son işlem</span><strong><?php echo $lastAuditAt ? e(tr_datetime($lastAuditAt)) : 'Yok'; ?></strong><small>Muhasebe değişiklik izi</small></article>
</section>

<section class="panel-card report-controls">
  <div class="card-head"><h3>Muhasebe değişiklikleri</h3><span>Filtrele ve hızlıca bul</span></div>
  <form method="get" class="filterbar audit-filter">
    <input type="date" name="start" value="<?php echo e($start); ?>" aria-label="Başlangıç tarihi">
    <input type="date" name="end" value="<?php echo e($end); ?>" aria-label="Bitiş tarihi">
    <select name="entity">
      <option value="">Tüm kayıt türleri</option>
      <?php foreach ($validEntities as $opt): ?><option value="<?php echo e($opt); ?>" <?php echo $entity===$opt?'selected':''; ?>><?php echo e(audit_entity_label($opt)); ?></option><?php endforeach; ?>
    </select>
    <select name="action">
      <option value="">Tüm işlem tipleri</option>
      <?php foreach ($validActions as $opt): ?><option value="<?php echo e($opt); ?>" <?php echo $action===$opt?'selected':''; ?>><?php echo e(audit_action_label($opt)); ?></option><?php endforeach; ?>
    </select>
    <select name="username">
      <option value="">Tüm kullanıcılar</option>
      <?php foreach ($userRows as $u): $un = (string)$u['username']; ?><option value="<?php echo e($un); ?>" <?php echo $username===$un?'selected':''; ?>><?php echo e($un); ?></option><?php endforeach; ?>
    </select>
    <input name="q" value="<?php echo e($q); ?>" placeholder="Cari, tutar, açıklama ara">
    <button class="btn btn-primary" type="submit">Filtrele</button>
    <a class="btn btn-secondary" href="loglar.php">Temizle</a>
  </form>
</section>

<section class="panel-card">
  <div class="card-head"><h3>Kritik işlem listesi</h3><span><?php echo e(min($auditCount, 500)); ?> satır</span></div>
  <div class="table-wrap audit-table">
    <table>
      <thead><tr><th>Tarih</th><th>Kullanıcı</th><th>Kayıt</th><th>İşlem</th><th>Önce</th><th>Sonra</th><th>IP</th></tr></thead>
      <tbody>
        <?php if(!$audit): ?><tr><td colspan="7" class="empty">Bu filtreye uygun audit kaydı yok.</td></tr><?php endif; ?>
        <?php foreach($audit as $a): ?>
          <tr>
            <td><?php echo e(tr_datetime($a['created_at'])); ?></td>
            <td><?php echo e($a['username'] ?: '-'); ?></td>
            <td><strong><?php echo e(audit_entity_label($a['entity_type'])); ?></strong><small>#<?php echo e($a['entity_id'] ?: '-'); ?> <?php echo e($a['detail'] ?: ''); ?></small></td>
            <td><?php echo badge(audit_action_label($a['action']), audit_action_tone($a['action'])); ?></td>
            <td class="audit-diff"><small><?php echo e(audit_short($a['old_value'])); ?></small></td>
            <td class="audit-diff"><small><?php echo e(audit_short($a['new_value'])); ?></small></td>
            <td><?php echo e($a['ip']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel-card report-block muted-logs">
  <div class="card-head"><h3>Sistem logları</h3><span>Giriş, çıkış, yedek gibi yardımcı kayıtlar</span></div>
  <div class="security-note" style="margin-bottom:14px"><strong>Not:</strong> Burası muhasebe sonucunu değil, sistem hareketlerini gösterir. Karışıklık olmasın diye en fazla son 200 satır listelenir.</div>
  <div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Kullanıcı</th><th>İşlem</th><th>Detay</th><th>IP</th></tr></thead><tbody><?php if(!$logs): ?><tr><td colspan="5" class="empty">Sistem log kaydı yok.</td></tr><?php endif; ?><?php foreach($logs as $l): ?><tr><td><?php echo e(tr_datetime($l['created_at'])); ?></td><td><?php echo e($l['username'] ?: '-'); ?></td><td><strong><?php echo e($l['action']); ?></strong></td><td><?php echo e($l['detail']); ?></td><td><?php echo e($l['ip']); ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<?php page_footer(); ?>
