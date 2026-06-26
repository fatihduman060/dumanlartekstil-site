<?php
require_once __DIR__ . '/layout.php';
require_login();

$today = date('Y-m-d');
$weekAhead = date('Y-m-d', strtotime('+7 days'));

function cek_banka_listesi(): array
{
    return ['Akbank','Aktif Bank','Albaraka Türk','Alternatif Bank','Anadolubank','Burgan Bank','Citibank','DenizBank','Emlak Katılım','Fibabanka','Garanti BBVA','Halkbank','HSBC Türkiye','ING Bank','İş Bankası','Kuveyt Türk','Odea Bank','QNB Finansbank','Şekerbank','TEB','Türkiye Finans','Vakıf Katılım','VakıfBank','Yapı Kredi','Ziraat Bankası','Ziraat Katılım','Diğer'];
}
function cek_status_meta(string $status): array
{
    $map = ['bekliyor'=>['label'=>'Bekliyor','tone'=>'info'],'bankaya_verildi'=>['label'=>'Bankaya verildi','tone'=>'info'],'tahsil_edildi'=>['label'=>'Tahsil edildi','tone'=>'success'],'odendi'=>['label'=>'Ödendi','tone'=>'success'],'ciro_edildi'=>['label'=>'Ciro edildi','tone'=>'warning'],'iade'=>['label'=>'İade','tone'=>'neutral'],'karsiliksiz'=>['label'=>'Karşılıksız','tone'=>'danger'],'protestolu'=>['label'=>'Protestolu','tone'=>'danger'],'iptal'=>['label'=>'İptal','tone'=>'neutral']];
    return $map[$status] ?? ['label'=>$status ?: 'Bekliyor','tone'=>'neutral'];
}
function cek_status_label2(string $status): string { return cek_status_meta($status)['label']; }
function cek_status_tone2(string $status): string { return cek_status_meta($status)['tone']; }
function cek_due_text(array $ch, string $today): string
{
    if ((int)($ch['is_cancelled'] ?? 0) === 1) return 'İptal edildi';
    $status = (string)($ch['status'] ?? 'bekliyor');
    if (!in_array($status, ['bekliyor','bankaya_verildi'], true)) return cek_status_label2($status);
    $due = (string)($ch['due_date'] ?? '');
    if ($due === '') return '-';
    $diff = (int)round((strtotime($due) - strtotime($today)) / 86400);
    if ($diff < 0) return abs($diff) . ' gün gecikti';
    if ($diff === 0) return 'Bugün vade';
    if ($diff === 1) return 'Yarın vade';
    return $diff . ' gün kaldı';
}
function cek_prefix(int $id): string { return 'Çek #' . $id . ' | '; }
function cek_extra_docs_for_ids(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $rows = db()->query("SELECT * FROM standalone_documents WHERE description LIKE 'Çek #% | %' ORDER BY id ASC")->fetchAll();
    $out = [];
    $allowed = array_flip($ids);
    foreach ($rows as $row) {
        if (!preg_match('/^Çek #(\d+) \| /u', (string)($row['description'] ?? ''), $m)) continue;
        $id = (int)$m[1];
        if (!isset($allowed[$id])) continue;
        $out[$id][] = $row;
    }
    return $out;
}
function save_check_extra_upload(int $checkId, ?int $cariId, string $field, string $docType, string $label): void
{
    if (empty($_FILES[$field]['name'])) return;
    $doc = handle_upload($field);
    if (!$doc['path']) return;
    db()->prepare('INSERT INTO standalone_documents (cari_id, document_date, document_type, document_path, document_name, document_mime, description, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$cariId ?: null, date('Y-m-d'), $docType, $doc['path'], $doc['name'], $doc['mime'], cek_prefix($checkId) . $label, current_user()['id'] ?? null, now(), now()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_write(); require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $direction = $_POST['direction'] ?? 'alinacak';
        $amount = decimal_from_input($_POST['amount'] ?? '0');
        $due = $_POST['due_date'] ?? '';
        if (!isset(check_directions()[$direction]) || $amount <= 0 || $due === '') { flash('error','Çek yönü, tutar ve vade tarihi kontrol edilmeli.'); redirect('cekler.php'); }
        $oldDoc = null; $oldCheck = null; $status = 'bekliyor';
        if ($id > 0) {
            $stmt = db()->prepare('SELECT * FROM checks WHERE id=?'); $stmt->execute([$id]); $oldCheck = $stmt->fetch() ?: null;
            if (!$oldCheck || (int)($oldCheck['is_cancelled'] ?? 0) === 1) { flash('error','İptal edilmiş çek düzenlenemez.'); redirect('cekler.php?include_cancelled=1'); }
            $status = (string)($oldCheck['status'] ?? 'bekliyor');
            $oldDoc = ['path'=>$oldCheck['document_path'] ?? null, 'name'=>$oldCheck['document_name'] ?? null, 'mime'=>$oldCheck['document_mime'] ?? null];
        }
        try { $doc = handle_upload('document', $oldDoc); } catch (Throwable $e) { flash('error', $e->getMessage()); redirect('cekler.php'); }
        $cariIdForExtra = ($_POST['cari_id'] ?? '') !== '' ? (int)$_POST['cari_id'] : null;
        $payload = [
            'cari_id'=>$cariIdForExtra,
            'account_id'=>($_POST['account_id'] ?? '') !== '' ? (int)$_POST['account_id'] : null,
            'direction'=>$direction,'status'=>$status,'amount'=>$amount,
            'issue_date'=>$_POST['issue_date'] ?: null,'due_date'=>$due,
            'bank_name'=>trim($_POST['bank_name'] ?? ''),'branch_name'=>trim($_POST['branch_name'] ?? ''),
            'check_no'=>trim($_POST['check_no'] ?? ''),'drawer'=>trim($_POST['drawer'] ?? ''),
            'description'=>trim($_POST['description'] ?? ''),
            'document_path'=>$doc['path'],'document_name'=>$doc['name'],'document_mime'=>$doc['mime'],
            'closed_at'=>$oldCheck['closed_at'] ?? null,
            'is_opening_balance_check'=>isset($_POST['is_opening_balance_check']) ? 1 : 0,
        ];
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE checks SET cari_id=:cari_id, account_id=:account_id, direction=:direction, status=:status, amount=:amount, issue_date=:issue_date, due_date=:due_date, bank_name=:bank_name, branch_name=:branch_name, check_no=:check_no, drawer=:drawer, description=:description, document_path=:document_path, document_name=:document_name, document_mime=:document_mime, closed_at=:closed_at, is_opening_balance_check=:is_opening_balance_check, updated_at=:updated_at WHERE id=:id');
            $payload['updated_at']=now(); $payload['id']=$id; $stmt->execute($payload);
            sync_check_to_movement($id); delete_replaced_upload($oldDoc, $doc);
            save_check_extra_upload($id, $cariIdForExtra, 'front_document', 'cek_on_gorseli', 'Ön görsel');
            save_check_extra_upload($id, $cariIdForExtra, 'back_document', 'cek_arka_gorseli', 'Arka görsel');
            log_action('Çek güncellendi', '#' . $id . ' ' . check_direction_label($direction) . ' ' . money($amount)); audit_action('cek', $id, 'guncellendi', $oldCheck, $payload, check_direction_label($direction)); flash('success','Çek güncellendi.');
        } else {
            $stmt = db()->prepare('INSERT INTO checks (cari_id, account_id, direction, status, amount, issue_date, due_date, bank_name, branch_name, check_no, drawer, description, document_path, document_name, document_mime, closed_at, is_opening_balance_check, created_by, created_at, updated_at) VALUES (:cari_id, :account_id, :direction, :status, :amount, :issue_date, :due_date, :bank_name, :branch_name, :check_no, :drawer, :description, :document_path, :document_name, :document_mime, :closed_at, :is_opening_balance_check, :created_by, :created_at, :updated_at)');
            $payload['created_by']=current_user()['id']; $payload['created_at']=now(); $payload['updated_at']=now(); $stmt->execute($payload); $newId=(int)db()->lastInsertId();
            sync_check_to_movement($newId);
            save_check_extra_upload($newId, $cariIdForExtra, 'front_document', 'cek_on_gorseli', 'Ön görsel');
            save_check_extra_upload($newId, $cariIdForExtra, 'back_document', 'cek_arka_gorseli', 'Arka görsel');
            log_action('Çek eklendi', check_direction_label($direction) . ' ' . money($amount)); audit_action('cek', $newId, 'eklendi', null, $payload, check_direction_label($direction)); flash('success','Çek eklendi.');
        }
        redirect('cekler.php');
    }
    if ($action === 'status') {
        $id=(int)($_POST['id'] ?? 0); $newStatus=$_POST['status'] ?? 'bekliyor';
        if (!array_key_exists($newStatus, ['bekliyor'=>1,'bankaya_verildi'=>1,'tahsil_edildi'=>1,'odendi'=>1,'ciro_edildi'=>1,'iade'=>1,'karsiliksiz'=>1,'protestolu'=>1])) redirect('cekler.php');
        $stmt=db()->prepare('SELECT * FROM checks WHERE id=?'); $stmt->execute([$id]); $old=$stmt->fetch();
        if ($old && (int)($old['is_cancelled'] ?? 0)===0) {
            $closedAt = in_array($newStatus, ['bekliyor','bankaya_verildi'], true) ? null : date('Y-m-d');
            db()->prepare('UPDATE checks SET status=?, closed_at=?, updated_at=? WHERE id=?')->execute([$newStatus,$closedAt,now(),$id]);
            sync_check_to_movement($id); log_action('Çek durumu güncellendi', '#' . $id . ' ' . cek_status_label2($newStatus)); audit_action('cek', $id, 'durum', $old, ['status'=>$newStatus,'closed_at'=>$closedAt], cek_status_label2($newStatus)); flash('success','Çek durumu güncellendi: ' . cek_status_label2($newStatus));
        }
        redirect('cekler.php');
    }
    if ($action === 'cancel') {
        $id=(int)($_POST['id'] ?? 0); $stmt=db()->prepare('SELECT * FROM checks WHERE id=?'); $stmt->execute([$id]); $ch=$stmt->fetch();
        if ($ch && (int)($ch['is_cancelled'] ?? 0)===0) {
            $reason=trim($_POST['cancel_reason'] ?? 'Liste üzerinden iptal');
            db()->prepare('UPDATE checks SET is_cancelled=1, status=?, cancelled_at=?, cancelled_by=?, cancel_reason=?, updated_at=? WHERE id=?')->execute(['iptal', now(), current_user()['id'], $reason, now(), $id]);
            sync_check_to_movement($id, false); log_action('Çek iptal edildi', '#' . $id . ' ' . money($ch['amount'])); audit_action('cek',$id,'iptal',$ch,['is_cancelled'=>1,'cancel_reason'=>$reason],money($ch['amount'])); flash('success','Çek iptal edildi. Kayıt silinmedi; geçmişte korunuyor.');
        }
        redirect('cekler.php');
    }
}

