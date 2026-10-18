#!/usr/bin/env python3
"""Isolated PHP/SQLite and browser tests; never uses the live database.
PHP_BINARY, NODE_BINARY, PLAYWRIGHT_MODULE and CHROME_BINARY may be configured.
"""
import os, pathlib, shutil, socket, subprocess, tempfile, time, sqlite3
import urllib.request, urllib.error, urllib.parse
root = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BINARY', 'php')
with tempfile.TemporaryDirectory(prefix='urun-fiyat-test-') as tmp:
    site = pathlib.Path(tmp)
    shutil.copytree(root/'muhasebe', site/'muhasebe', ignore=shutil.ignore_patterns('storage'))
    (site/'sessions').mkdir()
    bootstrap = site/'muhasebe/bootstrap.php'
    bootstrap.write_text(bootstrap.read_text().replace("!extension_loaded('pdo_sqlite')", "!in_array('sqlite', PDO::getAvailableDrivers(), true)"))
    (site/'seed.php').write_text(r'''<?php
require __DIR__.'/muhasebe/depo-cikis-paylas-lib.php';
teklif_db_ensure();
depo_cikis_db_ensure();
$pdo=db();
$pdo->exec("DELETE FROM settings WHERE key='urun_fiyat_listesi_2026_v1'");
$pdo->beginTransaction();
teklif_db_ensure();
if (!$pdo->inTransaction()) throw new RuntimeException('List import committed caller transaction');
$pdo->rollBack();
teklif_db_ensure();
ensure_column($pdo,'movements','currency',"TEXT NOT NULL DEFAULT 'TL'");
foreach ([[101,'qa-admin','admin'],[102,'qa-depo','warehouse'],[103,'qa-view','viewer'],[104,'magaza','editor'],[105,'qa-editor','editor'],[106,'murat','editor']] as $u) {
 $pdo->prepare('INSERT INTO users(id,username,display_name,password_hash,role,is_active,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)')->execute([$u[0],$u[1],$u[1],'disabled',$u[2],now(),now()]);
}
foreach ([101,102,103] as $id) $pdo->prepare('INSERT INTO cariler(id,name,created_at,updated_at) VALUES(?,?,?,?)')->execute([$id,'Test müşteri '.$id,now(),now()]);
$_SESSION['user_id']=101;
setting_set('auto_backup_last_date',date('Y-m-d'));
$count = (int)$pdo->query('SELECT COUNT(*) FROM offer_products WHERE list_unit_price IS NOT NULL')->fetchColumn();
$distinct = (int)$pdo->query("SELECT COUNT(DISTINCT barcode) FROM offer_products WHERE list_unit_price IS NOT NULL AND TRIM(COALESCE(barcode,''))!=''")->fetchColumn();
if ($count !== 76 || $distinct !== 76) throw new RuntimeException('2026 list import count: '.$count.'/'.$distinct);
$samples = ['8699234860003'=>444.0,'8699234860119'=>444.0,'8699923460200'=>480.0,'8699923425308'=>300.0];
foreach ($samples as $barcode=>$expected) {
 $s=$pdo->prepare('SELECT list_unit_price FROM offer_products WHERE barcode=?');$s->execute([$barcode]);
 if ((float)$s->fetchColumn()!==$expected) throw new RuntimeException('Imported list price: '.$barcode);
}
// Stable natural article sorting and canonical identity matching.
$sorted=urun_sort([['id'=>3,'name'=>'6070 Z'],['id'=>1,'name'=>'6012 A'],['id'=>2,'name'=>'6000 B'],['id'=>4,'name'=>'6011 C'],['id'=>5,'name'=>'No article']]);
if (array_column($sorted,'id') !== [2,4,1,3,5]) throw new RuntimeException('Article order');
$base=urun_define('6000','');
if ($base['barcode']!=='8699234860003') throw new RuntimeException('Article matching');
// Customer documents must not overwrite the explicit list price.
teklif_save_product_suggestion('6000 ERKEK MODAL LİKRALI ÇORAP (YIKAMALI)','4 Mevsim / Yazlık',280,'8699234860003');
if ((float)$pdo->query("SELECT list_unit_price FROM offer_products WHERE barcode='8699234860003'")->fetchColumn()!==444.0) throw new RuntimeException('List price overwritten');
foreach ([[102,'6000 ERKEK MODAL LİKRALI ÇORAP (YIKAMALI)','4 Mevsim / Yazlık',280,'TL'],[102,'6011 ERKEK BAMBU LİKRALI ÇORAP (YIKAMALI)','4 Mevsim / Yazlık',380,'TL'],[102,'6000 ERKEK MODAL LİKRALI ÇORAP (YIKAMALI)','4 Mevsim / Yazlık',99,'USD'],[103,'6000 ERKEK MODAL LİKRALI ÇORAP (YIKAMALI)','4 Mevsim / Yazlık',77,'TL']] as $i=>$data) {
 $_POST=['customer_name'=>'Test müşteri '.$data[0],'cari_id'=>$data[0],'offer_no'=>'QA'.$i,'offer_date'=>'2026-09-24','currency'=>$data[4],
 'product_name'=>[$data[1]],'product_type'=>[$data[2]],'quantity'=>['1'],'unit_price'=>[(string)$data[3]]];
 teklif_save_from_post();
}
$_POST=['customer_name'=>'Test müşteri 102','cari_id'=>102,'dispatch_no'=>'CANCEL','dispatch_date'=>'2026-09-24',
 'product_name'=>['6000 ERKEK MODAL LİKRALI ÇORAP (YIKAMALI)'],'product_type'=>['4 Mevsim / Yazlık'],'quantity'=>['1'],'unit_price'=>['9999']];
$id=depo_cikis_save(0);
$pdo->prepare("UPDATE warehouse_dispatches SET is_cancelled=1,updated_at='2099-01-01' WHERE id=?")->execute([$id]);
// Saving without JavaScript must also define unknown products and retain a blank base.
$_POST=['customer_name'=>'Test müşteri 101','cari_id'=>101,'offer_no'=>'NEW','offer_date'=>'2026-10-18',
'product_name'=>['7801 Kaydedilen teklif ürünü'],'quantity'=>['1'],'unit_price'=>['123']];
teklif_save_from_post();
$_POST=['customer_name'=>'Test müşteri 101','cari_id'=>101,'dispatch_no'=>'NEW','dispatch_date'=>'2026-10-18',
'product_name'=>['7802 Kaydedilen depo ürünü'],'quantity'=>['1'],'unit_price'=>['234']];
depo_cikis_save(0);
foreach (['7801','7802'] as $article) {
 $p=urun_define($article);
 if ($p['list_unit_price']!==null) throw new RuntimeException('Customer price leaked to base');
}
session_write_close();
foreach ([101,102,103,104,105,106] as $userId) {session_id('qa'.$userId);session_start();$_SESSION=['user_id'=>$userId,'csrf_token'=>'qatoken','last_activity'=>time()];session_write_close();}
''')
    command=[php,'-d','session.save_path='+str(site/'sessions')]
    subprocess.run(command+[str(site/'seed.php')],check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    url=f'http://127.0.0.1:{port}/muhasebe/'
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self,*args,**kwargs): return None
    opener=urllib.request.build_opener(NoRedirect)
    def request(path,user=101,data=None):
        req=urllib.request.Request(url+path,headers={'Cookie':f'bitke_muhasebe_session=qa{user}'},data=urllib.parse.urlencode(data).encode() if data is not None else None)
        try: res=opener.open(req)
        except urllib.error.HTTPError as e: res=e
        return res.status,res.read().decode()
    with open(site/'server.log','w') as log:
        server=subprocess.Popen(command+['-S',f'127.0.0.1:{port}','-t',str(site)],stdout=log,stderr=log)
        try:
            for attempt in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                except OSError: time.sleep(.05)
            else: raise RuntimeError('Test server did not start')
            import json
            data=json.loads(request('musteri-urun-son-fiyat.php?cari_id=102&currency=TL')[1])
            prices={i['name']:i['unit_price'] for i in data['items'] if i['source_type']!='base'}
            assert len([i for i in data['items'] if i['source_type']=='base'])==74
            assert prices=={'6000 ERKEK MODAL LİKRALI ÇORAP (YIKAMALI)':280,'6011 ERKEK BAMBU LİKRALI ÇORAP (YIKAMALI)':380},prices
            data=json.loads(request('musteri-urun-son-fiyat.php?cari_id=102&currency=USD')[1])
            assert [i['unit_price'] for i in data['items']]==[99]
            con=sqlite3.connect(site/'muhasebe/storage/bitke_muhasebe.sqlite')
            product_id=con.execute("SELECT id FROM offer_products WHERE barcode='8699234860003'").fetchone()[0]
            product_name=con.execute('SELECT name FROM offer_products WHERE id=?',(product_id,)).fetchone()[0]
            original=con.execute('SELECT * FROM offer_items').fetchall()
            post={'action':'price','id':product_id,'new_price':'445,50','expected_price':'444','csrf_token':'qatoken'}
            assert request('urun-fiyat-listesi.php',102,post)[0]==302
            assert con.execute('SELECT list_unit_price FROM offer_products WHERE id=?',(product_id,)).fetchone()[0]==444
            assert request('urun-fiyat-listesi.php',101,dict(post,csrf_token='wrong'))[0]==302
            assert con.execute('SELECT list_unit_price FROM offer_products WHERE id=?',(product_id,)).fetchone()[0]==444
            assert request('urun-fiyat-listesi.php',101,post)[0]==302
            assert con.execute('SELECT list_unit_price FROM offer_products WHERE id=?',(product_id,)).fetchone()[0]==445.5
            assert original==con.execute('SELECT * FROM offer_items').fetchall()
            assert request('urun-fiyat-listesi.php',101,dict(post,new_price='444',expected_price='445.5'))[0]==302
            # New products are defined on typing and on saving, without learning a base price.
            for user in [101,102]:
                new=json.loads(request('urun-tanimla.php',user,{'name':'7999 Test ürün','product_type':'Test','csrf_token':'qatoken'})[1])
                assert new['ok'] and new['product']['list_unit_price'] is None
            assert con.execute("SELECT COUNT(*) FROM offer_products WHERE name='7999 Test ürün'").fetchone()[0]==1
            alias=json.loads(request('urun-tanimla.php',101,{'name':'79-99 test ÜRÜN','csrf_token':'qatoken'})[1])
            assert alias['product']['id']==new['product']['id']
            assert request('urun-tanimla.php',103,{'name':'Yetkisiz','csrf_token':'qatoken'})[0]==302
            assert request('urun-tanimla.php',101,{'name':'CSRF','csrf_token':'bad'})[0]==302
            # Atomic audit and optimistic concurrency; neither may silently lose a price.
            assert request('urun-fiyat-listesi.php',101,dict(post,expected_price='100'))[0]==200
            assert con.execute('SELECT list_unit_price FROM offer_products WHERE id=?',(product_id,)).fetchone()[0]==444
            con.execute("CREATE TRIGGER qa_base_audit BEFORE INSERT ON audit_logs WHEN NEW.entity_type='offer_product' BEGIN SELECT RAISE(ABORT,'test'); END");con.commit()
            assert request('urun-fiyat-listesi.php',101,post)[0]==200
            assert con.execute('SELECT list_unit_price FROM offer_products WHERE id=?',(product_id,)).fetchone()[0]==444
            con.execute('DROP TRIGGER qa_base_audit');con.commit()
            audit=con.execute("SELECT old_value,new_value FROM audit_logs WHERE entity_type='offer_product' AND action='guncellendi' ORDER BY id LIMIT 1").fetchone()
            assert json.loads(audit[0])['list_unit_price']==444 and json.loads(audit[1])['list_unit_price']==445.5
            for user in [101,102,104]: assert request('urun-fiyat-listesi.php',user)[0]==200
            assert request('urun-fiyat-listesi.php',103)[0]==302
            print('API: customer, currency, cancelled dispatch, list editing, CSRF and permissions passed',flush=True)
            import runpy
            runpy.run_path(str(root/'tools/test-fiyat-listeleri.py'))['run'](request,opener,url,con,product_id)
            subprocess.run([os.environ.get('NODE_BINARY','node'),str(root/'tools/test-urun-fiyat.js'),url],check=True)
        finally:
            server.terminate();server.wait(timeout=10)
