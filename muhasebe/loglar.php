<?php
require_once __DIR__ . '/layout.php';
require_admin();

$db = db();

function log_audit_entity_label(string $type): string
{
    return [
        'magaza_gunluk_satis' => 'Mağaza Günlük Satışı',
        'magaza_odeme_dagilimi' => 'Mağaza Ödeme Dağılımı',
    ][$type] ?? audit_entity_label($type);
}

function log_audit_decode(?string $json): ?array
{
    if ($json === null || trim($json) === '') return null;
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function log_audit_specs(string $entityType): array
{
    if ($entityType === 'magaza_gunluk_satis') {
        return [
            'sale_date' => ['label' => 'Satış tarihi', 'type' => 'date'],
            'gross_amount' => ['label' => 'Toplam satış', 'type' => 'amount'],
            'subtotal' => ['label' => 'KDV hariç satış', 'type' => 'amount'],
            'vat_rate' => ['label' => 'KDV oranı', 'type' => 'percent'],
            'vat_amount' => ['label' => 'KDV tutarı', 'type' => 'amount'],
            'note' => ['label' => 'Not', 'type' => 'text'],
        ];
    }

    if ($entityType === 'magaza_odeme_dagilimi') {
        return [
            'sale_date' => ['label' => 'Satış tarihi', 'type' => 'date'],
            'cash_amount' => ['label' => 'Nakit satış', 'type' => 'amount'],
            'card_amount' => ['label' => 'Kart / POS satışı', 'type' => 'amount'],
            'credit_amount' => ['label' => 'Veresiye satış', 'type' => 'amount'],
            'cash_credit_collection_amount' => ['label' => 'Nakit veresiye tahsilatı', 'type' => 'amount'],
            'card_credit_collection_amount' => ['label' => 'Kart veresiye tahsilatı', 'type' => 'amount'],
            'credit_collection_amount' => ['label' => 'Toplam veresiye tahsilatı', 'type' => 'amount'],
            'cash_change_left_amount' => ['label' => 'Kasada bırakılan bozuk para', 'type' => 'amount'],
            'daily_total' => ['label' => 'Günlük satış toplamı', 'type' => 'amount'],
        ];
    }

    return [];
}

function log_audit_hidden_field(string $key): bool
{
    return in_array($key, [
        'id', 'created_by', 'created_at', 'updated_by', 'updated_at',
        'cash_movement_id', 'card_movement_id', 'movement_id', 'account_id',
        'document_path', 'document_mime', 'password_hash',
    ], true);
}

function log_audit_field_label(string $entityType, string $key): string
{
    $specs = log_audit_specs($entityType);
    if (isset($specs[$key]['label'])) return $specs[$key]['label'];

    return [
        'name' => 'Ad',
        'amount' => 'Tutar',
        'description' => 'Açıklama',
        'status' => 'Durum',
        'movement_date' => 'İşlem tarihi',
        'due_date' => 'Vade tarihi',
        'payment_method' => 'Ödeme yöntemi',
        'document_name' => 'Belge adı',
        'tax_type' => 'Vergi türü',
        'tax_period' => 'Vergilendirme dönemi',
        'document_no' => 'Belge numarası',
        'paid_date' => 'Ödeme tarihi',
        'role' => 'Kullanıcı rolü',
        'is_active' => 'Aktiflik',
        'note' => 'Not',
    ][$key] ?? ucwords(str_replace('_', ' ', $key));
}

function log_audit_field_type(string $entityType, string $key): string
{
    $specs = log_audit_specs($entityType);
    if (isset($specs[$key]['type'])) return $specs[$key]['type'];
    if ($key === 'vat_rate' || str_ends_with($key, '_rate')) return 'percent';
    if ($key === 'is_active' || str_starts_with($key, 'is_')) return 'boolean';
    if ($key === 'sale_date' || $key === 'movement_date' || $key === 'due_date' || $key === 'paid_date' || str_ends_with($key, '_date')) return 'date';
    if (str_ends_with($key, '_at')) return 'datetime';
    if ($key === 'amount' || str_contains($key, 'amount') || str_contains($key, 'subtotal') || str_contains($key, 'total')) return 'amount';
    return 'text';
}

function log_audit_human_value(string $entityType, string $key, $value): string
{
    if ($value === null || $value === '') return 'Boş';
    $type = log_audit_field_type($entityType, $key);

    if ($type === 'amount' && is_numeric($value)) return money((float)$value);
    if ($type === 'percent' && is_numeric($value)) return '%' . number_format((float)$value, 2, ',', '.');
    if ($type === 'date') return tr_date((string)$value);
    if ($type === 'datetime') return tr_datetime((string)$value);
    if ($type === 'boolean') return ((int)$value === 1) ? 'Evet' : 'Hayır';
    if (is_bool($value)) return $value ? 'Evet' : 'Hayır';
    if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';

    return trim((string)$value) !== '' ? (string)$value : 'Boş';
}

function log_audit_values_equal($before, $after): bool
{
    if (is_numeric($before) && is_numeric($after)) {
        return abs((float)$before - (float)$after) < 0.00001;
    }
    return $before === $after;
}

function log_audit_visible_keys(string $entityType, ?array $before, ?array $after): array
{
    $specs = log_audit_specs($entityType);
    if ($specs) return array_keys($specs);

    $keys = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));
    return array_values(array_filter($keys, fn($key) => !log_audit_hidden_field((string)$key)));
}