$cariler=cariler_for_select(); $accounts=accounts_for_select(true); $bankList=cek_banka_listesi();
$edit=null; if(!empty($_GET['edit'])){ $stmt=db()->prepare('SELECT * FROM checks WHERE id=?'); $stmt->execute([(int)$_GET['edit']]); $edit=$stmt->fetch() ?: null; if($edit && (int)($edit['is_cancelled'] ?? 0)===1){ flash('error','İptal edilmiş çek düzenlenemez.'); redirect('cekler.php?include_cancelled=1'); } }
$q=trim($_GET['q'] ?? ''); $cariId=trim($_GET['cari_id'] ?? ''); $direction=trim($_GET['direction'] ?? ''); $statusFilter=trim($_GET['status'] ?? ''); $dueFilter=trim($_GET['due_filter'] ?? ''); $accountId=trim($_GET['account_id'] ?? ''); $start=trim($_GET['start'] ?? ''); $end=trim($_GET['end'] ?? ''); $includeCancelled=isset($_GET['include_cancelled']) && $_GET['include_cancelled']==='1';
$where=[]; $params=[]; if(!$includeCancelled){$where[]='COALESCE(ch.is_cancelled,0)=0';}
if($q!==''){ $where[]='(ch.bank_name LIKE ? OR ch.branch_name LIKE ? OR ch.check_no LIKE ? OR ch.drawer LIKE ? OR ch.description LIKE ? OR c.name LIKE ? OR a.name LIKE ?)'; array_push($params,"%$q%","%$q%","%$q%","%$q%","%$q%","%$q%","%$q%"); }
if($cariId!==''){ $where[]='ch.cari_id=?'; $params[]=(int)$cariId; } if($direction!==''){ $where[]='ch.direction=?'; $params[]=$direction; } if($statusFilter!==''){ $where[]='ch.status=?'; $params[]=$statusFilter; } if($accountId!==''){ $where[]='ch.account_id=?'; $params[]=(int)$accountId; } if($start!==''){ $where[]='ch.due_date>=?'; $params[]=$start; } if($end!==''){ $where[]='ch.due_date<=?'; $params[]=$end; }
if($dueFilter==='overdue'){ $where[]="ch.status IN ('bekliyor','bankaya_verildi') AND ch.due_date < ?"; $params[]=$today; } if($dueFilter==='today'){ $where[]="ch.status IN ('bekliyor','bankaya_verildi') AND ch.due_date = ?"; $params[]=$today; } if($dueFilter==='week'){ $where[]="ch.status IN ('bekliyor','bankaya_verildi') AND ch.due_date >= ? AND ch.due_date <= ?"; array_push($params,$today,$weekAhead); }
$sql="SELECT ch.*, c.name AS cari_name, a.name AS account_name FROM checks ch LEFT JOIN cariler c ON c.id=ch.cari_id LEFT JOIN accounts a ON a.id=ch.account_id"; if($where)$sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY ch.due_date ASC, ch.id DESC LIMIT 500'; $stmt=db()->prepare($sql); $stmt->execute($params); $checks=$stmt->fetchAll();
$extraDocs=cek_extra_docs_for_ids(array_column($checks,'id')); $pendingReceived=$pendingGiven=$endorsedAmount=0.0; $overdueCount=$todayCount=$soonCount=0;
foreach($checks as $ch){ if((int)($ch['is_cancelled'] ?? 0)===1) continue; $amount=(float)$ch['amount']; $status=(string)($ch['status'] ?? 'bekliyor'); if($status==='ciro_edildi') $endorsedAmount+=$amount; if(in_array($status,['bekliyor','bankaya_verildi'],true)){ if($ch['direction']==='alinacak')$pendingReceived+=$amount; if($ch['direction']==='verilecek')$pendingGiven+=$amount; if($ch['due_date']<$today)$overdueCount++; elseif($ch['due_date']===$today)$todayCount++; elseif($ch['due_date']<=$weekAhead)$soonCount++; } }
page_header('Çekler','cekler');
?>
<style>.acc-check-screen{width:min(100%,1580px);margin:0 auto}.acc-check-hero,.acc-check-card,.acc-check-form-card,.acc-check-summary-card{background:#fff;border:1px solid #e5dccf;box-shadow:0 18px 54px rgba(7,27,63,.07);border-radius:24px}.acc-check-hero{padding:28px;margin-bottom:18px;background:linear-gradient(135deg,#102818,#23613c);color:#fff}.acc-check-hero span{display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.14);font-size:12px;font-weight:900;letter-spacing:.08em}.acc-check-hero h2{margin:10px 0 0;color:#fff;font-size:clamp(28px,4.2vw,48px);line-height:1}.acc-check-hero p{color:#e7f4ea;max-width:760px}.acc-check-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}.acc-check-summary-card{padding:16px}.acc-check-summary-card span{font-size:12px;color:#8a6a26;font-weight:900}.acc-check-summary-card strong{display:block;margin-top:8px;color:#102818;font-size:22px}.acc-check-grid{display:grid;grid-template-columns:minmax(380px,.72fr) minmax(740px,1.28fr);gap:18px;align-items:start}.acc-check-form-card,.acc-check-card{overflow:hidden}.acc-check-form-card header,.acc-check-card header{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 18px;background:#fbf6ed;border-bottom:1px solid #e5dccf}.acc-check-form-card h3,.acc-check-card h3{margin:4px 0 0;color:#102818}.acc-check-form{padding:18px}.acc-check-fields{display:grid;grid-template-columns:1fr 1fr;gap:13px}.acc-check-fields label{display:grid;gap:7px;font-size:12px;color:#102818;font-weight:850}.acc-check-fields input,.acc-check-fields select,.acc-check-fields textarea{width:100%;min-height:44px;border:1px solid #e5dccf;border-radius:14px;padding:10px 12px;background:#fff;color:#102818}.acc-check-wide{grid-column:1/-1}.acc-check-submit{width:100%;min-height:50px;margin-top:16px;border-radius:999px;background:#16482e;color:#fff;font-weight:900}.acc-check-note{margin:12px 0 0;padding:12px;border-radius:14px;background:#fbf6ed;color:#776b5c}.acc-check-filter{display:grid;grid-template-columns:1.2fr 130px 140px 140px auto;gap:10px;padding:14px;border-bottom:1px solid #e5dccf}.acc-check-filter input,.acc-check-filter select,.acc-check-filter button{min-height:42px;border:1px solid #e5dccf;border-radius:999px;padding:8px 12px;background:#fff;color:#102818;font-weight:800}.acc-check-table-wrap{overflow:auto}.acc-check-table{width:100%;min-width:1320px;border-collapse:collapse}.acc-check-table th{background:#16482e;color:#fff;text-align:left;padding:11px;font-size:12px}.acc-check-table td{border-bottom:1px solid #e5dccf;padding:12px;vertical-align:top;font-size:13px}.acc-check-table td b{display:block;color:#102818}.acc-check-table td span,.acc-check-table td small{display:block;color:#776b5c;font-size:12px;margin-top:3px}.acc-check-table tr.is-receivable td{background:#f5fff8}.acc-check-table tr.is-payable td{background:#fff8f1}.acc-check-table tr.is-endorsed td{background:#fff8dd}.acc-check-table tr.is-overdue td{background:#fff1ed}.acc-check-life{display:grid;gap:3px;margin-top:8px}.acc-check-life em{display:block;border-left:3px solid #16482e;padding:3px 0 3px 7px;color:#102818;font-size:11px;font-style:normal;background:rgba(22,72,46,.06);border-radius:6px}.acc-check-image-link{display:inline-flex;margin:2px 4px 2px 0;min-height:30px;border:1px solid #e5dccf;border-radius:999px;padding:5px 10px;background:#fff;color:#102818;text-decoration:none;font-weight:900;font-size:12px}.acc-check-actions{display:flex;gap:6px;flex-wrap:wrap}.acc-check-actions button,.acc-check-actions a{min-height:32px;border-radius:999px;padding:6px 10px;background:#fff;border:1px solid #e5dccf;color:#102818;font-weight:800;font-size:12px;text-decoration:none}.acc-check-actions button.danger{color:#b64242;border-color:rgba(182,66,66,.22)}@media(max-width:1180px){.acc-check-grid{grid-template-columns:1fr}.acc-check-summary{grid-template-columns:repeat(2,1fr)}}@media(max-width:760px){.acc-check-summary{grid-template-columns:1fr 1fr}.acc-check-filter{grid-template-columns:1fr}.acc-check-screen{padding:0}.acc-check-hero{padding:20px}.acc-check-table{min-width:980px}}</style>
<div class="acc-check-screen"><section class="acc-check-hero"><span>BİTKE ÇEK TAKİBİ</span><h2>Çek yaşam döngüsüyle takip.</h2><p>Çekin alındı, bankaya verildi, ciro edildi, tahsil oldu, karşılıksız çıktı veya iptal edildi adımlarını tek ekranda izleyebilirsin. Ön/arka görsel ve ek belgeler çek kartına bağlanır.</p></section><section class="acc-check-summary"><article class="acc-check-summary-card"><span>Alınan bekleyen</span><strong><?php echo e(money($pendingReceived)); ?></strong></article><article class="acc-check-summary-card"><span>Verilen bekleyen</span><strong><?php echo e(money($pendingGiven)); ?></strong></article><article class="acc-check-summary-card"><span>Ciro edilen</span><strong><?php echo e(money($endorsedAmount)); ?></strong></article><article class="acc-check-summary-card"><span>Vade uyarısı</span><strong><?php echo e($overdueCount+$todayCount+$soonCount); ?> adet</strong></article></section>
<section class="acc-check-grid"><form method="post" enctype="multipart/form-data" class="acc-check-form-card" id="cek-ekle"><header><div><span>Çek kaydı</span><h3><?php echo $edit ? 'Çek düzenle' : 'Yeni çek'; ?></h3></div><select name="direction" required><?php foreach(check_directions() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo (($edit['direction'] ?? 'alinacak')===$key)?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select></header><?php if(can_write()): ?><div class="acc-check-form"><?php echo csrf_field(); ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e($edit['id'] ?? 0); ?>"><div class="acc-check-fields"><label class="acc-check-wide"><span>Cari</span><select name="cari_id" required><option value="">Cari seç</option><?php foreach($cariler as $c): $selected=(string)($edit['cari_id'] ?? ($_GET['cari_id'] ?? ''))===(string)$c['id']; ?><option value="<?php echo e($c['id']); ?>" <?php echo $selected?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></label><label><span>Banka</span><select name="bank_name"><option value="">Banka seç</option><?php $currentBank=trim((string)($edit['bank_name'] ?? '')); if($currentBank!==''&&!in_array($currentBank,$bankList,true)): ?><option value="<?php echo e($currentBank); ?>" selected><?php echo e($currentBank); ?></option><?php endif; ?><?php foreach($bankList as $bank): ?><option value="<?php echo e($bank); ?>" <?php echo $currentBank===$bank?'selected':''; ?>><?php echo e($bank); ?></option><?php endforeach; ?></select></label><label><span>Çek no</span><input name="check_no" value="<?php echo e($edit['check_no'] ?? ''); ?>" placeholder="Çek no"></label><label><span>Vade</span><input name="due_date" type="date" required value="<?php echo e($edit['due_date'] ?? date('Y-m-d')); ?>"></label><label><span>Tutar</span><input name="amount" type="text" inputmode="decimal" required value="<?php echo e($edit['amount'] ?? ''); ?>" placeholder="100000"></label><label><span>Çek tarihi</span><input name="issue_date" type="date" value="<?php echo e($edit['issue_date'] ?? ''); ?>"></label><label><span>Şube</span><input name="branch_name" value="<?php echo e($edit['branch_name'] ?? ''); ?>"></label><label><span>Keşideci / Veren</span><input name="drawer" value="<?php echo e($edit['drawer'] ?? ''); ?>"></label><label class="acc-check-wide"><span>Bilgi amaçlı hesap</span><select name="account_id"><option value="">Hesap seçilmedi</option><?php foreach($accounts as $a): ?><option value="<?php echo e($a['id']); ?>" <?php echo ((string)($edit['account_id'] ?? '')===(string)$a['id'])?'selected':''; ?>><?php echo e($a['name']); ?> — <?php echo e(account_type_label($a['account_type'])); ?></option><?php endforeach; ?></select></label><label class="acc-check-wide"><span>Açıklama</span><textarea name="description" rows="2" placeholder="Opsiyonel not"><?php echo e($edit['description'] ?? ''); ?></textarea></label><label><span>Çek ön görsel</span><input name="front_document" type="file" accept="image/*,application/pdf"></label><label><span>Çek arka görsel</span><input name="back_document" type="file" accept="image/*,application/pdf"></label><label class="acc-check-wide"><span>Ana çek belgesi</span><input name="document" type="file" accept="image/*,application/pdf"></label><label class="acc-check-wide check"><input type="checkbox" name="is_opening_balance_check" value="1" <?php echo ((int)($edit['is_opening_balance_check'] ?? 0)===1)?'checked':''; ?>> Bu çek eski/devir çek; cari bakiyesi daha önce net girildi</label></div><p class="acc-check-note">Ön/arka görsel opsiyonel. Kaydedilince çek ek belgeleri kartında görünür.</p><button class="acc-check-submit" type="submit"><?php echo $edit ? 'Çek Güncelle' : 'Çek Kaydet'; ?></button><?php if($edit): ?><a class="btn btn-secondary" style="margin-top:10px;width:100%" href="cekler.php">Vazgeç</a><?php endif; ?></div><?php else: ?><div class="acc-check-form"><p class="muted">Görüntüleme yetkisindesiniz. Çek ekleme/düzenleme kapalı.</p></div><?php endif; ?></form>
<section class="acc-check-card"><header><div><span>Çek listesi</span><h3><?php echo e(count($checks)); ?> kayıt</h3></div><a class="btn btn-secondary" href="export.php?type=checks&<?php echo e(http_build_query($_GET)); ?>">CSV</a></header><form class="acc-check-filter" method="get"><input name="q" placeholder="Cari, banka, çek no, durum, hareket ara" value="<?php echo e($q); ?>"><select name="direction"><option value="">Tümü</option><?php foreach(check_directions() as $key=>$meta): ?><option value="<?php echo e($key); ?>" <?php echo $direction===$key?'selected':''; ?>><?php echo e($meta['label']); ?></option><?php endforeach; ?></select><select name="status"><option value="">Tüm durumlar</option><?php foreach(['bekliyor','bankaya_verildi','ciro_edildi','tahsil_edildi','odendi','karsiliksiz','protestolu','iade'] as $st): ?><option value="<?php echo e($st); ?>" <?php echo $statusFilter===$st?'selected':''; ?>><?php echo e(cek_status_label2($st)); ?></option><?php endforeach; ?></select><select name="due_filter"><option value="">Tüm vadeler</option><option value="overdue" <?php echo $dueFilter==='overdue'?'selected':''; ?>>Geciken</option><option value="today" <?php echo $dueFilter==='today'?'selected':''; ?>>Bugün</option><option value="week" <?php echo $dueFilter==='week'?'selected':''; ?>>7 gün</option></select><button type="submit">Filtrele</button></form><div class="acc-check-table-wrap"><table class="acc-check-table"><thead><tr><th>Tür / Cari</th><th>Banka / No</th><th>Vade</th><th>Tutar</th><th>Durum / Yaşam Döngüsü</th><th>Görseller</th><th>İşlem</th></tr></thead><tbody><?php if(!$checks): ?><tr><td colspan="7">Çek kaydı yok.</td></tr><?php endif; ?><?php foreach($checks as $ch): $cancelled=(int)($ch['is_cancelled'] ?? 0)===1; $status=$cancelled?'iptal':(string)($ch['status'] ?? 'bekliyor'); $isOverdue=!$cancelled&&in_array($status,['bekliyor','bankaya_verildi'],true)&&$ch['due_date']<$today; $rowClass=($ch['direction']==='alinacak'?'is-receivable ':'is-payable ').($status==='ciro_edildi'?'is-endorsed ':'').($isOverdue?'is-overdue ':''); $docs=$extraDocs[(int)$ch['id']] ?? []; $frontDocs=array_values(array_filter($docs, fn($d)=>($d['document_type'] ?? '')==='cek_on_gorseli')); $backDocs=array_values(array_filter($docs, fn($d)=>($d['document_type'] ?? '')==='cek_arka_gorseli')); ?><tr class="<?php echo e(trim($rowClass)); ?>"><td><b><?php echo e(check_direction_label($ch['direction'])); ?></b><span><?php echo $ch['cari_id'] ? '<a href="cari-detay.php?id='.e($ch['cari_id']).'">'.e($ch['cari_name']).'</a>' : '-'; ?></span><?php echo ((int)($ch['is_opening_balance_check'] ?? 0)===1)?'<span>Devir dengeli</span>':''; ?></td><td><b><?php echo e($ch['bank_name'] ?: '-'); ?></b><span><?php echo e($ch['check_no'] ?: '-'); ?></span><?php echo $ch['drawer']?'<small>'.e($ch['drawer']).'</small>':''; ?></td><td><b><?php echo e(tr_date($ch['due_date'])); ?></b><span><?php echo e(cek_due_text($ch,$today)); ?></span><?php echo $ch['issue_date']?'<small>Çek tarihi: '.e(tr_date($ch['issue_date'])).'</small>':''; ?></td><td><b><?php echo e(money($ch['amount'])); ?></b><span>TRY</span></td><td><b><?php echo e(cek_status_label2($status)); ?></b><span>Son hareket: <?php echo e(cek_status_label2($status)); ?></span><?php echo $ch['description']?'<small>'.e($ch['description']).'</small>':''; ?><div class="acc-check-life"><em><?php echo e(tr_date(substr((string)($ch['created_at'] ?? $today),0,10))); ?> · Kayda alındı</em><?php if(!empty($ch['updated_at'])&&substr((string)$ch['updated_at'],0,10)!==substr((string)($ch['created_at'] ?? ''),0,10)): ?><em><?php echo e(tr_date(substr((string)$ch['updated_at'],0,10))); ?> · Düzenlendi</em><?php endif; ?><?php if($status!=='bekliyor'): ?><em><?php echo e(tr_date($ch['closed_at'] ?: substr((string)($ch['updated_at'] ?? $today),0,10))); ?> · <?php echo e(cek_status_label2($status)); ?></em><?php endif; ?></div></td><td><?php if($frontDocs): ?><a class="acc-check-image-link" href="serbest-belge-indir.php?id=<?php echo e($frontDocs[0]['id']); ?>" target="_blank">Ön Görsel</a><?php elseif(!empty($ch['document_path'])): ?><a class="acc-check-image-link" href="cek-belge-indir.php?id=<?php echo e($ch['id']); ?>" target="_blank">Ana Belge</a><?php else: ?><span>Ön yok</span><?php endif; ?><?php if($backDocs): ?><a class="acc-check-image-link" href="serbest-belge-indir.php?id=<?php echo e($backDocs[0]['id']); ?>" target="_blank">Arka Görsel</a><?php else: ?><span>Arka yok</span><?php endif; ?><?php if(count($docs)>2): ?><small><?php echo e(count($docs)); ?> ek belge</small><?php endif; ?></td><td><div class="acc-check-actions"><?php if(!$cancelled): ?><a href="cekler.php?edit=<?php echo e($ch['id']); ?>">Düzenle</a><a href="cek-ek-belge.php?id=<?php echo e($ch['id']); ?>">Ek belge</a><?php if(can_write()): ?><?php foreach([['bankaya_verildi','Bankaya Verildi'],['ciro_edildi','Ciro Et'],[$ch['direction']==='alinacak'?'tahsil_edildi':'odendi',$ch['direction']==='alinacak'?'Tahsil Oldu':'Ödendi'],['karsiliksiz','Karşılıksız'],['bekliyor','Beklemeye Al']] as $st): ?><form method="post"><input type="hidden" name="action" value="status"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo e($ch['id']); ?>"><input type="hidden" name="status" value="<?php echo e($st[0]); ?>"><button type="submit"><?php echo e($st[1]); ?></button></form><?php endforeach; ?><form method="post" onsubmit="return confirm('Çek silinmeyecek, iptal edildi olarak işaretlenecek. Devam edilsin mi?');"><?php echo csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e($ch['id']); ?>"><input type="hidden" name="cancel_reason" value="Liste üzerinden iptal"><button class="danger">İptal</button></form><?php endif; ?><?php else: ?><span class="muted">Kayıt korundu</span><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div></section></section></div>
<?php page_footer(); ?>
