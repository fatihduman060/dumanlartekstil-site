<?php
require_once __DIR__ . '/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write();
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_account') {
        $id = (int)($_POST['id'] ?? 0);
        $type = $_POST['account_type'] ?? 'kasa';
        if (!isset(account_types()[$type])) $type = 'diger';
        $name = trim($_POST['name'] ?? '');
        $opening = decimal_from_input($_POST['opening_balance'] ?? '0');
        if ($name === '') {
            flash('error', 'Hesap adı boş olamaz.');
            redirect('hesaplar.php');
        }
        $payload = [$type, $name, trim($_POST['iban'] ?? ''), trim($_POST['bank_name'] ?? ''), $opening, isset($_POST['is_active']) ? 1 : 0, trim($_POST['notes'] ?? '')];
        if ($id > 0) {
            db()->prepare('UPDATE accounts SET account_type=?, name=?, iban=?, bank_name=?, opening_balance=?, is_active=?, notes=?, updated_at=? WHERE id=?')
                ->execute(array_merge($payload, [now(), $id]));
            log_action('Kasa/Banka hesabı güncellendi', $name);
            flash('success', 'Hesap güncellendi.');
        } else {
            db()->prepare('INSERT INTO accounts (account_type, name, iban, bank_name, opening_balance, is_active, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($payload, [now(), now()]));
            log_action('Kasa/Banka hesabı eklendi', $name);
            flash('success', 'Hesap eklendi.');
        }
        redirect('hesaplar.php');
    }

    if ($action === 'manual_transaction') {
        $accountId = (int)($_POST['account_id'] ?? 0);
        $direction = $_POST['direction'] ?? '';
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $date = $_POST['transaction_date'] ?: date('Y-m-d');
        if ($accountId <= 0 || !in_array($direction, ['in','out'], true) || $amount <= 0) {
            flash('error', 'Hesap, yön ve tutar kontrol edilmeli.');
            redirect('hesaplar.php');
        }
        db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)')
            ->execute([$accountId, $direction, $amount, $date, 'manual', trim($_POST['description'] ?? ''), current_user()['id'], now()]);
        log_action('Kasa/Banka manuel hareket eklendi', ($direction === 'in' ? 'Giriş ' : 'Çıkış ') . money($amount));
        flash('success', 'Manuel hesap hareketi eklendi.');
        redirect('hesaplar.php');
    }

    if ($action === 'transfer') {
        $from = (int)($_POST['from_account_id'] ?? 0);
        $to = (int)($_POST['to_account_id'] ?? 0);
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $date = $_POST['transaction_date'] ?: date('Y-m-d');
        if ($from <= 0 || $to <= 0 || $from === $to || $amount <= 0) {
            flash('error', 'Virman için iki farklı hesap ve geçerli tutar seçilmeli.');
            redirect('hesaplar.php');
        }
        $desc = trim($_POST['description'] ?? 'Hesaplar arası virman');
        $stamp = 'Virman #' . date('YmdHis');
        db()->beginTransaction();
        try {
            db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)')
                ->execute([$from, 'out', $amount, $date, 'transfer', $stamp . ' - ' . $desc, current_user()['id'], now()]);
            db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)')
                ->execute([$to, 'in', $amount, $date, 'transfer', $stamp . ' - ' . $desc, current_user()['id'], now()]);
            db()->commit();
            log_action('Kasa/Banka virman', money($amount));
            flash('success', 'Virman kaydı oluşturuldu.');
        } catch (Throwable $e) {
            db()->rollBack();
            flash('error', 'Virman kaydedilemedi: ' . $e->getMessage());
        }
        redirect('hesaplar.php');
    }

    if ($action === 'delete_transaction') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("SELECT * FROM account_transactions WHERE id=? AND source_type IN ('manual','transfer')");
        $stmt->execute([$id]);
        $tr = $stmt->fetch();
        if ($tr) {
            db()->prepare('DELETE FROM account_transactions WHERE id=?')->execute([$id]);
            log_action('Kasa/Banka hareketi silindi', '#' . $id . ' ' . money($tr['amount']));
            flash('success', 'Hesap hareketi silindi.');
        }
        redirect('hesaplar.php');
    }
}

