#!/usr/bin/env python3
"""Run with PHP_BINARY=/path/to/php python3 tools/test-depo-cikis-bakiye.py.
Uses a disposable application copy and synthetic SQLite data only.
"""
import os
import pathlib
import shutil
import subprocess
import tempfile

root = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='depo-bakiye-') as tmp:
    site = pathlib.Path(tmp)
    shutil.copytree(root / 'muhasebe', site / 'muhasebe',
                    ignore=shutil.ignore_patterns('storage'))
    bootstrap = site / 'muhasebe/bootstrap.php'
    bootstrap.write_text(bootstrap.read_text().replace(
        "!extension_loaded('pdo_sqlite')",
        "!in_array('sqlite', PDO::getAvailableDrivers(), true)"))
    test = site / 'test.php'
    test.write_text(r'''<?php
require __DIR__.'/muhasebe/depo-cikis-paylas-lib.php';
depo_cikis_db_ensure();
$pdo = db();
$pdo->exec("INSERT INTO cariler(id,name,created_at,updated_at) VALUES
    (101,'Test müşteri','2026-09-24','2026-09-24'),
    (102,'Diğer müşteri','2026-09-24','2026-09-24')");
// Printing must also work before the optional currency schema has been installed.
$pdo->exec('PRAGMA query_only=ON');
$legacy = depo_cikis_balance_summary(['cari_id'=>101,'total'=>3000]);
$pdo->exec('PRAGMA query_only=OFF');
if ($legacy['old'] !== 0.0 || $legacy['new'] !== 3000.0) throw new RuntimeException('Legacy schema');
ensure_column($pdo, 'movements', 'currency', "TEXT NOT NULL DEFAULT 'TL'");
function movement(int $id, string $type, float $amount, string $currency = 'TL', int $cancelled = 0, int $cariId = 101): void {
    db()->prepare('INSERT INTO movements(id,cari_id,movement_type,amount,currency,is_cancelled,movement_date,created_at,updated_at) VALUES(?,?,?,?,?,?,?, ?,?)')
        ->execute([$id,$cariId,$type,$amount,$currency,$cancelled,'2026-09-24',now(),now()]);
}
movement(101,'alacak',15000);
movement(102,'tahsilat',2000);
movement(103,'ciro_primi',500);
movement(104,'verecek',700);
movement(105,'odeme',200);
movement(106,'gelir',999);
movement(107,'gider',999);
movement(108,'alacak',99999,'TL',1);
movement(109,'alacak',900,'USD');
movement(110,'alacak',3000,'TL',0,102);
movement(111,'alacak',50,' tl ');
movement(112,'alacak',50,'');
$base = ['cari_id'=>101,'currency'=>'TL','total'=>3000,'posted_to_cari'=>0];
$checks = 0;
function check_summary(array $row, $old, $new, string $label, bool $note = false): void {
    global $checks;
    // The new helper must work with SQLite writes disabled.
    db()->exec('PRAGMA query_only=ON');
    try { $s = depo_cikis_balance_summary($row); }
    finally { db()->exec('PRAGMA query_only=OFF'); }
    if ($s['old'] !== $old || $s['new'] !== $new || $s['dispatch'] !== 3000.0
        || ($note && $s['note'] === '')) {
        throw new RuntimeException($label.': '.json_encode($s));
    }
    $checks++;
}
check_summary($base,12100.0,15100.0,'Unposted; receipts, rebate, payable, payment, cancelled and FX');
movement(113,'alacak',2500);
$posted = array_merge($base,['posted_to_cari'=>1,'cari_movement_id'=>113]);
check_summary($posted,12100.0,14600.0,'Posted uses active movement amount, not edited document total');
check_summary(array_merge($posted,['posted_to_cari'=>0]),12100.0,14600.0,'Active link prevents double counting with stale flag');
movement(114,'tahsilat',16000);
check_summary($posted,-3900.0,-1400.0,'Later collection and negative balance');
check_summary($base,-1400.0,1600.0,'Unposted crosses zero');
check_summary(array_merge($posted,['cari_movement_id'=>108]),-1400.0,-1400.0,'Cancelled movement',true);
check_summary(array_merge($posted,['cari_movement_id'=>9999]),-1400.0,-1400.0,'Missing movement',true);
check_summary(array_merge($posted,['cari_movement_id'=>110]),-1400.0,-1400.0,'Other customer movement',true);
check_summary(array_merge($posted,['cari_movement_id'=>109]),-1400.0,-1400.0,'Other currency movement',true);
check_summary(array_merge($base,['is_cancelled'=>1]),-1400.0,-1400.0,'Cancelled unposted dispatch',true);
check_summary(array_merge($base,['cari_id'=>null]),null,null,'No customer',true);
check_summary(array_merge($base,['cari_id'=>9999]),null,null,'Missing customer',true);
check_summary(array_merge($posted,['cari_movement_id'=>104]),-700.0,-1400.0,'Payable uses negative sign');
if (abs(cari_balance(101)['net'] - (-500)) > 0.001) throw new RuntimeException('Legacy balance changed');

// Exercise actual dispatch posting and printing, including discount/VAT total.
$_SESSION['user_id'] = (int)$pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$_POST = ['customer_name'=>'Test müşteri','cari_id'=>101,'dispatch_no'=>'TEST-42',
    'dispatch_date'=>'2026-09-24','product_name'=>['Çorap'],'quantity'=>['10'],
    'unit_price'=>['100'],'discount_enabled'=>'1','discount_input_mode'=>'amount',
    'discount_amount'=>'100','vat_enabled'=>'1','vat_rate'=>'10'];
$id = depo_cikis_save(0);
$row = depo_cikis_load($id);
if (abs((float)$row['total'] - 990) > 0.001) throw new RuntimeException('Discount/VAT fixture total');
$before = depo_cikis_balance_summary($row);
depo_cikis_post_to_cari($id);
$after = depo_cikis_balance_summary(depo_cikis_load($id));
if ($before !== $after) throw new RuntimeException('Posting must not change summary values');
$snapshot = $pdo->query('SELECT * FROM movements ORDER BY id')->fetchAll();
$_GET = ['id'=>$id];
ob_start();
require __DIR__.'/muhasebe/depo-cikis-yazdir.php';
$html = ob_get_clean();
foreach (['Eski Bakiye','Bu Fiş','Yeni Bakiye','-1.400,00 TL','990,00 TL','-410,00 TL'] as $text) {
    if (strpos($html,$text) === false) throw new RuntimeException('Missing print text: '.$text);
}
if ($snapshot !== $pdo->query('SELECT * FROM movements ORDER BY id')->fetchAll()) {
    throw new RuntimeException('Printing changed financial records');
}
echo $checks." read-only balance cases, posting/discount/VAT integration and print checks passed.\n";
''')
    subprocess.run([os.environ.get('PHP_BINARY', 'php'), str(test)], check=True)
