(function(){
  function esc(s){return String(s == null ? '' : s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function moneyText(v,c){return esc(v || '0,00') + ' ' + esc(c || 'TL');}
  function norm(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C')
      .replace(/[^A-Z0-9]+/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }
  function findMovementId(row){
    var a = row.querySelector('a[href*="hareketler.php?edit="]');
    if (!a) return '';
    try { return new URL(a.getAttribute('href'), location.href).searchParams.get('edit') || ''; }
    catch(e){ return ''; }
  }
  function sourceKind(row){
    var t = (row.textContent || '').toLocaleLowerCase('tr-TR');
    if (t.indexOf('giden fatura') !== -1 || t.indexOf('gelen fatura') !== -1 || t.indexOf('nolu fatura') !== -1) return 'invoice';
    if (t.indexOf('teklif formu') !== -1 || t.indexOf('sipariş fişi') !== -1 || t.indexOf('siparis fisi') !== -1 || t.indexOf('ürün satışı') !== -1 || t.indexOf('urun satisi') !== -1) return 'offer';
    return '';
  }
  function shortInvoiceNo(value){
    var compact=String(value||'').toUpperCase().replace(/[^A-Z0-9]/g,'');
    var match=compact.match(/^[A-Z]{2,8}20\d{2}(\d+)$/);
    if(!match) match=compact.match(/(\d+)$/);
    if(!match) return compact||'Fatura';
    var serial=String(match[1]||'').replace(/^0+/,'');
    return serial||'0';
  }
  function invoiceNoFromText(text){
    var raw=String(text||'');
    var match=raw.match(/(?:gelen|giden)\s+fatura\s+no\s*:\s*([A-Z0-9._\/-]+)/i);
    if(match) return match[1];
    match=raw.match(/([0-9]+)\s*nolu\s*fatura/i);
    return match?match[1]:'';
  }
  function panel(){
    var p = document.getElementById('cariKaynakPanel');
    if (p) return p;
    p = document.createElement('section');
    p.id = 'cariKaynakPanel';
    p.className = 'panel-card cari-kaynak-panel';
    p.style.display = 'none';
    p.innerHTML = '<div class="card-head"><h3>Belge içeriği</h3><button type="button" class="btn btn-secondary" id="cariKaynakKapat">Kapat</button></div><div id="cariKaynakIcerik"></div>';
    var sec = document.getElementById('hareketler');
    (sec || document.querySelector('.main') || document.body).insertAdjacentElement('afterend', p);
    var close = document.getElementById('cariKaynakKapat');
    if (close) close.onclick = function(){ p.style.display = 'none'; };
    return p;
  }
  function renderOffer(data){
    var p = panel();
    var b = document.getElementById('cariKaynakIcerik');
    var o = data.offer || {};
    var items = data.items || [];
    var currency = o.currency || 'TL';
    var qtyLabel = o.quantity_label || 'Miktar';
    var hasType = items.some(function(it){ return String(it.product_type || '').trim() !== ''; });
    p.querySelector('h3').textContent = (o.document_title || 'Satış fişi') + ' no: ' + (o.offer_no || '');
    var rows = items.map(function(it){
      var typeTd = hasType ? '<td>'+esc(it.product_type || '')+'</td>' : '';
      return '<tr><td><strong>'+esc(it.product_barcode || '-')+'</strong></td><td><strong>'+esc(it.product_name || '-')+'</strong></td>'+typeTd+'<td class="right">'+esc(it.quantity_text || '')+'</td><td class="right">'+moneyText(it.unit_price_text,currency)+'</td><td class="right"><strong>'+moneyText(it.line_total_text,currency)+'</strong></td></tr>';
    }).join('');
    var colCount = hasType ? 6 : 5;
    var totalSpan = hasType ? 5 : 4;
    if (!rows) rows = '<tr><td colspan="'+colCount+'" class="empty">Ürün satırı bulunamadı.</td></tr>';
    var typeHead = hasType ? '<th>Ürün cinsi / açıklama</th>' : '';
    var discountRow = o.discount_enabled ? '<tr><td colspan="'+totalSpan+'" class="right">İskonto %'+esc(o.discount_rate)+'</td><td class="right"><strong>-'+moneyText(o.discount_amount_text,currency)+'</strong></td></tr>' : '';
    var vatRow = o.vat_enabled ? '<tr><td colspan="'+totalSpan+'" class="right">KDV %'+esc(o.vat_rate)+'</td><td class="right"><strong>'+moneyText(o.vat_amount_text,currency)+'</strong></td></tr>' : '';
    b.innerHTML = '<div class="cari-kaynak-head"><div><strong>'+esc(o.customer_name || '')+'</strong><small>'+esc(o.offer_date || '')+'</small></div><div class="row-actions"><a href="'+esc(o.pdf_url || '#')+'" target="_blank">PDF Aç</a><a href="'+esc(o.edit_url || '#')+'">Düzenle</a></div></div><div class="table-wrap"><table><thead><tr><th>Barkod</th><th>Ürün adı</th>'+typeHead+'<th class="right">'+esc(qtyLabel)+'</th><th class="right">Birim fiyat</th><th class="right">Tutar</th></tr></thead><tbody>'+rows+'</tbody><tfoot><tr><td colspan="'+totalSpan+'" class="right">Ara toplam</td><td class="right"><strong>'+moneyText(o.subtotal_text,currency)+'</strong></td></tr>'+discountRow+vatRow+'<tr><td colspan="'+totalSpan+'" class="right">Genel toplam</td><td class="right"><strong>'+moneyText(o.grand_total_text,currency)+'</strong></td></tr></tfoot></table></div>'+(o.note ? '<p class="muted"><strong>Not:</strong> '+esc(o.note)+'</p>' : '');
    p.style.display = 'block';
    p.scrollIntoView({behavior:'smooth', block:'nearest'});
  }
  function loadPdfJs(){
    if(window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return new Promise(function(resolve,reject){
      var existing=document.querySelector('script[data-cari-fatura-pdfjs]');
      if(existing){
        existing.addEventListener('load',function(){resolve(window.pdfjsLib);},{once:true});
        existing.addEventListener('error',function(){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));},{once:true});
        return;
      }
      var script=document.createElement('script');
      script.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
      script.setAttribute('data-cari-fatura-pdfjs','1');
      script.onload=function(){
        if(!window.pdfjsLib){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));return;}
        window.pdfjsLib.GlobalWorkerOptions.workerSrc='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        resolve(window.pdfjsLib);
      };
      script.onerror=function(){reject(new Error('PDF okuma kütüphanesi yüklenemedi.'));};
      document.head.appendChild(script);
    });
  }
  function pageRows(content){
    var rows=[];
    (content.items||[]).forEach(function(item){
      var text=String(item.str||'').trim();
      if(!text) return;
      var x=item.transform&&item.transform.length>4?Number(item.transform[4]||0):0;
      var y=item.transform&&item.transform.length>5?Number(item.transform[5]||0):0;
      var row=null;
      for(var i=0;i<rows.length;i++){
        if(Math.abs(rows[i].y-y)<=2.5){row=rows[i];break;}
      }
      if(!row){row={y:y,items:[]};rows.push(row);}
      row.items.push({x:x,text:text});
    });
    rows.sort(function(a,b){return b.y-a.y;});
    return rows.map(function(row){
      row.items.sort(function(a,b){return a.x-b.x;});
      return row.items.map(function(item){return item.text;}).join(' ').replace(/\s+/g,' ').trim();
    }).filter(Boolean);
  }
  function extractInvoiceLines(lines){
    var start=-1;
    var end=lines.length;
    for(var i=0;i<lines.length;i++){
      var key=norm(lines[i]);
      if((key.indexOf('MAL HIZMET')!==-1||key.indexOf('URUN HIZMET')!==-1||key.indexOf('HIZMET ACIKLAMASI')!==-1) && (key.indexOf('MIKTAR')!==-1||key.indexOf('BIRIM FIYAT')!==-1||key.indexOf('TUTAR')!==-1)){
        start=i+1;
        break;
      }
    }
    if(start<0){
      for(var h=0;h<lines.length;h++){
        var hk=norm(lines[h]);
        if(hk==='MAL HIZMET'||hk==='URUN HIZMET'||hk.indexOf('MAL HIZMET ACIKLAMASI')!==-1){start=h+1;break;}
      }
    }
    if(start<0) start=0;
    for(var j=start;j<lines.length;j++){
      var endKey=norm(lines[j]);
      if(endKey.indexOf('MAL HIZMET TOPLAM')!==-1||endKey.indexOf('VERGILER HARIC TOPLAM')!==-1||endKey.indexOf('HESAPLANAN KDV')!==-1||endKey.indexOf('ODENECEK TUTAR')!==-1||endKey.indexOf('GENEL TOPLAM')!==-1){end=j;break;}
    }
    var blocked=/FATURA|ETTN|UUID|VKN|TCKN|VERGI DAIRESI|SENARYO|TARIH|SAAT|IRSALIYE|SIPARIS NO|MERSIS|TICARET SICIL|IBAN|BANKA|TOPLAM KDV|KDV MATRAHI/;
    var results=[];
    var seen={};
    for(var k=start;k<end;k++){
      var raw=String(lines[k]||'').trim();
      var key=norm(raw);
      if(!raw||raw.length<3||blocked.test(key)) continue;
      if(/^(SIRA NO|MIKTAR|BIRIM|FIYAT|TUTAR|KDV|ISKONTO|MAL HIZMET)$/.test(key)) continue;
      if(!/[A-ZÇĞİÖŞÜa-zçğıöşü]/.test(raw)) continue;
      if(raw.length>220) raw=raw.slice(0,217)+'...';
      if(seen[key]) continue;
      seen[key]=1;
      results.push(raw);
      if(results.length>=35) break;
    }
    return results;
  }
  async function renderPdfPreview(pdf){
    var canvas=document.getElementById('cariFaturaCanvas');
    if(!canvas) return;
    var page=await pdf.getPage(1);
    var base=page.getViewport({scale:1});
    var holder=canvas.parentElement;
    var target=Math.min(920,Math.max(320,(holder?holder.clientWidth:760)-24));
    var scale=target/base.width;
    var viewport=page.getViewport({scale:scale});
    canvas.width=Math.floor(viewport.width);
    canvas.height=Math.floor(viewport.height);
    canvas.style.width=Math.floor(viewport.width)+'px';
    canvas.style.height=Math.floor(viewport.height)+'px';
    await page.render({canvasContext:canvas.getContext('2d'),viewport:viewport}).promise;
  }
  async function loadInvoiceDocument(inv){
    var itemBox=document.getElementById('cariFaturaKalemleri');
    var previewStatus=document.getElementById('cariFaturaPreviewStatus');
    if(!inv.has_document||!inv.document_url){
      if(itemBox) itemBox.innerHTML='<p class="muted">Bu faturaya ait PDF dosyası bulunmuyor.</p>';
      if(previewStatus) previewStatus.textContent='PDF önizlemesi yok.';
      return;
    }
    try{
      var values=await Promise.all([
        loadPdfJs(),
        fetch(inv.document_url,{credentials:'same-origin',cache:'no-store'}).then(function(response){if(!response.ok) throw new Error('Fatura PDF dosyası açılamadı.');return response.arrayBuffer();})
      ]);
      var pdf=await values[0].getDocument({data:values[1]}).promise;
      var lines=[];
      for(var pageNo=1;pageNo<=Math.min(pdf.numPages,5);pageNo++){
        var page=await pdf.getPage(pageNo);
        var content=await page.getTextContent();
        lines=lines.concat(pageRows(content));
      }
      var items=extractInvoiceLines(lines);
      if(itemBox){
        if(items.length){
          itemBox.innerHTML='<div class="table-wrap"><table><thead><tr><th>#</th><th>Faturada yazan ürün / hizmet</th></tr></thead><tbody>'+items.map(function(line,index){return '<tr><td><strong>'+(index+1)+'</strong></td><td>'+esc(line)+'</td></tr>';}).join('')+'</tbody></table></div>';
        }else{
          itemBox.innerHTML='<p class="muted">Fatura kalemleri metin olarak ayrılamadı. Faturanın birebir görüntüsü aşağıda gösteriliyor.</p>';
        }
      }
      if(previewStatus) previewStatus.textContent=pdf.numPages>1?'Faturanın ilk sayfası gösteriliyor. Tamamı için PDF Aç bağlantısını kullan.':'Faturanın birebir görüntüsü';
      await renderPdfPreview(pdf);
    }catch(error){
      if(itemBox) itemBox.innerHTML='<p class="text-danger">'+esc(error.message||'Fatura içeriği okunamadı.')+'</p>';
      if(previewStatus) previewStatus.textContent='PDF önizlemesi hazırlanamadı.';
    }
  }
  function renderInvoice(data){
    var p=panel();
    var b=document.getElementById('cariKaynakIcerik');
    var inv=data.invoice||{};
    var currency=inv.currency||'TL';
    var title=(inv.short_no||shortInvoiceNo(inv.invoice_no||''))+' nolu fatura';
    p.querySelector('h3').textContent=title+' içeriği';
    b.innerHTML=''
      +'<div class="cari-kaynak-head"><div><strong>'+esc(inv.cari_name||'Cari seçilmedi')+'</strong><small>'+esc(inv.invoice_date||'')+(inv.due_date?' · Vade: '+esc(inv.due_date):'')+' · '+esc(inv.direction_label||'Fatura')+'</small></div><div class="row-actions">'+(inv.document_url?'<a href="'+esc(inv.document_url)+'" target="_blank">PDF Aç</a>':'')+'<a href="'+esc(inv.edit_url||'#')+'">Faturayı düzenle</a><a href="'+esc(inv.list_url||'faturalar.php')+'">Fatura listesi</a></div></div>'
      +'<div class="cari-fatura-ozet"><article><span>Fatura no</span><strong>'+esc(inv.invoice_no||inv.short_no||'')+'</strong></article><article><span>Matrah</span><strong>'+moneyText(inv.subtotal_text,currency)+'</strong></article><article><span>KDV</span><strong>'+moneyText(inv.vat_text,currency)+'</strong></article><article><span>Genel toplam</span><strong>'+moneyText(inv.total_text,currency)+'</strong></article></div>'
      +'<div class="cari-fatura-block"><div class="cari-fatura-block-head"><strong>Müşteriye gönderilen ürün / hizmetler</strong><small>Fatura PDF’sinin içinden otomatik okunur.</small></div><div id="cariFaturaKalemleri"><p class="muted">Fatura kalemleri okunuyor...</p></div></div>'
      +'<div class="cari-fatura-block"><div class="cari-fatura-block-head"><strong>Faturanın aslı</strong><small id="cariFaturaPreviewStatus">PDF önizlemesi hazırlanıyor...</small></div><div class="cari-fatura-canvas-wrap"><canvas id="cariFaturaCanvas"></canvas></div></div>'
      +(inv.description?'<p class="muted"><strong>Not:</strong> '+esc(inv.description)+'</p>':'');
    p.style.display='block';
    p.scrollIntoView({behavior:'smooth',block:'nearest'});
    loadInvoiceDocument(inv);
  }
  function render(data){
    if(data.type==='invoice'){renderInvoice(data);return;}
    renderOffer(data);
  }
  function load(movementId){
    var p = panel();
    p.style.display = 'block';
    document.getElementById('cariKaynakIcerik').innerHTML = '<p class="muted">Belge içeriği okunuyor...</p>';
    fetch('cari-hareket-kaynak.php?movement_id=' + encodeURIComponent(movementId) + '&_=' + Date.now(), {credentials:'same-origin', cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(d){ if (!d || !d.ok) throw new Error((d && d.error) || 'Belge okunamadı'); render(d); })
      .catch(function(err){ document.getElementById('cariKaynakIcerik').innerHTML = '<p class="text-danger">'+esc(err.message || 'Belge okunamadı')+'</p>'; });
  }
  function enhance(){
    if (!/cari-detay\.php/i.test(location.pathname)) return;
    document.querySelectorAll('#hareketler tbody tr').forEach(function(row){
      var kind=sourceKind(row);
      if(!kind) return;
      var mid=findMovementId(row);
      if(!mid) return;
      var descCell=row.children[4];
      if(!descCell||descCell.querySelector('.cari-kaynak-ac')) return;
      var small=descCell.querySelector('small');
      var smallHtml=small?small.outerHTML:'';
      var label='';
      if(kind==='invoice'){
        var invoiceNo=invoiceNoFromText(descCell.textContent||'');
        label=shortInvoiceNo(invoiceNo)+' nolu fatura';
      }else{
        var textOnly='';
        Array.prototype.forEach.call(descCell.childNodes,function(node){if(node.nodeType===3) textOnly+=node.textContent;});
        label=textOnly.trim()||'Satış fişi içeriğini aç';
      }
      descCell.innerHTML='<button type="button" class="cari-kaynak-ac" data-movement-id="'+esc(mid)+'" data-source-kind="'+esc(kind)+'">'+esc(label)+'</button>'+smallHtml;
      descCell.querySelector('.cari-kaynak-ac').addEventListener('click',function(){load(mid);});
    });
  }
  function styles(){
    if(document.getElementById('cariKaynakStyle')) return;
    var s=document.createElement('style');
    s.id='cariKaynakStyle';
    s.textContent=''
      +'.cari-kaynak-ac{border:0;background:transparent;padding:0;margin:0;text-align:left;color:#061a33;font:inherit;font-weight:900;cursor:pointer}.cari-kaynak-ac:hover{text-decoration:underline}.cari-kaynak-panel{margin-top:16px}.cari-kaynak-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}.cari-kaynak-head small{display:block;color:#776b5c;margin-top:3px}.cari-kaynak-panel tfoot td{background:#fbf7ef;font-weight:900}'
      +'.cari-fatura-ozet{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px;margin:12px 0}.cari-fatura-ozet article{border:1px solid var(--border);background:#fbfaf7;border-radius:13px;padding:11px;display:grid;gap:4px}.cari-fatura-ozet span{font-size:9px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:850}.cari-fatura-ozet strong{font-size:13px;word-break:break-word}.cari-fatura-block{border:1px solid var(--border);border-radius:15px;padding:13px;margin-top:12px;background:#fff}.cari-fatura-block-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.cari-fatura-block-head strong{font-size:13px}.cari-fatura-block-head small{font-size:10px;color:var(--muted)}.cari-fatura-canvas-wrap{overflow:auto;background:#ece9e2;border-radius:12px;padding:10px;text-align:center}.cari-fatura-canvas-wrap canvas{display:block;max-width:none;margin:0 auto;background:#fff;box-shadow:0 4px 18px rgba(0,0,0,.12)}'
      +'@media(max-width:900px){.cari-fatura-ozet{grid-template-columns:repeat(2,minmax(120px,1fr))}}@media(max-width:760px){.cari-kaynak-head{display:block}.cari-kaynak-head .row-actions{margin-top:8px}.cari-fatura-block-head{display:grid}.cari-fatura-ozet{grid-template-columns:1fr 1fr}}';
    document.head.appendChild(s);
  }
  function init(){styles();enhance();}
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