$edit = null;
if (!empty($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM accounts WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$accounts = accounts_for_select(false);
$summary = account_summary();
$stmt = db()->query("SELECT at.*, a.name AS account_name, a.account_type, u.display_name AS user_name
    FROM account_transactions at
    JOIN accounts a ON a.id=at.account_id
    LEFT JOIN users u ON u.id=at.created_by
    ORDER BY at.transaction_date DESC, at.id DESC LIMIT 80");
$transactions = $stmt->fetchAll();
page_header('Kasa / Banka', 'hesaplar');
?>
<section class="stats-grid four">
  <article class="stat-card"><span>Toplam bakiye</span><strong class="<?php echo $summary['total'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo e(money($summary['total'])); ?></strong><small>Tüm hesaplar</small></article>
  <article class="stat-card soft"><span>Kasa</span><strong><?php echo e(money($summary['kasa'])); ?></strong><small>Nakit hesapları</small></article>
  <article class="stat-card soft"><span>Banka</span><strong><?php echo e(money($summary['banka'])); ?></strong><small>Banka hesapları</small></article>
  <article class="stat-card soft"><span>Aktif hesap</span><strong><?php echo e($summary['active']); ?></strong><small>Kullanımdaki hesap</small></article>
</section>

<section class="form-grid">
  <article class="panel-card form-card">
    <div class="card-head"><h3><?php echo $edit ? 'Hesap düzenle' : 'Yeni kasa/banka hesabı'; ?></h3></div>
    <?php if (can_write()): ?>
    <form method="post" class="stack-form">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save_account"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>">
      <div class="two-col">
        <label>Hesap tipi<select name="account_type"><?php foreach(account_types() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['account_type'] ?? 'kasa')===$key)?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select></label>
        <label>Hesap adı<input name="name" required value="<?php echo e($edit['name'] ?? ''); ?>" placeholder="Ana Kasa / İş Bankası"></label>
      </div>
      <div class="two-col">
        <label>Banka adı<input name="bank_name" value="<?php echo e($edit['bank_name'] ?? ''); ?>"></label>
        <label>Açılış bakiyesi<input name="opening_balance" type="text" inputmode="decimal" value="<?php echo e($edit['opening_balance'] ?? '0'); ?>"></label>
      </div>
      <label>IBAN<input name="iban" value="<?php echo e($edit['iban'] ?? ''); ?>"></label>
      <label>Not<textarea name="notes" rows="2"><?php echo e($edit['notes'] ?? ''); ?></textarea></label>
      <label class="check"><input type="checkbox" name="is_active" <?php echo ((int)($edit['is_active'] ?? 1)===1)?'checked':''; ?>> Aktif hesap</label>
      <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $edit ? 'Güncelle' : 'Hesap ekle'; ?></button><?php if ($edit): ?><a class="btn btn-secondary" href="hesaplar.php">Vazgeç</a><?php endif; ?></div>
    </form>
    <?php else: ?><p class="muted">Görüntüleme yetkisindesiniz. Hesap ekleme/düzenleme kapalı.</p><?php endif; ?>
  </article>

  <article class="panel-card">
    <div class="card-head"><h3>Hesap listesi</h3><a href="export.php?type=account_transactions">Hareket CSV</a></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Hesap</th><th>Tip</th><th>IBAN / Banka</th><th>Durum</th><th class="right">Bakiye</th><th></th></tr></thead>
        <tbody>
          <?php if(!$accounts): ?><tr><td colspan="6" class="empty">Hesap yok.</td></tr><?php endif; ?>
          <?php foreach($accounts as $a): $bal=account_balance((int)$a['id']); ?>
          <tr>
            <td><strong><?php echo e($a['name']); ?></strong><small>Açılış: <?php echo e(money($a['opening_balance'])); ?></small></td>
            <td><?php echo badge(account_type_label($a['account_type']), account_type_tone($a['account_type'])); ?></td>
            <td><?php echo e($a['bank_name'] ?: '-'); ?><small><?php echo e($a['iban'] ?: ''); ?></small></td>
            <td><?php echo ((int)$a['is_active']===1) ? badge('Aktif','success') : badge('Pasif','neutral'); ?></td>
            <td class="right"><strong class="<?php echo $bal>=0?'text-success':'text-danger'; ?>"><?php echo e(money($bal)); ?></strong></td>
            <td class="row-actions"><a href="hesaplar.php?edit=<?php echo e($a['id']); ?>">Düzenle</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </article>
</section>

<?php if (can_write()): ?>
<section class="content-grid compact">
  <article class="panel-card">
    <div class="card-head"><h3>Manuel kasa/banka hareketi</h3><span>Cari dışı giriş/çıkış</span></div>
    <form method="post" class="stack-form">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="manual_transaction">
      <label>Hesap<select name="account_id" required><?php foreach(accounts_for_select(true) as $a): ?><option value="<?php echo e($a['id']); ?>"><?php echo e($a['name']); ?> — <?php echo e(account_type_label($a['account_type'])); ?></option><?php endforeach; ?></select></label>
      <div class="two-col"><label>Yön<select name="direction"><option value="in">Giriş</option><option value="out">Çıkış</option></select></label><label>Tutar<input name="amount" type="text" inputmode="decimal" required></label></div>
      <label>Tarih<input type="date" name="transaction_date" value="<?php echo e(date('Y-m-d')); ?>" required></label>
      <label>Açıklama<textarea name="description" rows="2" placeholder="Kasa düzeltme, banka masrafı..."></textarea></label>
      <button class="btn btn-primary" type="submit">Hareket ekle</button>
    </form>
  </article>
  <article class="panel-card">
    <div class="card-head"><h3>Hesaplar arası virman</h3><span>Kasa → banka / banka → kasa</span></div>
    <form method="post" class="stack-form">
      <?php echo csrf_field(); ?><input type="hidden" name="action" value="transfer">
      <div class="two-col"><label>Çıkış hesabı<select name="from_account_id" required><?php foreach(accounts_for_select(true) as $a): ?><option value="<?php echo e($a['id']); ?>"><?php echo e($a['name']); ?></option><?php endforeach; ?></select></label><label>Giriş hesabı<select name="to_account_id" required><?php foreach(accounts_for_select(true) as $a): ?><option value="<?php echo e($a['id']); ?>"><?php echo e($a['name']); ?></option><?php endforeach; ?></select></label></div>
      <div class="two-col"><label>Tutar<input name="amount" type="text" inputmode="decimal" required></label><label>Tarih<input type="date" name="transaction_date" value="<?php echo e(date('Y-m-d')); ?>" required></label></div>
      <label>Açıklama<input name="description" value="Hesaplar arası virman"></label>
      <button class="btn btn-secondary" type="submit">Virman yap</button>
    </form>
  </article>
</section>
<?php endif; ?>

<section class="panel-card">
  <div class="card-head"><h3>Son kasa/banka hareketleri</h3><span>Hareket, çek ve manuel kayıtlar</span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Tarih</th><th>Hesap</th><th>Kaynak</th><th>Açıklama</th><th class="right">Giriş</th><th class="right">Çıkış</th><th></th></tr></thead>
      <tbody>
      <?php if(!$transactions): ?><tr><td colspan="7" class="empty">Hesap hareketi yok.</td></tr><?php endif; ?>
      <?php foreach($transactions as $tr): ?>
        <tr>
          <td><?php echo e(tr_date($tr['transaction_date'])); ?></td>
          <td><strong><?php echo e($tr['account_name']); ?></strong><small><?php echo e(account_type_label($tr['account_type'])); ?></small></td>
          <td><?php echo badge($tr['source_type'], $tr['source_type']==='manual'?'neutral':($tr['source_type']==='movement'?'success':'info')); ?></td>
          <td><?php echo e($tr['description'] ?: '-'); ?><small><?php echo e($tr['user_name'] ?: ''); ?></small></td>
          <td class="right"><?php echo $tr['direction']==='in' ? '<strong class="text-success">'.e(money($tr['amount'])).'</strong>' : '-'; ?></td>
          <td class="right"><?php echo $tr['direction']==='out' ? '<strong class="text-danger">'.e(money($tr['amount'])).'</strong>' : '-'; ?></td>
          <td class="row-actions"><?php if(can_write() && in_array($tr['source_type'], ['manual','transfer'], true)): ?><form method="post" onsubmit="return confirm('Bu manuel/virman hareketi silinsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="id" value="<?php echo e($tr['id']); ?>"><button>Sil</button></form><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_footer(); ?>
