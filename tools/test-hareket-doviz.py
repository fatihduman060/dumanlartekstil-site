#!/usr/bin/env python3
"""Integration checks against a disposable copy; never opens the live database.
Run: PHP_BINARY=/path/to/php python3 tools/test-depo-cikis.py
Requires PHP with PDO SQLite and Python 3.
"""
import json, datetime, os, pathlib, shutil, socket, subprocess, tempfile, time
import urllib.request, urllib.error, urllib.parse, sqlite3
php=os.environ.get('PHP_BINARY','php')
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='depo-cikis-test-') as tmp:
 site=pathlib.Path(tmp)
 shutil.copytree(root/'muhasebe',site/'muhasebe',ignore=shutil.ignore_patterns('storage'))
 (site/'sessions').mkdir()
 # Some static PHP builds register the SQLite PDO driver under sqlite3.
 # Adapt only the disposable copy's extension check, not application behavior.
 bootstrap=site/'muhasebe/bootstrap.php'
 bootstrap.write_text(bootstrap.read_text().replace("!extension_loaded('pdo_sqlite')", "!in_array('sqlite', PDO::getAvailableDrivers(), true)"))
 (site/'seed.php').write_text(r'''<?php
require __DIR__.'/muhasebe/depo-cikis-paylas-lib.php';
depo_cikis_db_ensure();
$pdo=db();
foreach ([[101,'fatih','admin'],[102,'qa-other','warehouse'],[103,'qa-viewer','viewer'],[104,'magaza','editor']] as [$id,$username,$role]) {
 $pdo->prepare('INSERT OR REPLACE INTO users(id,username,display_name,password_hash,role,is_active,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)')->execute([$id,$username,$username,'disabled',$role,now(),now()]);
}
$_SESSION['user_id']=101;
$_POST=['customer_name'=>'Şişli Tekstil','dispatch_no'=>'00042','dispatch_date'=>'2026-09-15','customer_city'=>'İstanbul','customer_address'=>'Öğretmen Şükrü Cd.','product_name'=>['Çorap'],'quantity'=>['10'],'unit_price'=>['25'],'product_barcode'=>['8691234567890'],'product_type'=>['Modal'],'note'=>'Türkçe: çğıöşü ÇĞİÖŞÜ'];
$id=depo_cikis_save(0);
setting_set('auto_backup_last_date',date('Y-m-d'));
session_write_close();
foreach ([101,102,103,104] as $userId) {
 session_id('qa'.$userId); session_start(); $_SESSION=['user_id'=>$userId,'csrf_token'=>'qatoken','last_activity'=>time()]; session_write_close();
}
echo $id;
''')
 command=[php,'-d','session.save_path='+str(site/'sessions')]
 subprocess.run(command+[str(site/'seed.php')],check=True,capture_output=True)
 with socket.socket() as sock:
  sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
 base_url=f'http://127.0.0.1:{port}/muhasebe/'
 with open(site/'server.log','w') as log:
  server=subprocess.Popen(command+['-S',f'127.0.0.1:{port}','-t',str(site)],stdout=log,stderr=log)
  try:
   for attempt in range(100):
    try:
     with socket.create_connection(('127.0.0.1',port),timeout=.1): break
    except OSError: time.sleep(.05)
   else: raise RuntimeError('Test server did not start')
   class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
   opener=urllib.request.build_opener(NoRedirect)
   def req(path,user=None,data=None):
    headers={'Cookie':f'bitke_muhasebe_session=qa{user}'} if user else {}
    r=urllib.request.Request(base_url+path,headers=headers,data=urllib.parse.urlencode(data).encode() if data is not None else None)
    try: response=opener.open(r)
    except urllib.error.HTTPError as e: response=e
    return response.status,response.headers,response.read()
   con=sqlite3.connect(str(site/'muhasebe/storage/bitke_muhasebe.sqlite'))
   con.execute("INSERT INTO accounts (id,name,account_type,opening_balance,is_active,created_at,updated_at) VALUES (900,'QA Kasa','kasa',0,1,'2026-01-01','2026-01-01')");con.commit()
   def save(currency,rate,amount='100',kind='tahsilat',id=0,account='900'):
    status,h,b=req('hareketler.php',101,{'action':'save','id':id,'csrf_token':'qatoken','movement_type':kind,'amount':amount,'currency':currency,'exchange_rate':rate,'movement_date':'2026-09-22','account_id':account,'category_id':'','cari_id':'','due_date':'','document_type':'','payment_method':'Nakit','description':'FX QA'})
    assert status==302,(status,b[:1000])
   def last():
    return con.execute('SELECT id,amount,currency,exchange_rate,account_amount_tl FROM movements ORDER BY id DESC LIMIT 1').fetchone()
   def entries(id):
    return con.execute("SELECT direction,amount FROM account_transactions WHERE source_type='movement' AND source_id=?",(id,)).fetchall()
   save('USD','42,5'); first=last(); assert first[1:]==(100,'USD',42.5,4250),first
   assert entries(first[0])==[('in',4250)]
   save('USD','43',id=first[0]); assert entries(first[0])==[('in',4300)]
   save('EUR','50','20','odeme'); second=last();assert entries(second[0])==[('out',1000)]
   save('EUR','50','20','gider'); assert entries(last()[0])==[('out',1000)]
   save('USD','42','10','gelir'); assert entries(last()[0])==[('in',420)]
   save('TL','999','50'); tl=last();assert tl[3:] == (None,None) and entries(tl[0])==[('in',50)],tl
   count=con.execute('SELECT COUNT(*) FROM movements').fetchone()[0]
   for rate in ['', '0', '-5','bad']:
    save('USD',rate)
    assert con.execute('SELECT COUNT(*) FROM movements').fetchone()[0]==count,rate
   save('EUR','','30','alacak'); noCash=last(); assert entries(noCash[0])==[]
   save('USD','42','10','alacak'); assert entries(last()[0])==[]
   save('USD','42.5555','1.23'); assert entries(last()[0])==[('in',52.34)]
   status,h,b=req('hareketler.php?edit='+str(first[0]),101);assert status==200 and b'name="exchange_rate"' in b and b'value="43"' in b,(status,b[:400])
   status,h,b=req('hareketler.php',101,{'action':'cancel','id':second[0],'csrf_token':'qatoken','cancel_reason':'QA cancellation'})
   assert status==302 and entries(second[0])==[]
   assert con.execute('SELECT is_cancelled FROM movements WHERE id=?',(second[0],)).fetchone()[0]==1
   audit=con.execute("SELECT new_value FROM audit_logs WHERE entity_type='hareket' AND entity_id=? AND action='guncellendi' ORDER BY id DESC LIMIT 1",(first[0],)).fetchone()
   assert json.loads(audit[0])['exchange_rate']==43
   print('PASS USD/EUR in/out conversion; editing replaces balance effect; TL unchanged; invalid rates rejected; noncash remains foreign; rounding; edit form; cancellation preserves movement and removes balance effect; audit rate.')
  finally:
   server.terminate(); server.wait(timeout=10)
