<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$kind = (string)($_GET['kind'] ?? '');
$allowed = ['cash_in','cash_out','cash_net','account_total','kasa_total','banka_total','check_in','check_out','check_7','check_overdue'];
if (!in_array($kind, $allowed, true)) $kind = 'cash_in';

function dnc_money($amount): string { return number_format((float)$amount, 2, ',', '.') . ' TL'; }
function dnc_date(?string $date): string { if (!$date) return '-'; $ts = strtotime($date); return $ts ? date('d.m.Y', $ts) : $date; }
function dnc_account_balance(array $a): float
{
    $stmt = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) AS giren,
        COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) AS cikan
        FROM account_transactions WHERE account_id=?");
    $stmt->execute([(int)$a['id']]);
    $t = $stmt->fetch() ?: ['giren'=>0,'cikan'=>0];
    return (float)($a['opening_balance'] ?? 0) + (float)$t['giren'] - (float)$t['cikan'];
}

try {
    $rows = [];
    $title = 'Detay';
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');

    if (in_array($kind, ['cash_in','cash_out','cash_net'], true)) {
        $direction = $kind === 'cash_out' ? 'out' : null;
        $title = $kind === 'cash_in' ? 'Bu ay kasaya/bankaya giren para' : ($kind === 'cash_out' ? 'Bu ay kasadan/bankadan çıkan para' : 'Bu ay nakit neti');
        $sql = "SELECT at.*, a.name AS account_name, a.account_type FROM account_transactions at LEFT JOIN accounts a ON a.id=at.account_id WHERE at.transaction_date BETWEEN ? AND ?";
        $params = [$monthStart, $monthEnd];
        if ($direction) { $sql .= " AND at.direction=?"; $params[] = $direction; }
        $sql .= " ORDER BY at.transaction_date DESC, at.id DESC LIMIT 300";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $dir = (string)($r['direction'] ?? '');
            $sign = $dir === 'out' ? -1 : 1;
            $rows[] = [
                'label' => trim((string)($r['description'] ?: ($dir === 'out' ? 'Çıkış' : 'Giriş'))),
                'sub' => dnc_date($r['transaction_date'] ?? '') . ' · ' . (($r['account_name'] ?? '') ?: 'Hesap yok'),
                'amount' => $sign * (float)($r['amount'] ?? 0),
                'amount_text' => ($dir === 'out' ? '-' : '+') . dnc_money($r['amount'] ?? 0),
                'url' => !empty($r['source_id']) && ($r['source_type'] ?? '') === 'movement' ? 'hareketler.php?edit=' . (int)$r['source_id'] . '&manual=1' : 'hesap-dokumleri.php',
            ];
        }
        if ($kind === 'cash_net') {
            usort($rows, fn($a,$b) => abs((float)$b['amount']) <=> abs((float)$a['amount']));
        }
    }

    if (in_array($kind, ['account_total','kasa_total','banka_total'], true)) {
        $title = $kind === 'kasa_total' ? 'Kasa hesapları' : ($kind === 'banka_total' ? 'Banka hesapları' : 'Genel kasa/banka hesapları');
        $where = "WHERE COALESCE(is_active,1)=1";
        if ($kind === 'kasa_total') $where .= " AND account_type='kasa'";
        if ($kind === 'banka_total') $where .= " AND account_type='banka'";
        $stmt = db()->query("SELECT * FROM accounts $where ORDER BY account_type ASC, name ASC");
        foreach ($stmt->fetchAll() as $a) {
            $bal = dnc_account_balance($a);
            $rows[] = [
                'label' => (string)$a['name'],
                'sub' => (($a['account_type'] ?? '') === 'banka' ? 'Banka' : 'Kasa') . (!empty($a['bank_name']) ? ' · ' . $a['bank_name'] : ''),
                'amount' => $bal,
                'amount_text' => dnc_money($bal),
                'url' => 'hesap-dokumleri.php?account_id=' . (int)$a['id'],
            ];
        }
        usort($rows, fn($a,$b) => (float)$b['amount'] <=> (float)$a['amount']);
    }

    if (in_array($kind, ['check_in','check_out','check_7','check_overdue'], true)) {
        $direction = in_array($kind, ['check_out'], true) ? 'verilecek' : 'alinacak';
        $title = $kind === 'check_in' ? 'Alınan çekler' : ($kind === 'check_out' ? 'Verilen çekler' : ($kind === 'check_7' ? '7 gün içinde alınacak çekler' : 'Vadesi geçen çekler'));
        $sql = "SELECT ch.*, c.name AS cari_name, c.city AS cari_city FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id WHERE COALESCE(ch.is_cancelled,0)=0";
        $params = [];
        if ($kind === 'check_in') { $sql .= " AND ch.direction='alinacak'"; }
        elseif ($kind === 'check_out') { $sql .= " AND ch.direction='verilecek'"; }
        elseif ($kind === 'check_7') { $sql .= " AND ch.direction='alinacak' AND ch.due_date BETWEEN ? AND ?"; $params[] = $today; $params[] = date('Y-m-d', strtotime('+7 day')); }
        elseif ($kind === 'check_overdue') { $sql .= " AND ch.due_date < ?"; $params[] = $today; }
        $sql .= " ORDER BY ch.due_date ASC, ch.id DESC LIMIT 300";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $ch) {
            $rows[] = [
                'label' => trim((string)(($ch['cari_name'] ?? '') ?: 'Cari yok')),
                'sub' => 'Vade: ' . dnc_date($ch['due_date'] ?? '') . ' · ' . trim((string)(($ch['bank_name'] ?? '') ?: '-')) . (!empty($ch['check_no']) ? ' · No: ' . $ch['check_no'] : ''),
                'amount' => (float)($ch['amount'] ?? 0),
                'amount_text' => dnc_money($ch['amount'] ?? 0),
                'url' => 'cekler.php?direction=' . urlencode((string)($ch['direction'] ?? 'alinacak')) . '&edit=' . (int)$ch['id'] . '#cek-form',
            ];
        }
    }

    echo json_encode(['ok'=>true, 'kind'=>$kind, 'title'=>$title, 'rows'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'Liste okunamadı.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
