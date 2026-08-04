(function(){
  function parseMoney(text){
    text = String(text || '').replace(/\s+/g, ' ');
    var m = text.match(/-?[0-9.]+,[0-9]{2}/);
    if (!m) m = text.match(/-?[0-9]+(?:\.[0-9]{3})*/);
    if (!m) return 0;
    var raw = m[0].replace(/\./g, '').replace(',', '.');
    var n = parseFloat(raw);
    return Number.isFinite(n) ? n : 0;
  }
  function fmt(n){
    try { return new Intl.NumberFormat('tr-TR', {minimumFractionDigits:2, maximumFractionDigits:2}).format(n) + ' TL'; }
    catch(e){ return n.toFixed(2).replace('.', ',') + ' TL'; }
  }
  function titleText(){
    var active = document.querySelector('.check-direction-tabs a.active');
    var t = active ? (active.textContent || '') : (document.body.textContent || '');
    t = t.toLocaleLowerCase('tr-TR');
    return t.indexOf('verilen') !== -1 ? 'Verilen çek toplamı' : 'Alınan çek toplamı';
  }
  function apply(){
    if (!/cekler\.php/i.test(location.pathname)) return;
    var table = document.querySelector('.check-table');
    if (!table) return;
    var rows = Array.from(table.querySelectorAll('tbody tr')).filter(function(row){
      return !row.classList.contains('empty') && row.children.length >= 4 && (row.textContent || '').indexOf('Çek kaydı yok') === -1;
    });
    var total = 0;
    var count = 0;
    rows.forEach(function(row){
      var amount = parseMoney(row.children[3] ? row.children[3].textContent : '');
      if (amount > 0) { total += amount; count++; }
    });
    var foot = table.querySelector('tfoot.cek-liste-toplam-foot');
    if (!foot) {
      foot = document.createElement('tfoot');
      foot.className = 'cek-liste-toplam-foot';
      table.appendChild(foot);
    }
    foot.innerHTML = '<tr class="cek-liste-toplam-row"><td colspan="3"><strong>'+titleText()+'</strong><small>Filtrelenen / ekranda görünen '+count+' çek</small></td><td><strong>'+fmt(total)+'</strong></td><td colspan="3"></td></tr>';
  }
  function style(){
    if (document.getElementById('cekListeToplamStyle')) return;
    var s = document.createElement('style');
    s.id = 'cekListeToplamStyle';
    s.textContent = '.cek-liste-toplam-foot td{position:sticky;bottom:0;background:#102818!important;color:#fff!important;border-top:2px solid #c49a4f!important;font-size:14px!important}.cek-liste-toplam-foot td strong{color:#fff!important;font-size:16px!important}.cek-liste-toplam-foot td small{display:block!important;color:#f4dfae!important;margin-top:4px!important}.cek-liste-toplam-row td:nth-child(2){text-align:left!important}';
    document.head.appendChild(s);
  }
  function init(){ style(); apply(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

(function(){
  function checkIdFromLink(link){
    if(!link) return '';
    try{return new URL(link.getAttribute('href'), location.href).searchParams.get('edit') || '';}
    catch(e){return '';}
  }

  function imageUrl(id){
    return 'cek-gorsel-ac.php?id=' + encodeURIComponent(id);
  }

  function addImageAction(row, recordLink, id){
    var actions = recordLink.parentElement;
    if(!actions || actions.querySelector('.cari-cek-gorsel-action')) return;
    var link = document.createElement('a');
    link.className = 'cari-cek-gorsel-action';
    link.href = imageUrl(id);
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = 'Çek görseli';
    link.title = 'Çekin yüklenen görüntüsünü aç';
    actions.insertBefore(link, recordLink);
  }

  function makeCellClickable(cell, id, fallbackLabel){
    if(!cell || cell.querySelector('.cari-cek-gorsel-ac')) return;
    var small = cell.querySelector('small');
    var clone = cell.cloneNode(true);
    Array.prototype.forEach.call(clone.querySelectorAll('small'), function(node){ node.remove(); });
    var label = (clone.textContent || '').replace(/\s+/g, ' ').trim() || fallbackLabel;

    Array.prototype.slice.call(cell.childNodes).forEach(function(node){
      if(node.nodeType === 3) node.remove();
    });

    var link = document.createElement('a');
    link.className = 'cari-cek-gorsel-ac';
    link.href = imageUrl(id);
    link.target = '_blank';
    link.rel = 'noopener';
    link.textContent = label;
    link.title = 'Çekin yüklenen görüntüsünü aç';
    cell.insertBefore(link, small || cell.firstChild);
  }

  function enhanceMovementRows(){
    document.querySelectorAll('#hareketler tbody tr').forEach(function(row){
      var recordLink = row.querySelector('.row-actions a[href*="cekler.php"][href*="edit="]');
      var id = checkIdFromLink(recordLink);
      if(!id) return;
      addImageAction(row, recordLink, id);
      makeCellClickable(row.children[4], id, 'Çek görselini aç');

      var documentCell = row.children[5];
      if(documentCell && documentCell.textContent.trim() === '-'){
        documentCell.innerHTML = '<a class="cari-cek-gorsel-belge" href="' + imageUrl(id) + '" target="_blank" rel="noopener">Çek görseli</a>';
      }
    });
  }

  function enhanceCheckHistory(){
    document.querySelectorAll('#cekler tbody tr').forEach(function(row){
      var recordLink = row.querySelector('.row-actions a[href*="cekler.php"][href*="edit="]');
      var id = checkIdFromLink(recordLink);
      if(!id) return;
      addImageAction(row, recordLink, id);
      makeCellClickable(row.children[3], id, 'Çek görselini aç');
    });
  }

  function styles(){
    if(document.getElementById('cariCekGorselStyle')) return;
    var style = document.createElement('style');
    style.id = 'cariCekGorselStyle';
    style.textContent = '.cari-cek-gorsel-ac,.cari-cek-gorsel-belge,.cari-cek-gorsel-action{color:#16482e;font-weight:900;text-decoration:underline;text-underline-offset:2px;cursor:pointer}.cari-cek-gorsel-ac:hover,.cari-cek-gorsel-belge:hover,.cari-cek-gorsel-action:hover{color:#9a6b16}';
    document.head.appendChild(style);
  }

  function init(){
    if(!/cari-detay\.php/i.test(location.pathname)) return;
    styles();
    enhanceMovementRows();
    enhanceCheckHistory();
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
