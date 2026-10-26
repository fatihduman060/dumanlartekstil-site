<?php
if (PHP_SAPI !== "cli") { http_response_code(404); exit; }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db(){global $pdo;return $pdo;}
function require_write(){global $readonly;if($readonly)throw new RuntimeException('readonly');}
function current_user(){return ['id'=>1];}
function now(){return date('Y-m-d H:i:s');}
function log_action($a,$b){}
function audit_action($a,$b,$c,$d,$e,$f){global $audits;$audits[]=[$a,$b,$c,$d,$e,$f];}
$src=file_get_contents(dirname(__DIR__).'/muhasebe/bootstrap.php');$start=strpos($src,'function decimal_from_input(');$end=strpos($src,'function tr_date(', $start);eval(substr($src,$start,$end-$start));
require dirname(__DIR__).'/muhasebe/iplik-lib.php';
function check($ok,$label){if(!$ok)throw new Exception($label);echo "PASS $label\n";}
function fails($fn,$label){try{$fn();}catch(Throwable $e){echo "PASS $label\n";return;}throw new Exception($label);}
$pdo->exec('CREATE TABLE cariler(id INTEGER PRIMARY KEY,name TEXT); INSERT INTO cariler VALUES(1,"A"),(2,"B"); CREATE TABLE movements(id INTEGER PRIMARY KEY,total REAL); INSERT INTO movements VALUES(1,42)');
iplik_db_ensure();iplik_db_ensure();$readonly=false;$audits=[];
$in=['movement_date'=>'2026-10-26','direction'=>'aldik','yarn_name'=>'Penye','yarn_number'=>'30/1','kg'=>'500,125','unit_price'=>'110,50','total'=>'1','color'=>'Siyah','lot'=>'L1'];
$id=iplik_save($in,1);$r=iplik_load($id,1);check($r['total']==55263.81 && $r['kg']==500.125,'Turkish kg and calculated total');
check(iplik_load($id,2)===null,'company scoped load');
fails(function()use($in,$id){iplik_save(array_merge($in,['id'=>$id,'version'=>1]),2);},'cross-company edit rejected');
$id2=iplik_save(array_merge($in,['direction'=>'verdik','kg'=>'300','unit_price'=>'','total'=>'1.234,56']),1);
iplik_save(array_merge($in,['yarn_number'=>'20/1','kg'=>'20']),1);iplik_save($in,2);
list($w,$p)=iplik_where(1,['from'=>'2026-10-01','to'=>'2026-10-31','color'=>'Siyah','lot'=>'L1']);
$s=$pdo->prepare('SELECT yarn_number,SUM(CASE WHEN direction="aldik" THEN kg ELSE -kg END) net FROM yarn_movements WHERE '.$w.' AND is_cancelled=0 GROUP BY yarn_name,yarn_number ORDER BY yarn_number');$s->execute($p);$b=$s->fetchAll();check(count($b)===2 && abs($b[1]['net']-200.125)<.00001,'company/product/filter balances');
check(iplik_load($id2,1)['total']==1234.56,'manual Turkish total');
iplik_save(array_merge($in,['id'=>$id,'version'=>1,'kg'=>'600']),1);
fails(function()use($in,$id){iplik_save(array_merge($in,['id'=>$id,'version'=>1]),1);},'stale edit rejected');
fails(function()use($id){iplik_cancel(['id'=>$id,'version'=>2,'cancel_reason'=>''],1);},'cancel requires reason');
iplik_cancel(['id'=>$id,'version'=>2,'cancel_reason'=>'Yanlış kayıt'],1);
check(iplik_load($id,1)['is_cancelled']==1 && iplik_load($id,1)['cancel_reason']==='Yanlış kayıt','soft cancel preserved');
check((int)$pdo->query('SELECT COUNT(*) FROM yarn_movements WHERE cari_id=1 AND is_cancelled=0')->fetchColumn()===2,'cancel excluded from active totals');
fails(function()use($in,$id){iplik_save(array_merge($in,['id'=>$id,'version'=>3]),1);},'cancelled edit rejected');
fails(function()use($in){iplik_save(array_merge($in,['movement_date'=>'2026-02-30']),1);},'invalid date rejected');
fails(function()use($in){iplik_save(array_merge($in,['kg'=>'oops']),1);},'invalid decimal rejected');
fails(function()use($in){iplik_save(array_merge($in,['kg'=>'0']),1);},'zero kg rejected');
fails(function(){iplik_where(1,['from'=>'2026-10-31','to'=>'2026-10-01']);},'reversed range rejected');
$readonly=true;fails(function()use($in){iplik_save($in,1);},'viewer write rejected');
check(count($audits)===6 && $audits[5][2]==='iptal' && $audits[5][3]['is_cancelled']==0 && $audits[5][4]['is_cancelled']==1,'audit before and after');
check((float)$pdo->query('SELECT total FROM movements')->fetchColumn()===42.0,'normal ledger unchanged');
echo "All yarn regression checks passed\n";
