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
   endpoint='barkod-kasa-parasi.php'
   status,h,b=req(endpoint,101); data=json.loads(b)
   assert status==200 and data['can_edit_past'], (status,b)
   today=data['today_date']; past=(datetime.date.fromisoformat(today)-datetime.timedelta(days=2)).isoformat()
   con=sqlite3.connect(str(site/'muhasebe/storage/bitke_muhasebe.sqlite'))
   def save(user,date,amount,token='qatoken'):
    return req(endpoint,user,{'date':date,'amount':amount,'csrf_token':token})
   assert save(101,past,'2500')[0]==200
   con.execute('UPDATE store_daily_payment_breakdown SET cash_amount=123,card_amount=456,daily_total=579 WHERE sale_date=?',(past,));con.commit()
   status,h,b=save(101,past,'3100')
   assert status==200 and json.loads(b)['selected_amount']==3100,(status,b)
   row=con.execute('SELECT cash_change_left_amount,cash_amount,card_amount,daily_total FROM store_daily_payment_breakdown WHERE sale_date=?',(past,)).fetchone()
   assert row==(3100,123,456,579),row
   audit=con.execute("SELECT old_value,new_value,detail FROM audit_logs WHERE action='kasada_birakilan_guncellendi' ORDER BY id DESC LIMIT 1").fetchone()
   assert json.loads(audit[0])['cash_change_left_amount']==2500 and json.loads(audit[1])['cash_change_left_amount']==3100 and audit[2]==past
   for user in [102,104]:
    assert save(user,past,'999')[0]==403
    assert save(user,today,'100')[0]==200
    assert json.loads(req(endpoint,user)[2])['can_edit_past'] is False
   assert save(103,past,'999')[0]!=200
   assert req(endpoint,None)[0]==302
   for date in ['2026-02-30','9999-01-01','bad','']:
    assert save(101,date,'999')[0]==422
   assert save(101,past,'999','wrong')[0]==422
   for amount in ['-1','10000001']:
    assert save(101,past,amount)[0]==422
   assert con.execute('SELECT cash_change_left_amount FROM store_daily_payment_breakdown WHERE sale_date=?',(past,)).fetchone()[0]==3100
   assert save(101,past,'0')[0]==200
   print('PASS: Fatih historical create/update/zero; other users today only; invalid date/future/CSRF/amount rejected; other financial fields preserved; audit old/new/date verified.')
  finally:
   server.terminate(); server.wait(timeout=10)
