<?php
require_once __DIR__ . '/teklif-db.php';

function fiyat_listeleri_ensure(): void
{
    teklif_db_ensure();
    db()->exec("CREATE TABLE IF NOT EXISTS price_lists (
        id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL,
        file_path TEXT NOT NULL, file_name TEXT NOT NULL, file_hash TEXT NOT NULL,
        is_current INTEGER NOT NULL DEFAULT 0 CHECK(is_current IN (0,1)),
        created_by INTEGER, created_at TEXT NOT NULL, deleted_at TEXT,
        CHECK(deleted_at IS NULL OR is_current=0)
    );
    CREATE UNIQUE INDEX IF NOT EXISTS price_lists_one_current ON price_lists(is_current) WHERE is_current=1;
    CREATE TABLE IF NOT EXISTS price_list_items (
        list_id INTEGER NOT NULL REFERENCES price_lists(id), product_id INTEGER NOT NULL REFERENCES offer_products(id),
        product_name TEXT NOT NULL, barcode TEXT NOT NULL, unit_price REAL NOT NULL CHECK(unit_price>0),
        PRIMARY KEY(list_id,product_id)
    )");
}

// Unlike the legacy audit helper, audit failure must roll back price changes.
function fiyat_listeleri_audit(int $id, string $action, $before, $after): void
{
    $u = current_user();
    db()->prepare('INSERT INTO audit_logs(user_id,username,entity_type,entity_id,action,old_value,new_value,detail,ip,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
        ->execute([$u['id'], $u['username'], 'fiyat_listesi', $id, $action,
            json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'Fiyat listesi arşivi', client_ip(), now()]);
}

function fiyat_listeleri_header(string $s): string
{
    $s = strtr($s, ['İ'=>'i','ı'=>'i','Ş'=>'s','ş'=>'s','Ü'=>'u','ü'=>'u','Ö'=>'o','ö'=>'o','Ç'=>'c','ç'=>'c','Ğ'=>'g','ğ'=>'g']);
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}

function fiyat_listeleri_rows(string $path, string $ext): array
{
    if ($ext === 'csv') {
        $h = fopen($path, 'rb');
        if (!$h) throw new RuntimeException('Dosya okunamadı.');
        try {
            $first = (string)fgets($h); rewind($h);
            $delimiter = ';'; $count = 0;
            foreach ([';', ',', "\t"] as $d) { $n=count(str_getcsv($first,$d,'"','')); if($n>$count){$count=$n;$delimiter=$d;} }
            $rows=[];
            while (($row=fgetcsv($h,0,$delimiter,'"',''))!==false) {
                $rows[]=$row;
                if(count($rows)>5001) throw new RuntimeException('En fazla 5000 ürün yükleyin.');
            }
            return $rows;
        } finally { fclose($h); }
    }
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) throw new RuntimeException('Sunucuda XLSX desteği yok. Excel’den CSV UTF-8 olarak kaydedip yükleyin.');
    $zip=new ZipArchive();
    if($zip->open($path)!==true) throw new RuntimeException('XLSX dosyası açılamadı.');
    try {
        $total=0;
        for($i=0;$i<$zip->numFiles;$i++) { $stat=$zip->statIndex($i);$total+=$stat['size']; }
        if($total>30*1024*1024 || $zip->numFiles>1000) throw new RuntimeException('Excel dosyası çok büyük.');
        $read=function($name) use($zip) {
            $s=$zip->getFromName($name);
            if($s===false) return null;
            if(stripos($s,'<!DOCTYPE')!==false || stripos($s,'<!ENTITY')!==false) throw new RuntimeException('Güvensiz Excel XML içeriği.');
            $xml=@simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET);
            if($xml===false) throw new RuntimeException('Geçersiz Excel içeriği.');
            return $xml;
        };
        $shared=[];$xml=$read('xl/sharedStrings.xml');
        if($xml!==null) foreach($xml->xpath('//*[local-name()="si"]') as $item) {
            $s='';foreach($item->xpath('.//*[local-name()="t"]') as $t) $s.=(string)$t;$shared[]=$s;
        }
        $xml=$read('xl/worksheets/sheet1.xml');
        if($xml===null) throw new RuntimeException('Fiyatları Excel’in ilk sayfasına koyun.');
        $rows=[];
        foreach($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $r) {
            $row=[];
            foreach($r->xpath('./*[local-name()="c"]') as $c) {
                if($c->xpath('./*[local-name()="f"]')) throw new RuntimeException('Formülleri değer olarak yapıştırıp tekrar yükleyin.');
                preg_match('/^([A-Z]+)[0-9]+$/',(string)$c['r'],$m);$col=0;
                foreach(str_split($m[1]??'') as $letter) $col=$col*26+ord($letter)-64;
                if($col<1 || $col>50) throw new RuntimeException('En fazla 50 sütun desteklenir.');
                $v=$c->xpath('./*[local-name()="v"]');$value=$v?(string)$v[0]:'';
                if((string)$c['t']==='s') $value=$shared[(int)$value]??'';
                if((string)$c['t']==='inlineStr') { $value='';foreach($c->xpath('.//*[local-name()="t"]') as $t) $value.=(string)$t; }
                $row[$col-1]=$value;
            }
            $rows[]=$row;
            if(count($rows)>5001) throw new RuntimeException('En fazla 5000 ürün yükleyin.');
        }
        return $rows;
    } finally { $zip->close(); }
}