function log_audit_snapshot(string $entityType, ?string $json, int $limit = 9): array
{
    $data = log_audit_decode($json);
    if (!$data) return [];

    $rows = [];
    foreach (log_audit_visible_keys($entityType, $data, null) as $key) {
        if (!array_key_exists($key, $data)) continue;
        $value = $data[$key];
        if (($value === null || $value === '') && $key !== 'note') continue;
        $rows[] = [
            'label' => log_audit_field_label($entityType, (string)$key),
            'value' => log_audit_human_value($entityType, (string)$key, $value),
        ];
        if (count($rows) >= $limit) break;
    }
    return $rows;
}

function log_audit_changes(array $auditRow, int $limit = 9): array
{
    $entityType = (string)($auditRow['entity_type'] ?? '');
    $action = (string)($auditRow['action'] ?? '');
    $before = log_audit_decode($auditRow['old_value'] ?? null);
    $after = log_audit_decode($auditRow['new_value'] ?? null);
    $rows = [];

    foreach (log_audit_visible_keys($entityType, $before, $after) as $key) {
        $hasBefore = is_array($before) && array_key_exists($key, $before);
        $hasAfter = is_array($after) && array_key_exists($key, $after);
        $beforeValue = $hasBefore ? $before[$key] : null;
        $afterValue = $hasAfter ? $after[$key] : null;

        if ($action === 'guncellendi' || $action === 'durum_guncellendi') {
            if (!$hasBefore && !$hasAfter) continue;
            if ($hasBefore && $hasAfter && log_audit_values_equal($beforeValue, $afterValue)) continue;
        } elseif ($action === 'eklendi') {
            if (!$hasAfter || ($afterValue === null || $afterValue === '')) continue;
        } elseif ($action === 'silindi' || $action === 'iptal') {
            if (!$hasBefore || ($beforeValue === null || $beforeValue === '')) continue;
        } elseif ($hasBefore && $hasAfter && log_audit_values_equal($beforeValue, $afterValue)) {
            continue;
        }

        $rows[] = [
            'label' => log_audit_field_label($entityType, (string)$key),
            'before' => $hasBefore ? log_audit_human_value($entityType, (string)$key, $beforeValue) : 'Yok',
            'after' => $hasAfter ? log_audit_human_value($entityType, (string)$key, $afterValue) : (($action === 'silindi' || $action === 'iptal') ? 'Silindi' : 'Boş'),
        ];
        if (count($rows) >= $limit) break;
    }

    return $rows;
}

$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');
$entity = trim($_GET['entity'] ?? '');
$action = trim($_GET['action'] ?? '');
$username = trim($_GET['username'] ?? '');
$q = trim($_GET['q'] ?? '');

