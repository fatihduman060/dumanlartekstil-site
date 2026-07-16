(function(){
  function createExcelCard(grid){
    var card = document.getElementById('maasExcelAktarCard');
    if(card) return card;

    card = document.createElement('section');
    card.id = 'maasExcelAktarCard';
    card.className = 'salary-card maas-excel-card';
    card.innerHTML = '<div class="salary-card-head"><h3>Excel’den personel aktar</h3><span>Ad Soyad + Maaş</span></div><div class="salary-body"><form method="post" action="maas-excel-aktar.php" enctype="multipart/form-data" class="salary-form"><input type="hidden" name="csrf_token" value="'+(document.querySelector('input[name=csrf_token]') ? document.querySelector('input[name=csrf_token]').value : '')+'"><label>Excel / CSV dosyası<input type="file" name="salary_excel" accept=".xlsx,.csv,text/csv" required></label><p class="muted" style="margin:0">Başlıklar: <strong>Ad Soyad</strong> ve <strong>Maaş</strong> olmalı. İsteğe bağlı: Bölüm, Görev, Telefon.</p><button class="btn btn-primary" type="submit">Excel dosyasını aktar</button></form></div>';

    var summary = grid.querySelector('.salary-summary');
    if(summary) summary.insertAdjacentElement('afterend', card); else grid.appendChild(card);
    return card;
  }

  function planCard(title, text, items){
    return '<article class="salary-plan-card"><span>TASLAK</span><h3>'+title+'</h3><p>'+text+'</p><div class="salary-plan-items">'+items.map(function(item){return '<div><b>'+item[0]+'</b><small>'+item[1]+'</small></div>';}).join('')+'</div></article>';
  }

  function createTabs(grid, excelCard){
    if(document.getElementById('salaryModuleTabs')) return;

    var hero = grid.querySelector('.salary-hero');
    var summary = grid.querySelector('.salary-summary');
    var columns = grid.querySelector('.salary-columns');
    if(!hero || !summary || !columns) return;

    var tabs = document.createElement('nav');
    tabs.id = 'salaryModuleTabs';
    tabs.className = 'salary-module-tabs';
    tabs.setAttribute('aria-label','Maaş modülü sekmeleri');
    tabs.innerHTML = ''+
      '<button type="button" data-salary-tab="maas">Maaş Takibi</button>'+
      '<button type="button" data-salary-tab="puantaj">Puantaj</button>'+
      '<button type="button" data-salary-tab="bordro">Bordro</button>';
    hero.insertAdjacentElement('afterend', tabs);

    var salaryPanel = document.createElement('div');
    salaryPanel.id = 'salaryTabMaas';
    salaryPanel.className = 'salary-tab-panel';
    salaryPanel.dataset.salaryPanel = 'maas';
    tabs.insertAdjacentElement('afterend', salaryPanel);
    salaryPanel.appendChild(summary);
    salaryPanel.appendChild(excelCard);
    salaryPanel.appendChild(columns);

    var attendancePanel = document.createElement('section');
    attendancePanel.id = 'salaryTabPuantaj';
    attendancePanel.className = 'salary-tab-panel salary-planning-panel';
    attendancePanel.dataset.salaryPanel = 'puantaj';
    attendancePanel.hidden = true;
    attendancePanel.innerHTML = '<div class="salary-planning-head"><div><span>PUANTAJ</span><h2>Aylık çalışma takibi</h2><p>Bu alan şimdilik yol haritası taslağıdır; henüz kayıt veya maaş hesabı oluşturmaz.</p></div><strong>'+((hero.querySelector('strong')||{}).textContent||'Seçili dönem')+'</strong></div><div class="salary-plan-grid">'+
      planCard('Günlük durum','Her personelin ay içindeki günlerini tek ekranda işaretleyeceğimiz bölüm.',[
        ['Çalıştı','Normal çalışma günü'],
        ['İzin / rapor','Ücretli, ücretsiz izin ve sağlık raporu'],
        ['Hafta tatili','Pazar veya kişiye özel izin günü'],
        ['Gelmedi','Eksik gün ve açıklaması']
      ])+
      planCard('Mesai ve süreler','Bordroya aktarılacak çalışma sürelerinin özeti.',[
        ['Fazla mesai','Saat veya gün olarak giriş'],
        ['Geç kalma','İstenirse dakika/saat takibi'],
        ['Resmî tatil','Çalıştı veya tatil durumu'],
        ['Aylık özet','Çalışılan ve eksik gün toplamı']
      ])+
      '</div>';
    salaryPanel.insertAdjacentElement('afterend', attendancePanel);

    var payrollPanel = document.createElement('section');
    payrollPanel.id = 'salaryTabBordro';
    payrollPanel.className = 'salary-tab-panel salary-planning-panel';
    payrollPanel.dataset.salaryPanel = 'bordro';
    payrollPanel.hidden = true;
    payrollPanel.innerHTML = '<div class="salary-planning-head"><div><span>BORDRO</span><h2>Puantajdan maaşa geçiş</h2><p>Bu alan da şimdilik taslaktır; mevcut maaş ve kasa kayıtlarını değiştirmez.</p></div><strong>'+((hero.querySelector('strong')||{}).textContent||'Seçili dönem')+'</strong></div><div class="salary-plan-grid">'+
      planCard('Kazançlar','Personelin o aya ait toplam hakedişini oluşturacağımız bölüm.',[
        ['Aylık ücret','Personel kartındaki varsayılan maaş'],
        ['Fazla mesai','Puantajdan otomatik gelecek tutar'],
        ['Prim / ek ödeme','Manuel eklenebilecek kazanç'],
        ['Brüt hakediş','Tüm kazançların toplamı']
      ])+
      planCard('Kesinti ve ödeme','Net ödenecek tutarın ve ödeme durumunun takibi.',[
        ['Avans','Ay içinde alınan avanslar'],
        ['Eksik gün','Puantajdan gelen kesinti'],
        ['Diğer kesinti','Manuel açıklamalı kesinti'],
        ['Net ödeme','Kasa veya bankaya işlenecek tutar']
      ])+
      '</div>';
    attendancePanel.insertAdjacentElement('afterend', payrollPanel);

    function activate(tab, updateUrl){
      if(['maas','puantaj','bordro'].indexOf(tab) === -1) tab = 'maas';
      Array.prototype.forEach.call(document.querySelectorAll('[data-salary-panel]'), function(panel){
        panel.hidden = panel.dataset.salaryPanel !== tab;
      });
      Array.prototype.forEach.call(tabs.querySelectorAll('[data-salary-tab]'), function(button){
        var active = button.dataset.salaryTab === tab;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      if(updateUrl){
        var url = new URL(location.href);
        if(tab === 'maas') url.searchParams.delete('salary_tab'); else url.searchParams.set('salary_tab', tab);
        history.replaceState(null, '', url.pathname + url.search + url.hash);
      }
    }

    tabs.addEventListener('click', function(event){
      var button = event.target.closest('[data-salary-tab]');
      if(!button) return;
      activate(button.dataset.salaryTab, true);
    });

    var initialTab = new URL(location.href).searchParams.get('salary_tab') || 'maas';
    activate(initialTab, false);
  }

  function addStyles(){
    if(document.getElementById('salaryModuleTabStyles')) return;
    var st = document.createElement('style');
    st.id = 'salaryModuleTabStyles';
    st.textContent = ''+
      '.maas-excel-card{border:1px dashed #c49a4f!important;background:#fffdf8!important}.maas-excel-card .salary-card-head{background:#fff7e7!important}.maas-excel-card input[type=file]{padding:12px;background:#fff;border-style:dashed}'+
      '.salary-module-tabs{display:flex;gap:8px;padding:7px;background:#eee5d7;border:1px solid #ded1be;border-radius:16px;overflow:auto}.salary-module-tabs button{flex:0 0 auto;border:0;border-radius:11px;padding:11px 18px;background:transparent;color:#526257;font-size:13px;font-weight:900;cursor:pointer}.salary-module-tabs button.is-active{background:#16482e;color:#fff;box-shadow:0 8px 18px rgba(22,72,46,.18)}'+
      '.salary-tab-panel{display:grid;gap:16px}.salary-tab-panel[hidden]{display:none!important}.salary-planning-panel{background:#fff;border:1px solid #e5dccf;border-radius:22px;padding:20px;box-shadow:0 12px 34px rgba(7,27,63,.06)}.salary-planning-head{display:flex;justify-content:space-between;gap:16px;align-items:center;padding-bottom:16px;border-bottom:1px solid #e5dccf}.salary-planning-head span{display:block;color:#8a6a26;font-size:11px;font-weight:950;letter-spacing:.08em}.salary-planning-head h2{margin:4px 0;color:#102818}.salary-planning-head p{margin:0;color:#776b5c}.salary-planning-head>strong{padding:9px 13px;border-radius:999px;background:#edf5ef;color:#16482e;white-space:nowrap}.salary-plan-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.salary-plan-card{border:1px solid #e5dccf;border-radius:18px;padding:18px;background:linear-gradient(145deg,#fff,#fbf6ed)}.salary-plan-card>span{font-size:10px;font-weight:950;color:#8a6a26}.salary-plan-card h3{margin:5px 0;color:#102818}.salary-plan-card>p{margin:0 0 14px;color:#776b5c}.salary-plan-items{display:grid;gap:8px}.salary-plan-items>div{display:grid;gap:2px;padding:10px 11px;border-radius:12px;background:#fff;border:1px solid #eee5d7}.salary-plan-items b{color:#16482e;font-size:13px}.salary-plan-items small{color:#776b5c}'+
      '@media(max-width:760px){.salary-module-tabs button{padding:10px 14px}.salary-planning-head{display:block}.salary-planning-head>strong{display:inline-flex;margin-top:12px}.salary-plan-grid{grid-template-columns:1fr}}';
    document.head.appendChild(st);
  }

  function init(){
    if (!/maaslar\.php/i.test(location.pathname)) return;
    var grid = document.querySelector('.salary-grid');
    if (!grid) return;
    addStyles();
    var excelCard = createExcelCard(grid);
    createTabs(grid, excelCard);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();