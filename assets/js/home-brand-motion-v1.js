(() => {
  const ready = (fn) => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  };

  const applyBrandMotion = () => {
    if (!document.body.classList.contains('home-page')) return;
    const section = document.querySelector('.brands-section');
    if (!section) return;

    section.classList.add('brands-section-motion-ready');
    section.style.backgroundImage = 'radial-gradient(circle at 14% 16%, rgba(201,154,63,.10), transparent 31%), linear-gradient(90deg, #ffffff 0%, #ffffff 48%, #f6f8fb 100%)';
    section.style.backgroundColor = '#fff';

    let brandBox = section.querySelector('.brand-box');
    if (!brandBox) {
      brandBox = document.createElement('div');
      brandBox.className = 'brand-box';
      section.insertBefore(brandBox, section.firstChild);
    }

    brandBox.classList.add('brand-box-motion-ready');
    brandBox.innerHTML = `
      <span class="section-label">Markalarımız</span>
      <div class="brand-names brand-names-motion" aria-label="Bitke ve Mofiy markaları">
        <a class="brand-logo-motion brand-logo-motion--bitke" href="markalar.html#bitke" aria-label="Bitke markasını incele">
          <img src="assets/img/markalarlogo/bitke.png" alt="Bitke" loading="eager" decoding="async">
        </a>
        <span class="separator" aria-hidden="true"></span>
        <a class="brand-logo-motion brand-logo-motion--mofiy" href="markalar.html#mofiy" aria-label="Mofiy markasını incele">
          <img src="assets/img/markalarlogo/mofiy.png" alt="Mofiy" loading="eager" decoding="async">
        </a>
      </div>
      <a class="btn btn-dark" href="markalar.html">Tüm Markalarımızı Keşfet</a>
    `;
  };

  ready(() => {
    applyBrandMotion();
    window.setTimeout(applyBrandMotion, 250);
    window.setTimeout(applyBrandMotion, 900);
  });
})();
