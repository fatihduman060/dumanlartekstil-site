(() => {
  const ASSET_BASE = 'assets/img/markalarlogo/';
  const FACTORY_BANNER = 'assets/img/dumanlarheroanasayfafabrika.png';

  const brands = [
    { name: 'Bitke Socks', key: 'bitke', logo: `${ASSET_BASE}bitke.png`, href: 'markalar.html#bitke', text: 'Günlük, klasik ve sezonluk koleksiyonlar için güçlü ana marka.' },
    { name: 'Mofiy Socks', key: 'mofiy', logo: `${ASSET_BASE}mofiy.png`, href: 'markalar.html#mofiy', text: 'Genç, renkli ve modern koleksiyon çizgisine uygun marka.' },
    { name: 'Bafiy Socks', key: 'bafiy', logo: `${ASSET_BASE}bafiy.png`, href: 'markalar.html#bafiy', text: 'Yeni koleksiyonlara ve farklı satış kanallarına tamamlayıcı marka.' },
  ];

  const injectPortalStyles = () => {
    if (document.getElementById('brand-portal-styles')) return;

    const style = document.createElement('style');
    style.id = 'brand-portal-styles';
    style.textContent = `
      body.home-page .feature-band {
        position: relative;
        overflow: hidden;
        border-top: 0 !important;
        border-bottom: 0 !important;
        outline: 0 !important;
        padding-bottom: clamp(30px, 2.8vw, 56px) !important;
        align-items: flex-start !important;
        background:
          linear-gradient(180deg, #061523 0%, #071827 50%, #0e2132 68%, #26394a 86%, rgba(248,243,235,.60) 132%) !important;
        box-shadow:
          inset 0 -42px 56px rgba(248,243,235,.10),
          0 18px 32px rgba(3,14,26,.10);
      }

      body.home-page .feature-band::before {
        display: none !important;
      }

      body.home-page .feature-band::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: clamp(44px, 4.8vw, 86px);
        pointer-events: none;
        z-index: 0;
        background: linear-gradient(0deg, rgba(248,243,235,.34), rgba(248,243,235,.12) 58%, rgba(248,243,235,0));
      }

      body.home-page .feature-band > * {
        position: relative;
        z-index: 1;
      }

      body.home-page .factory-showcase-section {
        position: relative;
        overflow: hidden;
        background: #f8f3eb;
        border-bottom: 0;
        margin-top: 0;
        padding-top: 0;
        box-shadow:
          inset 0 10px 18px rgba(3,14,26,.06),
          inset 0 -14px 26px rgba(248,243,235,.42);
      }

      body.home-page .factory-showcase-section::before,
      body.home-page .factory-showcase-section::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        z-index: 2;
        pointer-events: none;
      }

      body.home-page .factory-showcase-section::before {
        top: 0;
        height: clamp(18px, 2vw, 36px);
        background: linear-gradient(180deg, rgba(3,14,26,.12), rgba(3,14,26,.04) 56%, rgba(3,14,26,0));
      }

      body.home-page .factory-showcase-section::after {
        bottom: 0;
        height: clamp(26px, 2.8vw, 52px);
        background: linear-gradient(0deg, rgba(248,243,235,.50), rgba(248,243,235,.22) 54%, rgba(248,243,235,0));
      }

      body.home-page .factory-showcase-section img {
        display: block;
        width: 100%;
        height: auto;
        object-fit: contain;
        object-position: center;
      }

      body.home-page .brand-portal-section {
        --portal-mouth-x: 81.5%;
        position: relative;
        isolation: isolate;
        height: clamp(205px, 15.8vw, 292px);
        overflow: hidden;
        background: #f8f3eb;
        border-bottom: 1px solid rgba(201,154,63,.22);
        margin-top: -1px;
        box-shadow: inset 0 10px 18px rgba(248,243,235,.40), inset 0 -1px 0 rgba(7,21,35,.06);
      }

      body.home-page .brand-portal-section::before {
        content: '';
        position: absolute;
        inset: 0;
        z-index: 0;
        background:
          linear-gradient(90deg, rgba(255,255,255,.97) 0%, rgba(255,255,255,.86) 47%, rgba(255,255,255,.64) 74%, rgba(255,255,255,.46) 100%),
          url('${ASSET_BASE}brand-portal-bg.webp') center / cover no-repeat;
        transform: scale(1.018);
      }

      body.home-page .brand-portal-section::after {
        content: '';
        position: absolute;
        inset: 0;
        z-index: 1;
        pointer-events: none;
        background:
          radial-gradient(circle at var(--portal-mouth-x) 57%, rgba(255,225,158,.38), transparent 10%),
          linear-gradient(180deg, rgba(255,255,255,.24), transparent 48%, rgba(7,21,35,.032));
      }

      body.home-page .portal-flow {
        position: absolute;
        inset: 0;
        z-index: 2;
        pointer-events: none;
        overflow: hidden;
      }

      body.home-page .portal-flow span {
        position: absolute;
        left: -16%;
        right: -16%;
        height: 9px;
        border-radius: 999px;
        opacity: .56;
        filter: blur(1.6px);
        background: linear-gradient(90deg, transparent 0%, rgba(255,238,198,0) 9%, rgba(255,241,206,.36) 24%, rgba(255,255,245,.92) 52%, rgba(255,226,163,.30) 78%, transparent 100%);
        background-size: 520px 100%;
        animation: portalFlowMove 6.4s linear infinite;
      }

      body.home-page .portal-flow span:nth-child(1) { top: 49%; transform: rotate(-1.1deg); }
      body.home-page .portal-flow span:nth-child(2) { top: 56%; height: 6px; opacity: .38; animation-duration: 8s; transform: rotate(.7deg); }
      body.home-page .portal-flow span:nth-child(3) { top: 63%; height: 4px; opacity: .26; animation-duration: 9.6s; transform: rotate(-.5deg); }

      body.home-page .brand-portal-track-wrap {
        position: absolute;
        inset: 0;
        z-index: 3;
        overflow: hidden;
        pointer-events: none;
        clip-path: polygon(0 0, var(--portal-mouth-x) 0, var(--portal-mouth-x) 100%, 0 100%);
      }

      body.home-page .brand-portal-track {
        position: absolute;
        top: 40%;
        left: -58%;
        display: flex;
        align-items: center;
        gap: clamp(78px, 7.2vw, 148px);
        width: max-content;
        transform: translateY(-50%);
        animation: brandPortalMove 20s linear infinite;
        will-change: transform;
      }

      body.home-page .brand-portal-track img {
        display: block;
        width: auto;
        height: clamp(48px, 4.8vw, 90px);
        object-fit: contain;
        filter: drop-shadow(0 14px 18px rgba(7,21,35,.08));
        flex: 0 0 auto;
      }

      body.home-page .brand-portal-tunnel-mask,
      body.home-page .brand-portal-mouth-cover {
        position: absolute;
        z-index: 4;
        pointer-events: none;
      }

      body.home-page .brand-portal-tunnel-mask {
        left: calc(var(--portal-mouth-x) - .25vw);
        right: -2vw;
        top: 0;
        bottom: 0;
        background:
          linear-gradient(90deg, rgba(248,243,235,.02), rgba(248,243,235,.80) 8%, rgba(248,243,235,.90) 78%, rgba(248,243,235,.32)),
          radial-gradient(ellipse at 8% 57%, rgba(255,235,190,.32), transparent 54%);
        opacity: .95;
      }

      body.home-page .brand-portal-mouth-cover {
        left: var(--portal-mouth-x);
        top: 57%;
        width: clamp(76px, 5.6vw, 120px);
        height: clamp(70px, 5.6vw, 106px);
        border-radius: 999px;
        transform: translate(-50%, -50%);
        background:
          radial-gradient(ellipse at 50% 50%, rgba(248,243,235,.80) 0%, rgba(248,243,235,.52) 34%, rgba(248,243,235,0) 72%);
        filter: blur(5px);
        opacity: .78;
      }

      body.home-page .brand-portal-glow,
      body.home-page .brand-portal-mouth-glow {
        position: absolute;
        z-index: 6;
        pointer-events: none;
      }

      body.home-page .brand-portal-glow {
        right: -12vw;
        top: 57%;
        width: min(40vw, 620px);
        height: 86px;
        transform: translateY(-50%);
        background: radial-gradient(ellipse at 38% 50%, rgba(255,227,158,.38), transparent 66%);
        filter: blur(12px);
        opacity: .82;
      }

      body.home-page .brand-portal-mouth-glow {
        left: var(--portal-mouth-x);
        top: 57%;
        width: clamp(82px, 6.8vw, 132px);
        height: clamp(60px, 5.4vw, 98px);
        border-radius: 999px;
        transform: translate(-50%, -50%);
        background:
          radial-gradient(circle, rgba(255,245,214,.98) 0%, rgba(255,221,150,.50) 34%, rgba(255,221,150,0) 72%);
        filter: blur(4px);
        opacity: .90;
        mix-blend-mode: screen;
      }

      body.home-page .brand-portal-sock {
        position: absolute;
        z-index: 5;
        right: clamp(-210px, -12vw, -116px);
        top: 57%;
        width: min(34vw, 560px);
        max-width: none;
        height: auto;
        transform: translateY(-50%);
        filter: drop-shadow(0 20px 26px rgba(7,21,35,.17));
        pointer-events: none;
      }

      @keyframes brandPortalMove {
        from { transform: translateY(-50%) translateX(0); }
        to { transform: translateY(-50%) translateX(52%); }
      }

      @keyframes portalFlowMove {
        from { background-position: -260px 0; }
        to { background-position: 260px 0; }
      }

      @media (max-width: 900px) {
        body.home-page .feature-band {
          border-top: 0 !important;
          border-bottom: 0 !important;
          padding-bottom: 34px !important;
          background:
            linear-gradient(180deg, #061523 0%, #071827 60%, #13283a 82%, rgba(248,243,235,.38) 132%) !important;
        }
        body.home-page .brand-portal-section {
          height: 198px;
          --portal-mouth-x: 79%;
        }
        body.home-page .brand-portal-track {
          top: 41%;
          left: -122%;
          gap: 52px;
          animation-duration: 16s;
        }
        body.home-page .brand-portal-track img {
          height: 48px;
        }
        body.home-page .brand-portal-sock {
          right: -182px;
          top: 58%;
          width: 70vw;
        }
        body.home-page .brand-portal-glow {
          right: -150px;
          width: 70vw;
        }
        body.home-page .brand-portal-tunnel-mask {
          left: calc(var(--portal-mouth-x) - .5vw);
        }
      }

      @media (prefers-reduced-motion: reduce) {
        body.home-page .brand-portal-track,
        body.home-page .portal-flow span {
          animation: none;
        }
      }
    `;
    document.head.appendChild(style);
  };

  const setupBrandPortal = () => {
    if (!document.body.classList.contains('home-page')) return;

    const hero = document.querySelector('body.home-page #home.hero');
    if (!hero) return;

    injectPortalStyles();

    const featureBand = document.querySelector('body.home-page .feature-band');

    let factorySection = document.querySelector('.factory-showcase-section');
    if (!factorySection) {
      factorySection = document.createElement('section');
      factorySection.className = 'factory-showcase-section';
      factorySection.setAttribute('aria-label', 'Dumanlar fabrika tanıtım görseli');
      factorySection.innerHTML = `<img src="${FACTORY_BANNER}" alt="Dumanlar çorap ve tekstil üretimi" decoding="async">`;
    }

    if (featureBand) featureBand.insertAdjacentElement('afterend', factorySection);
    else hero.insertAdjacentElement('afterend', factorySection);

    if (document.querySelector('.brand-portal-section')) return;

    const trackLogos = [brands[2], brands[0], brands[1], brands[2], brands[0], brands[1], brands[2], brands[0], brands[1]];
    const logoTrack = trackLogos.map((brand) => `<img src="${brand.logo}" alt="" decoding="async">`).join('');
    const section = document.createElement('section');
    section.className = 'brand-portal-section';
    section.setAttribute('aria-label', 'Dumanlar marka geçiş animasyonu');
    section.innerHTML = `
      <div class="portal-flow" aria-hidden="true"><span></span><span></span><span></span></div>
      <div class="brand-portal-track-wrap" aria-hidden="true"><div class="brand-portal-track">${logoTrack}</div></div>
      <span class="brand-portal-tunnel-mask" aria-hidden="true"></span>
      <span class="brand-portal-mouth-cover" aria-hidden="true"></span>
      <span class="brand-portal-glow" aria-hidden="true"></span>
      <span class="brand-portal-mouth-glow" aria-hidden="true"></span>
      <img class="brand-portal-sock" src="${ASSET_BASE}sock-portal.png" alt="" decoding="async">
    `;

    factorySection.insertAdjacentElement('afterend', section);
  };

  const render = () => {
    const section = document.querySelector('body.home-page #markalar.brands-section');
    if (!section || section.dataset.brandCleanReady === '1') return;

    section.dataset.brandCleanReady = '1';
    section.className = 'brands-section section-light reveal brand-clean-section is-visible';
    section.removeAttribute('style');
    section.innerHTML = `
      <div class="brand-clean-inner">
        <div class="brand-clean-top">
          <div>
            <span class="brand-clean-kicker">Markalarımız</span>
            <h2 class="brand-clean-title">Satış kanalına göre ayrışan marka ailemiz</h2>
          </div>
          <p class="brand-clean-text">Bitke, Mofiy ve Bafiy; aynı üretim tecrübesinden beslenen, farklı koleksiyon ve hedef kitle ihtiyaçlarına cevap veren marka yapılarıdır.</p>
        </div>
        <div class="brand-clean-grid" aria-label="Dumanlar A.Ş. markaları">
          ${brands.map((brand) => `
            <a class="brand-clean-card brand-clean-card--${brand.key}" href="${brand.href}" aria-label="${brand.name} markasını incele">
              <span class="brand-clean-logo-wrap"><img src="${brand.logo}" alt="${brand.name}" loading="eager" decoding="async"></span>
              <strong class="brand-clean-name">${brand.name}</strong>
              <span class="brand-clean-desc">${brand.text}</span>
            </a>
          `).join('')}
        </div>
        <div class="brand-clean-actions">
          <a class="btn btn-dark" href="markalar.html">Tüm Markalarımızı Keşfet</a>
          <a class="btn btn-outline-light" href="iletisim.html">Üretim Talebi Oluştur</a>
        </div>
      </div>
    `;
  };

  const init = () => {
    setupBrandPortal();
    render();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();