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
 # Observe session state in the disposable copies only.
 for filename in ['barkod-satis-api.php','barkod-satis-arama.php']:
  endpoint=site/'muhasebe'/filename
  endpoint.write_text(endpoint.read_text().replace('try {', "header('X-QA-Session: '.session_status());\ntry {",1))
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
   for endpoint in ['barkod-satis-api.php?action=barcode&barcode=123','barkod-satis-arama.php?q=corap']:
    status,h,b=req(endpoint,101)
    assert status==200 and json.loads(b)['ok'],(status,b[:500])
    assert h['X-QA-Session']=='1',dict(h)
    assert req(endpoint,None)[0]==302
   status,h,b=req('barkod-satis-api.php',101,{'action':'sync_live_cart','csrf_token':'bad'})
   assert not json.loads(b)['ok'] and h['X-QA-Session']=='2',(status,b)
   status,h,b=req('barkod-satis-api.php',101,{'action':'sync_live_cart','csrf_token':'qatoken','terminal_id':'12345678-1234-1234-1234-123456789012','items_json':'[]','discount_amount':'0','state':'open'})
   assert json.loads(b)['ok'] and h['X-QA-Session']=='2',(status,b)
   print('PASS: authenticated reads release session; login required; writes retain session and CSRF; live-cart sync works.')
  finally:
   server.terminate(); server.wait(timeout=10)
