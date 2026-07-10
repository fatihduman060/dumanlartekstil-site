(function(){
  function esc(s){return String(s == null ? '' : s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function moneyText(v,c){return esc(v || '0,00') + ' ' + esc(c || 'TL');}
  function findMovementId(row){
    var a = row.querySelector('a[href*="hareketler.php?edit="]');
    if (!a) return '';
    try { return new URL(a.getAttribute('href'), location.href).searchParams.get('edit') || ''; }
    catch(e){ return ''; }
  }
  function looksLikeOffer(row){
    var t = (row.textContent || '').toLocaleLowerCase('tr-TR');
    return t.indexOf('teklif formu') !== -1 || t.indexOf('sipariş fişi') !== -1 || t.indexOf('siparis fisi') !== -1 || t.indexOf('ürün satışı') !== -1 || t.indexOf('urun satisi') !== -1;
  }
  function panel(){
    var p = document.getElementById('cariKaynakPanel');
    if (p) return p;
    p = document.createElement('section');
    p.id = 'cariKaynakPanel';
    p.className = 'panel-card cari-kaynak-panel';
    p.style.display = 'none';
    p.innerHTML = '<div class="card-head"><h3>Satış fişi içeriği</h3><button type="button" class="btn btn-secondary" id="cariKaynakKapat">Kapat</button></div><div id="cariKaynakIcerik"></div>';
    var sec = document.getElementById('hareketler');
    (sec || document.querySelector('.main') || document.body).insertAdjacentElement('afterend', p);
    var close = document.getElementById('cariKaynakKapat');
    if (close) close.onclick = function(){ p.style.display = 'none'; };
    return p;
  }
  function render(data){
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
  function load(movementId){
    var p = panel();
    p.style.display = 'block';
    document.getElementById('cariKaynakIcerik').innerHTML = '<p class="muted">Satış fişi okunuyor...</p>';
    fetch('cari-hareket-kaynak.php?movement_id=' + encodeURIComponent(movementId) + '&_=' + Date.now(), {credentials:'same-origin', cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(d){ if (!d || !d.ok) throw new Error((d && d.error) || 'Belge okunamadı'); render(d); })
      .catch(function(err){ document.getElementById('cariKaynakIcerik').innerHTML = '<p class="text-danger">'+esc(err.message || 'Belge okunamadı')+'</p>'; });
  }
  function enhance(){
    if (!/cari-detay\.php/i.test(location.pathname)) return;
    document.querySelectorAll('#hareketler tbody tr').forEach(function(row){
      if (!looksLikeOffer(row)) return;
      var mid = findMovementId(row);
      if (!mid) return;
      var descCell = row.children[4];
      if (!descCell || descCell.querySelector('.cari-kaynak-ac')) return;
      var text = descCell.innerHTML;
      descCell.innerHTML = '<button type="button" class="cari-kaynak-ac" data-movement-id="'+esc(mid)+'">'+text+'</button>';
      descCell.querySelector('.cari-kaynak-ac').addEventListener('click', function(){ load(mid); });
    });
  }
  function styles(){
    if (document.getElementById('cariKaynakStyle')) return;
    var s = document.createElement('style');
    s.id = 'cariKaynakStyle';
    s.textContent = '.cari-kaynak-ac{border:0;background:transparent;padding:0;margin:0;text-align:left;color:#061a33;font:inherit;font-weight:800;cursor:pointer}.cari-kaynak-ac:hover{text-decoration:underline}.cari-kaynak-panel{margin-top:16px}.cari-kaynak-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}.cari-kaynak-head small{display:block;color:#776b5c;margin-top:3px}.cari-kaynak-panel tfoot td{background:#fbf7ef;font-weight:900}@media(max-width:760px){.cari-kaynak-head{display:block}.cari-kaynak-head .row-actions{margin-top:8px}}';
    document.head.appendChild(s);
  }
  function init(){ styles(); enhance(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
