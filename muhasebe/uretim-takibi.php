<?php
require_once __DIR__ . '/layout.php';
require_login();
require_write();

function uretim_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS production_machines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_code TEXT NOT NULL,
        machine_no TEXT NOT NULL,
        default_article TEXT,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(group_code, machine_no),
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS production_daily_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        production_date TEXT NOT NULL,
        machine_id INTEGER NOT NULL,
        article TEXT,
        produced_dozen REAL NOT NULL DEFAULT 0,
        defective_qty INTEGER NOT NULL DEFAULT 0,
        note TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(production_date, machine_id),
        FOREIGN KEY(machine_id) REFERENCES production_machines(id) ON DELETE RESTRICT,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_daily_date ON production_daily_entries(production_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_machine_group ON production_machines(group_code, sort_order, machine_no)');
}

function uretim_group(string $value): string
{
    $value = strtoupper(trim($value));
    return in_array($value, ['A','B','C','D','E'], true) ? $value : 'A';
}

uretim_db_ensure();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_machine') {
        $group = uretim_group((string)($_POST['group_code'] ?? 'A'));
        $machineNo = trim((string)($_POST['machine_no'] ?? ''));
        $article = trim((string)($_POST['default_article'] ?? ''));
        if ($machineNo === '') {
            flash('error', 'Makine numarası zorunlu.');
            redirect('uretim-takibi.php');
        }
        try {
            $sortStmt = db()->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM production_machines WHERE group_code=?');
            $sortStmt->execute([$group]);
            $sort = (int)$sortStmt->fetchColumn();
            db()->prepare('INSERT INTO production_machines (group_code,machine_no,default_article,sort_order,is_active,created_by,created_at,updated_at) VALUES (?,?,?,?,1,?,?,?)')
                ->execute([$group, $machineNo, $article ?: null, $sort, current_user()['id'] ?? null, now(), now()]);
            flash('success', $group . ' grubuna ' . $machineNo . ' numaralı makine eklendi.');
        } catch (Throwable $e) {
            flash('error', 'Makine eklenemedi. Aynı makine bu grupta kayıtlı olabilir.');
        }
        redirect('uretim-takibi.php');
    }

    if ($action === 'toggle_machine') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT is_active FROM production_machines WHERE id=?');
        $stmt->execute([$id]);
        $active = $stmt->fetchColumn();
        if ($active !== false) {
            db()->prepare('UPDATE production_machines SET is_active=?, updated_at=? WHERE id=?')
                ->execute([(int)$active === 1 ? 0 : 1, now(), $id]);
            flash('success', 'Makine durumu güncellendi.');
        }
        redirect('uretim-takibi.php');
    }

    if ($action === 'save_day') {
        $date = trim((string)($_POST['production_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
        $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $machineCheck = $pdo->prepare('SELECT id FROM production_machines WHERE id=? AND is_active=1');
            $existing = $pdo->prepare('SELECT id FROM production_daily_entries WHERE production_date=? AND machine_id=?');
            $insert = $pdo->prepare('INSERT INTO production_daily_entries (production_date,machine_id,article,produced_dozen,defective_qty,note,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
            $update = $pdo->prepare('UPDATE production_daily_entries SET article=?, produced_dozen=?, defective_qty=?, note=?, updated_at=? WHERE id=?');
            foreach ($rows as $machineId => $row) {
                $machineId = (int)$machineId;
                if ($machineId <= 0 || !is_array($row)) continue;
                $machineCheck->execute([$machineId]);
                if (!$machineCheck->fetchColumn()) continue;
                $article = trim((string)($row['article'] ?? ''));
                $dozen = max(0, decimal_from_input($row['produced_dozen'] ?? 0));
                $defective = max(0, (int)preg_replace('/\D+/', '', (string)($row['defective_qty'] ?? '0')));
                $note = trim((string)($row['note'] ?? ''));
                $existing->execute([$date, $machineId]);
                $entryId = (int)($existing->fetchColumn() ?: 0);
                if ($entryId > 0) {
                    $update->execute([$article ?: null, $dozen, $defective, $note ?: null, now(), $entryId]);
                } elseif ($dozen > 0 || $defective > 0 || $article !== '' || $note !== '') {
                    $insert->execute([$date, $machineId, $article ?: null, $dozen, $defective, $note ?: null, current_user()['id'] ?? null, now(), now()]);
                }
            }
            $pdo->commit();
            flash('success', date('d.m.Y', strtotime($date)) . ' üretim kayıtları kaydedildi.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Üretim kayıtları kaydedilemedi: ' . $e->getMessage());
        }
        redirect('uretim-takibi.php?date=' . urlencode($date));
    }
}

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$machines = db()->query("SELECT * FROM production_machines ORDER BY CASE group_code WHEN 'A' THEN 1 WHEN 'B' THEN 2 WHEN 'C' THEN 3 WHEN 'D' THEN 4 ELSE 5 END, sort_order, machine_no")->fetchAll();
$entryStmt = db()->prepare('SELECT * FROM production_daily_entries WHERE production_date=?');
$entryStmt->execute([$date]);
$entries = [];
foreach ($entryStmt->fetchAll() as $entry) $entries[(int)$entry['machine_id']] = $entry;

$groups = ['A'=>[], 'B'=>[], 'C'=>[], 'D'=>[], 'E'=>[]];
foreach ($machines as $machine) $groups[uretim_group((string)$machine['group_code'])][] = $machine;

$totalDozen = 0.0;
$totalDefective = 0;
foreach ($entries as $entry) {
    $totalDozen += (float)$entry['produced_dozen'];
    $totalDefective += (int)$entry['defective_qty'];
}

page_header('Üretim Takibi', 'uretim_takibi');
?>
<script src="assets/uretim-hizli-giris.js?v=3"></script>
<script src="assets/uretim-vardiya-ayri-kaydet.js?v=3"></script>
<style>
.stock-follow-link{display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:18px;padding:15px 17px;border:1px solid #b9d5c0;border-radius:16px;background:linear-gradient(135deg,#eff8f1,#fff)}.stock-follow-link h3{margin:0;color:#173f29}.stock-follow-link p{margin:4px 0 0;color:#657168;font-size:12px}.stock-follow-link a{white-space:nowrap}.production-head{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:18px}.production-head p{margin:5px 0 0;color:#657168}.production-date{display:flex;gap:8px;align-items:flex-end}.production-date label{font-size:12px;font-weight:850;color:#59665e}.production-date input{display:block;margin-top:5px;height:42px;border:1px solid #d7e0d9;border-radius:10px;padding:8px 11px}.production-date button{height:42px;border:0;border-radius:10px;background:#183f29;color:#fff;padding:0 15px;font-weight:900}.production-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}.production-summary article{padding:16px;border:1px solid #dde6df;border-radius:16px;background:#fff}.production-summary span{display:block;color:#6b776f;font-size:12px;font-weight:800}.production-summary strong{display:block;margin-top:5px;font-size:25px;color:#183f29}.production-groups{display:grid;gap:16px}.production-group{border:1px solid #dce5de;border-radius:18px;background:#fff;overflow:hidden}.production-group-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:#f2f7f3}.production-group-head h2{margin:0;font-size:18px;color:#183f29}.production-group-head strong{font-size:13px;color:#526158}.production-table-wrap{overflow:auto}.production-table{width:100%;border-collapse:collapse;min-width:850px}.production-table th,.production-table td{padding:10px;border-bottom:1px solid #edf1ee;text-align:left}.production-table th{font-size:10px;text-transform:uppercase;color:#6b776f;background:#fbfcfb}.production-table input{width:100%;height:40px;border:1px solid #dbe3dd;border-radius:9px;padding:7px 9px}.production-table .machine-no{font-weight:900;color:#183f29}.production-table .number{max-width:120px}.production-table .net-cell{font-weight:900;color:#176536;white-space:nowrap}.production-empty{padding:22px;color:#6a766e;text-align:center}.production-actions{position:sticky;bottom:0;display:flex;justify-content:flex-end;padding:14px 0;margin-top:14px;background:linear-gradient(transparent,#f7f9f7 25%)}.production-actions button{border:0;border-radius:12px;background:#183f29;color:#fff;padding:12px 20px;font-weight:900}.machine-add{margin-top:20px;padding:16px;border:1px dashed #bfcfc3;border-radius:16px;background:#f8fbf9}.machine-add h3{margin:0 0 10px}.machine-add-grid{display:grid;grid-template-columns:120px 1fr 1fr auto;gap:10px}.machine-add select,.machine-add input{height:42px;border:1px solid #d7e0d9;border-radius:10px;padding:8px 10px}.machine-add button{border:0;border-radius:10px;background:#e8f2eb;color:#183f29;padding:0 16px;font-weight:900}.machine-list{margin-top:12px;display:flex;flex-wrap:wrap;gap:7px}.machine-chip{display:inline-flex;gap:7px;align-items:center;border:1px solid #dce5de;border-radius:999px;padding:5px 9px;background:#fff;font-size:12px}.machine-chip form{margin:0}.machine-chip button{border:0;background:transparent;color:#9a3f3f;cursor:pointer;font-weight:900}@media(max-width:760px){.production-head{align-items:stretch;flex-direction:column}.production-date{align-items:stretch}.production-date label{flex:1}.production-date input{width:100%}.production-summary{grid-template-columns:1fr}.machine-add-grid{grid-template-columns:1fr}.production-actions{padding-bottom:80px}.production-group{border-radius:14px}.production-table{min-width:720px}}
.shift-entry-box{margin:0 0 20px;border:1px solid #d8e4db;border-radius:18px;background:#fff;overflow:hidden}.shift-main-head{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:17px 18px;background:#eef7f0}.shift-main-head h2{margin:0;color:#173f29}.shift-main-head p{margin:4px 0 0;color:#657168}.day-total{text-align:right}.day-total span,.day-total small{display:block;color:#627067;font-size:12px}.day-total strong{display:block;color:#173f29;font-size:23px}.shift-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:16px}.shift-card{border:1px solid #dde6df;border-radius:15px;overflow:hidden}.shift-card header{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:13px 14px;background:#f7faf8}.shift-card h3{margin:0;color:#173f29}.shift-card header div{text-align:right}.shift-card header strong,.shift-card header span{display:block}.shift-card header span{font-size:12px;color:#6a766e}.shift-table{width:100%;border-collapse:collapse}.shift-table th,.shift-table td{padding:10px;border-bottom:1px solid #edf1ee;text-align:left}.shift-table thead th{font-size:10px;text-transform:uppercase;color:#68766d;background:#fbfcfb}.shift-table tbody th{width:80px}.fixed-group{display:inline-flex;width:36px;height:36px;border-radius:10px;align-items:center;justify-content:center;background:#e7f2ea;color:#173f29;font-weight:950}.shift-table input{width:100%;height:42px;border:1px solid #d7e1d9;border-radius:9px;padding:8px 10px;font-size:16px}.shift-card-save{padding:14px}.shift-card-save button{width:100%;border:0;border-radius:11px;background:#173f29;color:#fff;padding:12px 16px;font-weight:900;cursor:pointer}.shift-card-save button:disabled{opacity:.65;cursor:wait}.production-report{display:grid;grid-template-columns:minmax(220px,.7fr) minmax(420px,1.6fr) minmax(220px,.7fr);gap:16px;margin-bottom:24px}.selected-month-card,.year-total-card,.month-table-card{border:1px solid #dce5de;border-radius:17px;background:#fff;padding:17px}.selected-month-card,.year-total-card{display:flex;flex-direction:column;justify-content:center}.selected-month-card span,.year-total-card span{font-weight:850;color:#647168}.selected-month-card strong,.year-total-card strong{font-size:27px;color:#173f29;margin:7px 0}.selected-month-card small,.year-total-card small{color:#6b776f}.month-table-card h2{margin:0 0 12px;color:#173f29}.month-table-wrap{overflow:auto}.month-table{width:100%;border-collapse:collapse}.month-table th,.month-table td{padding:9px 10px;border-bottom:1px solid #edf1ee;text-align:left}.month-table thead th{font-size:10px;text-transform:uppercase;color:#68766d}.month-table .selected-month{background:#eef7f0}.month-table .selected-month th{color:#173f29}@media(max-width:900px){.shift-grid{grid-template-columns:1fr}.production-report{grid-template-columns:1fr}.day-total{text-align:left}.shift-main-head{align-items:flex-start;flex-direction:column}}@media(max-width:600px){.shift-grid{padding:10px}.shift-main-head{padding:14px}.shift-table th,.shift-table td{padding:8px}.shift-card-save{padding:10px}.shift-card-save button{min-height:48px}.production-report{gap:10px}}
</style>

<section class="production-head">
  <div><h2 style="margin:0">Günlük makine üretimi</h2><p>A–E gruplarındaki makine üretimini, düzine miktarını ve defolu adedini tek ekrandan kaydet.</p></div>
  <form method="get" class="production-date">
    <label>Tarih<input type="date" name="date" value="<?php echo e($date); ?>"></label>
    <button type="submit">Günü aç</button>
  </form>
</section>

<section class="stock-follow-link"><div><h3>Stok Takibi</h3><p>Ürün artikellerini, başlangıç stoklarını ve satışlardan otomatik düşen düzine miktarlarını takip et.</p></div><a class="btn btn-primary" href="stok-takibi.php">Stok Takibini Aç</a></section>

<div data-shift-production-entry>
  <section class="shift-entry-box">
    <header class="shift-main-head"><div><h2>Günlük üretim girişi</h2><p>Gündüz ve gece vardiyasını ayrı ayrı kaydet.</p></div><div class="day-total"><span>Gün toplamı</span><strong data-day-total-dz>0,00 DZ</strong><small data-day-total-def>0 adet</small></div></header>
    <form method="post" action="uretim-hizli-kaydet.php" data-shift-form>
      <?php echo csrf_field(); ?><input type="hidden" name="production_date" value="<?php echo e($date); ?>">
      <div class="shift-grid">
        <?php foreach (['gunduz'=>'Gündüz Vardiyası','gece'=>'Gece Vardiyası'] as $shiftCode => $shiftTitle): ?>
        <section class="shift-card" data-shift="<?php echo e($shiftCode); ?>">
          <header><h3><?php echo e($shiftTitle); ?></h3><div><strong data-shift-dozen>0,00 DZ</strong><span data-shift-defective>0 defolu</span></div></header>
          <table class="shift-table"><thead><tr><th>Grup</th><th>Üretim (DZ)</th><th>Defolu (Adet)</th></tr></thead><tbody>
          <?php foreach (['A','B','C','D','E'] as $groupCode): ?>
            <tr data-shift-row="<?php echo e($shiftCode . '-' . $groupCode); ?>"><th scope="row"><span class="fixed-group"><?php echo e($groupCode); ?></span></th><td><input data-dozen inputmode="decimal" placeholder="0,00" autocomplete="off"></td><td><input data-defective inputmode="numeric" placeholder="0" autocomplete="off"></td></tr>
          <?php endforeach; ?>
          </tbody></table>
          <div class="shift-card-save"><button type="button" data-save-one-shift="<?php echo e($shiftCode); ?>"><?php echo e($shiftTitle); ?>nı Kaydet</button></div>
        </section>
        <?php endforeach; ?>
      </div>
    </form>
  </section>
  <section class="production-report">
    <div class="selected-month-card"><span data-selected-month-title>Seçili ay üretimi</span><strong data-selected-month-dz>0,00 DZ</strong><small data-selected-month-def>0 defolu</small></div>
    <div class="month-table-card"><h2>Ay ay üretim</h2><div class="month-table-wrap"><table class="month-table"><thead><tr><th>Ay</th><th>Üretim</th><th>Defolu</th></tr></thead><tbody data-month-body><?php foreach (['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'] as $monthName): ?><tr><th><?php echo e($monthName); ?></th><td>0,00 DZ</td><td>0 adet</td></tr><?php endforeach; ?></tbody></table></div></div>
    <div class="year-total-card"><span data-year-title>Yıl toplamı</span><strong data-year-dz>0,00 DZ</strong><small data-year-def>0 defolu</small></div>
  </section>
</div>

<div hidden aria-hidden="true">
<section class="production-summary">
  <article><span>Toplam üretim</span><strong data-total-dozen><?php echo number_format($totalDozen, 2, ',', '.'); ?> DZ</strong></article>
  <article><span>Toplam defolu</span><strong data-total-defective><?php echo number_format($totalDefective, 0, ',', '.'); ?> adet</strong></article>
  <article><span>Çalışan makine</span><strong data-active-count>0</strong></article>
</section>

<form method="post" data-production-form>
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="save_day">
  <input type="hidden" name="production_date" value="<?php echo e($date); ?>">
  <div class="production-groups">
  <?php foreach ($groups as $groupCode => $groupMachines): ?>
    <?php $activeGroup = array_values(array_filter($groupMachines, function($m){ return (int)$m['is_active'] === 1; })); ?>
    <section class="production-group" data-group="<?php echo e($groupCode); ?>">
      <header class="production-group-head"><h2><?php echo e($groupCode); ?> Grubu</h2><strong data-group-total>0,00 DZ · 0 defolu</strong></header>
      <?php if (!$activeGroup): ?>
        <div class="production-empty">Bu gruba henüz aktif makine eklenmedi.</div>
      <?php else: ?>
      <div class="production-table-wrap"><table class="production-table" data-mobile-table="scroll">
        <thead><tr><th>Makine</th><th>Ürün / Artikel</th><th>Üretim (DZ)</th><th>Defolu (Adet)</th><th>Net üretim</th><th>Açıklama</th></tr></thead>
        <tbody>
        <?php foreach ($activeGroup as $machine): $mid=(int)$machine['id']; $entry=$entries[$mid] ?? null; ?>
          <tr data-production-row>
            <td class="machine-no"><?php echo e($machine['machine_no']); ?></td>
            <td><input name="rows[<?php echo $mid; ?>][article]" value="<?php echo e($entry['article'] ?? $machine['default_article'] ?? ''); ?>" placeholder="Artikel / ürün"></td>
            <td><input class="number" data-dozen inputmode="decimal" name="rows[<?php echo $mid; ?>][produced_dozen]" value="<?php echo e(isset($entry['produced_dozen']) ? str_replace('.', ',', (string)$entry['produced_dozen']) : ''); ?>" placeholder="0,00"></td>
            <td><input class="number" data-defective inputmode="numeric" name="rows[<?php echo $mid; ?>][defective_qty]" value="<?php echo e(isset($entry['defective_qty']) ? (string)$entry['defective_qty'] : ''); ?>" placeholder="0"></td>
            <td class="net-cell" data-net>0,00 DZ</td>
            <td><input name="rows[<?php echo $mid; ?>][note]" value="<?php echo e($entry['note'] ?? ''); ?>" placeholder="İsteğe bağlı"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>
  <div class="production-actions"><button type="submit">Günlük üretimi kaydet</button></div>
</form>

<section class="machine-add">
  <h3>Makine şablonu</h3>
  <form method="post" class="machine-add-grid">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="add_machine">
    <select name="group_code"><?php foreach (['A','B','C','D','E'] as $g): ?><option value="<?php echo $g; ?>"><?php echo $g; ?> Grubu</option><?php endforeach; ?></select>
    <input name="machine_no" placeholder="Makine no" required>
    <input name="default_article" placeholder="Varsayılan artikel (isteğe bağlı)">
    <button type="submit">Makine ekle</button>
  </form>
  <?php if ($machines): ?><div class="machine-list">
    <?php foreach ($machines as $machine): ?><span class="machine-chip"><?php echo e($machine['group_code'] . ' · ' . $machine['machine_no']); ?><?php if (!(int)$machine['is_active']): ?> (pasif)<?php endif; ?><form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle_machine"><input type="hidden" name="id" value="<?php echo (int)$machine['id']; ?>"><button type="submit" title="Aktif/pasif değiştir"><?php echo (int)$machine['is_active'] ? '×' : '↺'; ?></button></form></span><?php endforeach; ?>
  </div><?php endif; ?>
</section>

<script>
(function(){
  var form=document.querySelector('[data-production-form]'); if(!form)return;
  var fmt=new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2});
  function num(v){v=String(v||'').trim().replace(/\s/g,'');if(v.indexOf(',')>=0)v=v.replace(/\./g,'').replace(',','.');var n=parseFloat(v);return Number.isFinite(n)?n:0;}
  function recalc(){var td=0,tf=0,active=0;document.querySelectorAll('.production-group').forEach(function(group){var gd=0,gf=0;group.querySelectorAll('[data-production-row]').forEach(function(row){var d=Math.max(0,num(row.querySelector('[data-dozen]').value));var f=Math.max(0,parseInt(row.querySelector('[data-defective]').value||'0',10)||0);var net=Math.max(0,d-(f/12));row.querySelector('[data-net]').textContent=fmt.format(net)+' DZ';gd+=d;gf+=f;if(d>0||f>0)active++;});var out=group.querySelector('[data-group-total]');if(out)out.textContent=fmt.format(gd)+' DZ · '+gf+' defolu';td+=gd;tf+=gf;});document.querySelector('[data-total-dozen]').textContent=fmt.format(td)+' DZ';document.querySelector('[data-total-defective]').textContent=tf+' adet';document.querySelector('[data-active-count]').textContent=String(active);}
  form.addEventListener('input',recalc);recalc();
})();
</script>
</div>
<?php page_footer(); ?>
