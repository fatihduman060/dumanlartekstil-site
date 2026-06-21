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
        position: relative;
        isolation: isolate;
        height: clamp(230px, 17.5vw, 346px);
        overflow: hidden;
        background: #f7f2ea;
        border-bottom: 1px solid rgba(201,154,63,.22);
        box-shadow: inset 0 -1px 0 rgba(7,21,35,.06);
      }

      body.home-page .brand-portal-section::before {
        content: '';
        position: absolute;
        inset: 0;
        z-index: 0;
        background:
          linear-gradient(90deg, rgba(255,255,255,.92) 0%, rgba(255,255,255,.62) 48%, rgba(255,255,255,.18) 100%),
          url('${ASSET_BASE}brand-portal-bg.webp') center / cover no-repeat;
        transform: scale(1.015);
      }

      body.home-page .brand-portal-section::after {
        content: '';
        position: absolute;
        inset: 0;
        z-index: 1;
        pointer-events: none;
        background:
          radial-gradient(circle at 70% 52%, rgba(255,229,174,.28), transparent 18%),
          linear-gradient(180deg, rgba(255,255,255,.34), transparent 48%, rgba(7,21,35,.04));
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
        left: -14%;
        right: -14%;
        height: 9px;
        border-radius: 999px;
        opacity: .55;
        filter: blur(1.6px);
        background: linear-gradient(90deg, transparent 0%, rgba(255,238,198,0) 9%, rgba(255,241,206,.38) 24%, rgba(255,255,245,.92) 52%, rgba(255,226,163,.34) 78%, transparent 100%);
        animation: portalFlowMove 6.6s linear infinite;
      }

      body.home-page .portal-flow span:nth-child(1) { top: 45%; transform: rotate(-1.3deg); }
      body.home-page .portal-flow span:nth-child(2) { top: 52%; height: 6px; opacity: .38; animation-duration: 8.2s; transform: rotate(.9deg); }
      body.home-page .portal-flow span:nth-child(3) { top: 59%; height: 4px; opacity: .26; animation-duration: 10s; transform: rotate(-.6deg); }

      body.home-page .brand-portal-track-wrap {
        position: absolute;
        inset: 0;
        z-index: 3;
        overflow: hidden;
        pointer-events: none;
      }

      body.home-page .brand-portal-track {
        position: absolute;
        top: 51%;
        left: -45%;
        display: flex;
        align-items: center;
        gap: clamp(72px, 7.4vw, 150px);
        width: max-content;
        transform: translateY(-50%);
        animation: brandPortalMove 20s linear infinite;
        will-change: transform;
      }

      body.home-page .brand-portal-track img {
        display: block;
        width: auto;
        height: clamp(54px, 5.9vw, 112px);
        object-fit: contain;
        filter: drop-shadow(0 14px 18px rgba(7,21,35,.08));
        flex: 0 0 auto;
      }

      body.home-page .brand-portal-sock {
        position: absolute;
        z-index: 5;
        right: clamp(34px, 7vw, 150px);
        top: 52%;
        height: min(84%, 300px);
        width: auto;
        transform: translateY(-50%);
        filter: drop-shadow(0 18px 24px rgba(7,21,35,.16));
        pointer-events: none;
      }

      body.home-page .brand-portal-glow {
        position: absolute;
        z-index: 4;
        right: clamp(22px, 5.8vw, 128px);
        top: 52%;
        width: min(38vw, 680px);
        height: 92px;
        transform: translateY(-50%);
        pointer-events: none;
        background: radial-gradient(ellipse at 55% 50%, rgba(255,227,158,.38), transparent 63%);
        filter: blur(12px);
        opacity: .86;
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
          height: 215px;
        }
        body.home-page .brand-portal-track {
          left: -105%;
          gap: 54px;
          animation-duration: 16s;
        }
        body.home-page .brand-portal-track img {
          height: 54px;
        }
        body.home-page .brand-portal-sock {
          right: -22px;
          height: 70%;
        }
        body.home-page .brand-portal-glow {
          right: -30px;
          width: 58vw;
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
    const section = document.createElement('section');
    section.className = 'brand-portal-section';
    section.setAttribute('aria-label', 'Dumanlar marka geçiş animasyonu');
    section.innerHTML = `
      <div class="portal-flow" aria-hidden="true"><span></span><span></span><span></span></div>
      <span class="brand-portal-glow" aria-hidden="true"></span>
      <div class="brand-portal-track-wrap" aria-hidden="true">
        <div class="brand-portal-track">
          ${trackLogos.map((brand) => `<img src="${brand.logo}" alt="" decoding="async">`).join('')}
        </div>
      </div>
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