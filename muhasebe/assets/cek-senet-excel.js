(function(){
  var path=location.pathname||'';
  if(!/\/(?:cekler|cek-senet-arsivi)\.php$/i.test(path)) return;

  function button(href,label,title){
    var a=document.createElement('a');
    a.href=href;
    a.textContent=label;
    a.title=title;
    a.setAttribute('download','');
    a.style.cssText='display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:9px 13px;border-radius:999px;background:#fff;color:#16482e;border:1px solid #d8cdbb;text-decoration:none;font-size:11px;font-weight:950;white-space:nowrap;';
    return a;
  }

  function addButtons(wrap){
    wrap.appendChild(button('cek-senet-excel.php?type=gelen','Excel · Gelen','Müşteriden alınan çek ve senetleri Excel olarak indir'));
    wrap.appendChild(button('cek-senet-excel.php?type=giden','Excel · Giden / Ciro','Verilen ve ciro edilen çek ve senetleri Excel olarak indir'));
    wrap.appendChild(button('kartli-odemeler-excel.php','Excel · Kart ile Ödemeler','Cari borçlara kredi kartı ile yapılan ödemeleri Excel olarak indir'));
  }

  function addToChecks(){
    var actions=document.querySelector('.checks-actions');
    if(!actions||actions.querySelector('[data-cek-senet-excel]')) return false;
    var wrap=document.createElement('span');
    wrap.setAttribute('data-cek-senet-excel','1');
    wrap.style.cssText='display:flex;gap:8px;flex-wrap:wrap;';
    addButtons(wrap);
    actions.appendChild(wrap);
    return true;
  }

  function addToArchive(){
    var hero=document.querySelector('.archive-hero');
    if(!hero||hero.querySelector('[data-cek-senet-excel]')) return false;
    var existing=hero.querySelector('a[href*="cekler.php"]');
    var wrap=document.createElement('div');
    wrap.setAttribute('data-cek-senet-excel','1');
    wrap.style.cssText='display:flex;gap:8px;flex-wrap:wrap;align-items:center;';
    addButtons(wrap);
    if(existing){ existing.insertAdjacentElement('beforebegin',wrap); }
    else { hero.appendChild(wrap); }
    return true;
  }

  function init(){
    if(/\/cekler\.php$/i.test(path)) addToChecks();
    if(/\/cek-senet-arsivi\.php$/i.test(path)) addToArchive();
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
  window.setTimeout(init,300);
})();
