(() => {
  const MOBILE_MAX = 900;
  const isMobile = () => window.innerWidth <= MOBILE_MAX;
  const menuToggle = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.main-nav');

  const closeCorporateDropdown = () => {
    document.querySelectorAll('.nav-dropdown-corporate.is-open').forEach((dropdown) => {
      dropdown.classList.remove('is-open');
      dropdown.querySelector('a[href="kurumsal.html"]')?.setAttribute('aria-expanded', 'false');
    });
  };

  const setMenuState = (open) => {
    if (!nav || !menuToggle) return;
    nav.classList.toggle('open', open);
    menuToggle.setAttribute('aria-expanded', String(open));
    menuToggle.setAttribute('aria-label', open ? 'Menüyü kapat' : 'Menüyü aç');
    if (!open) closeCorporateDropdown();
  };

  const injectRuntimeStyles = () => {
    ['dumanlar-runtime-fixes-v6', 'dumanlar-runtime-fixes-v7', 'dumanlar-runtime-fixes-v8', 'dumanlar-runtime-fixes-v9', 'dumanlar-runtime-fixes-v10'].forEach((id) => {
      document.getElementById(id)?.remove();
    });

    const style = document.createElement('style');
    style.id = 'dumanlar-runtime-fixes-v10';
    style.textContent = `
      .site-header,
      .main-nav { overflow: visible !important; }

      .main-nav .nav-dropdown { position: relative; display: flex; align-items: center; }
      .main-nav .nav-dropdown > a { display: inline-flex; align-items: center; gap: 6px; }
      .main-nav .nav-dropdown > a::after { content: '▾'; font-size: 10px; margin-left: 2px; color: #c99a3f; transition: transform .2s ease; }
      .nav-dropdown-corporate { position: relative !important; padding-bottom: 18px !important; margin-bottom: -18px !important; }
      .nav-dropdown-corporate::before { content: ''; position: absolute; left: -34px; right: -34px; top: 100%; height: 24px; pointer-events: auto; }
      .nav-dropdown-corporate .nav-dropdown-panel { position: absolute !important; left: 50% !important; top: calc(100% + 2px) !important; width: min(520px, calc(100vw - 32px)) !important; padding: 18px !important; display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 10px !important; background: radial-gradient(circle at 12% 0%, rgba(225,189,104,.22), transparent 36%), linear-gradient(145deg, rgba(6,18,31,.98), rgba(9,31,52,.98)) !important; border: 1px solid rgba(225,189,104,.42) !important; border-radius: 24px !important; box-shadow: 0 34px 90px rgba(0,0,0,.38), inset 0 1px 0 rgba(255,255,255,.08) !important; transform: translateX(-50%) translateY(12px) scale(.985) !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; overflow: hidden !important; transition: opacity .18s ease, transform .18s ease, visibility .18s ease, max-height .25s ease !important; z-index: 9999 !important; }
      .nav-dropdown-corporate .nav-dropdown-panel::before { content: 'Kurumsal İçerikler'; grid-column: 1 / -1; display: block; padding: 4px 4px 12px; color: #e1bd68; font-size: 11px; font-weight: 900; letter-spacing: .22em; text-transform: uppercase; border-bottom: 1px solid rgba(225,189,104,.18); }
      .nav-dropdown-corporate .nav-dropdown-panel::after { content: ''; position: absolute; top: -7px; left: 50%; width: 14px; height: 14px; background: #091f34; border-left: 1px solid rgba(225,189,104,.42); border-top: 1px solid rgba(225,189,104,.42); transform: translateX(-50%) rotate(45deg); }
      .nav-dropdown-corporate .nav-dropdown-panel a { position: relative !important; min-height: 78px !important; display: grid !important; grid-template-columns: 38px minmax(0, 1fr) !important; align-items: center !important; gap: 12px !important; padding: 14px 14px 14px 12px !important; border: 1px solid rgba(255,255,255,.08) !important; border-radius: 16px !important; background: rgba(255,255,255,.045) !important; color: #fff !important; font-size: 14px !important; font-weight: 900 !important; line-height: 1.15 !important; letter-spacing: -.01em !important; text-transform: none !important; white-space: normal !important; box-shadow: inset 0 1px 0 rgba(255,255,255,.05); }
      .nav-dropdown-corporate .nav-dropdown-panel a::before { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 14px; background: linear-gradient(145deg, rgba(225,189,104,.22), rgba(201,154,63,.08)); border: 1px solid rgba(225,189,104,.34); color: #e1bd68; font-size: 18px; line-height: 1; }
      .nav-dropdown-corporate .nav-dropdown-panel a::after { grid-column: 2; display: block; margin-top: -18px; color: #aebccc; font-size: 11px; font-weight: 600; line-height: 1.35; letter-spacing: 0; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(2)::before { content: '🏢'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(2)::after { content: "1975'ten bugüne firma yolculuğu"; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(3)::before { content: '🕰'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(3)::after { content: 'Ticaret ve imalat geçmişi'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(4)::before { content: '⚙'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(4)::after { content: 'Makine parkı ve üretim kapasitesi'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(5)::before { content: '🎯'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(5)::after { content: 'Müşteri odaklı üretim anlayışı'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(6)::before { content: '🚀'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(6)::after { content: 'Ulusal ve uluslararası hedefler'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(7)::before { content: '◆'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(7)::after { content: 'Kalite, güven ve sürekli gelişim'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(8)::before { content: '✓'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:nth-child(8)::after { content: 'Kalite her şeyden önce gelir'; }
      .nav-dropdown-corporate .nav-dropdown-panel a:hover,
      .nav-dropdown-corporate .nav-dropdown-panel a:focus { transform: translateY(-2px) !important; border-color: rgba(225,189,104,.55) !important; background: rgba(225,189,104,.11) !important; color: #e1bd68 !important; }

      body .site-footer.footer-unified,
      body footer.site-footer.footer-unified {
        display: block !important;
        position: relative !important;
        margin: 0 !important;
        padding: 0 !important;
        color: #fff !important;
        border-top: 1px solid rgba(201,154,63,.34) !important;
        background: #020a12 !important;
        overflow: hidden !important;
      }

      body .site-footer.footer-unified::before {
        content: '';
        position: absolute;
        inset: 0;
        background:
          radial-gradient(circle at 6% 0%, rgba(225,189,104,.16), transparent 34%),
          radial-gradient(circle at 92% 100%, rgba(44,91,136,.17), transparent 32%),
          linear-gradient(135deg, #020812 0%, #061524 54%, #02060c 100%);
        pointer-events: none;
      }

      .footer-unified-stage {
        position: relative !important;
        z-index: 1 !important;
        width: min(1774px, calc(100% - 86px)) !important;
        min-height: clamp(430px, 31vw, 560px) !important;
        margin: 0 auto !important;
        padding: clamp(48px, 4vw, 72px) clamp(50px, 4.4vw, 76px) 0 !important;
        background:
          linear-gradient(180deg, rgba(255,255,255,.035), rgba(255,255,255,.006) 58%, transparent),
          url('assets/img/footer-background-clean.png') center / 100% 100% no-repeat !important;
        overflow: hidden !important;
      }

      .footer-unified-stage::after {
        content: '';
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 105px;
        opacity: .58;
        pointer-events: none;
        background:
          radial-gradient(ellipse at 50% 100%, rgba(225,189,104,.42), transparent 56%),
          repeating-linear-gradient(102deg, rgba(201,154,63,.13) 0 1px, transparent 1px 14px);
        mask-image: linear-gradient(180deg, transparent 0%, #000 42%, #000 100%);
      }

      .footer-unified-main {
        position: relative !important;
        z-index: 2 !important;
        display: grid !important;
        grid-template-columns: minmax(360px, 35%) minmax(120px, 13%) minmax(175px, 18%) minmax(270px, 26%) !important;
        column-gap: clamp(22px, 2.5vw, 44px) !important;
        align-items: start !important;
      }

      .footer-unified-brand {
        min-height: 250px !important;
        display: block !important;
      }

      .footer-unified-brand-mobile {
        display: none !important;
      }

      .footer-unified-col,
      .footer-unified-contact {
        position: relative !important;
        min-width: 0 !important;
      }

      .footer-unified-col h3,
      .footer-unified-contact h3 {
        margin: 0 0 clamp(20px, 1.6vw, 30px) !important;
        color: #f0c66e !important;
        font-family: Inter, Arial, sans-serif !important;
        font-size: clamp(13px, .95vw, 18px) !important;
        line-height: 1.06 !important;
        letter-spacing: .18em !important;
        text-transform: uppercase !important;
        font-weight: 900 !important;
      }

      .footer-unified-col h3::after,
      .footer-unified-contact h3::after {
        content: '' !important;
        display: block !important;
        width: clamp(35px, 2.45vw, 48px) !important;
        height: 2px !important;
        margin-top: 10px !important;
        background: linear-gradient(90deg, #e0af51, rgba(224,175,81,.12)) !important;
      }

      .footer-unified-col nav,
      .footer-unified-contact ul {
        display: grid !important;
        gap: clamp(7px, .55vw, 10px) !important;
        margin: 0 !important;
        padding: 0 !important;
      }

      .footer-unified-col nav a,
      .footer-unified-contact a,
      .footer-unified-bottom a {
        color: #e4edf8 !important;
        text-decoration: none !important;
        transition: color .18s ease, transform .18s ease !important;
      }

      .footer-unified-col nav a {
        display: block !important;
        font-size: clamp(11px, .75vw, 14px) !important;
        line-height: 1.2 !important;
        font-weight: 650 !important;
      }

      .footer-unified-col nav a:hover,
      .footer-unified-contact a:hover,
      .footer-unified-bottom a:hover {
        color: #f2cf80 !important;
        transform: translateX(3px) !important;
      }

      .footer-unified-contact ul {
        list-style: none !important;
        gap: 0 !important;
      }

      .footer-unified-contact li {
        display: grid !important;
        grid-template-columns: clamp(32px, 2.35vw, 42px) minmax(0, 1fr) !important;
        gap: clamp(8px, .65vw, 13px) !important;
        align-items: center !important;
        padding: 0 0 clamp(7px, .58vw, 11px) !important;
        margin: 0 0 clamp(7px, .58vw, 11px) !important;
        border-bottom: 1px solid rgba(255,255,255,.10) !important;
      }

      .footer-unified-contact li:last-child {
        border-bottom: 0 !important;
        margin-bottom: 0 !important;
      }

      .footer-unified-contact .contact-icon {
        width: clamp(31px, 2.25vw, 40px) !important;
        height: clamp(31px, 2.25vw, 40px) !important;
        min-width: clamp(31px, 2.25vw, 40px) !important;
        border: 1px solid rgba(213,158,60,.92) !important;
        border-radius: 50% !important;
        display: grid !important;
        place-items: center !important;
        color: #e3aa48 !important;
        background: rgba(255,255,255,.012) !important;
      }

      .footer-unified-contact .contact-icon svg {
        width: 48% !important;
        height: 48% !important;
        fill: none !important;
        stroke: currentColor !important;
        stroke-width: 1.85 !important;
        stroke-linecap: round !important;
        stroke-linejoin: round !important;
      }

      .footer-unified-contact b {
        display: block !important;
        margin: 0 0 4px !important;
        color: #f0c66e !important;
        font-size: clamp(7.5px, .52vw, 10px) !important;
        line-height: 1 !important;
        font-weight: 900 !important;
        letter-spacing: .1em !important;
        text-transform: uppercase !important;
      }

      .footer-unified-contact a,
      .footer-unified-contact strong {
        display: block !important;
        color: #f1f5fa !important;
        font-size: clamp(10px, .68vw, 13px) !important;
        line-height: 1.24 !important;
        font-weight: 650 !important;
        overflow-wrap: anywhere !important;
      }

      .footer-unified-social {
        display: flex !important;
        gap: clamp(9px, .75vw, 14px) !important;
        margin-top: clamp(9px, .75vw, 14px) !important;
      }

      .footer-unified-social a {
        width: clamp(34px, 2.35vw, 44px) !important;
        height: clamp(34px, 2.35vw, 44px) !important;
        display: grid !important;
        place-items: center !important;
        border-radius: 50% !important;
        border: 1px solid rgba(213,158,60,.86) !important;
        color: #e3aa48 !important;
        background: rgba(255,255,255,.012) !important;
        font-size: clamp(12px, .82vw, 15px) !important;
        font-weight: 800 !important;
      }

      .footer-unified-bottom {
        position: absolute !important;
        z-index: 3 !important;
        left: clamp(50px, 4.4vw, 76px) !important;
        right: clamp(50px, 4.4vw, 76px) !important;
        bottom: clamp(28px, 2.55vw, 44px) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 18px !important;
        padding-top: 16px !important;
        border-top: 1px solid rgba(225,189,104,.44) !important;
      }

      .footer-unified-bottom::before {
        content: '';
        position: absolute;
        left: 42%;
        top: -1px;
        width: 16%;
        height: 1px;
        background: #061524;
      }

      .footer-unified-bottom p {
        margin: 0 !important;
        color: #d2dbe7 !important;
        font-size: clamp(10px, .72vw, 13px) !important;
        line-height: 1.4 !important;
      }

      .footer-unified-bottom nav {
        display: flex !important;
        flex-wrap: wrap !important;
        justify-content: flex-end !important;
        gap: 10px 18px !important;
        margin: 0 !important;
        padding: 0 !important;
        font-size: clamp(10px, .7vw, 13px) !important;
      }

      .whatsapp-float {
        width: 66px !important;
        height: 66px !important;
        min-width: 66px !important;
        min-height: 66px !important;
        right: 22px !important;
        bottom: 22px !important;
      }

      .whatsapp-float-icon,
      .whatsapp-float-icon svg {
        width: 38px !important;
        height: 38px !important;
      }

      @media (min-width: 901px) {
        .nav-dropdown-corporate:hover .nav-dropdown-panel,
        .nav-dropdown-corporate:focus-within .nav-dropdown-panel { opacity: 1 !important; visibility: visible !important; pointer-events: auto !important; transform: translateX(-50%) translateY(0) scale(1) !important; }
      }

      @media (max-width: 1180px) {
        .footer-unified-stage {
          width: min(100% - 48px, 1774px) !important;
          padding-left: 34px !important;
          padding-right: 34px !important;
          background-size: cover !important;
        }
        .footer-unified-main {
          grid-template-columns: 1fr 1fr 1.1fr !important;
          gap: 32px !important;
        }
        .footer-unified-brand {
          display: none !important;
        }
        .footer-unified-brand-mobile {
          display: grid !important;
          grid-column: 1 / -1 !important;
          gap: 14px !important;
          max-width: 640px !important;
        }
        .footer-unified-brand-mobile img {
          width: min(310px, 100%) !important;
          padding: 12px 18px !important;
          border-radius: 18px !important;
          background: rgba(255,255,255,.96) !important;
          box-shadow: 0 18px 44px rgba(0,0,0,.22) !important;
        }
        .footer-unified-brand-mobile p {
          margin: 0 !important;
          color: #c8d3e0 !important;
          font-size: 14px !important;
          line-height: 1.65 !important;
        }
      }

      @media (max-width: 900px) {
        .main-nav.open { max-height: calc(100vh - 128px) !important; overflow-y: auto !important; -webkit-overflow-scrolling: touch !important; padding-bottom: 34px !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate { width: 100% !important; display: block !important; padding: 0 !important; margin: 0 !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate > a { width: 100% !important; display: flex !important; align-items: center !important; justify-content: space-between !important; padding: 28px 0 !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate.is-open > a::after { transform: rotate(180deg) !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open) > .nav-dropdown-panel,
        .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open):hover > .nav-dropdown-panel,
        .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open):focus-within > .nav-dropdown-panel { position: static !important; display: block !important; width: 100% !important; height: 0 !important; max-height: 0 !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; overflow: hidden !important; transform: none !important; border: 0 !important; box-shadow: none !important; background: transparent !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate.is-open > .nav-dropdown-panel { position: static !important; display: grid !important; grid-template-columns: 1fr !important; gap: 10px !important; width: 100% !important; height: auto !important; max-height: 820px !important; margin: 12px 0 18px !important; padding: 18px !important; opacity: 1 !important; visibility: visible !important; pointer-events: auto !important; overflow: hidden !important; transform: none !important; border: 1px solid rgba(225,189,104,.36) !important; border-radius: 24px !important; background: rgba(7,21,35,.96) !important; box-shadow: none !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel::before,
        .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel::after { display: none !important; content: none !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel a { display: block !important; min-height: 0 !important; padding: 14px 16px !important; white-space: normal !important; font-size: 15px !important; transform: none !important; }
      }

      @media (max-width: 720px) {
        .footer-unified-stage {
          width: 100% !important;
          min-height: 0 !important;
          padding: 34px 20px 104px !important;
          background:
            radial-gradient(circle at 0% 0%, rgba(225,189,104,.14), transparent 38%),
            linear-gradient(145deg, #04101d 0%, #020813 58%, #03070e 100%) !important;
        }
        .footer-unified-main {
          grid-template-columns: 1fr !important;
          gap: 26px !important;
        }
        .footer-unified-col h3,
        .footer-unified-contact h3 {
          font-size: 14px !important;
          margin-bottom: 14px !important;
        }
        .footer-unified-col nav a {
          font-size: 14.5px !important;
        }
        .footer-unified-contact a,
        .footer-unified-contact strong {
          font-size: 14px !important;
        }
        .footer-unified-bottom {
          position: relative !important;
          left: auto !important;
          right: auto !important;
          bottom: auto !important;
          display: grid !important;
          justify-content: start !important;
          margin-top: 26px !important;
          padding-top: 18px !important;
        }
        .footer-unified-bottom::before { display: none !important; }
        .footer-unified-bottom nav {
          justify-content: flex-start !important;
          font-size: 13px !important;
        }
        .whatsapp-float {
          width: 58px !important;
          height: 58px !important;
          min-width: 58px !important;
          min-height: 58px !important;
          right: 16px !important;
          bottom: max(16px, env(safe-area-inset-bottom)) !important;
        }
      }
    `;
    document.head.appendChild(style);
  };

  const setupUnifiedFooter = () => {
    const footer = document.querySelector('.site-footer') || document.createElement('footer');
    footer.className = 'site-footer footer-unified';
    footer.innerHTML = `
      <div class="footer-unified-stage">
        <div class="footer-unified-main">
          <section class="footer-unified-brand" aria-label="Dumanlar marka alanı"></section>
          <section class="footer-unified-brand-mobile" aria-label="Dumanlar marka bilgisi">
            <img src="assets/img/header-logo-only.png" alt="Dumanlar Çorap ve Tekstil Üretimi" loading="lazy" decoding="async" />
            <p>Dumanlar A.Ş.; Bitke ve Mofiy markalarıyla toptan çorap, tekstil üretimi ve özel marka projelerinde düzenli, güvenilir ve sürdürülebilir iş ortaklığı sunar.</p>
          </section>

          <section class="footer-unified-col" aria-label="Sayfalar">
            <h3>Sayfalar</h3>
            <nav>
              <a href="index.html">Ana Sayfa</a>
              <a href="kurumsal.html">Kurumsal</a>
              <a href="markalar.html">Markalarımız</a>
              <a href="urunler.html">Ürünlerimiz</a>
              <a href="uretim.html">Üretim</a>
              <a href="ozel-marka-uretimi.html">Özel Marka Üretimi</a>
              <a href="katalog.html">Katalog</a>
              <a href="sss.html">Sıkça Sorulan Sorular</a>
              <a href="iletisim.html">İletişim</a>
            </nav>
          </section>

          <section class="footer-unified-col" aria-label="Üretim alanları hızlı erişim">
            <h3>Üretim Alanları</h3>
            <nav>
              <a href="erkek-corap.html">Erkek Çorap</a>
              <a href="urunler.html#kadin-corap">Kadın Çorap</a>
              <a href="urunler.html#cocuk-corap">Çocuk Çorap</a>
              <a href="urunler.html#spor-corap">Spor Çorap</a>
              <a href="urunler.html#bambu-corap">Bambu Çorap</a>
              <a href="urunler.html#modal-corap">Modal Çorap</a>
              <a href="ozel-marka-uretimi.html">Markaya Özel Üretim</a>
            </nav>
          </section>

          <section class="footer-unified-contact" aria-label="İletişim">
            <h3>İletişim</h3>
            <ul>
              <li><span class="contact-icon contact-icon-phone"></span><div><b>Telefon</b><a href="tel:+903567158283">0 (356) 715-8283</a></div></li>
              <li><span class="contact-icon contact-icon-whatsapp"></span><div><b>WhatsApp</b><a href="https://wa.me/905321798707">0532 179 87 07</a></div></li>
              <li><span class="contact-icon contact-icon-email"></span><div><b>E-posta</b><a href="mailto:info@dumanlartekstil.com.tr">info@dumanlartekstil.com.tr</a></div></li>
              <li><span class="contact-icon contact-icon-pin"></span><div><b>Adres</b><strong>Organize Sanayi Bölgesi No:8, Erbaa / Tokat</strong></div></li>
            </ul>
            <div class="footer-unified-social" aria-label="Sosyal medya">
              <a href="#" aria-label="LinkedIn">in</a>
              <a href="#" aria-label="Instagram">◎</a>
              <a href="#" aria-label="YouTube">▶</a>
            </div>
          </section>
        </div>

        <div class="footer-unified-bottom">
          <p>© 2026 Dumanlar A.Ş. Tüm hakları saklıdır.</p>
          <nav aria-label="Yasal bağlantılar">
            <a href="kvkk-aydinlatma-metni.html">KVKK Aydınlatma Metni</a>
            <a href="gizlilik-politikasi.html">Gizlilik Politikası</a>
            <a href="cerez-politikasi.html">Çerez Politikası</a>
          </nav>
        </div>
      </div>
    `;

    if (!footer.parentNode) {
      const whatsapp = document.querySelector('.whatsapp-float');
      document.body.insertBefore(footer, whatsapp || null);
    }
  };

  const setupCorporateDropdown = () => {
    if (!nav || nav.querySelector('.nav-dropdown-corporate')) return;
    const corporateLink = Array.from(nav.querySelectorAll('a')).find((link) => link.getAttribute('href') === 'kurumsal.html');
    if (!corporateLink) return;

    const dropdownItems = [
      ['Hakkımızda', 'kurumsal.html#hakkimizda'],
      ['Tarihçe', 'kurumsal.html#tarihce'],
      ['Üretim Gücü', 'kurumsal.html#uretim-gucu'],
      ['Misyonumuz', 'kurumsal.html#misyonumuz'],
      ['Vizyonumuz', 'kurumsal.html#vizyonumuz'],
      ['Değerlerimiz', 'kurumsal.html#degerlerimiz'],
      ['Kalite Politikası', 'kurumsal.html#kalite-politikasi'],
    ];

    const wrapper = document.createElement('div');
    wrapper.className = 'nav-dropdown nav-dropdown-corporate';
    corporateLink.parentNode.insertBefore(wrapper, corporateLink);
    wrapper.appendChild(corporateLink);

    const panel = document.createElement('div');
    panel.className = 'nav-dropdown-panel';
    panel.setAttribute('aria-label', 'Kurumsal alt menü');
    panel.innerHTML = dropdownItems.map(([label, href]) => `<a href="${href}">${label}</a>`).join('');
    wrapper.appendChild(panel);

    corporateLink.setAttribute('aria-haspopup', 'true');
    corporateLink.setAttribute('aria-expanded', 'false');

    corporateLink.addEventListener('click', (event) => {
      if (!isMobile()) return;
      event.preventDefault();
      event.stopPropagation();
      const open = !wrapper.classList.contains('is-open');
      wrapper.classList.toggle('is-open', open);
      corporateLink.setAttribute('aria-expanded', String(open));
    });

    panel.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        if (!isMobile()) return;
        wrapper.classList.remove('is-open');
        corporateLink.setAttribute('aria-expanded', 'false');
        setMenuState(false);
      });
    });
  };

  const setupMenu = () => {
    menuToggle?.addEventListener('click', () => setMenuState(!nav?.classList.contains('open')));
    nav?.addEventListener('click', (event) => {
      const link = event.target.closest('a');
      if (!link || link.closest('.nav-dropdown-corporate')) return;
      if (isMobile()) setMenuState(false);
    });
    window.addEventListener('resize', () => {
      if (!isMobile()) closeCorporateDropdown();
    });
  };

  const setupForms = () => {
    document.querySelectorAll('.teklif-formu').forEach((form) => {
      const messageInput = form.querySelector('.whatsapp-message');
      if (!messageInput) return;
      form.addEventListener('submit', () => {
        if (!form.checkValidity()) return;
        const formData = new FormData(form);
        const name = String(formData.get('name') || '').trim();
        const contact = String(formData.get('contact') || '').trim();
        const message = String(formData.get('message') || '').trim();
        const subject = String(formData.get('subject') || '').trim();
        const parts = ['Merhaba, Dumanlar A.Ş. web sitesinden teklif talebi oluşturmak istiyorum.', '', `Ad Soyad / Firma: ${name}`, `Telefon veya E-posta: ${contact}`];
        if (subject) parts.push(`Talep Konusu: ${subject}`);
        parts.push(`Talep Detayı: ${message}`);
        messageInput.value = parts.join('\n');
      });
    });
  };

  const setupReveal = () => {
    const items = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window && items.length) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12 });
      items.forEach((item) => observer.observe(item));
    } else {
      items.forEach((item) => item.classList.add('is-visible'));
    }
  };

  const setupCookieBanner = () => {
    const banner = document.getElementById('cookie-banner');
    const key = 'dumanlar_cookie_choice';
    if (banner && !localStorage.getItem(key)) banner.hidden = false;
    document.querySelectorAll('[data-cookie-accept], [data-cookie-decline]').forEach((button) => {
      button.addEventListener('click', () => {
        localStorage.setItem(key, button.hasAttribute('data-cookie-accept') ? 'accepted' : 'necessary');
        if (banner) banner.hidden = true;
      });
    });
  };

  const setupProductHotspots = () => {
    document.querySelectorAll('.product-groups-frame').forEach((frame) => {
      frame.style.position = 'relative';
      frame.style.display = 'block';
      [
        { selector: '.product-hotspot-men', left: '0%', top: '0%', width: '34%', height: '100%' },
        { selector: '.product-hotspot-women', left: '33%', top: '0%', width: '34%', height: '100%' },
        { selector: '.product-hotspot-kids', left: '66%', top: '0%', width: '34%', height: '100%' },
      ].forEach((item) => {
        const link = frame.querySelector(item.selector);
        if (!link) return;
        Object.assign(link.style, { position: 'absolute', left: item.left, top: item.top, width: item.width, height: item.height, display: 'block', zIndex: '20', cursor: 'pointer', background: 'rgba(255,255,255,0)', pointerEvents: 'auto' });
      });
    });
  };

  const setupGallery = () => {
    document.querySelectorAll('[data-gallery]').forEach((gallery) => {
      const track = gallery.querySelector('.gallery-track');
      const slides = Array.from(gallery.querySelectorAll('.gallery-slide'));
      const dots = Array.from(gallery.querySelectorAll('[data-gallery-dot]'));
      const prev = gallery.querySelector('[data-gallery-prev]');
      const next = gallery.querySelector('[data-gallery-next]');
      if (!track || slides.length < 2) return;
      let index = 0;
      const setActive = (nextIndex) => {
        index = (nextIndex + slides.length) % slides.length;
        track.style.transform = `translateX(-${index * 100}%)`;
        slides.forEach((slide, i) => slide.classList.toggle('is-active', i === index));
        dots.forEach((dot) => dot.classList.toggle('is-active', Number(dot.dataset.galleryDot) === index));
      };
      prev?.addEventListener('click', () => setActive(index - 1));
      next?.addEventListener('click', () => setActive(index + 1));
      dots.forEach((dot) => dot.addEventListener('click', () => setActive(Number(dot.dataset.galleryDot))));
      gallery.setAttribute('tabindex', '0');
      setActive(0);
    });
  };

  const setupHomeRefresh = () => {
    const href = 'assets/css/bitke-refresh.css';
    if (!document.querySelector(`link[href="${href}"]`)) {
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      document.head.appendChild(link);
    }
    if (!document.body.classList.contains('home-page')) return;
    const heroTitle = document.querySelector('.hero h1');
    if (heroTitle) heroTitle.innerHTML = 'Çorap Üretiminde <strong>Markalara Özel Güçlü Çözüm</strong>';
    const heroText = document.querySelector('.hero-text');
    if (heroText) heroText.textContent = 'BİTKE ve MOFİY markalarımızla toptan satış kanallarına, mağazalara ve özel marka projelerine uygun; planlı, kaliteli ve sürdürülebilir çorap üretimi sunuyoruz.';
    const primaryCta = document.querySelector('.hero-actions .btn-gold');
    if (primaryCta) primaryCta.textContent = 'Ürün Gruplarını İncele';
    const outlineCta = document.querySelector('.hero-actions .btn-outline');
    if (outlineCta) outlineCta.textContent = 'Toptan Üretim Talebi';
  };

  const setupFooterIcons = () => {
    const icons = {
      'contact-icon-phone': '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8c1.5 3 3.6 5.1 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.2 1.3.4 2.7.6 4.1.6.7 0 1.2.5 1.2 1.2v3.6c0 .7-.5 1.2-1.2 1.2C10.4 21.6 2.4 13.6 2.4 3.3c0-.7.5-1.2 1.2-1.2h3.6c.7 0 1.2.5 1.2 1.2 0 1.4.2 2.8.6 4.1.1.4 0 .8-.3 1.2l-2.1 2.2z"/></svg>',
      'contact-icon-whatsapp': '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 11.9a8.5 8.5 0 0 1-12.6 7.4L3 20.6l1.3-4.7A8.5 8.5 0 1 1 20.5 12z"/><path d="M8.8 7.7c-.2-.5-.4-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.1 1-1.1 2.5s1.1 2.9 1.2 3.1c.1.2 2.1 3.4 5.2 4.6 2.6 1 3.1.8 3.7.8.6-.1 1.8-.8 2.1-1.5.3-.7.3-1.3.2-1.5-.1-.1-.3-.2-.7-.4l-2.1-1c-.3-.1-.5-.2-.7.2-.2.3-.8 1-1 1.2-.2.2-.4.2-.7.1-.3-.2-1.3-.5-2.5-1.6-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.7l.5-.6c.2-.2.2-.3.3-.6.1-.2 0-.4 0-.6l-.9-1.8z"/></svg>',
      'contact-icon-email': '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>',
      'contact-icon-pin': '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s7-6.2 7-13A7 7 0 0 0 5 9c0 6.8 7 13 7 13z"/><circle cx="12" cy="9" r="2.5"/></svg>',
    };
    Object.entries(icons).forEach(([className, svg]) => {
      document.querySelectorAll(`.${className}`).forEach((target) => {
        if (!target.querySelector('svg')) target.innerHTML = svg;
      });
    });
  };

  const setupCorporatePage = () => {
    const infoSection = document.querySelector('.corporate-info-premium');
    if (infoSection) infoSection.remove();
    if (!document.body.classList.contains('corporate-page')) return;
    document.querySelectorAll('img[src$="/fabrikadis.png"], img[src="assets/img/bitkekurumsal/fabrikadis.png"]').forEach((img) => {
      img.src = 'assets/img/bitkekurumsal/fabrikadış.png';
    });
    if (window.location.hash) setTimeout(() => document.querySelector(window.location.hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
  };

  injectRuntimeStyles();
  setupUnifiedFooter();
  setupCorporateDropdown();
  setupMenu();
  setupForms();
  setupReveal();
  setupCookieBanner();
  setupProductHotspots();
  setupGallery();
  setupHomeRefresh();
  setupFooterIcons();
  setupCorporatePage();
})();
