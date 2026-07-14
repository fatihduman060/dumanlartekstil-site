(function(root){
  'use strict';

  var VERSION='3.0.0';
  var BLOCKED_NO=/^(ETTN|UUID|VKN|TCKN|VERGINO|VERGINUMARASI|TICARETSICILNO|TICARETSICILNUMARASI|MERSISNO|IBAN|SIPARISNO|IRSALIYENO)$/;

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
      'Hesaplanan Katma Değer Vergisi'
    ],['Tevkifat']);
    if(explicit!==null) return explicit;
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
    var vat=findVat(lines);
    if(vat===null&&taxRows.length){
      vat=roundMoney(taxRows.reduce(function(total,row){return total+row.vat;},0));
    }
    var taxInclusive=findAmount(lines,['Vergiler Dahil Toplam Tutar','Vergiler Dahil Toplam']);
    var withholding=findAmount(lines,['Toplam Tevkifat','Tevkifat Tutarı','KDV Tevkifatı']);
    var payable=findAmount(lines,['Net Ödenecek Tutar','Ödenecek Tutar','Ödenecek Toplam']);
    var general=findAmount(lines,['Genel Toplam','Fatura Toplamı']);

    var subtotal=taxExclusive;
    if(subtotal===null&&gross!==null) subtotal=roundMoney(gross-(discount||0));
    if(subtotal===null&&taxInclusive!==null&&vat!==null) subtotal=roundMoney(taxInclusive-vat);
    if(subtotal===null&&payable!==null&&vat!==null&&withholding===null) subtotal=roundMoney(payable-vat);

    var total=payable!==null?payable:(taxInclusive!==null?taxInclusive:general);
    if(total===null&&subtotal!==null&&vat!==null){
      total=roundMoney(subtotal+vat-(withholding||0));
      addIssue(warnings,'Genel toplam belge etiketinden değil, bulunan tutarlardan hesaplandı.');
    }

    if(discount!==null&&discount>0) addIssue(warnings,'İskontolu fatura: matrah iskonto sonrası hesaplandı.');
    if(withholding!==null&&withholding>0) addIssue(warnings,'Tevkifatlı fatura: belgedeki ödenecek tutar korundu.');
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
    parseMoney:parseMoney,
    isValidInvoiceNo:isValidInvoiceNo,
    invoiceNoFromFileName:invoiceNoFromFileName,
    parseDateToken:parseDateToken
  };
})(typeof window!=='undefined'?window:globalThis);
