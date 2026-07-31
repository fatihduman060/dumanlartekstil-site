<?php
require_once __DIR__ . '/layout.php';
require_login();

function kart_ekstre_cards(): array
{
    return [
        'garanti_9029' => [
            'name' => 'Garanti Bankası Kredi Kartı •••• 9029',
            'account_name' => 'Garanti Bankası Fatih Duman',
            'bank_name' => 'Garanti BBVA',
            'aliases' => ['Garanti Fatih', 'Garanti Bankası Fatih', 'Garanti Fatih Duman'],
        ],
        'isbank_3833' => [
            'name' => 'İş Bankası Kredi Kartı •••• 3833',
            'account_name' => 'İş Bankası Fatih Duman',
            'bank_name' => 'Türkiye İş Bankası',
            'aliases' => ['İş Bankası Fatih', 'İşbank Fatih', 'İşbank Fatih Duman'],
        ],
        'ziraat_7754' => [
            'name' => 'Ziraat Bankası Kredi Kartı •••• 7754',
            'account_name' => 'Ziraat Bankası Fatih Duman',
            'bank_name' => 'T.C. Ziraat Bankası',
            'aliases' => ['Ziraat Fatih', 'Ziraat Bankası Fatih', 'Ziraat Fatih Duman'],
        ],
        'ziraat_4091' => [
            'name' => 'Ziraat Bankası Kredi Kartı •••• 4091',
            'account_name' => 'Ziraat Bankası Fatih Duman',
            'bank_name' => 'T.C. Ziraat Bankası',
            'aliases' => ['Ziraat Fatih', 'Ziraat Bankası Fatih', 'Ziraat Fatih Duman'],
        ],
        'kuveyt_4357' => [
            'name' => 'Kuveyt Türk Kredi Kartı •••• 4357',
            'account_name' => 'Kuveyt Türk Fatih Duman',
            'bank_name' => 'Kuveyt Türk Katılım Bankası',
            'aliases' => ['Kuveyt Fatih', 'Kuveyt Türk Fatih', 'Kuveyt Fatih Duman'],
        ],
    ];
}

function kart_ekstre_column_exists(string $table, string $column): bool
{
    $stmt = db()->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        if ((string)($row['name'] ?? '') === $column) return true;
    }
    return false;
}

function kart_ekstre_ensure_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS card_statements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        card_key TEXT,
        card_name TEXT NOT NULL,
        statement_period TEXT NOT NULL,
        amount REAL NOT NULL DEFAULT 0 CHECK(amount >= 0),
        due_date TEXT NOT NULL,
        note TEXT,
        status TEXT NOT NULL DEFAULT 'bekliyor',
        paid_date TEXT,
        payment_account_id INTEGER,
        payment_transaction_id INTEGER,
        reversal_transaction_id INTEGER,
        cancelled_at TEXT,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(payment_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        FOREIGN KEY(payment_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL,
        FOREIGN KEY(reversal_transaction_id) REFERENCES account_transactions(id) ON DELETE SET NULL
    )");

    $columns = [
        'card_key' => 'TEXT',
        'payment_account_id' => 'INTEGER',
        'payment_transaction_id' => 'INTEGER',
        'reversal_transaction_id' => 'INTEGER',
        'cancelled_at' => 'TEXT',
    ];
    foreach ($columns as $column => $type) {
        if (!kart_ekstre_column_exists('card_statements', $column)) {
            db()->exec('ALTER TABLE card_statements ADD COLUMN ' . $column . ' ' . $type);
        }
    }

    db()->exec("CREATE INDEX IF NOT EXISTS idx_card_statements_status_due ON card_statements(status, due_date)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_card_statements_card_period ON card_statements(card_key, statement_period)");
}

kart_ekstre_ensure_table();

function kart_ekstre_valid_date(string $value): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
}

function kart_ekstre_valid_period(string $value): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) return false;
    $month = (int)$m[2];
    return $month >= 1 && $month <= 12;
}

function kart_ekstre_period_label(string $period): string
{
    $months = [1=>'Ocak',2=>'Şubat',3=>'Mart',4=>'Nisan',5=>'Mayıs',6=>'Haziran',7=>'Temmuz',8=>'Ağustos',9=>'Eylül',10=>'Ekim',11=>'Kasım',12=>'Aralık'];
    if (kart_ekstre_valid_period($period)) {
        $parts = explode('-', $period);
        return ($months[(int)$parts[1]] ?? $parts[1]) . ' ' . $parts[0];
    }
    return $period;
}

