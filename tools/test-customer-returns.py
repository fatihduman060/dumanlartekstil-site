#!/usr/bin/env python3
"""Regression tests using a disposable app and synthetic SQLite records."""
import os, pathlib, shutil, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='returns-') as tmp:
    site = pathlib.Path(tmp)
    shutil.copytree(root/'muhasebe', site/'muhasebe', ignore=shutil.ignore_patterns('storage'))
    bootstrap = site/'muhasebe/bootstrap.php'
    bootstrap.write_text(bootstrap.read_text().replace("!extension_loaded('pdo_sqlite')", "!in_array('sqlite', PDO::getAvailableDrivers(), true)"))
    (site/'test.php').write_text(r'''<?php
require __DIR__.'/muhasebe/bootstrap.php';
require __DIR__.'/muhasebe/dashboard-cari-aggregate.php';
$p = db();
ensure_column($p, 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");
$p->exec("INSERT INTO cariler(id,name,created_at,updated_at) VALUES (101,'Test customer','2026-01-01','2026-01-01'),(102,'Test closure','2026-01-01','2026-01-01')");
$p->exec("INSERT INTO categories(id,name,type,created_at) VALUES (900,'İade','genel','2026-01-01'),(901,'Other','genel','2026-01-01')");
function eq($a, $b, $message) { if ($a != $b) throw new RuntimeException($message.': '.json_encode([$a,$b])); }
function movement($id,$type,$amount,$category=null,$currency='TL',$cancelled=0,$cari=101) {
    db()->prepare('INSERT INTO movements(id,cari_id,category_id,movement_type,amount,currency,is_cancelled,movement_date,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
      ->execute([$id,$cari,$category,$type,$amount,$currency,$cancelled,'2026-10-06',now(),now()]);
}
movement(1,'alacak',1034000); movement(2,'tahsilat',400000); movement(3,'gider',491150,900);
eq(cari_balance(101,'TL')['net'],634000,'legacy failure reproduced');
$rows = customer_return_candidates($p); eq(count($rows),1,'one eligible');
$expected=[3=>$rows[0]['fingerprint']];
eq(customer_return_repair($p,$expected,[]),1,'repair count');
eq(customer_return_repair($p,$expected,[]),0,'idempotence');
eq(cari_balance(101,'TL')['net'],142850,'return balance');
eq(cari_open_period_balance(101)['net'],142850,'open period');
eq(dashboard_totals()['net_alacak'],142850,'dashboard totals');
$a=dashboard_cari_aggregate()['positions'][0];
eq($a['alacak']-$a['tahsilat']-$a['ciro_primi']-$a['iade']-$a['verecek']+$a['odeme'],142850,'aggregate');
eq(movement_cash_direction('iade'),null,'no cash direction');
sync_movement_account_transaction(3);
eq($p->query('SELECT COUNT(*) FROM account_transactions')->fetchColumn(),0,'no cash rows');
eq($p->query('SELECT COUNT(*) FROM movements')->fetchColumn(),3,'records retained');
$a=$p->query("SELECT * FROM audit_logs WHERE entity_id=3 AND action='iade_duzeltildi'")->fetchAll();
eq(count($a),1,'one audit'); eq(json_decode($a[0]['old_value'],true)['movement_type'],'gider','old snapshot'); eq(json_decode($a[0]['new_value'],true)['movement_type'],'iade','new snapshot');
eq(customer_return_entry_type('gider',900,101),'iade','cached form category'); eq(customer_return_entry_type('alacak',900,101),'iade','category override');
eq(customer_return_entry_type('gider',901,101),'gider','ordinary expense');
try { customer_return_entry_type('iade',0,null); throw new Exception('missing cari accepted'); } catch (RuntimeException $e) {}
movement(4,'iade',999999,null,'TL',1); movement(5,'alacak',1000,null,'USD'); movement(6,'iade',200,null,'USD');
eq(cari_balance(101,'TL')['net'],142850,'cancelled excluded and currencies isolated'); eq(cari_balance(101,'USD')['net'],800,'USD return');
movement(7,'alacak',100,null,'TL',0,102); movement(8,'iade',100,null,'TL',0,102); movement(9,'alacak',20,null,'TL',0,102);
eq(cari_open_period_balance(102)['net'],20,'return closes old period'); eq(cari_open_period_balance(102)['alacak_close_id'],8,'closure id');
movement(10,'gider',5,900,'TL',1); movement(11,'gider',5,901); movement(12,'gider',5,900); movement(13,'gider',5,900); movement(14,'tahsilat',5,900);
$p->exec("UPDATE movements SET account_id=1 WHERE id=12");
$p->exec("UPDATE movements SET check_id=1 WHERE id=13");
$rows=customer_return_candidates($p);eq(count($rows),3,'only active exact category');foreach($rows as $r) eq($r['blocked_reason']!=='',true,'unsafe is skipped');
movement(15,'alacak',100,900); $rows=customer_return_candidates($p);$last=end($rows);$fp=[15=>$last['fingerprint']];
$p->exec('UPDATE movements SET amount=101 WHERE id=15');
try { customer_return_repair($p,$fp,[]);throw new Exception('stale preview accepted'); } catch(RuntimeException $e) {}
eq($p->query('SELECT movement_type FROM movements WHERE id=15')->fetchColumn(),'alacak','stale rollback');
$rows=customer_return_candidates($p);$last=end($rows);$fp=[15=>$last['fingerprint']];
$p->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT,'test failure'); END");
try { customer_return_repair($p,$fp,[]);throw new Exception('audit failure accepted'); } catch(PDOException $e) {}
eq($p->query('SELECT movement_type FROM movements WHERE id=15')->fetchColumn(),'alacak','audit failure rollback');
$p->exec('DROP TRIGGER reject_audit');$before=cari_balance(101,'TL')['net']; customer_return_repair($p,$fp,[]);eq(cari_balance(101,'TL')['net'],$before-202,'legacy alacak sign corrected');
echo "PASS: return balances, dashboard, currency, cancelled rows, period closure, cash neutrality, audit, rollback, concurrency guard, idempotence\n";
''')
    subprocess.run([os.environ.get('PHP_BINARY','php'),str(site/'test.php')],check=True)
