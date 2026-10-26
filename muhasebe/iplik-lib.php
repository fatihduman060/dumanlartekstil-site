<?php
// Operational ledger only: never writes movements or cari balances.
function iplik_db_ensure(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS yarn_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT, cari_id INTEGER NOT NULL REFERENCES cariler(id),
        movement_date TEXT NOT NULL, direction TEXT NOT NULL CHECK(direction IN ('aldik','verdik')),
        yarn_name TEXT NOT NULL, yarn_number TEXT NOT NULL DEFAULT '', color TEXT NOT NULL DEFAULT '', lot TEXT NOT NULL DEFAULT '',
        kg REAL NOT NULL CHECK(kg>0), unit_price REAL, total REAL NOT NULL CHECK(total>=0),
        document_no TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',
        created_by INTEGER, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1,
        is_cancelled INTEGER NOT NULL DEFAULT 0, cancelled_by INTEGER, cancelled_at TEXT, cancel_reason TEXT
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS idx_yarn_cari_date ON yarn_movements(cari_id,is_cancelled,movement_date)');
}
function iplik_date(string $v): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}
function iplik_decimal($value, int $scale): float
{
    $v = str_replace(' ', '', trim((string)$value));
    if (!preg_match('/^(?:\d+(?:[.,]\d+)?|\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d{1,3}(?:,\d{3})+(?:\.\d+)?)$/D', $v)) throw new InvalidArgumentException('Geçerli, negatif olmayan bir miktar/tutar girin.');
    $n = decimal_from_input($v);
    if (!is_finite($n) || $n > 999999999) throw new InvalidArgumentException('Miktar/tutar çok büyük.');
    return round($n, $scale);
}
function iplik_load(int $id, int $cari): ?array
{
    $s = db()->prepare('SELECT * FROM yarn_movements WHERE id=? AND cari_id=?');
    $s->execute([$id,$cari]); return $s->fetch() ?: null;
}
function iplik_save(array $input, int $cari): int
{
    require_write();
    $s = db()->prepare('SELECT id FROM cariler WHERE id=?'); $s->execute([$cari]);
    if (!$s->fetch()) throw new InvalidArgumentException('Geçerli bir firma seçin.');
    $id = (int)($input['id'] ?? 0);
    $data = [];
    foreach (['movement_date','direction','yarn_name','yarn_number','color','lot','document_no','description'] as $key) {
        $data[$key] = trim((string)($input[$key] ?? ''));
        if (strlen($data[$key]) > ($key==='description'?4000:250)) throw new InvalidArgumentException('Alan uzunluğu sınırı aşıldı.');
    }
    if (!iplik_date($data['movement_date']) || !in_array($data['direction'],['aldik','verdik'],true) || $data['yarn_name']==='') throw new InvalidArgumentException('Tarih, işlem türü ve iplik adı zorunludur.');
    $data['kg'] = iplik_decimal($input['kg'] ?? '',3);
    if ($data['kg']<=0) throw new InvalidArgumentException('Kg sıfırdan büyük olmalıdır.');
    $data['unit_price'] = trim((string)($input['unit_price']??''))==='' ? null : iplik_decimal($input['unit_price'],4);
    $data['total'] = $data['unit_price']===null ? iplik_decimal($input['total']??'',2) : round($data['kg']*$data['unit_price'],2);
    if ($data['total']>999999999) throw new InvalidArgumentException('Toplam tutar çok büyük.');
    $pdo=db(); $pdo->beginTransaction();
    try {
        $old=$id>0?iplik_load($id,$cari):null;
        if ($id>0 && (!$old || $old['is_cancelled'] || (int)$old['version']!==(int)($input['version']??0))) throw new RuntimeException('Kayıt değişmiş, iptal edilmiş veya bu firmaya ait değil. Sayfayı yenileyin.');
        $data['updated_at']=now();
        if ($id>0) {
            $sets=[]; foreach(array_keys($data) as $key) $sets[]=$key.'=?';
            $s=$pdo->prepare('UPDATE yarn_movements SET '.implode(',',$sets).',version=version+1 WHERE id=? AND cari_id=? AND version=? AND is_cancelled=0');
            $s->execute(array_merge(array_values($data),[$id,$cari,$old['version']]));
            if($s->rowCount()!==1) throw new RuntimeException('Kayıt değişti. Sayfayı yenileyin.');
        } else {
            $data['cari_id']=$cari; $data['created_by']=current_user()['id']; $data['created_at']=now();
            $s=$pdo->prepare('INSERT INTO yarn_movements ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');
            $s->execute(array_values($data)); $id=(int)$pdo->lastInsertId();
        }
        audit_action('iplik',$id,$old?'guncellendi':'eklendi',$old,iplik_load($id,$cari),'İplik alışverişi');
        log_action('İplik hareketi kaydedildi','#'.$id);
        $pdo->commit(); return $id;
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function iplik_cancel(array $input, int $cari): void
{
    require_write(); $reason=trim((string)($input['cancel_reason']??''));
    if($reason==='' || strlen($reason)>1000) throw new InvalidArgumentException('İptal nedeni zorunludur (en fazla 1000 karakter).');
    $pdo=db(); $pdo->beginTransaction();
    try {
        $id=(int)($input['id']??0); $old=iplik_load($id,$cari);
        if(!$old || $old['is_cancelled'] || (int)$old['version']!==(int)($input['version']??0)) throw new RuntimeException('Kayıt değişmiş, iptal edilmiş veya bu firmaya ait değil. Sayfayı yenileyin.');
        $s=$pdo->prepare('UPDATE yarn_movements SET is_cancelled=1,cancel_reason=?,cancelled_by=?,cancelled_at=?,updated_at=?,version=version+1 WHERE id=? AND cari_id=? AND version=? AND is_cancelled=0');
        $s->execute([$reason,current_user()['id'],now(),now(),$id,$cari,$old['version']]);
        if($s->rowCount()!==1) throw new RuntimeException('Kayıt değişti. Sayfayı yenileyin.');
        audit_action('iplik',$id,'iptal',$old,iplik_load($id,$cari),$reason);
        log_action('İplik hareketi iptal edildi','#'.$id.' '.$reason); $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function iplik_where(int $cari, array $filters): array
{
    $where=['cari_id=?']; $params=[$cari];
    foreach(['from'=>'>=','to'=>'<='] as $key=>$op) if(($filters[$key]??'')!=='') {
        if(!iplik_date($filters[$key])) throw new InvalidArgumentException('Tarih filtresi geçersiz.');
        $where[]='movement_date'.$op.'?'; $params[]=$filters[$key];
    }
    if(!empty($filters['from']) && !empty($filters['to']) && $filters['from']>$filters['to']) throw new InvalidArgumentException('Başlangıç tarihi bitişten sonra olamaz.');
    foreach(['color','lot'] as $key) if(($filters[$key]??'')!=='') { $where[]=$key.'=?'; $params[]=$filters[$key]; }
    return [implode(' AND ',$where),$params];
}
function iplik_number($n, int $scale=3): string { return number_format((float)$n,$scale,',','.'); }
function iplik_net($n, string $unit): string { return abs($n)<0.00001?'Dengede':('Net '.iplik_number(abs($n),$unit==='TL'?2:3).' '.$unit.($n>0?' aldık':' verdik')); }
