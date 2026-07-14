(function(root){
  'use strict';

  var VERSION='3.3.0';
  var BLOCKED_NO=/^(ETTN|UUID|VKN|TCKN|VERGINO|VERGINUMARASI|TICARETSICILNO|TICARETSICILNUMARASI|MERSISNO|IBAN|SIPARISNO|IRSALIYENO)$/;
  var SELLER_ROLES=[
    'SATICI','SATICI FIRMA','SATICI FIRMA UNVANI','SATICI UNVANI','SATICI BILGILERI',
    'SELLER','SELLER INFORMATION','SUPPLIER','SUPPLIER INFORMATION',
    'TEDARIKCI','TEDARIKCI FIRMA','TEDARIKCI UNVANI','TEDARIKCI BILGILERI'
  ];
  var BUYER_ROLES=[
    'ALICI','ALICI FIRMA','ALICI FIRMA UNVANI','ALICI UNVANI','ALICI BILGILERI',
    'BUYER','BUYER INFORMATION','CUSTOMER','CUSTOMER INFORMATION','MUSTERI','SAYIN'
  ];
  var ISSUER_ALIASES=[
    {id:'turkcell-superonline',name:'Turkcell Superonline',pattern:/(^| )TURKCELL SUPERONLINE( |$)|(^| )SUPERONLINE( |$).*(^| )ILETISIM( |$)/},
    {id:'turkcell',name:'Turkcell İletişim Hizmetleri A.Ş.',pattern:/(^| )TURKCELL( |$)/},
    {id:'turk-telekom',name:'Türk Telekom',pattern:/(^| )TURK TELEKOM( |$)/},
    {id:'ttnet',name:'TTNET',pattern:/(^| )TTNET( |$)/},
    {id:'vodafone',name:'Vodafone Telekomünikasyon A.Ş.',pattern:/(^| )VODAFONE( |$)/},
    {id:'turknet',name:'TurkNet İletişim Hizmetleri A.Ş.',pattern:/(^| )TURKNET( |$)/},
    {id:'turksat',name:'Türksat / KabloNet',pattern:/(^| )(TURKSAT|KABLONET)( |$)/},
    {id:'guzel-hosting',name:'Güzel Hosting',pattern:/(^| )GUZEL HOSTING( |$)/},
    {id:'aksa-dogalgaz',name:'Aksa Doğalgaz',pattern:/(^| )AKSAGAZ( |$)|(^| )AKSA( |$).*(^| )(DOGALGAZ|GAZ)( |$)/},
    {id:'enerya',name:'Enerya Enerji',pattern:/(^| )ENERYA( |$)/},
    {id:'igdas',name:'İGDAŞ',pattern:/(^| )IGDAS( |$)/},
    {id:'gazdas',name:'GAZDAŞ',pattern:/(^| )GAZDAS( |$)/},
    {id:'yesilirmak-elektrik',name:'Yeşilırmak Elektrik Perakende Satış A.Ş.',pattern:/(^| )YESILIRMAK( |$).*(^| )ELEKTRIK( |$)/},
    {id:'yedas',name:'YEDAŞ',pattern:/(^| )YEDAS( |$)/},
    {id:'cedas',name:'ÇEDAŞ',pattern:/(^| )CEDAS( |$)/},
    {id:'uedas',name:'UEDAŞ',pattern:/(^| )UEDAS( |$)/}
  ];

  function fold(value){
    return String(value||'')
      .toLocaleUpperCase('tr-TR')
      .replace(/İ/g,'I').replace(/Ş/g,'S').replace(/Ğ/g,'G')
      .replace(/Ü/g,'U').replace(/Ö/g,'O').replace(/Ç/g,'C');
  }

  function norm(value){
    return fold(value)
      .replace(/[^A-Z0-9]+/g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function escapeRegex(value){
    return String(value||'').replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
  }

  function roundMoney(value){
    return Math.round((Number(value)||0)*100)/100;
  }

  function parseMoney(raw){
    var value=String(raw==null?'':raw).trim().replace(/\s/g,'');
    if(!value) return null;
    var comma=value.lastIndexOf(',');
    var dot=value.lastIndexOf('.');
    if(comma>-1&&dot>-1){
      if(comma>dot) value=value.replace(/\./g,'').replace(',','.');
      else value=value.replace(/,/g,'');
    }else if(comma>-1){
      value=value.replace(/\./g,'').replace(',','.');
    }else if(dot>-1){
      var decimals=value.length-dot-1;
      if(decimals!==2) value=value.replace(/\./g,'');
    }
    var number=parseFloat(value);
    return Number.isFinite(number)?roundMoney(number):null;
  }

  function moneyMatches(text){
    var source=String(text||'');
    var regex=/-?(?:(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}|(?:\d{1,3}(?:,\d{3})+|\d+)\.\d{2})/g;
    var rows=[];
    var match;
    while((match=regex.exec(source))!==null){
      var prefix=source.slice(Math.max(0,match.index-4),match.index);
      if(/%\s*$/.test(prefix)) continue;
      var parsed=parseMoney(match[0]);
      if(parsed!==null) rows.push({value:parsed,index:match.index,raw:match[0]});
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return rows;
  }

  function labelRegex(label){
    var words=norm(label).split(' ').filter(Boolean);
    return new RegExp(words.map(escapeRegex).join('[\\s\\W_]+'),'i');
  }

  function amountForLabel(lines,label,excludeWords){
    var pattern=labelRegex(label);
    var excludes=(excludeWords||[]).map(norm);
    for(var i=0;i<lines.length;i++){
      var raw=String(lines[i]||'');
      var lineNorm=norm(raw);
      if(excludes.some(function(word){return lineNorm.indexOf(word)!==-1;})) continue;
      var found=pattern.exec(fold(raw));
      if(!found) continue;
      var amounts=moneyMatches(raw);
      var after=amounts.filter(function(item){return item.index>=found.index+found[0].length-2;});
      if(after.length) return {value:after[0].value,index:i,label:label};
      if(amounts.length===1) return {value:amounts[0].value,index:i,label:label};
      var next=String(lines[i+1]||'');
      var nextAmounts=moneyMatches(next);
      if(nextAmounts.length===1&&nextAmounts[0].index<=4&&norm(next).split(' ').length<=4){
        return {value:nextAmounts[0].value,index:i+1,label:label};
      }
    }
    return null;
  }

  function findAmount(lines,labels,excludeWords){
    for(var i=0;i<labels.length;i++){
      var match=amountForLabel(lines,labels[i],excludeWords);
      if(match) return match.value;
    }
    return null;
  }

  function findAllAmounts(lines,label,excludeWords){
    var pattern=labelRegex(label);
    var excludes=(excludeWords||[]).map(norm);
    var rows=[];
    for(var i=0;i<lines.length;i++){
      var raw=String(lines[i]||'');
      var lineNorm=norm(raw);
      if(excludes.some(function(word){return lineNorm.indexOf(word)!==-1;})) continue;
      var found=pattern.exec(fold(raw));
      if(!found) continue;
      var amounts=moneyMatches(raw).filter(function(item){return item.index>=found.index+found[0].length-2;});
      if(amounts.length) rows.push(amounts[0].value);
    }
    return rows;
  }

  function findVat(lines){
    var explicit=findAmount(lines,[
      'Toplam KDV',
      'KDV Toplamı',
      'Hesaplanan KDV Tutarı',
      'Toplam Katma Değer Vergisi',
      'Hesaplanan Katma Değer Vergisi'
    ],['Tevkifat','KDV Matrahı','KDV Oranı','KDV Dahil','KDV Hariç']);
    if(explicit!==null) return explicit;

    // Telefon operatörü faturalarında KDV çoğu zaman klasik e-Fatura
    // etiketleri yerine "KDV(%20)" gibi bir tablo sütununda gösteriliyor.
    // Yüzde değerini para sanmadan, yalnız etiketin sağındaki/altındaki
    // gerçek parasal tutarı al.
    for(var i=0;i<lines.length;i++){
      var raw=String(lines[i]||'');
      var lineNorm=norm(raw);
      if(/KDV MATRAHI|KDV ORANI|KDV DAHIL|KDV HARIC|TEVKIFAT/.test(lineNorm)) continue;
      var rateLabel=/\bKDV\s*(?:\(\s*%\s*\d{1,2}\s*\)|%\s*\d{1,2})/i.exec(fold(raw));
      if(!rateLabel) continue;
      var inline=moneyMatches(raw).filter(function(item){
        return item.index>=rateLabel.index+rateLabel[0].length-2;
      });
      if(inline.length) return inline[0].value;
      var nextAmounts=moneyMatches(lines[i+1]||'');
      if(nextAmounts.length===1) return nextAmounts[0].value;
    }

    var parts=findAllAmounts(lines,'Hesaplanan KDV',['Tevkifat']);
    if(!parts.length) return null;
    return roundMoney(parts.reduce(function(total,value){return total+value;},0));
  }

  function findTaxBreakdown(lines){
    var pattern=labelRegex('KDV Matrahı');
    var rows=[];
    lines.forEach(function(line){
      var raw=String(line||'');
      var found=pattern.exec(fold(raw));
      if(!found) return;
      var amounts=moneyMatches(raw).filter(function(item){return item.index>=found.index+found[0].length-2;});
      if(amounts.length<2) return;
      rows.push({base:amounts[0].value,vat:amounts[amounts.length-1].value});
    });
    return rows;
  }

  function cleanInvoiceNo(value){
    return fold(value).replace(/[^A-Z0-9]/g,'');
  }

  function isValidInvoiceNo(value){
    var compact=cleanInvoiceNo(value);
    if(!compact||compact.length<11||compact.length>32||BLOCKED_NO.test(compact)) return false;
    if(/^[A-Z]+$/.test(compact)||/^\d{10,11}$/.test(compact)) return false;
    return /^[A-Z0-9]{2,8}20\d{2}\d{5,20}$/.test(compact)&&/[A-Z]/.test(compact.slice(0,8));
  }

  function invoiceNoFromFileName(fileName){
    var name=fold(String(fileName||'').replace(/\.(PDF|XML|XSLT?)$/i,''));
    var matches=name.match(/[A-Z0-9]{2,8}20\d{2}\d{5,20}/g)||[];
    for(var i=0;i<matches.length;i++){
      if(isValidInvoiceNo(matches[i])) return cleanInvoiceNo(matches[i]);
    }
    return '';
  }

  function invoiceNoCandidates(text){
    var matches=fold(text).match(/[A-Z0-9]{8,40}/g)||[];
    var values=[];
    matches.forEach(function(match){
      var value=cleanInvoiceNo(match);
      if(isValidInvoiceNo(value)&&values.indexOf(value)===-1) values.push(value);
    });
    return values;
  }

  function findInvoiceNo(lines,fileName){
    var fromFile=invoiceNoFromFileName(fileName);
    if(fromFile) return {value:fromFile,source:'file'};
    var labels=['E-Arşiv Fatura No','E-Fatura No','Fatura Numarası','Fatura No','Belge No'];
    for(var l=0;l<labels.length;l++){
      var pattern=labelRegex(labels[l]);
      for(var i=0;i<lines.length;i++){
        var raw=String(lines[i]||'');
        var found=pattern.exec(fold(raw));
        if(!found) continue;
        var suffix=raw.slice(found.index+found[0].length);
        var candidates=invoiceNoCandidates(suffix);
        if(candidates.length) return {value:candidates[0],source:'label'};
        var nextCandidates=invoiceNoCandidates(lines[i+1]||'');
        if(nextCandidates.length) return {value:nextCandidates[0],source:'next-line'};
      }
    }
    var all=[];
    lines.forEach(function(line){
      invoiceNoCandidates(line).forEach(function(value){if(all.indexOf(value)===-1) all.push(value);});
    });
    return all.length===1?{value:all[0],source:'unique'}:{value:'',source:''};
  }

  function parseDateToken(value){
    var text=String(value||'');
    var iso=text.match(/\b(20\d{2})-(\d{1,2})-(\d{1,2})\b/);
    var y,month,day;
    if(iso){
      y=Number(iso[1]);month=Number(iso[2]);day=Number(iso[3]);
    }else{
      var tr=text.match(/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](20\d{2})\b/);
      if(!tr) return '';
      day=Number(tr[1]);month=Number(tr[2]);y=Number(tr[3]);
    }
    var date=new Date(Date.UTC(y,month-1,day));
    if(date.getUTCFullYear()!==y||date.getUTCMonth()!==month-1||date.getUTCDate()!==day) return '';
    return String(y)+'-'+String(month).padStart(2,'0')+'-'+String(day).padStart(2,'0');
  }

  function findInvoiceDate(lines){
    var labels=['Fatura Düzenleme Tarihi','Düzenleme Tarihi','Fatura Tarihi','Belge Tarihi'];
    for(var l=0;l<labels.length;l++){
      var pattern=labelRegex(labels[l]);
      for(var i=0;i<lines.length;i++){
        var raw=String(lines[i]||'');
        var found=pattern.exec(fold(raw));
        if(!found) continue;
        var date=parseDateToken(raw.slice(found.index+found[0].length));
        if(date) return {value:date,source:'label'};
        date=parseDateToken(lines[i+1]||'');
        if(date) return {value:date,source:'next-line'};
      }
    }
    var unique=[];
    lines.forEach(function(line){
      if(/VADE|SEVK|IRSALIYE|SIPARIS|SON ODEME/.test(norm(line))) return;
      var date=parseDateToken(line);
      if(date&&unique.indexOf(date)===-1) unique.push(date);
    });
    return unique.length===1?{value:unique[0],source:'unique'}:{value:'',source:''};
  }

  function boundedTokenIndexes(text,token){
    var rows=[];
    var regex=new RegExp('(^|[^A-Z0-9])'+escapeRegex(token)+'(?=$|[^A-Z0-9])','g');
    var match;
    while((match=regex.exec(text))!==null){
      rows.push(match.index+(match[1]?match[1].length:0));
      if(match.index===regex.lastIndex) regex.lastIndex++;
    }
    return rows;
  }

  function nearestMarker(text,index,markers,radius){
    var best=Infinity;
    markers.forEach(function(marker){
      var offset=text.indexOf(marker,Math.max(0,index-radius));
      while(offset!==-1&&offset<=index+radius){
        best=Math.min(best,Math.abs(offset-index));
        offset=text.indexOf(marker,offset+1);
      }
    });
    return best;
  }

  function detectDirection(fullText,companyTaxNo){
    var text=norm(fullText);
    var seller=['SATICI','SELLER','TEDARIKCI'];
    var buyer=['ALICI','SAYIN','MUSTERI','BUYER'];
    var companyTax=String(companyTaxNo||'').replace(/\D/g,'');
    var positions=companyTax?boundedTokenIndexes(text,companyTax):[];
    var markers=['DUMANLAR','BITKE','MOFIY','BAFIY'];
    if(!positions.length){
      markers.some(function(marker){
        var index=text.indexOf(marker);
        if(index<0) return false;
        positions=[index];
        return true;
      });
    }
    for(var i=0;i<positions.length;i++){
      var sellerDistance=nearestMarker(text,positions[i],seller,320);
      var buyerDistance=nearestMarker(text,positions[i],buyer,320);
      if(sellerDistance<buyerDistance) return 'giden';
      if(buyerDistance<sellerDistance) return 'gelen';
    }
    return '';
  }

  function issuerResult(name,confidence,source,warnings){
    return {
      name:String(name||''),
      confidence:Math.max(0,Math.min(100,Math.round(Number(confidence)||0))),
      source:String(source||''),
      warnings:Array.isArray(warnings)?warnings:[]
    };
  }

  function normalizedRole(value,roles){
    var valueNorm=norm(value);
    for(var i=0;i<roles.length;i++){
      if(valueNorm===roles[i]) return roles[i];
    }
    return '';
  }

  function rolePrefix(value,roles){
    var valueNorm=norm(value);
    var ordered=roles.slice().sort(function(a,b){return b.length-a.length;});
    for(var i=0;i<ordered.length;i++){
      if(valueNorm===ordered[i]||valueNorm.indexOf(ordered[i]+' ')===0) return ordered[i];
    }
    return '';
  }

  function roleInlineValue(value,roles){
    var raw=String(value||'').replace(/\s+/g,' ').trim();
    if(!raw) return {matched:false,value:''};
    var separator=raw.search(/[:|;]|\s[-–—]\s/);
    if(separator>-1&&separator<48){
      var prefix=raw.slice(0,separator);
      if(normalizedRole(prefix,roles)){
        return {matched:true,value:raw.slice(separator+1).replace(/^\s*[-–—]?\s*/,'')};
      }
    }
    var prefixRole=rolePrefix(raw,roles);
    if(!prefixRole) return {matched:false,value:''};
    if(norm(raw)===prefixRole) return {matched:true,value:''};
    var wordCount=prefixRole.split(' ').length;
    return {matched:true,value:raw.split(/\s+/).slice(wordCount).join(' ')};
  }

  function findIssuerAlias(value){
    var valueNorm=norm(value);
    for(var i=0;i<ISSUER_ALIASES.length;i++){
      if(ISSUER_ALIASES[i].pattern.test(valueNorm)) return ISSUER_ALIASES[i];
    }
    return null;
  }

  function startsWithIssuerMetadata(value){
    var valueNorm=norm(value);
    return /^(UNVANI|FIRMA UNVANI|FIRMA ADI|VKN|TCKN|VERGI|VERGI DAIRESI|VERGI KIMLIK|MERSIS|TICARET SICIL|IBAN|HESAP NO|ADRES|TEL|TELEFON|FAKS|E POSTA|EPOSTA|WEB|WWW|FATURA|E FATURA|E ARSIV|ETTN|UUID|TARIH|DUZENLEME TARIHI|ODEME|BANKA|SUBE)( |$)/.test(valueNorm);
  }

  function trailingMetadataIndex(tokens){
    var labels=[
      'VKN','TCKN','VERGI','VERGI DAIRESI','VERGI KIMLIK','MERSIS','TICARET SICIL',
      'IBAN','HESAP NO','ADRES','TEL','TELEFON','FAKS','E POSTA','EPOSTA','WEB','WWW',
      'FATURA','E FATURA','E ARSIV','ETTN','UUID','ALICI','BUYER','CUSTOMER','MUSTERI','SAYIN'
    ];
    for(var i=1;i<tokens.length;i++){
      for(var size=3;size>=1;size--){
        var label=norm(tokens.slice(i,i+size).join(' '));
        if(labels.indexOf(label)!==-1) return i;
      }
    }
    return -1;
  }

  function cleanIssuerCandidate(value){
    var raw=String(value||'').replace(/[\u0000-\u001f]+/g,' ').replace(/\s+/g,' ').trim();
    if(!raw) return '';

    var sellerInline=roleInlineValue(raw,SELLER_ROLES);
    if(sellerInline.matched) raw=sellerInline.value;
    var buyerInline=roleInlineValue(raw,BUYER_ROLES);
    if(buyerInline.matched) return '';

    raw=raw.replace(/^[\s:;|,\-–—]+|[\s:;|,\-–—]+$/g,'').trim();
    if(!raw) return '';

    var legalEnd=raw.match(/^(.*?(?:A\.?\s*Ş\.?|LTD\.?\s*ŞTİ\.?|LİMİTED\s+ŞİRKETİ|ANONİM\s+ŞİRKETİ))/i);
    if(legalEnd&&legalEnd[1]) raw=legalEnd[1].trim();

    var pieces=raw.split(/\|+|\t+/).map(function(piece){return piece.trim();}).filter(Boolean);
    if(pieces.length>1){
      var bestPiece='';
      for(var p=0;p<pieces.length;p++){
        if(findIssuerAlias(pieces[p])){bestPiece=pieces[p];break;}
      }
      raw=bestPiece||pieces[0];
    }

    var tokens=raw.split(/\s+/);
    var metadataAt=trailingMetadataIndex(tokens);
    if(metadataAt>-1) tokens=tokens.slice(0,metadataAt);
    raw=tokens.join(' ').replace(/^[\s:;|,\-–—]+|[\s:;|,\-–—]+$/g,'').trim();
    if(raw.length>180) raw=raw.slice(0,180).replace(/\s+\S*$/,'').trim();
    return raw;
  }

  function isOwnOrGenericIssuer(value,options){
    var valueNorm=norm(value);
    if(/(^| )(DUMANLAR|BITKE|MOFIY|BAFIY)( |$)/.test(valueNorm)) return true;
    if(/(^| )MUHTELIF( FATURA)?( GIRISI)?( |$)/.test(valueNorm)) return true;
    var ownNames=(options&&Array.isArray(options.ownNames))?options.ownNames:[];
    for(var i=0;i<ownNames.length;i++){
      var ownNorm=norm(ownNames[i]);
      if(ownNorm&&(' '+valueNorm+' ').indexOf(' '+ownNorm+' ')!==-1) return true;
    }
    return false;
  }

  function isRejectedIssuer(value,options){
    var valueNorm=norm(value);
    if(!valueNorm||valueNorm.length<3||isOwnOrGenericIssuer(value,options)) return true;
    if(normalizedRole(valueNorm,SELLER_ROLES)||normalizedRole(valueNorm,BUYER_ROLES)) return true;
    if(startsWithIssuerMetadata(valueNorm)) return true;
    if(/(^| )(BANKA|BANKASI|BANK|KREDI KARTI|POS|TAHSILAT|ODEME KURULUSU)( |$)/.test(valueNorm)) return true;
    if(/(^| )(MAHALLESI|MAHALLE|MAH|CADDESI|CADDE|CAD|SOKAGI|SOKAK|SOK|BULVARI|BULVAR|APT|APARTMAN|POSTA KODU)( |$)/.test(valueNorm)) return true;
    if(/^(FATURA|E FATURA|E ARSIV|ETTN|UUID|VKN|TCKN|MERSIS|IBAN|TOPLAM|GENEL TOPLAM|ODENECEK|VERGI|KDV|TARIH|ACIKLAMA)( |$)/.test(valueNorm)) return true;
    if(/^\d+$/.test(valueNorm.replace(/ /g,''))) return true;
    var letters=(valueNorm.match(/[A-Z]/g)||[]).length;
    var digits=(valueNorm.match(/[0-9]/g)||[]).length;
    if(letters<3||digits>letters) return true;
    return false;
  }

  function issuerNameFeatures(value){
    var valueNorm=norm(value);
    var alias=findIssuerAlias(value);
    var legal=/(^| )(A S|AS|LTD|LIMITED|STI|SIRKETI|HOLDING|KOLLEKTIF|KOOPERATIF)( |$)/.test(valueNorm);
    var industry=/(^| )(ILETISIM|TELEKOM|TELEKOMUNIKASYON|INTERNET|DOGALGAZ|GAZ|ENERJI|ELEKTRIK|DAGITIM|PERAKENDE|SANAYI|TICARET|HIZMETLERI|UYDU|KABLO|TEKNOLOJI)( |$)/.test(valueNorm);
    var words=valueNorm.split(' ').filter(Boolean).length;
    return {alias:alias,legal:legal,industry:industry,words:words};
  }

  function isJoinableIssuerFragment(value,options){
    var clean=cleanIssuerCandidate(value);
    if(!clean||clean.length>100||isRejectedIssuer(clean,options)) return false;
    var words=norm(clean).split(' ').filter(Boolean);
    if(words.length>10) return false;
    return true;
  }

  function addIssuerCandidate(candidates,value,context,options){
    var clean=cleanIssuerCandidate(value);
    if(!clean||isRejectedIssuer(clean,options)) return;
    var features=issuerNameFeatures(clean);
    var roleScore=context&&context.roleScore?context.roleScore:0;
    var score=roleScore;
    if(features.alias) score+=62;
    if(features.legal) score+=18;
    if(features.industry) score+=15;
    if(features.words>=2&&features.words<=18) score+=6;
    if(features.alias&&features.words===1) score+=8;
    if(context&&context.joined) score+=4;
    if(!roleScore&&!features.alias) return;

    var roleBased=roleScore>0;
    var source=roleBased?'seller-label':(features.alias?'known-alias':'company-line');
    var display=clean;
    if(features.alias&&(features.words===1||display.length<4)) display=features.alias.name;
    var key=features.alias?features.alias.id:norm(display);
    var candidate={
      name:display,
      score:Math.min(100,score),
      source:source,
      roleBased:roleBased,
      key:key
    };
    for(var i=0;i<candidates.length;i++){
      if(candidates[i].key!==key) continue;
      if(candidate.score>candidates[i].score||
        (candidate.score===candidates[i].score&&candidate.name.length>candidates[i].name.length)){
        candidates[i]=candidate;
      }
      return;
    }
    candidates.push(candidate);
  }

  function extractIssuer(rawLines,options){
    var opts=options||{};
    var direction=norm(opts.direction||'');
    if(direction==='GIDEN') return issuerResult('',0,'',[]);

    var sourceLines=Array.isArray(rawLines)?rawLines:String(rawLines||'').split(/\r?\n/);
    var lines=sourceLines.map(function(line){
      return String(line||'').replace(/[\u0000-\u001f]+/g,' ').replace(/\s+/g,' ').trim();
    }).filter(Boolean);
    if(!lines.length) return issuerResult('',0,'',[]);

    var candidates=[];
    for(var i=0;i<lines.length;i++){
      var inline=roleInlineValue(lines[i],SELLER_ROLES);
      if(!inline.matched) continue;
      if(inline.value){
        addIssuerCandidate(candidates,inline.value,{roleScore:62},opts);
        var inlineFeatures=issuerNameFeatures(cleanIssuerCandidate(inline.value));
        if(inlineFeatures.alias||inlineFeatures.legal||inlineFeatures.industry) continue;
      }

      var parts=[];
      for(var j=i+1;j<lines.length&&j<=i+5&&parts.length<3;j++){
        if(roleInlineValue(lines[j],BUYER_ROLES).matched||roleInlineValue(lines[j],SELLER_ROLES).matched) break;
        if(startsWithIssuerMetadata(lines[j])||isRejectedIssuer(lines[j],opts)){
          if(parts.length) break;
          continue;
        }
        if(!isJoinableIssuerFragment(lines[j],opts)){
          if(parts.length) break;
          continue;
        }
        parts.push(lines[j]);
        addIssuerCandidate(candidates,lines[j],{roleScore:Math.max(36,60-(parts.length-1)*10)},opts);
        addIssuerCandidate(candidates,parts.join(' '),{roleScore:60,joined:parts.length>1},opts);
      }
    }

    for(var lineIndex=0;lineIndex<lines.length;lineIndex++){
      if(roleInlineValue(lines[lineIndex],SELLER_ROLES).matched||roleInlineValue(lines[lineIndex],BUYER_ROLES).matched) continue;
      addIssuerCandidate(candidates,lines[lineIndex],{roleScore:0},opts);
      if(!isJoinableIssuerFragment(lines[lineIndex],opts)) continue;
      var joined=[lines[lineIndex]];
      for(var offset=1;offset<=2&&lineIndex+offset<lines.length;offset++){
        var next=lines[lineIndex+offset];
        if(roleInlineValue(next,SELLER_ROLES).matched||roleInlineValue(next,BUYER_ROLES).matched||!isJoinableIssuerFragment(next,opts)) break;
        joined.push(next);
        addIssuerCandidate(candidates,joined.join(' '),{roleScore:0,joined:true},opts);
      }
    }

    candidates.sort(function(a,b){
      if(a.roleBased!==b.roleBased) return a.roleBased?-1:1;
      if(a.score!==b.score) return b.score-a.score;
      return b.name.length-a.name.length;
    });
    if(!candidates.length||candidates[0].score<70) return issuerResult('',0,'',[]);

    var best=candidates[0];
    var second=null;
    for(var c=1;c<candidates.length;c++){
      if(candidates[c].key!==best.key){second=candidates[c];break;}
    }
    if(second&&second.roleBased===best.roleBased&&Math.abs(best.score-second.score)<=5){
      return issuerResult('',0,'',['Birden fazla olası gönderen firma bulundu.']);
    }
    return issuerResult(best.name,best.score,best.source,[]);
  }

  function detectCurrency(fullText){
    var text=' '+norm(fullText)+' ';
    if(/\b(EUR|EURO)\b/.test(text)) return 'EUR';
    if(/\b(USD|DOLAR)\b/.test(text)) return 'USD';
    return 'TL';
  }

  function addIssue(list,message){
    if(list.indexOf(message)===-1) list.push(message);
  }

  function extractInvoice(rawLines,options){
    var opts=options||{};
    var lines=(rawLines||[]).map(function(line){return String(line||'').trim();}).filter(Boolean);
    var fullText=lines.join('\n');
    var chars=norm(fullText).replace(/\s/g,'').length;
    var needsOcr=lines.length<5||chars<80;
    var detectedDirection=detectDirection(fullText,opts.companyTaxNo||'');
    var issuer=extractIssuer(lines,{
      direction:opts.direction||detectedDirection,
      companyTaxNo:opts.companyTaxNo||'',
      ownNames:opts.ownNames||[]
    });
    var critical=[];
    var warnings=[];

    var noResult=findInvoiceNo(lines,opts.fileName||'');
    var dateResult=findInvoiceDate(lines);
    var gross=findAmount(lines,['Mal Hizmet Toplam Tutarı','Mal/Hizmet Toplam Tutarı','Brüt Toplam']);
    var discount=findAmount(lines,['Toplam İskonto','İskonto Toplamı','İskonto Tutarı','Toplam İndirim','İndirim Toplamı']);
    var taxRows=findTaxBreakdown(lines);
    var taxExclusive=taxRows.length>1
      ?roundMoney(taxRows.reduce(function(total,row){return total+row.base;},0))
      :findAmount(lines,['Vergiler Hariç Toplam Tutar','Toplam Matrah','KDV Matrahı']);
    var vat=taxRows.length
      ?roundMoney(taxRows.reduce(function(total,row){return total+row.vat;},0))
      :findVat(lines);
    var taxInclusive=findAmount(lines,['Vergiler Dahil Toplam Tutar','Vergiler Dahil Toplam']);
    var withholding=findAmount(lines,['Toplam Tevkifat','Tevkifat Tutarı','KDV Tevkifatı']);
    var payable=findAmount(lines,['Net Ödenecek Tutar','Ödenecek Tutar','Ödenecek Toplam']);
    var general=findAmount(lines,['Genel Toplam','Fatura Toplamı']);

    var subtotal=taxExclusive;
    var subtotalFromPayable=false;
    if(subtotal===null&&gross!==null) subtotal=roundMoney(gross-(discount||0));
    if(subtotal===null&&taxInclusive!==null&&vat!==null) subtotal=roundMoney(taxInclusive-vat);
    if(subtotal===null&&payable!==null&&vat!==null&&withholding===null){
      subtotal=roundMoney(payable-vat);
      subtotalFromPayable=true;
    }

    var total=payable!==null?payable:(taxInclusive!==null?taxInclusive:general);
    if(total===null&&subtotal!==null&&vat!==null){
      total=roundMoney(subtotal+vat-(withholding||0));
      addIssue(warnings,'Genel toplam belge etiketinden değil, bulunan tutarlardan hesaplandı.');
    }

    if(discount!==null&&discount>0) addIssue(warnings,'İskontolu fatura: matrah iskonto sonrası hesaplandı.');
    if(withholding!==null&&withholding>0) addIssue(warnings,'Tevkifatlı fatura: belgedeki ödenecek tutar korundu.');
    if(subtotalFromPayable) addIssue(warnings,'Matrah, ödenecek tutardan KDV çıkarılarak hesaplandı; diğer vergi ve ücretleri kontrol et.');
    if(taxRows.length>1) addIssue(warnings,'Birden fazla KDV satırı birleştirildi; tutarları kontrol et.');
    if(!detectedDirection&&!needsOcr) addIssue(warnings,'Fatura yönü otomatik belirlenemedi.');

    if(needsOcr) addIssue(critical,'PDF metin katmanı bulunamadı; OCR veya manuel giriş gerekli.');
    if(!noResult.value) addIssue(critical,'Fatura numarası güvenilir biçimde bulunamadı.');
    if(!dateResult.value) addIssue(critical,'Fatura tarihi güvenilir biçimde bulunamadı.');
    if(subtotal===null) addIssue(critical,'Matrah bulunamadı.');
    if(vat===null) addIssue(critical,'KDV tutarı bulunamadı.');
    if(total===null) addIssue(critical,'Genel toplam bulunamadı.');

    var tolerance=0.05;
    if(taxInclusive!==null&&subtotal!==null&&vat!==null&&Math.abs(taxInclusive-(subtotal+vat))>tolerance){
      addIssue(warnings,'Matrah + KDV, vergiler dahil toplamla uyuşmuyor.');
    }
    if(payable!==null&&taxInclusive!==null&&withholding!==null&&Math.abs(payable-(taxInclusive-withholding))>tolerance){
      addIssue(warnings,'Ödenecek tutar ile tevkifat özeti uyuşmuyor.');
    }
    if(gross!==null&&discount!==null&&subtotal!==null&&Math.abs(subtotal-(gross-discount))>tolerance){
      addIssue(warnings,'İskonto sonrası matrah doğrulanamadı.');
    }
    if(payable!==null&&subtotal!==null&&vat!==null&&withholding===null&&taxInclusive===null&&Math.abs(payable-(subtotal+vat))>tolerance){
      addIssue(warnings,'Ödenecek tutar, matrah + KDV ile uyuşmuyor.');
    }

    return {
      version:VERSION,
      invoiceNo:noResult.value,
      invoiceNoSource:noResult.source,
      invoiceDate:dateResult.value,
      invoiceDateSource:dateResult.source,
      subtotal:subtotal,
      vat:vat,
      total:total,
      currency:detectCurrency(fullText),
      direction:detectedDirection,
      issuerName:issuer.name,
      issuerConfidence:issuer.confidence,
      issuerSource:issuer.source,
      issuerWarnings:issuer.warnings,
      textLength:chars,
      lineCount:lines.length,
      needsOcr:needsOcr,
      criticalIssues:critical,
      warnings:warnings,
      canAutoSave:critical.length===0&&warnings.length===0,
      meta:{
        gross:gross,
        discount:discount,
        taxExclusive:taxExclusive,
        taxInclusive:taxInclusive,
        withholding:withholding,
        payable:payable,
        general:general
      }
    };
  }

  root.FaturaOkumaCore={
    version:VERSION,
    extractInvoice:extractInvoice,
    extractIssuer:extractIssuer,
    parseMoney:parseMoney,
    isValidInvoiceNo:isValidInvoiceNo,
    invoiceNoFromFileName:invoiceNoFromFileName,
    parseDateToken:parseDateToken
  };
})(typeof window!=='undefined'?window:globalThis);
