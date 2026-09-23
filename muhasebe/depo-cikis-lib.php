<?php
require_once __DIR__ . '/teklif-db.php';

function depo_cikis_table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if ((string)($row['name'] ?? '') === $column) return true;
        }
    } catch (Throwable $e) {
    }
    return false;
}

function depo_cikis_db_ensure(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_dispatches (
        id INTEGER PRIMARY KEY AUTOINCREMENT, dispatch_no TEXT NOT NULL, dispatch_date TEXT NOT NULL,
        cari_id INTEGER, customer_name TEXT NOT NULL, customer_city TEXT, customer_address TEXT,
        note TEXT, currency TEXT NOT NULL DEFAULT 'TL',
        subtotal REAL NOT NULL DEFAULT 0,
        discount_enabled INTEGER NOT NULL DEFAULT 0, discount_rate REAL NOT NULL DEFAULT 0, discount_amount REAL NOT NULL DEFAULT 0,
        vat_enabled INTEGER NOT NULL DEFAULT 0, vat_rate REAL NOT NULL DEFAULT 10, vat_amount REAL NOT NULL DEFAULT 0,
        total REAL NOT NULL DEFAULT 0,
        processed INTEGER NOT NULL DEFAULT 0, processed_at TEXT, processed_by INTEGER,
        posted_to_cari INTEGER NOT NULL DEFAULT 0, cari_movement_id INTEGER,
        source_offer_id INTEGER,
        created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
        FOREIGN KEY(cari_id) REFERENCES cariler(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    )");
    try { ensure_column($pdo, 'warehouse_dispatches', 'source_offer_id', 'INTEGER'); } catch (Throwable $e) {}
    try { ensure_column($pdo, 'warehouse_dispatches', 'is_cancelled', 'INTEGER NOT NULL DEFAULT 0'); } catch (Throwable $e) {}
    try { ensure_column($pdo, 'warehouse_dispatches', 'cancelled_at', 'TEXT'); } catch (Throwable $e) {}
    try { ensure_column($pdo, 'warehouse_dispatches', 'cancelled_by', 'INTEGER'); } catch (Throwable $e) {}
    try { ensure_column($pdo, 'warehouse_dispatches', 'cancel_reason', 'TEXT'); } catch (Throwable $e) {}
    foreach ([
        'subtotal' => 'REAL NOT NULL DEFAULT 0',
        'discount_enabled' => 'INTEGER NOT NULL DEFAULT 0',
        'discount_rate' => 'REAL NOT NULL DEFAULT 0',
        'discount_amount' => 'REAL NOT NULL DEFAULT 0',
        'vat_enabled' => 'INTEGER NOT NULL DEFAULT 0',
        'vat_rate' => 'REAL NOT NULL DEFAULT 10',
        'vat_amount' => 'REAL NOT NULL DEFAULT 0',
    ] as $column => $definition) {
        try { ensure_column($pdo, 'warehouse_dispatches', $column, $definition); } catch (Throwable $e) {}
    }
    // Eski fişlerde toplam, iskonto/KDV uygulanmamış ara toplamdır; mevcut kayıtları aynen koru.
    try {
        $pdo->exec("UPDATE warehouse_dispatches SET subtotal=total WHERE COALESCE(subtotal,0)=0 AND COALESCE(total,0)<>0 AND COALESCE(discount_amount,0)=0 AND COALESCE(vat_amount,0)=0");
    } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouse_dispatch_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, dispatch_id INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0,
        product_barcode TEXT, product_name TEXT, product_type TEXT, quantity REAL NOT NULL DEFAULT 0,
        unit_price REAL NOT NULL DEFAULT 0, line_total REAL NOT NULL DEFAULT 0,
        FOREIGN KEY(dispatch_id) REFERENCES warehouse_dispatches(id) ON DELETE CASCADE
    )");
    try {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_warehouse_dispatch_date ON warehouse_dispatches(dispatch_date,id)');
    } catch (Throwable $e) {
    }

    // Şema/index yükseltmeleri sayfa açılışını hiçbir koşulda kilitlememeli.
    // Canlı veride eski kolon/index yapısı varsa mevcut finansal kayıtları değiştirmeden
    // uygulama seviyesi mükerrer kontrolüyle devam et.
    $hasSourceOffer = depo_cikis_table_has_column($pdo, 'warehouse_dispatches', 'source_offer_id');
    $hasCancelled = depo_cikis_table_has_column($pdo, 'warehouse_dispatches', 'is_cancelled');
    if ($hasSourceOffer) {
        try {
            $indexStmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='index' AND name='idx_warehouse_dispatch_source_offer' LIMIT 1");
            $indexStmt->execute();
            $existingIndexSql = (string)($indexStmt->fetchColumn() ?: '');
            if ($existingIndexSql !== '' && $hasCancelled && stripos($existingIndexSql, 'is_cancelled') === false) {
                $pdo->exec("DROP INDEX IF EXISTS idx_warehouse_dispatch_source_offer");
                $existingIndexSql = '';
            }
            if ($existingIndexSql === '') {
                if ($hasCancelled) {
                    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_warehouse_dispatch_source_offer ON warehouse_dispatches(source_offer_id) WHERE source_offer_id IS NOT NULL AND COALESCE(is_cancelled,0)=0");
                } else {
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_warehouse_dispatch_source_offer_lookup ON warehouse_dispatches(source_offer_id)");
                }
            }
        } catch (Throwable $e) {
            try {
                $lookupColumns = $hasCancelled ? 'source_offer_id,is_cancelled' : 'source_offer_id';
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_warehouse_dispatch_source_offer_lookup ON warehouse_dispatches(" . $lookupColumns . ")");
            } catch (Throwable $ignored) {
            }
        }
    }
}

