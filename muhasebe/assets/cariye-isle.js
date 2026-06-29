(function(){
  function csrfToken(){
    return document.querySelector('input[name="csrf_token"]')?.value || '';
  }
  function currentBack(){
    return location.pathname.split('/').pop() + location.search;
  }
  function submitCari(sourceType, id){
    const token = csrfToken();
    if (!id || !token) return;
    if (!confirm('Bu belge cariye işlenecek. Emin misin?')) return;
    const form = document.createElement('form');
    form.method = 'post';
    form.action = 'cariye-isle.php';
    form.style.display = 'none';
    const data = {csrf_token: token, source_type: sourceType, id: id, back: currentBack()};
    Object.keys(data).forEach(function(key){
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = key;
      input.value = data[key];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }
  function makePostButton(sourceType, id, label){
    if (!id || !csrfToken()) return null;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'cariye-isle-btn';
    btn.textContent = label;
    btn.addEventListener('click', function(){ submitCari(sourceType, id); });
    return btn;
  }
  function parseIdFromHref(href){
    try {
      const u = new URL(href, location.href);
      return u.searchParams.get('id') || u.searchParams.get('edit') || '';
    } catch(e){ return ''; }
  }
  function enhanceListActions(){
    document.querySelectorAll('.saved-actions').forEach(function(actions){
      if (actions.querySelector('.cariye-isle-btn')) return;
      let source = '';
      let id = '';
      const offerPdf = actions.querySelector('a[href*="teklif-yazdir.php?id="]');
      const receiptPdf = actions.querySelector('a[href*="tahsilat-yazdir.php?id="]');
      if (offerPdf) { source = 'offer'; id = parseIdFromHref(offerPdf.href); }
      if (receiptPdf) { source = 'tahsilat'; id = parseIdFromHref(receiptPdf.href); }
      if (!source || !id) return;
      const button = makePostButton(source, id, 'Cariye İşle');
      if (button) actions.appendChild(button);
    });
  }
  function enhanceCurrentEdit(){
    const params = new URLSearchParams(location.search);
    const editId = params.get('edit');
    if (!editId) return;
    const isOffer = /teklif-ver\.php$/i.test(location.pathname);
    const isReceipt = /tahsilat-makbuzu\.php$/i.test(location.pathname);
    if (!isOffer && !isReceipt) return;
    const actions = document.querySelector(isOffer ? '.offer-actions' : '.receipt-actions');
    if (!actions || actions.querySelector('.cariye-isle-btn')) return;
    const button = makePostButton(isOffer ? 'offer' : 'tahsilat', editId, 'Cariye İşle');
    if (button) actions.appendChild(button);
  }
  function addStyles(){
    if (document.getElementById('cariye-isle-style')) return;
    const st = document.createElement('style');
    st.id = 'cariye-isle-style';
    st.textContent = '.cariye-isle-btn{border:1px solid #c49a4f!important;background:#fff8e8!important;color:#7a541d!important;border-radius:999px!important;padding:7px 10px!important;font-weight:900!important;cursor:pointer!important}.offer-actions .cariye-isle-btn,.receipt-actions .cariye-isle-btn{min-height:42px!important;padding:9px 16px!important}.cariye-isle-btn:hover{background:#ffe8ae!important}';
    document.head.appendChild(st);
  }
  function init(){
    if (!/teklif-ver\.php|tahsilat-makbuzu\.php/i.test(location.pathname)) return;
    addStyles();
    enhanceListActions();
    enhanceCurrentEdit();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
