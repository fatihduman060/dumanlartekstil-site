"""Price-list lifecycle regression cases, invoked by test-urun-fiyat.py."""
import io, json, urllib.request, urllib.error, uuid, zipfile, os, subprocess

def run(request, opener, url, con, product_id):
    before=con.execute('SELECT id,list_unit_price FROM offer_products').fetchall()
    financial={t:con.execute('SELECT * FROM '+t).fetchall() for t in ['offers','offer_items','warehouse_dispatches','warehouse_dispatch_items','movements','account_transactions']}
    def upload(content, current=False, user=101, csrf='qatoken', filename='prices.csv', extra=None):
        boundary='test'+uuid.uuid4().hex
        parts=[]
        fields={'action':'upload','title':'QA fiyat listesi','csrf_token':csrf}
        if current: fields['current']='1'
        for key,value in fields.items():
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
        files=[('document',filename,content)]
        if extra: files.append(('prices','data.csv',extra))
        for field,name,data in files:
            if isinstance(data,str): data=data.encode()
            parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{name}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+data+b'\r\n')
        parts.append(f'--{boundary}--\r\n'.encode())
        req=urllib.request.Request(url+'fiyat-listeleri.php',data=b''.join(parts),headers={'Cookie':f'bitke_muhasebe_session=qa{user}','Content-Type':'multipart/form-data; boundary='+boundary})
        try: res=opener.open(req)
        except urllib.error.HTTPError as e: res=e
        return res.status,res.read().decode()
    def count(): return con.execute('SELECT COUNT(*) FROM price_lists').fetchone()[0]
    def price(): return con.execute('SELECT list_unit_price FROM offer_products WHERE id=?',(product_id,)).fetchone()[0]
    def post(action,id,user=101,csrf='qatoken'): return request('fiyat-listeleri.php',user,{'action':action,'id':id,'csrf_token':csrf})
    assert request('fiyat-listeleri.php')[0]==200
    assert request('fiyat-listeleri.php',103)[0]==200
    for user in [102,104]: assert request('fiyat-listeleri.php',user)[0]==302
    for user in [102,103,104]: assert upload('Artikel;Birim Fiyat\n6000;555',True,user)[0]==302
    assert upload('Artikel;Birim Fiyat\n6000;555',True,csrf='bad')[0]==302
    assert count()==0
    for csv in ['Artikel;Birim Fiyat\n9999;10','Artikel;Birim Fiyat\n6000;0','Artikel;Birim Fiyat\n6000;555\n6000;666','Artikel;Birim Fiyat\n6000;1e3','Barkod;Artikel;Birim Fiyat\n8699234860003;6011;50']:
        assert upload(csv,True)[0]==200
        assert count()==0 and price()==444
    result=upload('Artikel;Birim Fiyat\n6000;555,50',True)
    assert result[0]==302, result
    first=con.execute('SELECT id FROM price_lists').fetchone()[0]
    assert price()==555.5
    assert con.execute('SELECT COUNT(*) FROM offer_products WHERE list_unit_price IS NOT NULL').fetchone()[0]==1
    assert request('fiyat-listeleri.php?download='+str(first))[0]==200
    assert request('fiyat-listeleri.php?download='+str(first),102)[0]==302
    assert post('delete',first,103)[0]==302 and price()==555.5
    assert post('delete',first,csrf='bad')[0]==302 and price()==555.5
    name=con.execute('SELECT name FROM offer_products WHERE id=?',(product_id,)).fetchone()[0]
    edit={'id':product_id,'name':name,'product_type':'4 Mevsim / Yazlık','barcode':'8699234860003','list_unit_price':'999','csrf_token':'qatoken'}
    assert request('urun-fiyat-listesi.php',101,edit)[0]==200 and price()==555.5
    assert request('urun-fiyat-listesi.php',101,dict(edit,list_unit_price=''))[0]==302 and price()==555.5
    # XLSX numeric cells, leading zero safe string identifiers and published archive switching.
    buf=io.BytesIO()
    with zipfile.ZipFile(buf,'w') as z:
        z.writestr('xl/worksheets/sheet1.xml','''<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Barkod</t></is></c><c r="B1" t="inlineStr"><is><t>Birim Fiyat</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>8699234860003</t></is></c><c r="B2"><v>666.75</v></c></row></sheetData></worksheet>''')
    assert upload(buf.getvalue(),False,filename='prices.xlsx')[0]==302
    second=con.execute('SELECT MAX(id) FROM price_lists').fetchone()[0]
    assert price()==555.5
    assert post('current',second)[0]==302 and price()==666.75
    assert con.execute('SELECT COUNT(*) FROM price_lists WHERE is_current=1').fetchone()[0]==1
    assert post('current',first)[0]==302 and price()==555.5
    # Failure to persist audit must roll back the switch.
    con.execute("CREATE TRIGGER qa_fail_audit BEFORE INSERT ON audit_logs WHEN NEW.entity_type='fiyat_listesi' BEGIN SELECT RAISE(ABORT,'test'); END")
    con.commit()
    assert post('current',second)[0]==200 and price()==555.5
    con.execute('DROP TRIGGER qa_fail_audit');con.commit()
    history=json.loads(request('musteri-urun-son-fiyat.php?cari_id=102&currency=TL')[1])
    assert next(i['unit_price'] for i in history['items'] if i['name']==name)==280
    env=dict(os.environ, LIST_EXPECTED='555,5')
    subprocess.run([os.environ.get('NODE_BINARY','node'),str(__import__('pathlib').Path(__file__).with_name('test-urun-fiyat.js')),url],check=True,env=env)
    assert post('delete',second)[0]==302 and price()==555.5
    assert post('delete',first)[0]==302 and price() is None
    assert request('fiyat-listeleri.php?download='+str(first))[0]==404
    assert post('current',first)[0]==200 and price() is None
    assert count()==2 and con.execute('SELECT COUNT(*) FROM price_list_items').fetchone()[0]==2
    assert con.execute("SELECT COUNT(*) FROM audit_logs WHERE entity_type='fiyat_listesi'").fetchone()[0]>=7
    assert all(con.execute('SELECT * FROM '+t).fetchall()==rows for t,rows in financial.items())
    # PDF archive-only, paired structured data, ordinary editor and existing limited-document access.
    pdf=b'%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF'
    assert upload(pdf,True,filename='list.pdf')[0]==200 and count()==2
    assert upload(pdf,False,user=105,filename='list.pdf')[0]==302
    pdf_id=con.execute('SELECT MAX(id) FROM price_lists').fetchone()[0]
    assert request('fiyat-listeleri.php',106)[0]==200
    assert post('current',pdf_id)[0]==200 and price() is None
    assert upload(pdf,True,user=106,filename='list.pdf',extra='Artikel;Birim Fiyat\n6000;777')[0]==302
    paired_id=con.execute('SELECT MAX(id) FROM price_lists').fetchone()[0]
    assert price()==777
    assert post('delete',paired_id,106)[0]==302 and price() is None
    assert upload('<?php echo 1;',filename='bad.php')[0]==200 and count()==4
    bad=io.BytesIO()
    with zipfile.ZipFile(bad,'w') as z:
        z.writestr('xl/worksheets/sheet1.xml','<!DOCTYPE x [<!ENTITY y SYSTEM "file:///etc/passwd">]><worksheet/>')
    assert upload(bad.getvalue(),True,filename='unsafe.xlsx')[0]==200 and count()==4
    assert all(con.execute('SELECT * FROM '+t).fetchall()==rows for t,rows in financial.items())
    # Restore test fixture only, never touches a live database.
    con.executemany('UPDATE offer_products SET list_unit_price=? WHERE id=?',[(v,i) for i,v in before]);con.commit()
    print('Price-list lifecycle: CSV/XLSX, archive/current/delete, permissions, CSRF, audit rollback, history and financial invariants passed',flush=True)