function depo_cikis_offer_map(array $offerIds): array
{
    depo_cikis_db_ensure();
    $offerIds = array_values(array_unique(array_filter(array_map('intval', $offerIds), static function ($id) { return $id > 0; })));
    if (!$offerIds) return [];
    $placeholders = implode(',', array_fill(0, count($offerIds), '?'));
    $stmt = db()->prepare("SELECT source_offer_id,id FROM warehouse_dispatches WHERE COALESCE(is_cancelled,0)=0 AND source_offer_id IN ($placeholders)");
    $stmt->execute($offerIds);
    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $map[(int)$row['source_offer_id']] = (int)$row['id'];
    }
    return $map;
}

function depo_cikis_from_offer(int $offerId): int
{
    if (!can_access_warehouse_dispatch()) {
        throw new RuntimeException('Depo Çıkış bölümüne erişim yetkiniz yok.');
    }
    if ($offerId <= 0) throw new RuntimeException('Aktarılacak teklif seçilmedi.');

    depo_cikis_db_ensure();
    $offer = teklif_load($offerId);
    if (!$offer) throw new RuntimeException('Aktarılacak teklif bulunamadı.');
    if (strtoupper(trim((string)($offer['currency'] ?? 'TL'))) !== 'TL') {
        throw new RuntimeException('Depo Çıkış aktarımı şu anda yalnız TL tekliflerde kullanılabilir.');
    }

    $stmt = db()->prepare('SELECT id FROM warehouse_dispatches WHERE source_offer_id=? AND COALESCE(is_cancelled,0)=0 LIMIT 1');
    $stmt->execute([$offerId]);
    $existingId = (int)($stmt->fetchColumn() ?: 0);
    if ($existingId > 0) return $existingId;

    $items = is_array($offer['items'] ?? null) ? $offer['items'] : [];
    if (!$items) throw new RuntimeException('Teklifte aktarılacak ürün satırı bulunmuyor.');

    $dispatchNo = depo_cikis_next_no();
    $dispatchDate = trim((string)($offer['offer_date'] ?? '')) ?: date('Y-m-d');
    $customerName = trim((string)($offer['customer_name'] ?? ''));
    if ($customerName === '') throw new RuntimeException('Teklifte firma / müşteri adı bulunmuyor.');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO warehouse_dispatches
            (dispatch_no,dispatch_date,cari_id,customer_name,customer_city,customer_address,note,currency,
             subtotal,discount_enabled,discount_rate,discount_amount,vat_enabled,vat_rate,vat_amount,total,
             processed,posted_to_cari,source_offer_id,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,?,?,?,?)');
        $stmt->execute([
            $dispatchNo,
            $dispatchDate,
            (int)($offer['cari_id'] ?? 0) ?: null,
            $customerName,
            trim((string)($offer['customer_city'] ?? '')),
            trim((string)($offer['customer_address'] ?? '')),
            trim((string)($offer['note'] ?? '')),
            'TL',
            round((float)($offer['subtotal'] ?? 0), 2),
            (int)($offer['discount_enabled'] ?? 0) === 1 ? 1 : 0,
            (float)($offer['discount_rate'] ?? 0),
            round((float)($offer['discount_amount'] ?? 0), 2),
            (int)($offer['vat_enabled'] ?? 0) === 1 ? 1 : 0,
            (float)($offer['vat_rate'] ?? 10),
            round((float)($offer['vat_amount'] ?? 0), 2),
            round((float)($offer['grand_total'] ?? 0), 2),
            $offerId,
            (int)(current_user()['id'] ?? 0) ?: null,
            now(),
            now(),
        ]);
        $dispatchId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO warehouse_dispatch_items
            (dispatch_id,sort_order,product_barcode,product_name,product_type,quantity,unit_price,line_total)
            VALUES (?,?,?,?,?,?,?,?)');
        foreach ($items as $index => $item) {
            $itemStmt->execute([
                $dispatchId,
                $index + 1,
                (string)($item['product_barcode'] ?? ''),
                (string)($item['product_name'] ?? ''),
                (string)($item['product_type'] ?? ''),
                (float)($item['quantity'] ?? 0),
                (float)($item['unit_price'] ?? 0),
                round((float)($item['line_total'] ?? ((float)($item['quantity'] ?? 0) * (float)($item['unit_price'] ?? 0))), 2),
            ]);
        }

        audit_action('depo_cikis', $dispatchId, 'tekliften_aktarildi', null, [
            'source_offer_id'=>$offerId,
            'offer_no'=>(string)($offer['offer_no'] ?? ''),
            'dispatch_no'=>$dispatchNo,
            'customer_name'=>$customerName,
            'item_count'=>count($items),
            'total'=>(float)($offer['grand_total'] ?? 0),
        ], $dispatchNo);
        $pdo->commit();
        log_action('Teklif Depo Çıkışa aktarıldı', (string)($offer['offer_no'] ?? '') . ' → ' . $dispatchNo);
        return $dispatchId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        // Çift tıklama / tekrar gönderimde aynı teklif için ikinci fiş oluşmasın.
        $stmt = db()->prepare('SELECT id FROM warehouse_dispatches WHERE source_offer_id=? AND COALESCE(is_cancelled,0)=0 LIMIT 1');
        $stmt->execute([$offerId]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) return $existingId;
        throw $e;
    }
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
    if ((int)($row['is_cancelled'] ?? 0) === 1) return false;
    return can_process_warehouse_dispatch() || is_warehouse_dispatch_operator();
}

