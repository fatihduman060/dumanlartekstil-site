#!/usr/bin/env python3
"""Real HTTP routes + disposable SQLite database. Never accesses production data."""
import os, pathlib, shutil, subprocess, tempfile, socket, time, sqlite3, urllib.request, urllib.error, urllib.parse, concurrent.futures
root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BINARY','php')
with tempfile.TemporaryDirectory(prefix='sale-links-') as tmp:
 site=pathlib.Path(tmp)
 shutil.copytree(root/'muhasebe',site/'muhasebe',ignore=shutil.ignore_patterns('storage'))
 b=site/'muhasebe/bootstrap.php'; b.write_text(b.read_text().replace("!extension_loaded('pdo_sqlite')","!in_array('sqlite', PDO::getAvailableDrivers(), true)"))
 (site/'sessions').mkdir()
 (site/'seed.php').write_text(r'''<?php
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/muhasebe/faturalar.php';
require __DIR__.'/muhasebe/bootstrap.php';
$p=db();
$admin=(int)$p->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$_SESSION['user_id']=$admin;
ob_start(); require __DIR__.'/muhasebe/faturalar.php'; ob_end_clean();
$p->exec("INSERT INTO cariler(id,name,created_at,updated_at) VALUES(101,'Link test',datetime('now'),datetime('now')),(102,'Other customer',datetime('now'),datetime('now'))");
for($id=1;$id<=12;$id++) {
 $p->prepare("INSERT INTO warehouse_dispatches(id,dispatch_no,dispatch_date,cari_id,customer_name,total,subtotal,processed,created_at,updated_at) VALUES(?,?,'2026-10-12',101,'Link test',100,100,1,?,?)")->execute([$id,'D'.$id,now(),now()]);
 $p->prepare("INSERT INTO invoices(id,direction,cari_id,invoice_no,invoice_date,subtotal,total_amount,currency,document_path,created_at,updated_at) VALUES(?,'giden',101,?,'2026-10-12',100,100,'TL','fixture.pdf',?,?)")->execute([$id,'TEST2026'.$id,now(),now()]);
}
$p->exec("INSERT INTO users(id,username,display_name,password_hash,role,is_active,created_at,updated_at) VALUES(101,'qa-warehouse','Warehouse','disabled','warehouse',1,datetime('now'),datetime('now')),(102,'qa-viewer','Viewer','disabled','viewer',1,datetime('now'),datetime('now'))");
setting_set('auto_backup_last_date',date('Y-m-d'));
session_write_close();
foreach(['admin'=>$admin,'admin2'=>$admin,'warehouse'=>101,'viewer'=>102] as $name=>$uid) { session_id('qa'.$name); session_start(); $_SESSION=['user_id'=>$uid,'csrf_token'=>'qatoken','last_activity'=>time()]; session_write_close(); }
''')
 cmd=[php,'-d','session.save_path='+str(site/'sessions')]
 r=subprocess.run(cmd+[str(site/'seed.php')],capture_output=True,text=True)
 assert r.returncode==0,r.stdout+r.stderr
 with socket.socket() as sock: sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 env=dict(os.environ,PHP_CLI_SERVER_WORKERS='4')
 log=open(site/'server.log','w')
 server=subprocess.Popen(cmd+['-S',f'127.0.0.1:{port}','-t',str(site)],env=env,stdout=log,stderr=log,start_new_session=True)
 try:
  for _ in range(100):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.05)
  class NoRedirect(urllib.request.HTTPRedirectHandler):
   def redirect_request(self,*args,**kw):return None
  def req(path,data=None,user='admin'):
   headers={'Cookie':'bitke_muhasebe_session=qa'+user}
   if data is not None:data=dict(data,csrf_token=data.get('csrf_token','qatoken'))
   request=urllib.request.Request(f'http://127.0.0.1:{port}/muhasebe/'+path,headers=headers,data=None if data is None else urllib.parse.urlencode(data).encode())
   try:r=urllib.request.build_opener(NoRedirect).open(request)
   except urllib.error.HTTPError as e:r=e
   body=r.read().decode();
   if os.environ.get('DEBUG_LINK_TEST'): print(path,r.status,r.headers.get('Location'),body[:300])
   assert r.status<500,(path,r.status,body[:500]);return r.status,body
  con=sqlite3.connect(site/'muhasebe/storage/bitke_muhasebe.sqlite');con.row_factory=sqlite3.Row
  def row(table,id):return dict(con.execute('SELECT * FROM '+table+' WHERE id=?',(id,)).fetchone())
  def link(i,d,**extra):
   data=dict(action='save',id=i,direction='giden',cari_id=101,invoice_no='TEST2026'+str(i),invoice_date='2026-10-12',subtotal=100,vat_amount=0,total_amount=100,currency='TL',related_dispatch_id=d)
   data.update(extra);return req('faturalar.php',data)
  def post(side,id,user='admin'):return req('faturalar.php' if side=='invoice' else 'depo-cikis.php',dict(action='post_cari',id=id),user)
  def shared(i,d):
   a=row('invoices',i);b=row('warehouse_dispatches',d);assert a['cari_movement_id']==b['cari_movement_id'] and a['posted_to_cari']==b['posted_to_cari']==1,(a,b)
   m=row('movements',a['cari_movement_id']);assert m['amount']==100 and m['is_cancelled']==0;return m['id']
  post('dispatch',1);mid=row('warehouse_dispatches',1)['cari_movement_id'];before=row('movements',mid);link(1,1);post('invoice',1);assert shared(1,1)==mid;assert row('movements',mid)==before
  post('invoice',2);mid=row('invoices',2)['cari_movement_id'];before=row('movements',mid);link(2,2);post('dispatch',2);assert shared(2,2)==mid;assert row('movements',mid)==before
  print('PASS dispatch-first, invoice-first: identical original movement, no financial overwrite')
  link(3,3)
  with concurrent.futures.ThreadPoolExecutor() as pool:
   futures=[pool.submit(post,'invoice',3),pool.submit(post,'dispatch',3,'admin2')]
   for f in futures:f.result()
  shared(3,3);assert con.execute('SELECT COUNT(*) FROM movements').fetchone()[0]==3
  for _ in range(2):post('invoice',3);post('dispatch',3)
  assert con.execute('SELECT COUNT(*) FROM movements').fetchone()[0]==3
  print('PASS simultaneous/repeated posting yields one active movement')
  for extras in [dict(cari_id=102),dict(total_amount=101),dict(currency='USD'),dict(direction='gelen'),dict(invoice_date='2026-10-11')]:
   link(4,4,**extras);assert con.execute('SELECT COUNT(*) FROM sale_document_links WHERE invoice_id=4').fetchone()[0]==0
   assert row('invoices',4)['total_amount']==100 and row('invoices',4)['cari_id']==101
  link(4,4,invoice_date='2026-10-11',confirm_date_difference='1');post('invoice',4);shared(4,4)
  assert row('movements',row('invoices',4)['cari_movement_id'])['movement_date']=='2026-10-11'
  print('PASS mismatch rollback and explicit date confirmation')
  post('invoice',5);post('dispatch',5);link(5,5);assert con.execute('SELECT COUNT(*) FROM sale_document_links WHERE invoice_id=5').fetchone()[0]==0
  link(6,1);assert con.execute('SELECT COUNT(*) FROM sale_document_links WHERE invoice_id=6').fetchone()[0]==0
  print('PASS two existing movements / already-linked dispatch rejected')
  link(1,0,link_reason='test unlink');assert con.execute('SELECT COUNT(*) FROM sale_document_links WHERE invoice_id=1').fetchone()[0]==1
  for sql in ["UPDATE invoices SET cari_id=102 WHERE id=1","UPDATE warehouse_dispatches SET total=101 WHERE id=1","UPDATE movements SET amount=101 WHERE id="+str(shared(1,1))]:
   try:con.execute(sql);raise AssertionError('financial update accepted')
   except sqlite3.IntegrityError:con.rollback()
  status,body=req('fatura-cari-sec.php',dict(action='set',invoice_id=1,cari_id=102));assert row('invoices',1)['cari_id']==101
  req('fatura-yon-sec.php',dict(invoice_id=1,direction='gelen'));assert row('invoices',1)['direction']=='giden'
  print('PASS linked financial fields and legacy quick-edit paths protected')
  mid=shared(1,1);con.execute("UPDATE movements SET is_cancelled=1,cancel_reason='test cancel' WHERE id=?",(mid,));con.commit();post('invoice',1);newmid=shared(1,1);assert newmid!=mid and row('movements',mid)['is_cancelled']==1
  con.execute('UPDATE movements SET is_cancelled=1 WHERE id=?',(newmid,));con.commit();link(1,0,link_reason='test unlink');assert not con.execute('SELECT * FROM sale_document_links WHERE invoice_id=1').fetchone()
  link(7,7);link(7,8,link_reason='test relink');assert con.execute('SELECT dispatch_id FROM sale_document_links WHERE invoice_id=7').fetchone()[0]==8
  print('PASS soft-cancel/repost, reasoned unlink/relink, cancelled movement retained')
  mid=shared(2,2);req('faturalar.php',dict(action='cancel',id=2,cancel_reason='test invoice cancellation'));assert row('invoices',2)['is_cancelled']==1 and row('movements',mid)['is_cancelled']==0 and row('warehouse_dispatches',2)['cari_movement_id']==mid
  assert not con.execute('SELECT * FROM sale_document_links WHERE invoice_id=2').fetchone()
  print('PASS invoice cancellation preserves dispatch sale and movement')
  con.execute("CREATE TRIGGER test_audit_fail BEFORE INSERT ON audit_logs WHEN NEW.entity_type='fatura_depo_bagi' BEGIN SELECT RAISE(ABORT,'audit failed'); END");con.commit()
  link(9,9);assert not con.execute('SELECT * FROM sale_document_links WHERE invoice_id=9').fetchone()
  con.execute('DROP TRIGGER test_audit_fail');con.commit()
  before=con.execute('SELECT COUNT(*) FROM movements').fetchone()[0]
  post('invoice',10,'viewer');post('dispatch',10,'warehouse');post('dispatch',10,'viewer')
  req('faturalar.php',dict(action='post_cari',id=10,csrf_token='wrong'))
  assert con.execute('SELECT COUNT(*) FROM movements').fetchone()[0]==before
  for path,needle in [('faturalar.php?edit=3','Depo çıkışı #D3'),('depo-cikis.php?edit=3','Fatura TEST20263'),('fatura-toplu-yukle.php','BITKE_BULK_DISPATCHES')]:
   status,body=req(path);assert status==200 and needle in body,(path,status,body[:300])
  assert con.execute("SELECT COUNT(*) FROM audit_logs WHERE entity_type='fatura_depo_bagi' AND action='yeniden_baglandi'").fetchone()[0]==1
  print('PASS mandatory audit rollback, permissions, CSRF, counterpart UI and bulk selector data')
  def upload(path,fields,filefield,pdf):
   boundary='qa-sale-boundary'
   parts=[]
   for name,value in dict(fields,csrf_token='qatoken').items():
    parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+name+'"\r\n\r\n'+str(value)+'\r\n').encode())
   parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="'+filefield+'"; filename="qa.pdf"\r\nContent-Type: application/pdf\r\n\r\n').encode()+pdf+b'\r\n')
   parts.append(('--'+boundary+'--\r\n').encode())
   request=urllib.request.Request(f'http://127.0.0.1:{port}/muhasebe/'+path,data=b''.join(parts),headers={'Cookie':'bitke_muhasebe_session=qaadmin','Content-Type':'multipart/form-data; boundary='+boundary})
   try:r=urllib.request.build_opener(NoRedirect).open(request)
   except urllib.error.HTTPError as e:r=e
   assert r.status==302,(r.status,r.read())
  import json
  upload('fatura-toplu-yukle.php',{'items_json':json.dumps([dict(direction='giden',cari_id=101,invoice_no='BULK202613',invoice_date='2026-10-12',subtotal='100',vat_amount='0,00',total_amount='100',currency='TL',related_dispatch_id=10)])},'documents[]',b'%PDF-1.4\n% QA bulk fixture\n%%EOF')
  bulk=con.execute("SELECT id FROM invoices WHERE invoice_no='BULK202613'").fetchone();assert bulk
  assert con.execute('SELECT dispatch_id FROM sale_document_links WHERE invoice_id=?',(bulk[0],)).fetchone()[0]==10
  upload('faturalar.php',dict(action='save',id=0,direction='giden',cari_id=101,invoice_no='NEW202614',invoice_date='2026-10-12',subtotal=100,vat_amount=0,total_amount=100,currency='TL',related_dispatch_id=11),'document',b'%PDF-1.4\n% QA single fixture\n%%EOF')
  single=con.execute("SELECT id FROM invoices WHERE invoice_no='NEW202614'").fetchone();assert single
  assert con.execute('SELECT dispatch_id FROM sale_document_links WHERE invoice_id=?',(single[0],)).fetchone()[0]==11
  print('PASS actual bulk PDF and single invoice upload create the selected link')

 finally:
  if os.environ.get('DEBUG_LINK_TEST'): print((site/'server.log').read_text()); print((site/'sessions/sess_qaadmin').read_text())
  import signal
  os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=10);log.close()