$validEntities = [
    'cari', 'hareket', 'cek', 'ozel_alacak', 'hesap', 'hesap_hareketi', 'kullanici', 'yedek', 'kategori',
    'magaza_gunluk_satis', 'magaza_odeme_dagilimi',
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
    <span class="status-pill">Anlaşılır işlem izi</span>
    <h2>Muhasebe sonucunu değiştiren işlemler burada.</h2>
    <p>Mağaza satışlarında nakit, kart/POS, veresiye ve toplam tutarlardaki değişiklikler eski ve yeni değerleriyle Türkçe olarak gösterilir.</p>
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
      <?php foreach ($validEntities as $opt): ?><option value="<?php echo e($opt); ?>" <?php echo $entity===$opt?'selected':''; ?>><?php echo e(log_audit_entity_label($opt)); ?></option><?php endforeach; ?>
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
  <div class="table-wrap audit-table audit-table-human">
    <table>
      <thead><tr><th>Tarih</th><th>Kullanıcı</th><th>Kayıt</th><th>İşlem</th><th>Ne değişti?</th><th>Önce</th><th>Sonra</th><th>IP</th></tr></thead>
      <tbody>
        <?php if(!$audit): ?><tr><td colspan="8" class="empty">Bu filtreye uygun audit kaydı yok.</td></tr><?php endif; ?>
        <?php foreach($audit as $a): $changes = log_audit_changes($a); $beforeRows = log_audit_snapshot((string)$a['entity_type'], $a['old_value']); $afterRows = log_audit_snapshot((string)$a['entity_type'], $a['new_value']); ?>
          <tr>
            <td><?php echo e(tr_datetime($a['created_at'])); ?></td>
            <td><?php echo e($a['username'] ?: '-'); ?></td>
            <td><strong><?php echo e(log_audit_entity_label((string)$a['entity_type'])); ?></strong><small>#<?php echo e($a['entity_id'] ?: '-'); ?> <?php echo e($a['detail'] ?: ''); ?></small></td>
            <td><?php echo badge(audit_action_label($a['action']), audit_action_tone($a['action'])); ?></td>
            <td class="audit-change-cell">
              <?php if ($changes): ?>
                <div class="audit-change-list">
                  <?php foreach ($changes as $change): ?>
                    <div class="audit-change-line"><strong><?php echo e($change['label']); ?>:</strong><span><?php echo e($change['before']); ?></span><b>→</b><span><?php echo e($change['after']); ?></span></div>
                  <?php endforeach; ?>
                </div>
              <?php elseif (in_array($a['action'], ['guncellendi','durum_guncellendi'], true)): ?>
                <span class="audit-no-change">Görünen tutar, tarih veya açıklamalarda değişiklik yok. Kayıt yeniden kaydedilmiş olabilir.</span>
              <?php else: ?>
                <span class="muted">Açıklanabilir alan bulunamadı.</span>
              <?php endif; ?>
            </td>
            <td class="audit-diff">
              <?php if ($beforeRows): ?><div class="audit-snapshot"><?php foreach ($beforeRows as $row): ?><div><strong><?php echo e($row['label']); ?>:</strong> <?php echo e($row['value']); ?></div><?php endforeach; ?></div><?php else: ?><small>-</small><?php endif; ?>
            </td>
            <td class="audit-diff">
              <?php if ($afterRows): ?><div class="audit-snapshot"><?php foreach ($afterRows as $row): ?><div><strong><?php echo e($row['label']); ?>:</strong> <?php echo e($row['value']); ?></div><?php endforeach; ?></div><?php else: ?><small>-</small><?php endif; ?>
            </td>
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

<style>
.audit-table-human table{min-width:1450px}.audit-change-cell{min-width:330px;max-width:460px}.audit-change-list,.audit-snapshot{display:grid;gap:5px}.audit-change-line{display:grid;grid-template-columns:minmax(120px,auto) minmax(90px,1fr) 18px minmax(90px,1fr);gap:5px;align-items:start;padding:5px 7px;border-radius:8px;background:#f7f4ed;font-size:11px;line-height:1.35}.audit-change-line strong{color:#173e2b}.audit-change-line b{text-align:center;color:#8c7252}.audit-change-line span:last-child{font-weight:800;color:#173e2b}.audit-snapshot{min-width:220px;font-size:10px;line-height:1.35}.audit-snapshot div{padding-bottom:3px;border-bottom:1px dashed #e2d8c8}.audit-snapshot div:last-child{border-bottom:0}.audit-snapshot strong{color:#594a36}.audit-no-change{display:block;padding:8px 10px;border-radius:9px;background:#fff4db;color:#7b540d;font-size:11px;line-height:1.4}@media(max-width:760px){.audit-change-line{grid-template-columns:1fr}.audit-change-line b{display:none}.audit-table-human table{min-width:1250px}}
</style>
<?php page_footer(); ?>
