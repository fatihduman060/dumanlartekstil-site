(function(){
  'use strict';
  if(!/\/cekler\.php$/i.test(location.pathname)) return;

  var details=document.getElementById('cek-form');
  var form=details?details.querySelector('form[enctype="multipart/form-data"]'):null;
  if(!form) return;

  var front=form.querySelector('input[name="front_document"]');
  var amount=form.querySelector('input[name="amount"]');
  var due=form.querySelector('input[name="due_date"]');
  var issue=form.querySelector('input[name="issue_date"]');
  var bank=form.querySelector('select[name="bank_name"]');
  var checkNo=form.querySelector('input[name="check_no"]');
  var branch=form.querySelector('input[name="branch_name"]');
  var drawer=form.querySelector('input[name="drawer"]');
  if(!front) return;

  var style=document.createElement('style');
  style.textContent=''
    +'.cek-ocr-box{grid-column:1/-1;border:1px solid #cfe2d5;background:#f3fbf6;border-radius:14px;padding:12px 14px;display:grid;gap:8px}'
    +'.cek-ocr-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.cek-ocr-head strong{color:#16482e}.cek-ocr-status{font-size:11px;color:#66756b;font-weight:750;line-height:1.45}'
    +'.cek-ocr-status.ok{color:#176536}.cek-ocr-status.warn{color:#946200}.cek-ocr-progress{height:7px;background:#e5eee8;border-radius:999px;overflow:hidden}.cek-ocr-progress span{display:block;height:100%;width:0;background:#2c7b4b;transition:width .2s ease}'
    +'.cek-ocr-preview{display:none;max-width:260px;max-height:130px;object-fit:contain;border-radius:10px;border:1px solid #dce7df;background:#fff}'
    +'.cek-ocr-box.has-preview .cek-ocr-preview{display:block}@media(max-width:700px){.cek-ocr-head{display:grid}.cek-ocr-preview{max-width:100%}}';
  document.head.appendChild(style);

  var box=document.createElement('div');
  box.className='cek-ocr-box';
  box.innerHTML='<div class="cek-ocr-head"><div><strong>📷 Çek görselini otomatik oku</strong><div class="cek-ocr-status">Ön görseli JPG/PNG olarak seçince tutar, tarih, banka, çek no, şube ve keşideci otomatik doldurulur. Kaydetmeden önce kontrol et.</div></div><img class="cek-ocr-preview" alt="Çek önizleme"></div><div class="cek-ocr-progress"><span></span></div>';
  var grid=form.querySelector('.check-form-grid');
  if(grid) grid.insertBefore(box,grid.firstChild);

  var status=box.querySelector('.cek-ocr-status');
  var progress=box.querySelector('.cek-ocr-progress span');
  var preview=box.querySelector('.cek-ocr-preview');
  var busy=false;

  function norm(s){
    return String(s||'').toLocaleUpperCase('tr-TR').replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G').replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C').replace(/[^A-Z0-9]+/g,' ').replace(/\s+/g,' ').trim();
  }
  function setStatus(text,tone){status.textContent=text;status.className='cek-ocr-status'+(tone?' '+tone:'');}
  function setField(el,value){
    if(!el||value==null||String(value).trim()==='') return false;
    el.value=String(value).trim();
    el.dispatchEvent(new Event('input',{bubbles:true}));
    el.dispatchEvent(new Event('change',{bubbles:true}));
    return true;
  }
  function toIso(d,m,y){
    d=Number(d);m=Number(m);y=Number(y);
    if(y<100) y+=2000;
    if(y<2000||y>2100||m<1||m>12||d<1||d>31) return '';
    return String(y).padStart(4,'0')+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
  }
  function findDate(text){
    var patterns=[
      /(?:VADE|ODEME\s*TARIHI|KESIDE\s*TARIHI|TARIH)[^0-9]{0,18}(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})/i,
      /(\d{1,2})[.\/-](\d{1,2})[.\/-](20\d{2})/
    ];
    for(var i=0;i<patterns.length;i++){
      var m=text.match(patterns[i]);
      if(m){var iso=toIso(m[1],m[2],m[3]);if(iso) return iso;}
    }
    return '';
  }
  function parseNumber(raw){
    var s=String(raw||'').replace(/\s/g,'').replace(/[^0-9.,]/g,'');
    if(!s) return 0;
    var comma=s.lastIndexOf(','),dot=s.lastIndexOf('.');
    if(comma>=0&&dot>=0){
      if(comma>dot)s=s.replace(/\./g,'').replace(',','.');
      else s=s.replace(/,/g,'');
    }else if(comma>=0){
      var c=s.length-comma-1;
      s=c<=2?s.replace(/\./g,'').replace(',','.'):s.replace(/,/g,'');
    }else if(dot>=0){
      var d=s.length-dot-1;
      if(d===3&&/^\d{1,3}(\.\d{3})+$/.test(s)) s=s.replace(/\./g,'');
    }
    var n=parseFloat(s);
    return Number.isFinite(n)?n:0;
  }
  function findAmount(text){
    var candidates=[];
    var labelled=[
      /(?:TUTAR|MEBLAG|MIKTAR)[^0-9]{0,24}([0-9][0-9.\s]{1,14}(?:,[0-9]{1,2})?)/gi,
      /(?:₺|TL|TRY)\s*([0-9][0-9.\s]{1,14}(?:,[0-9]{1,2})?)/gi,
      /([0-9][0-9.\s]{1,14}(?:,[0-9]{1,2})?)\s*(?:₺|TL|TRY)/gi
    ];
    labelled.forEach(function(re){var m;while((m=re.exec(text))!==null){var n=parseNumber(m[1]);if(n>=1&&n<=100000000)candidates.push(n);}});
    if(!candidates.length) return 0;
    return Math.max.apply(null,candidates);
  }
  function moneyInput(n){
    if(!n) return '';
    return n.toLocaleString('tr-TR',{minimumFractionDigits:(Math.abs(n-Math.round(n))<0.001?0:2),maximumFractionDigits:2});
  }
  function findLabeled(text,labels,maxLen){
    var lines=String(text||'').split(/\r?\n/).map(function(x){return x.trim();}).filter(Boolean);
    for(var i=0;i<lines.length;i++){
      var line=lines[i];var n=norm(line);
      for(var j=0;j<labels.length;j++){
        var lab=norm(labels[j]);var pos=n.indexOf(lab);
        if(pos===-1) continue;
        var re=new RegExp(labels[j].replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'\\s*[:.\-]?\\s*(.+)$','i');
        var m=line.match(re);
        if(m&&m[1]) return m[1].trim().slice(0,maxLen||80);
        if(lines[i+1]&&norm(lines[i+1]).length>1) return lines[i+1].trim().slice(0,maxLen||80);
      }
    }
    return '';
  }
  function findCheckNo(text){
    var m=String(text||'').match(/(?:CEK\s*(?:NO|NUMARASI)|SERI\s*NO)\s*[:.\-]?\s*([A-Z0-9\-\/]{4,30})/i);
    return m?m[1].trim():'';
  }
  function bankAlias(text){
    var n=norm(text);
    var aliases=[
      ['TURKIYE IS BANKASI','İş Bankası'],['IS BANKASI','İş Bankası'],['GARANTI BBVA','Garanti BBVA'],['GARANTI BANKASI','Garanti BBVA'],
      ['YAPI KREDI','Yapı Kredi'],['AKBANK','Akbank'],['VAKIFBANK','VakıfBank'],['VAKIF BANK','VakıfBank'],['ZIRAAT BANKASI','Ziraat Bankası'],
      ['HALKBANK','Halkbank'],['HALK BANKASI','Halkbank'],['QNB FINANSBANK','QNB Finansbank'],['QNB','QNB Finansbank'],['DENIZBANK','DenizBank'],
      ['TEB','TEB'],['TURK EKONOMI BANKASI','TEB'],['ING BANK','ING Bank'],['KUVEYT TURK','Kuveyt Türk'],['TURKIYE FINANS','Türkiye Finans'],
      ['ALBARAKA TURK','Albaraka Türk'],['SEKERBANK','Şekerbank'],['FIBABANKA','Fibabanka'],['ODEA BANK','Odea Bank'],['EMLAK KATILIM','Emlak Katılım'],
      ['VAKIF KATILIM','Vakıf Katılım'],['ZIRAAT KATILIM','Ziraat Katılım']
    ];
    for(var i=0;i<aliases.length;i++) if(n.indexOf(aliases[i][0])!==-1) return aliases[i][1];
    return '';
  }
  function selectBank(name){
    if(!bank||!name) return false;
    for(var i=0;i<bank.options.length;i++) if(bank.options[i].value===name){bank.value=name;bank.dispatchEvent(new Event('change',{bubbles:true}));return true;}
    return false;
  }
  function loadTesseract(){
    if(window.Tesseract) return Promise.resolve(window.Tesseract);
    return new Promise(function(resolve,reject){
      var existing=document.querySelector('script[data-cek-tesseract]');
      if(existing){existing.addEventListener('load',function(){resolve(window.Tesseract);},{once:true});existing.addEventListener('error',function(){reject(new Error('Görüntü okuma kütüphanesi yüklenemedi.'));},{once:true});return;}
      var s=document.createElement('script');
      s.src='https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
      s.async=true;s.dataset.cekTesseract='1';
      s.onload=function(){window.Tesseract?resolve(window.Tesseract):reject(new Error('Görüntü okuma kütüphanesi açılamadı.'));};
      s.onerror=function(){reject(new Error('Görüntü okuma kütüphanesi yüklenemedi.'));};
      document.head.appendChild(s);
    });
  }
  function applyText(raw){
    var text=String(raw||'');var upper=norm(text);var filled=[];
    var n=findAmount(text);if(n&&setField(amount,moneyInput(n)))filled.push('tutar');
    var date=findDate(upper);if(date){if(setField(due,date))filled.push('vade');if(issue&&!issue.value)setField(issue,date);}
    var b=bankAlias(text);if(b&&selectBank(b))filled.push('banka');
    var no=findCheckNo(upper);if(no&&setField(checkNo,no))filled.push('çek no');
    var br=findLabeled(text,['ŞUBE','ŞUBESİ','SUBE','SUBESI'],60);if(br&&setField(branch,br))filled.push('şube');
    var dr=findLabeled(text,['KEŞİDECİ','KESIDECI','HESAP SAHİBİ','HESAP SAHIBI','FİRMA','FIRMA'],100);if(dr&&setField(drawer,dr))filled.push('keşideci');
    return filled;
  }

  front.addEventListener('change',function(){
    var file=front.files&&front.files[0];
    if(!file||busy) return;
    if(!/^image\//i.test(file.type||'')){
      setStatus('PDF yüklendi. Otomatik okuma şu an JPG/PNG fotoğraflarda çalışıyor; PDF belge olarak yine kaydedilebilir.','warn');
      return;
    }
    details.open=true;busy=true;progress.style.width='3%';
    try{preview.src=URL.createObjectURL(file);box.classList.add('has-preview');}catch(e){}
    setStatus('Çek görseli hazırlanıyor…','');
    loadTesseract().then(function(T){
      return T.recognize(file,'tur+eng',{logger:function(m){
        if(m&&m.status==='recognizing text'){
          var pct=Math.max(5,Math.min(98,Math.round((m.progress||0)*100)));
          progress.style.width=pct+'%';setStatus('Çek okunuyor… %'+pct,'');
        }
      }});
    }).then(function(result){
      var text=result&&result.data?result.data.text:'';
      var fields=applyText(text);progress.style.width='100%';
      if(fields.length)setStatus('Otomatik okuma tamamlandı: '+fields.join(', ')+' dolduruldu. Kaydetmeden önce alanları kontrol et. Cari güvenlik nedeniyle otomatik seçilmedi.','ok');
      else setStatus('Görüntü okundu ama güvenilir alan bulunamadı. Fotoğrafı daha düz, net ve yakın çekip tekrar deneyebilirsin.','warn');
    }).catch(function(err){
      progress.style.width='0';setStatus((err&&err.message)||'Çek görseli okunamadı. Alanları elle girebilirsin.','warn');
    }).finally(function(){busy=false;});
  });
})();
