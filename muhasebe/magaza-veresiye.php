<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/magaza-odeme-dagilim-lib.php';
require_login();

function pv_normalize_name($name)
{
    $name = preg_replace('/\s+/u', ' ', trim((string)$name));
    return mb_strtolower($name, 'UTF-8');
}

function pv_db_ensure()
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_credit_people (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        search_name TEXT NOT NULL UNIQUE,
        notes TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS store_credit_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        person_id INTEGER NOT NULL,
        entry_type TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0,
        entry_date TEXT NOT NULL,
        description TEXT,
        is_cancelled INTEGER NOT NULL DEFAULT 0,
        cancelled_at TEXT,
        cancelled_by INTEGER,
        cancel_reason TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(person_id) REFERENCES store_credit_people(id) ON DELETE RESTRICT,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $columns = $pdo->query('PRAGMA table_info(store_credit_entries)')->fetchAll() ?: [];
    $hasPaymentMethod = $hasDailyBreakdown = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'payment_method') $hasPaymentMethod = true;
        if (($column['name'] ?? '') === 'daily_breakdown_id') $hasDailyBreakdown = true;
    }
    if (!$hasPaymentMethod) $pdo->exec('ALTER TABLE store_credit_entries ADD COLUMN payment_method TEXT');
    if (!$hasDailyBreakdown) $pdo->exec('ALTER TABLE store_credit_entries ADD COLUMN daily_breakdown_id INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_store_credit_person_date ON store_credit_entries(person_id,entry_date)');
}

