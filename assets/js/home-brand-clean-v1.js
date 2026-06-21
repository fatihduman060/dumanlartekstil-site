(() => {
  const ASSET_BASE = 'assets/img/markalarlogo/';

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
      body.home-page .brand-portal-section {
        --portal-mouth-x: 57%;
        --portal-nose-x: 88%;
        position: relative;
        isolation: isolate;
        height: clamp(220px, 17.2vw, 326px);
        overflow: hidden;
        background: #f8f3eb;
        border-bottom: 1px solid rgba(201,154,63,.22);
        box-shadow: inset 0 -1px 0 rgba(7,21,35,.06);
      }

      body.home-page .brand-portal-section::before {
        content: '';
        position: absolute;
        inset: 0;
        z-index: 0;
        background:
          linear-gradient(90deg, rgba(255,255,255,.96) 0%, rgba(255,255,255,.82) 43%, rgba(255,255,255,.58) 72%, rgba(255,255,255,.40) 100%),
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
          radial-gradient(circle at var(--portal-mouth-x) 52%, rgba(255,225,158,.32), transparent 14%),
          radial-gradient(circle at var(--portal-nose-x) 52%, rgba(255,224,154,.30), transparent 13%),
          linear-gradient(180deg, rgba(255,255,255,.25), transparent 48%, rgba(7,21,35,.035));
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
        opacity: .58;
        filter: blur(1.6px);
        background: linear-gradient(90deg, transparent 0%, rgba(255,238,198,0) 9%, rgba(255,241,206,.38) 24%, rgba(255,255,245,.94) 52%, rgba(255,226,163,.34) 78%, transparent 100%);
        background-size: 520px 100%;
        animation: portalFlowMove 6.4s linear infinite;
      }

      body.home-page .portal-flow span:nth-child(1) { top: 45%; transform: rotate(-1.3deg); }
      body.home-page .portal-flow span:nth-child(2) { top: 52%; height: 6px; opacity: .40; animation-duration: 8s; transform: rotate(.9deg); }
      body.home-page .portal-flow span:nth-child(3) { top: 59%; height: 4px; opacity: .28; animation-duration: 9.6s; transform: rotate(-.6deg); }

      body.home-page .brand-portal-track-wrap {
        position: absolute;
        inset: 0;
        z-index: 3;
        overflow: hidden;
        pointer-events: none;
      }

      body.home-page .brand-portal-track-wrap--entry {
        clip-path: polygon(0 0, var(--portal-mouth-x) 0, var(--portal-mouth-x) 100%, 0 100%);
      }

      body.home-page .brand-portal-track-wrap--exit {
        clip-path: polygon(var(--portal-nose-x) 0, 100% 0, 100% 100%, var(--portal-nose-x) 100%);
      }

      body.home-page .brand-portal-track {
        position: absolute;
        top: 51%;
        left: -48%;
        display: flex;
        align-items: center;
        gap: clamp(66px, 6.8vw, 136px);
        width: max-content;
        transform: translateY(-50%);
        animation: brandPortalMove 20s linear infinite;
        will-change: transform;
      }

      body.home-page .brand-portal-track img {
        display: block;
        width: auto;
        height: clamp(52px, 5.4vw, 104px);
        object-fit: contain;
        filter: drop-shadow(0 14px 18px rgba(7,21,35,.08));
        flex: 0 0 auto;
      }

      body.home-page .brand-portal-tunnel-mask {
        position: absolute;
        z-index: 4;
        left: calc(var(--portal-mouth-x) - 1.8vw);
        right: calc(100% - var(--portal-nose-x) - 1.2vw);
        top: 0;
        bottom: 0;
        pointer-events: none;
        background:
          linear-gradient(90deg, rgba(248,243,235,.18), rgba(248,243,235,.56) 18%, rgba(248,243,235,.62) 76%, rgba(248,243,235,.18)),
          radial-gradient(ellipse at 50% 52%, rgba(255,235,190,.25), transparent 66%);
        backdrop-filter: blur(1px);
        opacity: .72;
      }

      body.home-page .brand-portal-glow,
      body.home-page .brand-portal-mouth-glow,
      body.home-page .brand-portal-nose-glow {
        position: absolute;
        z-index: 6;
        pointer-events: none;
      }

      body.home-page .brand-portal-glow {
        right: clamp(-80px, 3.5vw, 56px);
        top: 52%;
        width: min(44vw, 740px);
        height: 96px;
        transform: translateY(-50%);
        background: radial-gradient(ellipse at 58% 50%, rgba(255,227,158,.40), transparent 66%);
        filter: blur(12px);
        opacity: .88;
      }

      body.home-page .brand-portal-mouth-glow,
      body.home-page .brand-portal-nose-glow {
        top: 52%;
        width: clamp(74px, 7vw, 138px);
        height: clamp(54px, 5vw, 94px);
        border-radius: 999px;
        transform: translate(-50%, -50%);
        background:
          radial-gradient(circle, rgba(255,245,214,.98) 0%, rgba(255,221,150,.48) 34%, rgba(255,221,150,0) 72%);
        filter: blur(4px);
        opacity: .78;
        mix-blend-mode: screen;
      }

      body.home-page .brand-portal-mouth-glow { left: var(--portal-mouth-x); }
      body.home-page .brand-portal-nose-glow { left: var(--portal-nose-x); }

      body.home-page .brand-portal-sock {
        position: absolute;
        z-index: 5;
        right: clamp(-92px, -3vw, -34px);
        top: 52%;
        width: min(41vw, 720px);
        max-width: none;
        height: auto;
        transform: translateY(-50%);
        filter: drop-shadow(0 22px 28px rgba(7,21,35,.18));
        pointer-events: none;
      }

      @keyframes brandPortalMove {
        from { transform: translateY(-50%) translateX(0); }
        to { transform: translateY(-50%) translateX(50%); }
      }

      @keyframes portalFlowMove {
        from { background-position: -260px 0; }
        to { background-position: 260px 0; }
      }

      @media (max-width: 900px) {
        body.home-page .brand-portal-section {
          --portal-mouth-x: 53%;
          --portal-nose-x: 87%;
          height: 205px;
        }
        body.home-page .brand-portal-track {
          left: -116%;
          gap: 48px;
          animation-duration: 16s;
        }
        body.home-page .brand-portal-track img {
          height: 50px;
        }
        body.home-page .brand-portal-sock {
          right: -112px;
          width: 78vw;
        }
        body.home-page .brand-portal-glow {
          right: -112px;
          width: 72vw;
        }
        body.home-page .brand-portal-tunnel-mask {
          left: calc(var(--portal-mouth-x) - 4vw);
          right: calc(100% - var(--portal-nose-x) - 3vw);
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
    if (document.querySelector('.brand-portal-section')) return;

    const hero = document.querySelector('body.home-page #home.hero');
    if (!hero) return;

    injectPortalStyles();

    const trackLogos = [brands[2], brands[0], brands[1], brands[2], brands[0], brands[1], brands[2], brands[0], brands[1]];
    const logoTrack = trackLogos.map((brand) => `<img src="${brand.logo}" alt="" decoding="async">`).join('');
    const section = document.createElement('section');
    section.className = 'brand-portal-section';
    section.setAttribute('aria-label', 'Dumanlar marka geçiş animasyonu');
    section.innerHTML = `
      <div class="portal-flow" aria-hidden="true"><span></span><span></span><span></span></div>
      <div class="brand-portal-track-wrap brand-portal-track-wrap--entry" aria-hidden="true"><div class="brand-portal-track">${logoTrack}</div></div>
      <div class="brand-portal-track-wrap brand-portal-track-wrap--exit" aria-hidden="true"><div class="brand-portal-track">${logoTrack}</div></div>
      <span class="brand-portal-tunnel-mask" aria-hidden="true"></span>
      <span class="brand-portal-glow" aria-hidden="true"></span>
      <span class="brand-portal-mouth-glow" aria-hidden="true"></span>
      <span class="brand-portal-nose-glow" aria-hidden="true"></span>
      <img class="brand-portal-sock" src="${ASSET_BASE}sock-portal.png" alt="" decoding="async">
    `;

    hero.insertAdjacentElement('afterend', section);
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