function fiyat_listeleri_items(array $rows): array
{
    $head=array_shift($rows) ?: []; $cols=[];
    $aliases=['barkod'=>'barcode','barcode'=>'barcode','artikel'=>'article','article'=>'article','urunkodu'=>'article',
        'urun'=>'name','urunadi'=>'name','name'=>'name','fiyat'=>'price','birimfiyat'=>'price','listebirimfiyati'=>'price','listunitprice'=>'price'];
    foreach($head as $i=>$v) { $key=fiyat_listeleri_header((string)$v); if(isset($aliases[$key])) $cols[$aliases[$key]]=$i; }
    if(!isset($cols['price']) || (!isset($cols['barcode'])&&!isset($cols['article'])&&!isset($cols['name']))) throw new RuntimeException('İlk satırda Barkod, Artikel veya Ürün Adı ve Birim Fiyat başlıkları olmalı.');
    $products=teklif_products_for_select();$items=[];
    foreach($rows as $i=>$row) {
        if(!array_filter($row,function($v){return trim((string)$v)!=='';})) continue;
        $get=function($k) use($cols,$row){return isset($cols[$k])?trim((string)($row[$cols[$k]]??'')):'';};
        $name=$get('name');$barcode=$get('barcode');$article=$get('article');$matches=[];
        foreach($products as $p) {
            $ok=true;$identified=false;
            if($barcode!=='') {$identified=true;$ok=$ok && teklif_normalize_barcode($barcode)===teklif_normalize_barcode((string)$p['barcode'],$p['name'],(string)$p['product_type']);}
            if($article!=='') {$identified=true;$a=teklif_article_from_text($article);$ok=$ok && $a!=='' && $a===teklif_article_from_text($p['name']);}
            if(!$identified && $name!=='') {$identified=true;$ok=$ok && fiyat_listeleri_header($name)===fiyat_listeleri_header($p['name']);}
            if($identified && $ok) $matches[]=$p;
        }
        $line=$i+2;
        if(count($matches)!==1) throw new RuntimeException("Satır $line: Ürün tek bir kayıtla eşleşmedi. Ürün Fiyat Listesi’nde ürünü tanımlayın veya barkodu düzeltin.");
        $raw=$get('price');
        if(!preg_match('/^(?:[0-9]+(?:[.,][0-9]{1,2})?|[0-9]{1,3}(?:\.[0-9]{3})+,[0-9]{1,2})$/D',$raw)) throw new RuntimeException("Satır $line: Geçerli TL fiyatı girin (ör. 444,50).");
        $price=round(teklif_decimal($raw),2);$p=$matches[0];
        if(!is_finite($price)||$price<=0||$price>100000000) throw new RuntimeException("Satır $line: Fiyat geçersiz.");
        if(isset($items[$p['id']])) throw new RuntimeException("Satır $line: Aynı ürün iki kez yazılmış.");
        $items[$p['id']]=['product_id'=>(int)$p['id'],'product_name'=>$p['name'],'barcode'=>(string)$p['barcode'],'unit_price'=>$price];
    }
    if(!$items) throw new RuntimeException('Liste boş olamaz.');
    return array_values($items);
}

// Caller owns a transaction. Clearing absent products prevents stale archived prices.
function fiyat_listeleri_activate(int $id): void
{
    $pdo=db();
    if (!$pdo->inTransaction()) throw new LogicException('Price activation requires a transaction.');
    $s=$pdo->prepare('SELECT * FROM price_lists WHERE id=? AND deleted_at IS NULL');$s->execute([$id]);$list=$s->fetch();
    if(!$list) throw new RuntimeException('Liste bulunamadı.');
    $s=$pdo->prepare('SELECT i.* FROM price_list_items i JOIN offer_products p ON p.id=i.product_id WHERE i.list_id=? AND p.is_active=1');$s->execute([$id]);$items=$s->fetchAll();
    $s=$pdo->prepare('SELECT COUNT(*) FROM price_list_items WHERE list_id=?');$s->execute([$id]);
    if(!$items || count($items)!==(int)$s->fetchColumn()) throw new RuntimeException('Liste boş veya pasif ürün içeriyor. Yeni liste yükleyin.');
    $before=$pdo->query('SELECT id,list_unit_price FROM offer_products')->fetchAll();
    $previous=$pdo->query('SELECT id FROM price_lists WHERE is_current=1')->fetchColumn();
    $pdo->exec('UPDATE price_lists SET is_current=0 WHERE is_current=1');
    $pdo->prepare('UPDATE price_lists SET is_current=1 WHERE id=?')->execute([$id]);
    $pdo->prepare('UPDATE offer_products SET list_unit_price=NULL,updated_at=?')->execute([now()]);
    $set=$pdo->prepare('UPDATE offer_products SET list_unit_price=?,updated_at=? WHERE id=?');
    foreach($items as $item) $set->execute([$item['unit_price'],now(),$item['product_id']]);
    fiyat_listeleri_audit($id,'guncellendi',['previous_current'=>$previous,'prices'=>$before],['current'=>$id,'items'=>$items]);
}
