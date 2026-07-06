(function(){
  function init(){
    if (!/maaslar\.php/i.test(location.pathname)) return;
    var grid = document.querySelector('.salary-grid');
    if (!grid || document.getElementById('maasExcelAktarCard')) return;
    var card = document.createElement('section');
    card.id = 'maasExcelAktarCard';
    card.className = 'salary-card maas-excel-card';
    card.innerHTML = '<div class="salary-card-head"><h3>Excel’den personel aktar</h3><span>Ad Soyad + Maaş</span></div><div class="salary-body"><form method="post" action="maas-excel-aktar.php" enctype="multipart/form-data" class="salary-form"><input type="hidden" name="csrf_token" value="'+(document.querySelector('input[name=csrf_token]') ? document.querySelector('input[name=csrf_token]').value : '')+'"><label>Excel / CSV dosyası<input type="file" name="salary_excel" accept=".xlsx,.csv,text/csv" required></label><p class="muted" style="margin:0">Başlıklar: <strong>Ad Soyad</strong> ve <strong>Maaş</strong> olmalı. İsteğe bağlı: Bölüm, Görev, Telefon.</p><button class="btn btn-primary" type="submit">Excel dosyasını aktar</button></form></div>';
    var summary = grid.querySelector('.salary-summary');
    if (summary) summary.insertAdjacentElement('afterend', card); else grid.insertAdjacentElement('afterbegin', card);
    var st = document.createElement('style');
    st.textContent = '.maas-excel-card{border:1px dashed #c49a4f!important;background:#fffdf8!important}.maas-excel-card .salary-card-head{background:#fff7e7!important}.maas-excel-card input[type=file]{padding:12px;background:#fff;border-style:dashed}';
    document.head.appendChild(st);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
