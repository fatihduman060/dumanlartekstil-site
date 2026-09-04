<?php
require_once __DIR__ . '/teklif-db.php';

function depo_cikis_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_dispatches (
        id INTEGER PRIMARY KEY AUTOINCREMENT, dispatch_no TEXT NOT NULL, dispatch_date TEXT NOT NULL,
        cari_id INTEGER, customer_name TEXT NOT NULL, customer_city TEXT, customer_address TEXT,
        note TEXT, currency TEXT NOT NULL DEFAULT 'TL', total REAL NOT NULL DEFAULT 0,
        processed INTEGER NOT NULL DEFAULT 0, processed_at TEXT, processed_by INTEGER,
        posted_to_cari INTEGER NOT NULL DEFAULT 0, cari_movement_id INTEGER,
        created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        FOREIGN KEY(cari_id) REFERENCES cariler(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_dispatch_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, dispatch_id INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0,
        product_barcode TEXT, product_name TEXT, product_type TEXT, quantity REAL NOT NULL DEFAULT 0,
        unit_price REAL NOT NULL DEFAULT 0, line_total REAL NOT NULL DEFAULT 0,
        FOREIGN KEY(dispatch_id) REFERENCES warehouse_dispatches(id) ON DELETE CASCADE
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_warehouse_dispatch_date ON warehouse_dispatches(dispatch_date,id)');
}

function depo_cikis_next_no(): string
{
    depo_cikis_db_ensure();
    $max = (int)(db()->query("SELECT MAX(CAST(dispatch_no AS INTEGER)) FROM warehouse_dispatches WHERE dispatch_no GLOB '[0-9]*'")->fetchColumn() ?: 0);
    return str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

function depo_cikis_load(int $id): ?array
{
    depo_cikis_db_ensure();
    $s=db()->prepare('SELECT * FROM warehouse_dispatches WHERE id=?'); $s->execute([$id]); $row=$s->fetch();
    if(!$row) return null;
    $s=db()->prepare('SELECT * FROM warehouse_dispatch_items WHERE dispatch_id=? ORDER BY sort_order,id'); $s->execute([$id]);
    $row['items']=$s->fetchAll(); return $row;
}

function depo_cikis_can_edit(array $row): bool
{
    return can_process_warehouse_dispatch() || (is_warehouse_user() && (int)($row['created_by'] ?? 0)===(int)(current_user()['id'] ?? 0));
}

function depo_cikis_save(int $id): int
{
    depo_cikis_db_ensure();
    $existing=$id>0?depo_cikis_load($id):null;
    if($existing && !depo_cikis_can_edit($existing)) throw new RuntimeException('Bu fişi düzenleme yetkiniz yok.');
    if($existing && is_warehouse_user() && (int)($existing['posted_to_cari']??0)===1) throw new RuntimeException('Cariye işlenmiş fişi yalnızca yönetici düzeltebilir.');
    $items=teklif_parse_items_from_post(); if(!$items) throw new RuntimeException('En az bir ürün girilmeli.');
    $total=array_sum(array_column($items,'line_total')); $name=trim((string)($_POST['customer_name']??''));
    if($name==='') throw new RuntimeException('Firma / müşteri adı gerekli.');
    $data=[trim((string)($_POST['dispatch_no']??'')),trim((string)($_POST['dispatch_date']??''))?:date('Y-m-d'),(int)($_POST['cari_id']??0)?:null,$name,trim((string)($_POST['customer_city']??'')),trim((string)($_POST['customer_address']??'')),trim((string)($_POST['note']??'')),'TL',$total,now()];
    $pdo=db(); $pdo->beginTransaction();
    try {
        if($existing){$data[]=$id;$pdo->prepare('UPDATE warehouse_dispatches SET dispatch_no=?,dispatch_date=?,cari_id=?,customer_name=?,customer_city=?,customer_address=?,note=?,currency=?,total=?,updated_at=? WHERE id=?')->execute($data);$pdo->prepare('DELETE FROM warehouse_dispatch_items WHERE dispatch_id=?')->execute([$id]);}
        else{$data[]=(int)(current_user()['id']??0);$data[]=now();$pdo->prepare('INSERT INTO warehouse_dispatches(dispatch_no,dispatch_date,cari_id,customer_name,customer_city,customer_address,note,currency,total,updated_at,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute($data);$id=(int)$pdo->lastInsertId();}
        $s=$pdo->prepare('INSERT INTO warehouse_dispatch_items(dispatch_id,sort_order,product_barcode,product_name,product_type,quantity,unit_price,line_total) VALUES(?,?,?,?,?,?,?,?)');
        foreach($items as $i=>$item)$s->execute([$id,$i,$item['product_barcode'],$item['product_name'],$item['product_type'],$item['quantity'],$item['unit_price'],$item['line_total']]);
        $pdo->commit(); log_action($existing?'Depo çıkış fişi güncellendi':'Depo çıkış fişi oluşturuldu',($data[0]?:('#'.$id))); return $id;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function depo_cikis_mark_processed(int $id): void
{
    if(!can_process_warehouse_dispatch()) throw new RuntimeException('Bu işlem için yetkiniz yok.');
    db()->prepare('UPDATE warehouse_dispatches SET processed=1,processed_at=?,processed_by=?,updated_at=? WHERE id=?')->execute([now(),current_user()['id']??null,now(),$id]);
}

function depo_cikis_post_to_cari(int $id): int
{
    if(!can_process_warehouse_dispatch()) throw new RuntimeException('Bu işlem için yetkiniz yok.');
    $row=depo_cikis_load($id); if(!$row)throw new RuntimeException('Fiş bulunamadı.');
    $cariId=(int)($row['cari_id']??0); if($cariId<=0)throw new RuntimeException('Cariye işlemek için fişte cari seçilmeli.');
    $total=(float)($row['total']??0); if($total<=0)throw new RuntimeException('Fiş toplamı bulunamadı.');
    $mid=teklif_active_movement_id((int)($row['cari_movement_id']??0));
    $desc='Depo çıkış sipariş fişi no: '.trim((string)$row['dispatch_no']).' / Ürün satışı'; $now=now();
    if($mid){db()->prepare('UPDATE movements SET cari_id=?,movement_type=?,amount=?,movement_date=?,payment_method=?,description=?,document_type=?,updated_at=? WHERE id=?')->execute([$cariId,'alacak',$total,$row['dispatch_date'],'Depo çıkış fişi',$desc,'depo_cikis_fisi',$now,$mid]);}
    else{$s=db()->prepare('INSERT INTO movements(cari_id,category_id,movement_type,amount,movement_date,payment_method,description,document_type,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$cariId,teklif_category_id('Satış'),'alacak',$total,$row['dispatch_date'],'Depo çıkış fişi',$desc,'depo_cikis_fisi',current_user()['id']??null,$now,$now]);$mid=(int)db()->lastInsertId();}
    db()->prepare('UPDATE warehouse_dispatches SET posted_to_cari=1,cari_movement_id=?,processed=1,processed_at=COALESCE(processed_at,?),processed_by=COALESCE(processed_by,?),updated_at=? WHERE id=?')->execute([$mid,$now,current_user()['id']??null,$now,$id]);
    log_action('Depo çıkış fişi cariye işlendi',trim((string)$row['dispatch_no']).' - '.money($total)); return $mid;
}
