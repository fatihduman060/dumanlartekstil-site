#!/usr/bin/env python3
"""Integration checks against a disposable copy; never opens the live database.
Run: PHP_BINARY=/path/to/php python3 tools/test-depo-cikis.py
Requires PHP with PDO SQLite and Python 3.
"""
import os, pathlib, shutil, socket, subprocess, tempfile, time
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
foreach ([[101,'qa-depo','warehouse'],[102,'qa-other','warehouse'],[103,'qa-viewer','viewer'],[104,'magaza','editor']] as [$id,$username,$role]) {
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
   for user,expected in [(None,302),(101,303),(102,404),(103,404),(104,303)]:
    status,h,b=req('depo-cikis-pdf.php?id=1',user)
    assert status==expected,(user,status,b[:150],str(h))
    if status==303: assert h['Location']=='depo-cikis-yazdir.php?id=1&pdf=1' and 'no-store' in h['Cache-Control']
   print('PDF view: login, owner, other warehouse, viewer, store access passed')
   for user,expected in [(None,302),(102,404),(103,404)]:
    status,h,b=req('depo-cikis-paylas.php',user,{'id':1,'csrf_token':'qatoken'})
    assert status==expected,(user,status)
   status,h,b=req('depo-cikis-paylas.php',101)
   assert status==405
   status,h,b=req('depo-cikis-paylas.php',101,{'id':1,'csrf_token':'wrong'})
   assert status==302 and not h['Location'].startswith('https://wa.me')
   status,h,b=req('depo-cikis-paylas.php',101,{'id':1,'csrf_token':'qatoken'})
   assert status==303,(status,b[:200])
   msg=urllib.parse.parse_qs(urllib.parse.urlparse(h['Location']).query)['text'][0]
   assert 'https://bitke.com.tr/muhasebe/depo-cikis-yazdir.php?token=' in msg and '&pdf=1' in msg and '#00042' in msg
   url=msg.split('https://bitke.com.tr/muhasebe/')[1]
   status,h,b=req(url)
   assert status==200 and b'S\xc4\xb0PAR\xc4\xb0\xc5\x9e F\xc4\xb0\xc5\x9e\xc4\xb0' in b and b'brand-line' in b,(status,b[:200])
   assert h['Referrer-Policy']=='no-referrer' and h['X-Content-Type-Options']=='nosniff'
   for token in ['x','0'*64,'../test','']:
    assert req('depo-cikis-yazdir.php?token='+token)[0]==404
   print('Share: POST + CSRF, permissions, WhatsApp message, anonymous printable view, invalid tokens passed')
   con=sqlite3.connect(str(site/'muhasebe/storage/bitke_muhasebe.sqlite'))
   con.execute('UPDATE warehouse_dispatches SET note=? WHERE id=1',('changed',));con.commit()
   assert req(url)[0]==404
   status,h,b=req('depo-cikis-paylas.php',101,{'id':1,'csrf_token':'qatoken'})
   url=urllib.parse.parse_qs(urllib.parse.urlparse(h['Location']).query)['text'][0].split('https://bitke.com.tr/muhasebe/')[1]
   con.execute('UPDATE warehouse_dispatch_shares SET expires_at=0');con.commit()
   assert req(url)[0]==404
   print('Share invalidation on edit and expiration passed')
   status,h,b=req('depo-cikis.php?edit=1',101);text=b.decode()
   assert status==200 and 'PDF görüntüle' in text and 'WhatsApp ile paylaş' in text,(status,text[:200])
   status,h,b=req('depo-cikis-yazdir.php?id=1',101);text=b.decode()
   assert status==200 and 'WhatsApp ile paylaş' in text and 'brand-line' in text,(status,text[:200])
   print('Form/list/print buttons and offer-style print view render for warehouse user')
  finally:
   server.terminate(); server.wait(timeout=10)
