<?php
// One invoice / one dispatch. No financial values are copied or reconciled implicitly.
function satis_bag_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sale_document_links (
        invoice_id INTEGER PRIMARY KEY, dispatch_id INTEGER NOT NULL UNIQUE,
        movement_source TEXT NOT NULL DEFAULT '', created_by INTEGER, created_at TEXT NOT NULL
    )");
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('invoices', $tables, true) || !in_array('warehouse_dispatches', $tables, true)) return;
    ensure_column($pdo, 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");
    // Guards also cover legacy quick-edit endpoints and concurrent stale requests.
    foreach (['invoices'=>['invoice_id', ['cari_id','direction','invoice_date','due_date','subtotal','vat_amount','total_amount','currency']],
              'warehouse_dispatches'=>['dispatch_id', ['cari_id','dispatch_date','total','currency']]] as $table=>$spec) {
        $changes = [];
        foreach ($spec[1] as $column) $changes[] = 'NEW.'.$column.' IS NOT OLD.'.$column;
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS sale_link_guard_".$table." BEFORE UPDATE ON ".$table."
            WHEN EXISTS(SELECT 1 FROM sale_document_links WHERE ".$spec[0]."=OLD.id)
            AND (".implode(' OR ', $changes)." OR NEW.is_cancelled IS NOT OLD.is_cancelled)
            BEGIN SELECT RAISE(ABORT, 'Bağlı belgede cari, tarih, tutar, para birimi veya iptal değişikliği için önce bağlantıyı kaldırın.'); END");
        $pdo->exec("CREATE TRIGGER IF NOT EXISTS sale_link_delete_".$table." BEFORE DELETE ON ".$table."
            WHEN EXISTS(SELECT 1 FROM sale_document_links WHERE ".$spec[0]."=OLD.id)
            BEGIN SELECT RAISE(ABORT, 'Bağlı belge silinemez.'); END");
    }
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS sale_link_guard_movement BEFORE UPDATE ON movements
        WHEN EXISTS(SELECT 1 FROM sale_document_links l JOIN invoices i ON i.id=l.invoice_id WHERE i.cari_movement_id=OLD.id)
        AND (NEW.cari_id IS NOT OLD.cari_id OR NEW.amount IS NOT OLD.amount OR NEW.currency IS NOT OLD.currency
             OR NEW.movement_type IS NOT OLD.movement_type OR NEW.movement_date IS NOT OLD.movement_date
             OR NEW.due_date IS NOT OLD.due_date OR NEW.account_id IS NOT OLD.account_id)
        BEGIN SELECT RAISE(ABORT, 'Ortak cari hareketinin finansal bilgileri değiştirilemez; önce belge bağlantısını kaldırın.'); END");
}

function satis_bag_get(string $side, int $id): ?array
{
    $column = $side === 'invoice' ? 'invoice_id' : 'dispatch_id';
    $s = db()->prepare('SELECT * FROM sale_document_links WHERE '.$column.'=?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function satis_bag_row(string $table, int $id): array
{
    if (!in_array($table, ['invoices','warehouse_dispatches','movements'], true)) throw new RuntimeException('Geçersiz belge.');
    $s = db()->prepare('SELECT * FROM '.$table.' WHERE id=?'); $s->execute([$id]);
    $row = $s->fetch();
    if (!$row) throw new RuntimeException('Bağlı belge veya cari hareket bulunamadı.');
    return $row;
}

function satis_bag_active(int $id): int
{
    if (!$id) return 0;
    $s = db()->prepare('SELECT id FROM movements WHERE id=? AND COALESCE(is_cancelled,0)=0');
    $s->execute([$id]); return (int)($s->fetchColumn() ?: 0);
}

function satis_bag_validate(array $i, array $d, bool $dateConfirmed): void
{
    if (!empty($i['is_cancelled']) || !empty($d['is_cancelled'])) throw new RuntimeException('İptal edilmiş belge bağlanamaz.');
    if ($i['direction'] !== 'giden') throw new RuntimeException('Depo çıkışı yalnız giden satış faturasıyla bağlanabilir.');
    if (!(int)$i['cari_id'] || (int)$i['cari_id'] !== (int)$d['cari_id']) throw new RuntimeException('Cari uyuşmazlığı: iki belgede aynı cari seçilmelidir.');
    if ($i['currency'] !== $d['currency'] || round((float)$i['total_amount'],2) !== round((float)$d['total'],2) || (float)$i['total_amount'] <= 0) {
        throw new RuntimeException('Tutar / para birimi uyuşmazlığı: fatura ve depo çıkışı toplamlarını kontrol edin. Finansal veri değiştirilmedi.');
    }
    if ($i['invoice_date'] !== $d['dispatch_date'] && !$dateConfirmed) throw new RuntimeException('Tarih uyuşmazlığı: fatura '.$i['invoice_date'].', depo çıkışı '.$d['dispatch_date'].'. Tarih farkını kontrol edip onaylayın.');
}

function satis_bag_movement(array $i, array $d): int
{
    $a = satis_bag_active((int)($i['cari_movement_id'] ?? 0));
    $b = satis_bag_active((int)($d['cari_movement_id'] ?? 0));
    if ($a && $b && $a !== $b) throw new RuntimeException('İki ayrı aktif cari hareketi var. Önce mükerrer hareketi mevcut iptal işlemiyle, nedeni kaydedilerek düzeltin. Otomatik birleştirme yapılmadı.');
    $mid = $a ?: $b;
    if ($mid) {
        $m = satis_bag_row('movements', $mid);
        if ((int)$m['cari_id'] !== (int)$i['cari_id'] || $m['currency'] !== $i['currency'] || $m['movement_type'] !== 'alacak'
            || round((float)$m['amount'],2) !== round((float)$i['total_amount'],2) || !empty($m['account_id'])) {
            throw new RuntimeException('Mevcut cari hareketi ile belgelerin cari / tutar / para birimi uyuşmuyor. Hareket değiştirilmedi.');
        }
    }
    return $mid;
}

function satis_bag_share(array $link, int $mid): void
{
    db()->prepare('UPDATE invoices SET cari_movement_id=?,posted_to_cari=1,posted_at=COALESCE(posted_at,?),posted_by=COALESCE(posted_by,?),updated_at=? WHERE id=?')
        ->execute([$mid,now(),current_user()['id']??null,now(),$link['invoice_id']]);
    db()->prepare('UPDATE warehouse_dispatches SET cari_movement_id=?,posted_to_cari=1,updated_at=? WHERE id=?')
        ->execute([$mid,now(),$link['dispatch_id']]);
}

// Caller owns the transaction; acquire SQLite's write lock BEFORE reading either record.
function satis_bag_lock(): void
{
    db()->exec('UPDATE sale_document_links SET invoice_id=invoice_id WHERE 0');
}

function satis_bag_audit(string $action, $old, $new, string $reason): void
{
    // Unlike the legacy best-effort logger, linkage audit must commit or fail with the change.
    $u = current_user();
    db()->prepare('INSERT INTO audit_logs(user_id,username,entity_type,entity_id,action,old_value,new_value,detail,ip,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([$u['id']??null,$u['username']??null,'fatura_depo_bagi',($new??$old)['invoice_id'],$action,
            $old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE),$new===null?null:json_encode($new,JSON_UNESCAPED_UNICODE),$reason,client_ip(),now()]);
}

function satis_bag_set(int $invoiceId, int $dispatchId, bool $dateConfirmed, string $reason): void
{
    if (!db()->inTransaction()) throw new RuntimeException('Belge bağlantısı işlem içinde kaydedilmelidir.');
    if (!can_write() || !can_process_warehouse_dispatch()) throw new RuntimeException('Belge bağlamak için fatura ve depo muhasebe yetkisi gerekir.');
    $old = satis_bag_get('invoice', $invoiceId);
    if ((int)($old['dispatch_id']??0) === $dispatchId) return;
    if ($old && strlen(trim($reason)) < 6) throw new RuntimeException('Bağlantı kaldırma / değiştirme nedeni en az 6 karakter olmalıdır.');
    if ($old) {
        $i = satis_bag_row('invoices',$invoiceId);
        $d = satis_bag_row('warehouse_dispatches',(int)$old['dispatch_id']);
        if (satis_bag_active((int)$i['cari_movement_id']) || satis_bag_active((int)$d['cari_movement_id'])) {
            throw new RuntimeException('Aktif ortak cari hareketi varken bağlantı kaldırılamaz/değiştirilemez. Önce hareketi nedeni ile iptal edin.');
        }
        db()->prepare('DELETE FROM sale_document_links WHERE invoice_id=?')->execute([$invoiceId]);
        // Keep cancelled movement references for history; only clear posted flags.
        db()->prepare('UPDATE invoices SET posted_to_cari=0,updated_at=? WHERE id=?')->execute([now(),$invoiceId]);
        db()->prepare('UPDATE warehouse_dispatches SET posted_to_cari=0,updated_at=? WHERE id=?')->execute([now(),$old['dispatch_id']]);
    }
    $new = null;
    if ($dispatchId) {
        $i = satis_bag_row('invoices',$invoiceId); $d = satis_bag_row('warehouse_dispatches',$dispatchId);
        satis_bag_validate($i,$d,$dateConfirmed);
        if (satis_bag_get('dispatch',$dispatchId)) throw new RuntimeException('Bu depo çıkışı başka bir faturaya bağlı.');
        $mid = satis_bag_movement($i,$d);
        $source = $mid ? (satis_bag_active((int)$d['cari_movement_id']) ? 'dispatch' : 'invoice') : '';
        db()->prepare('INSERT INTO sale_document_links(invoice_id,dispatch_id,movement_source,created_by,created_at) VALUES(?,?,?,?,?)')
            ->execute([$invoiceId,$dispatchId,$source,current_user()['id']??null,now()]);
        $new = satis_bag_get('invoice',$invoiceId);
        if ($mid) satis_bag_share($new,$mid);
    }
    satis_bag_audit($old ? ($new?'yeniden_baglandi':'bag_kaldirildi') : 'baglandi',$old,$new,$reason);
}

// Called under write lock by either posting route. Returns the existing shared movement.
function satis_bag_before_post(string $side, int $id): int
{
    $link = satis_bag_get($side,$id);
    if (!$link) return 0;
    $i = satis_bag_row('invoices',(int)$link['invoice_id']); $d = satis_bag_row('warehouse_dispatches',(int)$link['dispatch_id']);
    satis_bag_validate($i,$d,true);
    $mid = satis_bag_movement($i,$d);
    if ($mid) satis_bag_share($link,$mid);
    return $mid;
}

function satis_bag_after_post(string $side, int $id, int $mid): void
{
    $link = satis_bag_get($side,$id);
    if (!$link) return;
    $before = $link;
    db()->prepare('UPDATE sale_document_links SET movement_source=? WHERE invoice_id=?')->execute([$side,$link['invoice_id']]);
    satis_bag_share($link,$mid);
    satis_bag_audit('ortak_cari_hareketi',$before,array_merge($link,['movement_source'=>$side,'cari_movement_id'=>$mid]),'İki belge aynı aktif cari hareketine bağlandı.');
}

function satis_bag_display(string $side, int $id): void
{
    $link = satis_bag_get($side,$id); if (!$link) return;
    if ($side==='dispatch' && !can_process_warehouse_dispatch()) { echo '<div>Fatura bağlantısı mevcut.</div>'; return; }
    $invoice = satis_bag_row('invoices',(int)$link['invoice_id']);
    $dispatch = satis_bag_row('warehouse_dispatches',(int)$link['dispatch_id']);
    $url = $side==='invoice' ? 'depo-cikis.php?edit='.$link['dispatch_id'] : 'faturalar.php?edit='.$link['invoice_id'];
    $label = $side==='invoice' ? 'Depo çıkışı #'.$dispatch['dispatch_no'] : 'Fatura '.$invoice['invoice_no'];
    echo '<div class="calc-note"><a href="'.e($url).'">'.e($label).'</a><br>';
    $mid = satis_bag_active((int)$invoice['cari_movement_id']);
    echo $mid ? e(($link['movement_source']==='dispatch'?'Depo çıkışı':'Fatura').' üzerinden cariye işlendi · Hareket #'.$mid) : 'Aktif cari hareketi yok.';
    if ($invoice['invoice_date']!==$dispatch['dispatch_date']) echo '<br>'.e('Tarih farkı: fatura '.$invoice['invoice_date'].' / depo '.$dispatch['dispatch_date'].'. Mevcut hareket tarihi korundu.');
    echo '</div>';
}
