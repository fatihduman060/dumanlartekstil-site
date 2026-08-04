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

(function(){
  function addCompensationEntry(){
    if(!/maaslar\.php/i.test(location.pathname)) return;
    if(document.getElementById('salaryCompensationEntry')) return;
    var grid=document.querySelector('.salary-grid');
    var summary=grid&&grid.querySelector('.salary-summary');
    if(!grid||!summary) return;

    var card=document.createElement('section');
    card.id='salaryCompensationEntry';
    card.className='salary-card salary-compensation-entry';
    card.innerHTML='<div><span>PERSONEL ÇIKIŞ ÖDEMELERİ</span><h3>Tazminat Ödemesi</h3><p>Kıdem, ihbar, kullanılmayan izin ücreti ve diğer tazminatları maaştan ayrı kaydet.</p></div><a href="maas-tazminat.php">Tazminat ödemesi aç</a>';
    summary.insertAdjacentElement('afterend',card);

    var heroTools=grid.querySelector('.salary-hero-tools');
    if(heroTools&&!heroTools.querySelector('.salary-compensation-shortcut')){
      var shortcut=document.createElement('a');
      shortcut.className='salary-excel-link salary-compensation-shortcut';
      shortcut.href='maas-tazminat.php';
      shortcut.textContent='Tazminat Ödemesi';
      heroTools.appendChild(shortcut);
    }

    if(!document.getElementById('salaryCompensationEntryStyle')){
      var style=document.createElement('style');
      style.id='salaryCompensationEntryStyle';
      style.textContent='.salary-compensation-entry{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:17px 19px;border-color:#d8b07a!important;background:linear-gradient(135deg,#fff8ed,#fff)!important}.salary-compensation-entry span{font-size:10px;font-weight:950;letter-spacing:.07em;color:#9a5f24}.salary-compensation-entry h3{margin:4px 0;color:#4d2c19}.salary-compensation-entry p{margin:0;color:#776b5c;font-size:12px}.salary-compensation-entry>a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border-radius:999px;background:#6f3e20;color:#fff;text-decoration:none;font-weight:950;white-space:nowrap}.salary-compensation-shortcut{background:#fff3df!important;color:#6f3e20!important}@media(max-width:700px){.salary-compensation-entry{align-items:stretch;flex-direction:column}.salary-compensation-entry>a{width:100%}}';
      document.head.appendChild(style);
    }
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',addCompensationEntry); else addCompensationEntry();
})();
