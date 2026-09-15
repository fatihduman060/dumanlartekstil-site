(function(root){
  'use strict';
  function fold(s){return String(s||'').toLocaleUpperCase('tr-TR').replace(/[İŞĞÜÖÇ]/g,function(c){return {'İ':'I','Ş':'S','Ğ':'G','Ü':'U','Ö':'O','Ç':'C'}[c];});}
  function number(s,integer){
    s=String(s||'').trim();
    if(!/^\d+(?:[.,]\d+)*$/.test(s)) return null;
    if(integer){if(!/^\d+$/.test(s)&&!/^\d{1,3}(?:\.\d{3})+$/.test(s))return null;s=s.replace(/\./g,'');}
    else if(s.includes(',')){if(!/^\d+(?:,\d{1,2})$/.test(s)&&!/^\d{1,3}(?:\.\d{3})+,\d{1,2}$/.test(s))return null;s=s.replace(/\./g,'').replace(',','.');}
    else if(s.includes('.')){if(/^\d{1,3}(?:\.\d{3})+$/.test(s))s=s.replace(/\./g,'');else if(!/^\d+\.\d{1,2}$/.test(s))return null;}
    var n=Number(s);return Number.isFinite(n)&&n<=10000000?n:null;
  }
  function parse(data,shift){
    var lines=data.lines||[], rows={}, dates=new Set(), warnings=[], seen={}, active=null, reversed=false;
    var hasSections=lines.some(function(l){return /GUNDUZ|GECE/.test(fold(l.text));});
    lines.forEach(function(line){
      var text=fold(line.text), confidence=Number(line.confidence);
      var heading=text.match(/GUNDUZ|GECE/g);
      if(heading){active=heading.length===1?(heading[0]==='GECE'?'gece':'gunduz'):'ambiguous';}
      if(hasSections&&active!==null&&active!==shift)return;
      var matches=text.match(/\b(?:\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{4})\b/g)||[];
      matches.forEach(function(raw){var p=raw.split(/[-/.]/).map(Number);if(p[0]<100)p=[p[2],p[1],p[0]];var d=new Date(Date.UTC(p[0],p[1]-1,p[2]));if(confidence>=75&&d.getUTCFullYear()===p[0]&&d.getUTCMonth()===p[1]-1&&d.getUTCDate()===p[2])dates.add(p[0]+'-'+String(p[1]).padStart(2,'0')+'-'+String(p[2]).padStart(2,'0'));});
      if(hasSections&&active!==shift)return;
      if(text.includes('DEFOLU')&&text.includes('URETIM'))reversed=text.indexOf('DEFOLU')<text.indexOf('URETIM');
      var m=text.replace(/[|]/g,' ').trim().match(/^([A-E])\s*[:.)-]?\s+(.+)$/);
      if(!m)return;
      var group=m[1];seen[group]=(seen[group]||0)+1;
      var cells=m[2].trim().split(/\s+/);
      var lowWord=(line.words||[]).some(function(w){return /[0-9A-E]/.test(fold(w.text))&&Number(w.confidence)<70;});
      if(cells.length!==2||!(confidence>=75)||lowWord||reversed){warnings.push(group+' satırı eksik veya şüpheli; mevcut değerler korundu.');return;}
      var dz=number(cells[0],false),def=number(cells[1],true);
      if(dz===null||def===null){warnings.push(group+' satırının sayıları belirsiz; mevcut değerler korundu.');return;}
      rows[group]={produced_dozen:dz,defective_qty:def};
    });
    'ABCDE'.split('').forEach(function(g){if(seen[g]>1){delete rows[g];warnings.push(g+' birden fazla kez bulundu; aktarılmadı.');}else if(!rows[g])warnings.push(g+' satırını elle kontrol edin.');});
    if(dates.size!==1)warnings.push('Tarih okunamadı veya birden fazla tarih var; seçili tarih korundu.');
    return {rows:rows,date:dates.size===1?Array.from(dates)[0]:null,warnings:warnings};
  }
  var api={parse:parse,number:number};if(typeof module==='object'&&module.exports)module.exports=api;else root.ProductionPhoto=api;
})(typeof window!=='undefined'?window:this);