function depo_cikis_sync_cari_movement(array $row, int $movementId): void
{
    $cariId = (int)($row['cari_id'] ?? 0);
    $total = (float)($row['total'] ?? 0);
    $date = trim((string)($row['dispatch_date'] ?? '')) ?: date('Y-m-d');
    $dispatchNo = trim((string)($row['dispatch_no'] ?? ''));
    if ($cariId <= 0) throw new RuntimeException('Cariye işlenmiş fişte cari seçimi kaldırılamaz.');
    if ($total <= 0) throw new RuntimeException('Cariye işlenmiş fişin toplamı sıfır olamaz.');
    if ($movementId <= 0 || !teklif_active_movement_id($movementId)) {
        throw new RuntimeException('Fişin bağlı aktif cari hareketi bulunamadı. Cari bakiyesi bozulmasın diye fiş güncellenmedi.');
    }
    $desc = 'Depo çıkış sipariş fişi no: ' . $dispatchNo . ' / Ürün satışı';
    db()->prepare("UPDATE movements SET
        cari_id=?, category_id=?, account_id=NULL, movement_type='alacak', amount=?, currency='TL',
        movement_date=?, due_date=NULL, payment_method='Depo çıkış fişi', description=?,
        document_type='depo_cikis_fisi', updated_at=?
        WHERE id=? AND COALESCE(is_cancelled,0)=0")
        ->execute([$cariId, teklif_category_id('Satış'), $total, $date, $desc, now(), $movementId]);
    sync_movement_account_transaction($movementId);
}

function depo_cikis_save(int $id): int
{
    depo_cikis_db_ensure();
    $existing=$id>0?depo_cikis_load($id):null;
    if($existing && (int)($existing['is_cancelled']??0)===1) throw new RuntimeException('İptal edilmiş depo çıkış fişi düzenlenemez.');
    if($existing && !depo_cikis_can_edit($existing)) throw new RuntimeException('Bu fişi düzenleme yetkiniz yok.');
    $items=teklif_parse_items_from_post(); if(!$items) throw new RuntimeException('En az bir ürün girilmeli.');

    $subtotal=round((float)array_sum(array_column($items,'line_total')),2);
    $discountEnabled=isset($_POST['discount_enabled']) && (string)$_POST['discount_enabled']==='1' ? 1 : 0;
    $discountRate=teklif_decimal($_POST['discount_rate']??'0');
    $discountRate=max(0,min(100,$discountRate));
    $discountAmount=$discountEnabled?round($subtotal*$discountRate/100,2):0.0;
    $discountedSubtotal=max(0,round($subtotal-$discountAmount,2));
    $vatEnabled=isset($_POST['vat_enabled']) && (string)$_POST['vat_enabled']==='1' ? 1 : 0;
    $vatRate=max(0,teklif_decimal($_POST['vat_rate']??'10'));
    $vatAmount=$vatEnabled?round($discountedSubtotal*$vatRate/100,2):0.0;
    $total=round($discountedSubtotal+$vatAmount,2);

    $name=trim((string)($_POST['customer_name']??''));
    if($name==='') throw new RuntimeException('Firma / müşteri adı gerekli.');
    $data=[
        trim((string)($_POST['dispatch_no']??'')),
        trim((string)($_POST['dispatch_date']??''))?:date('Y-m-d'),
        (int)($_POST['cari_id']??0)?:null,
        $name,
        trim((string)($_POST['customer_city']??'')),
        trim((string)($_POST['customer_address']??'')),
        trim((string)($_POST['note']??'')),
        'TL',
        $subtotal,$discountEnabled,$discountRate,$discountAmount,$vatEnabled,$vatRate,$vatAmount,$total,
        now()
    ];
    $pdo=db(); $pdo->beginTransaction();
    try {
        if($existing){
            $data[]=$id;
            $pdo->prepare('UPDATE warehouse_dispatches SET dispatch_no=?,dispatch_date=?,cari_id=?,customer_name=?,customer_city=?,customer_address=?,note=?,currency=?,subtotal=?,discount_enabled=?,discount_rate=?,discount_amount=?,vat_enabled=?,vat_rate=?,vat_amount=?,total=?,updated_at=? WHERE id=?')->execute($data);
            $pdo->prepare('DELETE FROM warehouse_dispatch_items WHERE dispatch_id=?')->execute([$id]);
        } else {
            $data[]=(int)(current_user()['id']??0);$data[]=now();
            $pdo->prepare('INSERT INTO warehouse_dispatches(dispatch_no,dispatch_date,cari_id,customer_name,customer_city,customer_address,note,currency,subtotal,discount_enabled,discount_rate,discount_amount,vat_enabled,vat_rate,vat_amount,total,updated_at,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($data);
            $id=(int)$pdo->lastInsertId();
        }
        $s=$pdo->prepare('INSERT INTO warehouse_dispatch_items(dispatch_id,sort_order,product_barcode,product_name,product_type,quantity,unit_price,line_total) VALUES(?,?,?,?,?,?,?,?)');
        foreach($items as $i=>$item){
            $s->execute([$id,$i,$item['product_barcode'],$item['product_name'],$item['product_type'],$item['quantity'],$item['unit_price'],$item['line_total']]);
            teklif_save_product_suggestion($item['product_name'],$item['product_type'],(float)$item['unit_price'],$item['product_barcode']);
        }

        if($existing && (int)($existing['posted_to_cari']??0)===1){
            $movementId=teklif_active_movement_id((int)($existing['cari_movement_id']??0));
            depo_cikis_sync_cari_movement([
                'cari_id'=>$data[2],
                'total'=>$total,
                'dispatch_date'=>$data[1],
                'dispatch_no'=>$data[0],
            ],$movementId);
        }

        audit_action('depo_cikis',$id,$existing?'guncellendi':'olusturuldu',$existing,[
            'dispatch_no'=>$data[0],
            'dispatch_date'=>$data[1],
            'cari_id'=>$data[2],
            'customer_name'=>$name,
            'subtotal'=>$subtotal,
            'discount_amount'=>$discountAmount,
            'vat_amount'=>$vatAmount,
            'total'=>$total,
            'item_count'=>count($items),
            'cari_movement_id'=>$existing ? (int)($existing['cari_movement_id']??0) : null,
        ],$data[0]?:('#'.$id));
        $pdo->commit();
        log_action($existing?'Depo çıkış fişi güncellendi':'Depo çıkış fişi oluşturuldu',($data[0]?:('#'.$id)));
        return $id;
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function depo_cikis_mark_processed(int $id): void
{
    if(!can_process_warehouse_dispatch()) throw new RuntimeException('Bu işlem için yetkiniz yok.');
    db()->prepare('UPDATE warehouse_dispatches SET processed=1,processed_at=?,processed_by=?,updated_at=? WHERE id=? AND COALESCE(is_cancelled,0)=0')->execute([now(),current_user()['id']??null,now(),$id]);
}

function depo_cikis_post_to_cari(int $id): int
{
    if(!can_process_warehouse_dispatch()) throw new RuntimeException('Bu işlem için yetkiniz yok.');
    $row=depo_cikis_load($id); if(!$row)throw new RuntimeException('Fiş bulunamadı.');
    if((int)($row['is_cancelled']??0)===1)throw new RuntimeException('İptal edilmiş fiş cariye işlenemez.');
    $cariId=(int)($row['cari_id']??0); if($cariId<=0)throw new RuntimeException('Cariye işlemek için fişte cari seçilmeli.');
    $total=(float)($row['total']??0); if($total<=0)throw new RuntimeException('Fiş toplamı bulunamadı.');
    $pdo=db();
    $pdo->beginTransaction();
    try{
        $mid=teklif_active_movement_id((int)($row['cari_movement_id']??0));
        $now=now();
        if($mid){
            depo_cikis_sync_cari_movement($row,$mid);
        }else{
            $desc='Depo çıkış sipariş fişi no: '.trim((string)$row['dispatch_no']).' / Ürün satışı';
            $s=$pdo->prepare("INSERT INTO movements(cari_id,category_id,account_id,movement_type,amount,currency,movement_date,due_date,payment_method,description,document_type,created_by,created_at,updated_at)
                VALUES(?,?,NULL,'alacak',?,'TL',?,NULL,'Depo çıkış fişi',?,'depo_cikis_fisi',?,?,?)");
            $s->execute([$cariId,teklif_category_id('Satış'),$total,$row['dispatch_date'],$desc,current_user()['id']??null,$now,$now]);
            $mid=(int)$pdo->lastInsertId();
            sync_movement_account_transaction($mid);
        }
        $pdo->prepare('UPDATE warehouse_dispatches SET posted_to_cari=1,cari_movement_id=?,processed=1,processed_at=COALESCE(processed_at,?),processed_by=COALESCE(processed_by,?),updated_at=? WHERE id=?')
            ->execute([$mid,$now,current_user()['id']??null,$now,$id]);
        audit_action('depo_cikis',$id,'cariye_islendi',$row,['posted_to_cari'=>1,'cari_movement_id'=>$mid,'total'=>$total],trim((string)$row['dispatch_no']));
        $pdo->commit();
        log_action('Depo çıkış fişi cariye işlendi',trim((string)$row['dispatch_no']).' - '.money($total));
        return $mid;
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}