function pv_daily_sync($date, $type, $paymentMethod, $delta)
{
    magaza_odeme_dagilim_tablosunu_hazirla();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM store_daily_payment_breakdown WHERE sale_date=? LIMIT 1');
    $stmt->execute([$date]);
    $row = $stmt->fetch() ?: null;
    $userId = current_user()['id'] ?? null;

    if (!$row) {
        if ($delta < 0) throw new RuntimeException('Bağlı günlük mağaza kaydı bulunamadı.');
        $pdo->prepare('INSERT INTO store_daily_payment_breakdown (sale_date,cash_amount,card_amount,credit_amount,credit_collection_amount,cash_credit_collection_amount,card_credit_collection_amount,cash_change_left_amount,daily_total,created_by,created_at,updated_by,updated_at) VALUES (?,0,0,0,0,0,0,0,0,?,?,?,?)')
            ->execute([$date,$userId,now(),$userId,now()]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM store_daily_payment_breakdown WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
    }

    $credit = (float)($row['credit_amount'] ?? 0);
    $cashCollection = (float)($row['cash_credit_collection_amount'] ?? 0);
    $cardCollection = (float)($row['card_credit_collection_amount'] ?? 0);
    if ($type === 'debt') $credit = max(0, round($credit + $delta, 2));
    elseif ($paymentMethod === 'cash') $cashCollection = max(0, round($cashCollection + $delta, 2));
    else $cardCollection = max(0, round($cardCollection + $delta, 2));

    $dailyTotal = magaza_odeme_dagilim_gunluk_toplam((float)$row['cash_amount'], (float)$row['card_amount'], $credit);
    $pdo->prepare('UPDATE store_daily_payment_breakdown SET credit_amount=?,credit_collection_amount=?,cash_credit_collection_amount=?,card_credit_collection_amount=?,daily_total=?,updated_by=?,updated_at=? WHERE id=?')
        ->execute([$credit,round($cashCollection+$cardCollection,2),$cashCollection,$cardCollection,$dailyTotal,$userId,now(),$row['id']]);
    magaza_odeme_dagilim_hareketlerini_senkronla((int)$row['id']);
    return (int)$row['id'];
}

function pv_person($id)
{
    $stmt = db()->prepare("SELECT p.*, COALESCE(SUM(CASE WHEN e.is_cancelled=0 AND e.entry_type='debt' THEN e.amount WHEN e.is_cancelled=0 AND e.entry_type='payment' THEN -e.amount ELSE 0 END),0) AS balance
        FROM store_credit_people p LEFT JOIN store_credit_entries e ON e.person_id=p.id WHERE p.id=? GROUP BY p.id LIMIT 1");
    $stmt->execute([(int)$id]);
    return $stmt->fetch() ?: null;
}

pv_db_ensure();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_write();
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'add_person') {
        $name = preg_replace('/\s+/u', ' ', trim((string)($_POST['full_name'] ?? '')));
        $notes = trim((string)($_POST['notes'] ?? ''));
        if (mb_strlen($name, 'UTF-8') < 3) {
            flash('error', 'Personelin adı ve soyadı zorunludur.');
            redirect('magaza-veresiye.php');
        }
        try {
            db()->prepare('INSERT INTO store_credit_people (full_name,search_name,notes,is_active,created_by,created_at,updated_at) VALUES (?,?,?,1,?,?,?)')
                ->execute([$name, pv_normalize_name($name), $notes ?: null, current_user()['id'] ?? null, now(), now()]);
            $id = (int)db()->lastInsertId();
            audit_action('magaza_personel_veresiye', $id, 'personel_eklendi', null, ['full_name'=>$name,'notes'=>$notes], $name);
            flash('success', 'Personel eklendi: ' . $name);
            redirect('magaza-veresiye.php?person=' . $id);
        } catch (Throwable $e) {
            flash('error', 'Bu isimle bir personel daha önce eklenmiş olabilir.');
            redirect('magaza-veresiye.php');
        }
    }

    if ($action === 'add_entry') {
        $personId = (int)($_POST['person_id'] ?? 0);
        $type = (string)($_POST['entry_type'] ?? 'debt');
        $amount = max(0, decimal_from_input($_POST['amount'] ?? 0));
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        $date = trim((string)($_POST['entry_date'] ?? date('Y-m-d')));
        $description = trim((string)($_POST['description'] ?? ''));
        if (!in_array($type, ['debt','payment'], true)) $type = 'debt';
        if ($type === 'payment' && !in_array($paymentMethod, ['cash','card'], true)) {
            flash('error', 'Tahsilat için Nakit veya Kart seçmelisiniz.');
            redirect('magaza-veresiye.php?person=' . $personId);
        }
        if ($type === 'debt') $paymentMethod = '';
        $person = pv_person($personId);
        if (!$person || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash('error', 'Personel, tarih ve tutarı kontrol edin.');
            redirect('magaza-veresiye.php' . ($personId ? '?person='.$personId : ''));
        }
        if ($type === 'payment' && $amount > (float)$person['balance'] + 0.004) {
            flash('error', 'Tahsilat, personelin kalan borcundan fazla olamaz.');
            redirect('magaza-veresiye.php?person=' . $personId);
        }
        db()->beginTransaction();
        try {
            $dailyBreakdownId = pv_daily_sync($date, $type, $paymentMethod, $amount);
            db()->prepare('INSERT INTO store_credit_entries (person_id,entry_type,amount,entry_date,payment_method,daily_breakdown_id,description,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$personId, $type, $amount, $date, $paymentMethod ?: null, $dailyBreakdownId, $description ?: ($type === 'debt' ? 'Mağaza veresiye alışverişi' : 'Personelden ' . ($paymentMethod === 'cash' ? 'nakit' : 'kart') . ' tahsilat'), current_user()['id'] ?? null, now(), now()]);
            $entryId = (int)db()->lastInsertId();
            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
        audit_action('magaza_personel_veresiye_hareketi', $entryId, 'eklendi', null, ['person_id'=>$personId,'type'=>$type,'amount'=>$amount,'payment_method'=>$paymentMethod,'date'=>$date,'description'=>$description], $person['full_name']);
        flash('success', $type === 'debt' ? 'Veresiye alışveriş kaydedildi.' : 'Tahsilat kaydedildi.');
        redirect('magaza-veresiye.php?person=' . $personId);
    }

    if ($action === 'cancel_entry') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT e.*,p.full_name FROM store_credit_entries e JOIN store_credit_people p ON p.id=e.person_id WHERE e.id=? AND e.is_cancelled=0 LIMIT 1');
        $stmt->execute([$id]);
        $entry = $stmt->fetch() ?: null;
        if ($entry) {
            db()->beginTransaction();
            try {
                pv_daily_sync((string)$entry['entry_date'], (string)$entry['entry_type'], (string)($entry['payment_method'] ?? ''), -(float)$entry['amount']);
                db()->prepare('UPDATE store_credit_entries SET is_cancelled=1,cancelled_at=?,cancelled_by=?,cancel_reason=?,updated_at=? WHERE id=?')
                    ->execute([now(), current_user()['id'] ?? null, 'Liste üzerinden iptal edildi', now(), $id]);
                db()->commit();
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                throw $e;
            }
            audit_action('magaza_personel_veresiye_hareketi', $id, 'iptal', $entry, ['is_cancelled'=>1], $entry['full_name']);
            flash('success', 'Hareket iptal edildi; kayıt geçmişte korundu.');
            redirect('magaza-veresiye.php?person=' . (int)$entry['person_id']);
        }
        redirect('magaza-veresiye.php');
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = 'p.is_active=1';
if ($q !== '') {
    $where .= ' AND p.search_name LIKE ?';
    $params[] = '%' . pv_normalize_name($q) . '%';
}
$stmt = db()->prepare("SELECT p.*, COALESCE(SUM(CASE WHEN e.is_cancelled=0 AND e.entry_type='debt' THEN e.amount WHEN e.is_cancelled=0 AND e.entry_type='payment' THEN -e.amount ELSE 0 END),0) AS balance
    FROM store_credit_people p LEFT JOIN store_credit_entries e ON e.person_id=p.id
    WHERE $where GROUP BY p.id ORDER BY p.full_name");
$stmt->execute($params);
$people = $stmt->fetchAll() ?: [];

$selectedId = (int)($_GET['person'] ?? 0);
$selected = $selectedId ? pv_person($selectedId) : null;
$entries = [];
if ($selected) {
    $stmt = db()->prepare('SELECT e.*,u.display_name AS user_name FROM store_credit_entries e LEFT JOIN users u ON u.id=e.created_by WHERE e.person_id=? ORDER BY e.entry_date DESC,e.id DESC');
    $stmt->execute([$selectedId]);
    $entries = $stmt->fetchAll() ?: [];
}
$totals = db()->query("SELECT COUNT(DISTINCT p.id) AS people,
    COALESCE(SUM(CASE WHEN e.is_cancelled=0 AND e.entry_type='debt' THEN e.amount WHEN e.is_cancelled=0 AND e.entry_type='payment' THEN -e.amount ELSE 0 END),0) AS balance
    FROM store_credit_people p LEFT JOIN store_credit_entries e ON e.person_id=p.id WHERE p.is_active=1")->fetch() ?: ['people'=>0,'balance'=>0];

page_header('Personel Veresiye', 'magaza');
?>
<style>
.pv-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:16px}.pv-head h2{margin:0}.pv-head p{margin:5px 0 0;color:#67736b}.pv-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:16px}.pv-summary article{padding:15px;border:1px solid #dbe5de;border-radius:15px;background:#fff}.pv-summary span{font-size:12px;font-weight:850;color:#68756d}.pv-summary strong{display:block;margin-top:6px;font-size:24px}.pv-grid{display:grid;grid-template-columns:minmax(290px,.72fr) minmax(460px,1.28fr);gap:16px}.pv-form{display:grid;gap:10px;padding:16px}.pv-form label{display:grid;gap:5px;font-size:12px;font-weight:850}.pv-form input,.pv-form select,.pv-form textarea{width:100%;min-height:42px;border:1px solid #d8e1da;border-radius:11px;padding:8px 10px;box-sizing:border-box}.pv-search{display:grid;grid-template-columns:1fr auto;gap:8px;padding:14px}.pv-person-list{display:grid;gap:8px;padding:0 14px 14px}.pv-person{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px;border:1px solid #e0e7e2;border-radius:12px;text-decoration:none;color:inherit}.pv-person:hover{background:#f4faf6}.pv-person strong,.pv-person span{display:block}.pv-person small{color:#6a766e}.pv-debt{color:#a33f35}.pv-paid{color:#176536}.pv-cancelled{opacity:.55;background:#faf8f4}@media(max-width:760px){.pv-grid,.pv-summary{grid-template-columns:1fr}.pv-head{align-items:flex-start;flex-direction:column}.pv-search{grid-template-columns:1fr}}
</style>
<section class="pv-head"><div><h2>Personel Veresiye</h2><p>Fabrika çalışanlarının mağazadan veresiye alışverişlerini ve ödemelerini kişi bazında takip et.</p></div><a class="btn btn-secondary" href="magaza.php">Mağazaya dön</a></section>
<section class="pv-summary">
  <article><span>Kayıtlı personel</span><strong><?php echo e((int)$totals['people']); ?></strong></article>
  <article><span>Toplam açık veresiye</span><strong class="<?php echo (float)$totals['balance']>0?'pv-debt':'pv-paid'; ?>"><?php echo e(money((float)$totals['balance'])); ?></strong></article>
</section>
<section class="pv-grid">
  <div>
    <article class="panel-card">
      <div class="card-head"><h3>Personel ara</h3></div>
      <form class="pv-search" method="get"><input name="q" value="<?php echo e($q); ?>" placeholder="Ad veya soyad yaz"><button class="btn btn-secondary">Ara</button></form>
      <div class="pv-person-list">
        <?php if(!$people): ?><p class="empty"><?php echo $q!==''?'Aramaya uygun personel bulunamadı.':'Henüz personel eklenmedi.'; ?></p><?php endif; ?>
        <?php foreach($people as $person): ?><a class="pv-person" href="magaza-veresiye.php?person=<?php echo e($person['id']); ?>"><span><strong><?php echo e($person['full_name']); ?></strong><small><?php echo e($person['notes'] ?: 'Personel veresiye kartı'); ?></small></span><strong class="<?php echo (float)$person['balance']>0?'pv-debt':'pv-paid'; ?>"><?php echo e(money((float)$person['balance'])); ?></strong></a><?php endforeach; ?>
      </div>
    </article>
    <article class="panel-card" style="margin-top:16px">
      <div class="card-head"><h3>Yeni personel</h3></div>
      <form method="post" class="pv-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="add_person">
        <label>Adı ve soyadı<input name="full_name" required placeholder="Personelin adı soyadı"></label>
        <label>Not<input name="notes" placeholder="Bölüm veya kısa açıklama"></label>
        <button class="btn btn-primary" type="submit">Personeli ekle</button>
      </form>
    </article>
  </div>
  <article class="panel-card">
    <?php if(!$selected): ?>
      <div class="card-head"><h3>Veresiye hareketleri</h3></div><p class="empty">İsim aramasından bir personel seç veya yeni personel ekle.</p>
    <?php else: ?>
      <div class="card-head"><div><h3><?php echo e($selected['full_name']); ?></h3><span>Kişi bazında veresiye ve ödeme geçmişi</span></div><strong class="<?php echo (float)$selected['balance']>0?'pv-debt':'pv-paid'; ?>"><?php echo e(money((float)$selected['balance'])); ?> kalan</strong></div>
      <form method="post" class="pv-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="add_entry"><input type="hidden" name="person_id" value="<?php echo e($selected['id']); ?>">
        <div class="two-col"><label>İşlem<select name="entry_type" id="pv-entry-type"><option value="debt">Veresiye alışveriş</option><option value="payment">Ödeme / tahsilat</option></select></label><label>Tutar<input name="amount" inputmode="decimal" required placeholder="0,00"></label></div>
        <label id="pv-payment-method" style="display:none">Tahsilat şekli<select name="payment_method"><option value="">Nakit veya kart seç</option><option value="cash">Nakit</option><option value="card">Kart / POS</option></select><small>Seçilen tutar günlük mağaza kaydına otomatik yansır.</small></label>
        <div class="two-col"><label>Tarih<input type="date" name="entry_date" value="<?php echo e(date('Y-m-d')); ?>" required></label><label>Açıklama<input name="description" placeholder="Alınan ürünler veya ödeme açıklaması"></label></div>
        <button class="btn btn-primary" type="submit">Hareketi kaydet</button>
      </form>
      <div class="table-wrap"><table><thead><tr><th>Tarih</th><th>İşlem</th><th>Açıklama</th><th class="right">Borç</th><th class="right">Ödeme</th><th></th></tr></thead><tbody>
        <?php if(!$entries): ?><tr><td colspan="6" class="empty">Bu personelin henüz hareketi yok.</td></tr><?php endif; ?>
        <?php foreach($entries as $entry): $cancelled=(int)$entry['is_cancelled']===1; ?><tr class="<?php echo $cancelled?'pv-cancelled':''; ?>"><td><?php echo e(tr_date($entry['entry_date'])); ?></td><td><?php echo $cancelled?badge('İptal','neutral'):badge($entry['entry_type']==='debt'?'Veresiye':'Tahsilat',$entry['entry_type']==='debt'?'warning':'success'); ?></td><td><?php echo e($entry['description'] ?: '-'); ?><small><?php echo e($entry['user_name'] ?: '-'); ?></small></td><td class="right"><?php echo !$cancelled&&$entry['entry_type']==='debt'?e(money((float)$entry['amount'])):'-'; ?></td><td class="right"><?php echo !$cancelled&&$entry['entry_type']==='payment'?e(money((float)$entry['amount'])):'-'; ?></td><td><?php if(!$cancelled&&can_write()): ?><form method="post" onsubmit="return confirm('Bu hareket iptal edilsin mi? Kayıt silinmez.');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel_entry"><input type="hidden" name="id" value="<?php echo e($entry['id']); ?>"><button>İptal</button></form><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </article>
</section>
<script>
(function(){
  var type=document.getElementById('pv-entry-type'),method=document.getElementById('pv-payment-method');
  if(!type||!method)return;
  function sync(){method.style.display=type.value==='payment'?'grid':'none';method.querySelector('select').required=type.value==='payment';}
  type.addEventListener('change',sync);sync();
})();
</script>
<?php page_footer(); ?>