function kart_ekstre_next_period(string $period): string
{
    if (!kart_ekstre_valid_period($period)) return date('Y-m');
    return date('Y-m', strtotime($period . '-01 +1 month'));
}

function kart_ekstre_find_or_create_account(array $card): int
{
    $names = array_merge([(string)$card['account_name']], $card['aliases'] ?? []);
    $stmt = db()->prepare('SELECT id FROM accounts WHERE name=? LIMIT 1');
    foreach ($names as $name) {
        $stmt->execute([$name]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        if ($id > 0) return $id;
    }

    $stmt = db()->prepare("SELECT id FROM accounts WHERE account_type='banka' AND bank_name=? AND name LIKE ? ORDER BY is_active DESC, id ASC LIMIT 1");
    $stmt->execute([(string)$card['bank_name'], '%Fatih%']);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) return $id;

    db()->prepare('INSERT INTO accounts (account_type, name, iban, bank_name, opening_balance, is_active, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute(['banka', (string)$card['account_name'], '', (string)$card['bank_name'], 0, 1, 'Kart ekstresi ödemeleri için otomatik oluşturuldu.', now(), now()]);
    return (int)db()->lastInsertId();
}

function kart_ekstre_payment_description(array $row): string
{
    return (string)$row['card_name'] . ' / ' . kart_ekstre_period_label((string)$row['statement_period']) . ' ekstre ödemesi';
}

$cards = kart_ekstre_cards();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_write();
    require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $cardKey = trim((string)($_POST['card_key'] ?? ''));
        $period = trim((string)($_POST['statement_period'] ?? ''));
        $amount = max(0, decimal_from_input($_POST['amount'] ?? 0));
        $dueDate = trim((string)($_POST['due_date'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        if (!isset($cards[$cardKey]) || !kart_ekstre_valid_period($period) || $amount <= 0 || !kart_ekstre_valid_date($dueDate)) {
            flash('error', 'Kart, ekstre dönemi, tutar ve son ödeme tarihini kontrol et.');
            redirect('kart-ekstre-takibi.php');
        }
        $cardName = (string)$cards[$cardKey]['name'];

        $duplicate = db()->prepare("SELECT id FROM card_statements WHERE card_key=? AND statement_period=? AND status<>'iptal' AND id<>? LIMIT 1");
        $duplicate->execute([$cardKey, $period, $id]);
        if ((int)($duplicate->fetchColumn() ?: 0) > 0) {
            flash('error', 'Bu kartın seçilen döneme ait ekstresi zaten kayıtlı.');
            redirect('kart-ekstre-takibi.php');
        }

        if ($id > 0) {
            $oldStmt = db()->prepare('SELECT * FROM card_statements WHERE id=? LIMIT 1');
            $oldStmt->execute([$id]);
            $old = $oldStmt->fetch() ?: null;
            if (!$old || (string)$old['status'] !== 'bekliyor') {
                flash('error', 'Yalnızca bekleyen ekstreler düzenlenebilir.');
                redirect('kart-ekstre-takibi.php');
            }
            db()->prepare("UPDATE card_statements SET card_key=?, card_name=?, statement_period=?, amount=?, due_date=?, note=?, updated_at=? WHERE id=?")
                ->execute([$cardKey, $cardName, $period, $amount, $dueDate, $note ?: null, now(), $id]);
            audit_action('kart_ekstresi', $id, 'guncellendi', $old, ['card_key'=>$cardKey,'period'=>$period,'amount'=>$amount,'due_date'=>$dueDate], $cardName);
            flash('success', 'Kart ekstresi güncellendi.');
        } else {
            db()->prepare("INSERT INTO card_statements (card_key, card_name, statement_period, amount, due_date, note, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,'bekliyor',?,?,?)")
                ->execute([$cardKey, $cardName, $period, $amount, $dueDate, $note ?: null, current_user()['id'] ?? null, now(), now()]);
            $newId = (int)db()->lastInsertId();
            audit_action('kart_ekstresi', $newId, 'eklendi', null, ['card_key'=>$cardKey,'period'=>$period,'amount'=>$amount,'due_date'=>$dueDate], $cardName);
            flash('success', 'Kart ekstresi kaydedildi. Bir sonraki girişte dönem otomatik ilerleyecek.');
        }
        redirect('kart-ekstre-takibi.php');
    }

    if ($action === 'mark_paid') {
        $id = (int)($_POST['id'] ?? 0);
        $paidDate = trim((string)($_POST['paid_date'] ?? date('Y-m-d')));
        if ($id <= 0 || !kart_ekstre_valid_date($paidDate)) {
            flash('error', 'Ödeme tarihini kontrol et.');
            redirect('kart-ekstre-takibi.php');
        }

        $stmt = db()->prepare('SELECT * FROM card_statements WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: null;
        if (!$row || (string)$row['status'] !== 'bekliyor') {
            flash('error', 'Bu ekstre bekleyen durumda değil.');
            redirect('kart-ekstre-takibi.php');
        }
        $cardKey = (string)($row['card_key'] ?? '');
        if (!isset($cards[$cardKey])) {
            flash('error', 'Bu eski kaydın sabit kart bağlantısı yok. Önce kaydı düzenleyip kartını seç.');
            redirect('kart-ekstre-takibi.php?edit=' . $id);
        }

        db()->beginTransaction();
        try {
            $accountId = kart_ekstre_find_or_create_account($cards[$cardKey]);
            db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$accountId, 'out', (float)$row['amount'], $paidDate, 'card_statement', $id, kart_ekstre_payment_description($row), current_user()['id'] ?? null, now()]);
            $transactionId = (int)db()->lastInsertId();
            db()->prepare("UPDATE card_statements SET status='odendi', paid_date=?, payment_account_id=?, payment_transaction_id=?, reversal_transaction_id=NULL, updated_at=? WHERE id=?")
                ->execute([$paidDate, $accountId, $transactionId, now(), $id]);
            audit_action('kart_ekstresi', $id, 'odendi', $row, ['paid_date'=>$paidDate,'account_id'=>$accountId,'transaction_id'=>$transactionId], (string)$row['card_name']);
            db()->commit();
            flash('success', 'Ekstre ödendi. Tutar bağlı banka hesabından otomatik düşüldü.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash('error', 'Ekstre ödemesi kaydedilemedi: ' . $e->getMessage());
        }
        redirect('kart-ekstre-takibi.php');
    }

    if ($action === 'undo_paid') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM card_statements WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: null;
        if (!$row || (string)$row['status'] !== 'odendi' || (int)($row['payment_account_id'] ?? 0) <= 0) {
            flash('error', 'Geri alınabilecek bir ekstre ödemesi bulunamadı.');
            redirect('kart-ekstre-takibi.php');
        }

        db()->beginTransaction();
        try {
            $description = 'Ödeme geri alındı / ' . kart_ekstre_payment_description($row);
            db()->prepare('INSERT INTO account_transactions (account_id, direction, amount, transaction_date, source_type, source_id, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$row['payment_account_id'], 'in', (float)$row['amount'], date('Y-m-d'), 'card_statement_reversal', $id, $description, current_user()['id'] ?? null, now()]);
            $reversalId = (int)db()->lastInsertId();
            db()->prepare("UPDATE card_statements SET status='bekliyor', paid_date=NULL, reversal_transaction_id=?, updated_at=? WHERE id=?")
                ->execute([$reversalId, now(), $id]);
            audit_action('kart_ekstresi', $id, 'odeme_geri_alindi', $row, ['reversal_transaction_id'=>$reversalId], (string)$row['card_name']);
            db()->commit();
            flash('success', 'Ödeme geri alındı. Banka hesabına aynı tutarda iade hareketi işlendi; geçmiş kayıt korundu.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            flash('error', 'Ödeme geri alınamadı: ' . $e->getMessage());
        }
        redirect('kart-ekstre-takibi.php');
    }

    if ($action === 'cancel') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM card_statements WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: null;
        if (!$row || (string)$row['status'] !== 'bekliyor') {
            flash('error', 'Yalnızca bekleyen ekstre iptal edilebilir.');
        } else {
            db()->prepare("UPDATE card_statements SET status='iptal', cancelled_at=?, updated_at=? WHERE id=?")
                ->execute([now(), now(), $id]);
            audit_action('kart_ekstresi', $id, 'iptal', $row, ['status'=>'iptal'], (string)$row['card_name']);
            flash('success', 'Ekstre iptal edildi. Kayıt geçmişi silinmedi.');
        }
        redirect('kart-ekstre-takibi.php');
    }
}

$nextPeriods = [];
foreach ($cards as $key => $card) {
    $stmt = db()->prepare("SELECT statement_period FROM card_statements WHERE card_key=? AND status<>'iptal' AND statement_period GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]' ORDER BY statement_period DESC LIMIT 1");
    $stmt->execute([$key]);
    $lastPeriod = (string)($stmt->fetchColumn() ?: '');
    $nextPeriods[$key] = $lastPeriod !== '' ? kart_ekstre_next_period($lastPeriod) : date('Y-m');
}

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
if ($editId > 0) {
    $stmt = db()->prepare("SELECT * FROM card_statements WHERE id=? AND status='bekliyor' LIMIT 1");
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
}

$filter = trim((string)($_GET['status'] ?? ''));
$where = " WHERE status<>'iptal'";
$params = [];
if (in_array($filter, ['bekliyor', 'odendi', 'iptal'], true)) {
    $where = ' WHERE status=?';
    $params[] = $filter;
}
$stmt = db()->prepare("SELECT cs.*, a.name AS payment_account_name FROM card_statements cs LEFT JOIN accounts a ON a.id=cs.payment_account_id{$where} ORDER BY CASE cs.status WHEN 'bekliyor' THEN 0 WHEN 'odendi' THEN 1 ELSE 2 END, cs.due_date ASC, cs.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summary = db()->query("SELECT
    COALESCE(SUM(CASE WHEN status='bekliyor' AND substr(due_date,1,7)=strftime('%Y-%m','now','localtime') THEN amount ELSE 0 END),0) AS month_pending,
    COALESCE(SUM(CASE WHEN status='bekliyor' THEN 1 ELSE 0 END),0) AS pending_count,
    COALESCE(SUM(CASE WHEN status='odendi' AND substr(paid_date,1,7)=strftime('%Y-%m','now','localtime') THEN 1 ELSE 0 END),0) AS paid_count,
    MIN(CASE WHEN status='bekliyor' THEN due_date ELSE NULL END) AS nearest_due
    FROM card_statements")->fetch() ?: [];

$selectedCardKey = (string)($editRow['card_key'] ?? array_key_first($cards));
$selectedPeriod = (string)($editRow['statement_period'] ?? ($nextPeriods[$selectedCardKey] ?? date('Y-m')));

page_header('Kart Ekstre Takibi', 'kart_ekstre');
?>
<section class="hero-card">
  <div>
    <span class="status-pill">KART EKSTRE TAKİBİ</span>
    <h2>Sabit kartların ekstrelerini ve banka ödemelerini tek yerde takip et.</h2>
    <p>Kartı seçip yalnızca tutar ve son ödeme tarihini gir. Dönem otomatik gelir; istersen değiştirebilirsin.</p>
  </div>
</section>

<section class="stats-grid four section-stats">
  <article class="stat-card special"><span>Bu ay ödenecek</span><strong><?php echo e(money($summary['month_pending'] ?? 0)); ?></strong><small>Bekleyen ekstreler</small></article>
  <article class="stat-card status"><span>Bekleyen ekstre</span><strong><?php echo e((string)($summary['pending_count'] ?? 0)); ?></strong><small>Toplam kayıt</small></article>
  <article class="stat-card soft"><span>Bu ay ödenen</span><strong><?php echo e((string)($summary['paid_count'] ?? 0)); ?></strong><small>Ekstre sayısı</small></article>
  <article class="stat-card cash"><span>En yakın son ödeme</span><strong><?php echo !empty($summary['nearest_due']) ? e(date('d.m.Y', strtotime($summary['nearest_due']))) : '—'; ?></strong><small>Bekleyen ekstre</small></article>
</section>

<?php if (can_write()): ?>
<section class="panel-card">
  <div class="card-head"><h3><?php echo $editRow ? 'Ekstreyi düzenle' : 'Yeni ekstre ekle'; ?></h3><span>Kart ve dönem hazır gelir</span></div>
  <form method="post" class="stack-form" id="card-statement-form">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo e((string)($editRow['id'] ?? 0)); ?>">
    <div class="two-col">
      <label>Kredi kartı
        <select name="card_key" id="card-key" required>
          <?php foreach ($cards as $key => $card): ?>
            <option value="<?php echo e($key); ?>"<?php echo $selectedCardKey === $key ? ' selected' : ''; ?>><?php echo e($card['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Ekstre dönemi<input name="statement_period" id="statement-period" type="month" required value="<?php echo e($selectedPeriod); ?>"><small>Otomatik gelir, manuel değiştirilebilir.</small></label>
    </div>
    <div class="two-col">
      <label>Ekstre tutarı<input name="amount" inputmode="decimal" required autofocus placeholder="0,00" value="<?php echo isset($editRow['amount']) ? e(number_format((float)$editRow['amount'], 2, ',', '.')) : ''; ?>"></label>
      <label>Son ödeme tarihi<input name="due_date" type="date" required value="<?php echo e($editRow['due_date'] ?? ''); ?>"></label>
    </div>
    <label>Not<textarea name="note" placeholder="İsteğe bağlı not"><?php echo e($editRow['note'] ?? ''); ?></textarea></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?php echo $editRow ? 'Güncelle' : 'Ekstreyi kaydet'; ?></button><?php if ($editRow): ?><a class="btn btn-secondary" href="kart-ekstre-takibi.php">Vazgeç</a><?php endif; ?></div>
  </form>
</section>
<script>
(function () {
  var card = document.getElementById('card-key');
  var period = document.getElementById('statement-period');
  var nextPeriods = <?php echo json_encode($nextPeriods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  var editing = <?php echo $editRow ? 'true' : 'false'; ?>;
  if (!card || !period || editing) return;
  card.addEventListener('change', function () {
    period.value = nextPeriods[card.value] || '<?php echo e(date('Y-m')); ?>';
  });
}());
</script>
<?php endif; ?>

<section class="panel-card">
  <div class="card-head"><h3>Ekstre listesi</h3><div class="row-actions"><a class="btn btn-secondary" href="kart-ekstre-takibi.php">Aktifler</a><a class="btn btn-secondary" href="?status=bekliyor">Bekleyen</a><a class="btn btn-secondary" href="?status=odendi">Ödenen</a><a class="btn btn-secondary" href="?status=iptal">İptal</a></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Kart</th><th>Dönem</th><th class="right">Tutar</th><th>Son ödeme</th><th>Durum</th><th>Ödeme hesabı</th><th>Not</th><th>İşlem</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="empty">Henüz kart ekstresi eklenmedi.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $row):
        $days = (int)floor((strtotime($row['due_date']) - strtotime(date('Y-m-d'))) / 86400);
        $rowStyle = '';
        if ($row['status'] === 'bekliyor') {
            if ($days < 0) $rowStyle = 'background:#fff0f0';
            elseif ($days === 0) $rowStyle = 'background:#fff1df';
            elseif ($days <= 5) $rowStyle = 'background:#fff9df';
        }
      ?>
        <tr style="<?php echo e($rowStyle); ?>">
          <td><strong><?php echo e($row['card_name']); ?></strong></td>
          <td><?php echo e(kart_ekstre_period_label((string)$row['statement_period'])); ?></td>
          <td class="right"><strong><?php echo e(money($row['amount'])); ?></strong></td>
          <td><?php echo e(date('d.m.Y', strtotime($row['due_date']))); ?></td>
          <td><?php
            if ($row['status'] === 'odendi') echo badge('Ödendi', 'success');
            elseif ($row['status'] === 'iptal') echo badge('İptal', 'danger');
            else echo badge('Bekliyor', 'warning');
          ?></td>
          <td><?php echo e($row['payment_account_name'] ?: '—'); ?></td>
          <td><?php echo e($row['note'] ?: '—'); ?></td>
          <td><div class="row-actions">
            <?php if (can_write() && $row['status'] === 'bekliyor'): ?>
              <a href="?edit=<?php echo e((string)$row['id']); ?>">Düzenle</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Bu ekstre ödendi olarak işaretlensin ve bağlı banka hesabından düşülsün mü?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><input type="hidden" name="paid_date" value="<?php echo e(date('Y-m-d')); ?>"><button type="submit">Ödendi</button></form>
              <form method="post" style="display:inline" onsubmit="return confirm('Bu bekleyen ekstre iptal edilsin mi? Kayıt silinmeyecek.')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><button type="submit">İptal</button></form>
            <?php elseif (can_write() && $row['status'] === 'odendi'): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Ödeme geri alınsın ve banka hesabına iade hareketi işlensin mi?')"><?php echo csrf_field(); ?><input type="hidden" name="action" value="undo_paid"><input type="hidden" name="id" value="<?php echo e((string)$row['id']); ?>"><button type="submit">Geri al</button></form>
            <?php else: ?>—<?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php page_footer(); ?>
