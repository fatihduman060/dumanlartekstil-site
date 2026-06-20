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
    document.getElementById('dumanlar-runtime-fixes-v6')?.remove();
    document.getElementById('dumanlar-runtime-fixes-v7')?.remove();
    const style = document.createElement('style');
    style.id = 'dumanlar-runtime-fixes-v7';
    style.textContent = `
      .site-header, .main-nav { overflow: visible !important; }
      .main-nav .nav-dropdown { position: relative; display: flex; align-items: center; }
      .main-nav .nav-dropdown > a { display: inline-flex; align-items: center; gap: 6px; }
      .main-nav .nav-dropdown > a::after { content: '▾'; font-size: 10px; line-height: 1; margin-left: 2px; color: #c99a3f; transition: transform .2s ease; }
      .nav-dropdown-corporate { position: relative !important; padding-bottom: 18px !important; margin-bottom: -18px !important; }
      .nav-dropdown-corporate::before { content: ''; position: absolute; left: -34px; right: -34px; top: 100%; height: 24px; pointer-events: auto; }
      .nav-dropdown-corporate .nav-dropdown-panel { position: absolute !important; left: 50% !important; top: calc(100% + 2px) !important; width: min(520px, calc(100vw - 32px)) !important; padding: 18px !important; display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 10px !important; background: radial-gradient(circle at 12% 0%, rgba(225,189,104,.22), transparent 36%), linear-gradient(145deg, rgba(6,18,31,.98), rgba(9,31,52,.98)) !important; border: 1px solid rgba(225,189,104,.42) !important; border-radius: 24px !important; box-shadow: 0 34px 90px rgba(0,0,0,.38), inset 0 1px 0 rgba(255,255,255,.08) !important; backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); transform: translateX(-50%) translateY(12px) scale(.985) !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; overflow: hidden !important; transition: opacity .18s ease, transform .18s ease, visibility .18s ease, max-height .25s ease !important; z-index: 9999 !important; }
      .nav-dropdown-corporate .nav-dropdown-panel::before { content: 'Kurumsal İçerikler'; grid-column: 1 / -1; display: block; padding: 4px 4px 12px; color: #e1bd68; font-size: 11px; font-weight: 900; letter-spacing: .22em; text-transform: uppercase; border-bottom: 1px solid rgba(225,189,104,.18); }
      .nav-dropdown-corporate .nav-dropdown-panel::after { content: ''; position: absolute; top: -7px; left: 50%; width: 14px; height: 14px; background: #091f34; border-left: 1px solid rgba(225,189,104,.42); border-top: 1px solid rgba(225,189,104,.42); transform: translateX(-50%) rotate(45deg); }
      @media (min-width: 901px) { .nav-dropdown-corporate:hover .nav-dropdown-panel, .nav-dropdown-corporate:focus-within .nav-dropdown-panel { opacity: 1 !important; visibility: visible !important; pointer-events: auto !important; transform: translateX(-50%) translateY(0) scale(1) !important; } }
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
      .nav-dropdown-corporate .nav-dropdown-panel a:hover, .nav-dropdown-corporate .nav-dropdown-panel a:focus { transform: translateY(-2px) !important; border-color: rgba(225,189,104,.55) !important; background: rgba(225,189,104,.11) !important; color: #e1bd68 !important; }

      @media (min-width: 901px) {
        body .site-footer.footer-exact { background: #020b14 !important; border-top: 0 !important; padding: 0 !important; margin: 0 !important; overflow: visible !important; }
        body .site-footer.footer-exact .footer-exact-stage { width: 100% !important; max-width: 1774px !important; height: clamp(500px, 34vw, 620px) !important; min-height: 500px !important; aspect-ratio: auto !important; margin: 0 auto !important; padding: 0 !important; position: relative !important; overflow: hidden !important; background: url('../img/footer-background-clean.png') center / 100% 100% no-repeat !important; }
        body .site-footer.footer-exact .footer-links, body .site-footer.footer-exact .footer-contact-exact, body .site-footer.footer-exact .footer-copy, body .site-footer.footer-exact .footer-legal-exact { position: absolute !important; z-index: 5 !important; font-family: Inter, Arial, sans-serif !important; margin: 0 !important; padding: 0 !important; }
        body .site-footer.footer-exact .footer-pages { left: 36.6% !important; top: 11.8% !important; width: 17.2% !important; }
        body .site-footer.footer-exact .footer-production { left: 50.4% !important; top: 11.8% !important; width: 13.2% !important; }
        body .site-footer.footer-exact .footer-quick { left: 62.5% !important; top: 11.8% !important; width: 10.8% !important; }
        body .site-footer.footer-exact .footer-contact-exact { left: 73.2% !important; top: 11.8% !important; width: 22.2% !important; }
        body .site-footer.footer-exact .footer-links h3, body .site-footer.footer-exact .footer-contact-exact h3 { margin: 0 !important; padding: 0 !important; color: #f0c66e !important; font-size: clamp(13px, .92vw, 18px) !important; line-height: 1.05 !important; letter-spacing: .17em !important; text-transform: uppercase !important; font-weight: 900 !important; }
        body .site-footer.footer-exact .footer-links h3::after, body .site-footer.footer-exact .footer-contact-exact h3::after { content: '' !important; display: block !important; width: clamp(34px, 2.4vw, 48px) !important; height: 2px !important; margin-top: clamp(8px, .62vw, 12px) !important; background: linear-gradient(90deg, #e0af51, rgba(224,175,81,.12)) !important; }
        body .site-footer.footer-exact .footer-links nav { display: grid !important; gap: clamp(6px, .47vw, 10px) !important; margin: clamp(18px, 1.55vw, 28px) 0 0 !important; padding: 0 !important; }
        body .site-footer.footer-exact .footer-links nav a { display: block !important; color: #dfe8f2 !important; font-size: clamp(11px, .72vw, 15px) !important; line-height: 1.18 !important; font-weight: 500 !important; letter-spacing: -.018em !important; text-decoration: none !important; }
        body .site-footer.footer-exact .footer-links nav a:hover { color: #f2cf80 !important; transform: translateX(3px) !important; }
        body .site-footer.footer-exact .footer-contact-exact ul { display: grid !important; gap: clamp(5px, .42vw, 8px) !important; margin: clamp(18px, 1.55vw, 28px) 0 0 !important; padding: 0 !important; list-style: none !important; }
        body .site-footer.footer-exact .footer-contact-exact li { display: grid !important; grid-template-columns: clamp(34px, 2.45vw, 46px) minmax(0, 1fr) !important; gap: clamp(8px, .62vw, 12px) !important; align-items: center !important; padding: 0 0 clamp(6px, .45vw, 9px) !important; margin: 0 !important; border-bottom: 1px solid rgba(255,255,255,.10) !important; }
        body .site-footer.footer-exact .footer-contact-exact li:last-child { border-bottom: 0 !important; }
        body .site-footer.footer-exact .contact-icon { width: clamp(32px, 2.35vw, 44px) !important; height: clamp(32px, 2.35vw, 44px) !important; min-width: clamp(32px, 2.35vw, 44px) !important; border: 1px solid rgba(213,158,60,.9) !important; border-radius: 50% !important; display: grid !important; place-items: center !important; color: #e3aa48 !important; background: rgba(255,255,255,.012) !important; }
        body .site-footer.footer-exact .contact-icon svg { width: 48% !important; height: 48% !important; fill: none !important; stroke: currentColor !important; stroke-width: 1.85 !important; stroke-linecap: round !important; stroke-linejoin: round !important; }
        body .site-footer.footer-exact .footer-contact-exact b { display: block !important; color: #f0c66e !important; font-size: clamp(8px, .55vw, 11px) !important; line-height: 1 !important; font-weight: 900 !important; letter-spacing: .1em !important; text-transform: uppercase !important; margin: 0 0 4px !important; }
        body .site-footer.footer-exact .footer-contact-exact a, body .site-footer.footer-exact .footer-contact-exact strong { display: block !important; color: #f1f5fa !important; font-size: clamp(10px, .72vw, 14px) !important; line-height: 1.25 !important; font-weight: 600 !important; text-decoration: none !important; overflow-wrap: anywhere !important; }
        body .site-footer.footer-exact .footer-copy { left: 4.6% !important; top: 82.8% !important; bottom: auto !important; width: 32% !important; color: #c9d2dd !important; font-size: clamp(10px, .78vw, 14px) !important; line-height: 1.25 !important; font-weight: 400 !important; text-align: left !important; }
        body .site-footer.footer-exact .footer-legal-exact { left: auto !important; right: 5.9% !important; top: 82.8% !important; bottom: auto !important; width: auto !important; max-width: 58% !important; display: flex !important; flex-direction: row !important; flex-wrap: wrap !important; align-items: center !important; justify-content: flex-end !important; gap: clamp(8px, .78vw, 14px) !important; color: #d7dfe9 !important; font-size: clamp(10px, .68vw, 13px) !important; line-height: 1.25 !important; white-space: normal !important; }
        body .site-footer.footer-exact .footer-legal-exact a, body .site-footer.footer-exact .footer-legal-exact span { color: inherit !important; text-decoration: none !important; display: inline !important; width: auto !important; margin: 0 !important; padding: 0 !important; }
        body .site-footer.footer-exact .footer-legal-exact span { color: rgba(255,255,255,.38) !important; }
      }

      @media (max-width: 900px) {
        .main-nav.open { max-height: calc(100vh - 128px) !important; overflow-y: auto !important; -webkit-overflow-scrolling: touch !important; padding-bottom: 34px !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate { width: 100% !important; display: block !important; padding: 0 !important; margin: 0 !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate > a { width: 100% !important; display: flex !important; align-items: center !important; justify-content: space-between !important; padding: 28px 0 !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate.is-open > a::after { transform: rotate(180deg) !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open) > .nav-dropdown-panel, .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open):hover > .nav-dropdown-panel, .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open):focus-within > .nav-dropdown-panel { position: static !important; display: block !important; width: 100% !important; height: 0 !important; max-height: 0 !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; overflow: hidden !important; transform: none !important; border: 0 !important; box-shadow: none !important; background: transparent !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate.is-open > .nav-dropdown-panel { position: static !important; display: grid !important; grid-template-columns: 1fr !important; gap: 10px !important; width: 100% !important; height: auto !important; max-height: 820px !important; margin: 12px 0 18px !important; padding: 18px !important; opacity: 1 !important; visibility: visible !important; pointer-events: auto !important; overflow: hidden !important; transform: none !important; border: 1px solid rgba(225,189,104,.36) !important; border-radius: 24px !important; background: rgba(7,21,35,.96) !important; box-shadow: none !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel::before, .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel::after { display: none !important; content: none !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel a { display: block !important; min-height: 0 !important; padding: 14px 16px !important; white-space: normal !important; font-size: 15px !important; transform: none !important; }

        body .site-footer.footer-exact { position: relative !important; overflow: visible !important; background: radial-gradient(circle at 12% 0%, rgba(207,160,70,.16), transparent 34%), radial-gradient(circle at 86% 72%, rgba(73,112,156,.11), transparent 36%), linear-gradient(145deg, #03101c 0%, #020813 58%, #03070e 100%) !important; border-top: 1px solid rgba(207,160,70,.34) !important; padding: 0 0 88px !important; }
        body .site-footer.footer-exact .footer-exact-stage { position: relative !important; display: grid !important; grid-template-columns: 1fr !important; gap: 22px !important; width: 100% !important; max-width: none !important; min-height: 0 !important; aspect-ratio: auto !important; padding: 34px 20px 24px !important; margin: 0 !important; background-image: none !important; background: linear-gradient(180deg, rgba(255,255,255,.035), transparent 18%), radial-gradient(circle at 0% 0%, rgba(207,160,70,.12), transparent 42%) !important; overflow: visible !important; }
        body .site-footer.footer-exact .footer-links, body .site-footer.footer-exact .footer-contact-exact, body .site-footer.footer-exact .footer-copy, body .site-footer.footer-exact .footer-legal-exact { position: static !important; left: auto !important; right: auto !important; top: auto !important; bottom: auto !important; width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; z-index: 2 !important; }
        body .site-footer.footer-exact .footer-links, body .site-footer.footer-exact .footer-contact-exact { padding-bottom: 20px !important; border-bottom: 1px solid rgba(255,255,255,.08) !important; }
        body .site-footer.footer-exact .footer-links h3, body .site-footer.footer-exact .footer-contact-exact h3 { margin: 0 !important; color: #f0c66e !important; font-size: 18px !important; line-height: 1.1 !important; letter-spacing: .16em !important; text-transform: uppercase !important; font-weight: 900 !important; }
        body .site-footer.footer-exact .footer-links h3::after, body .site-footer.footer-exact .footer-contact-exact h3::after { content: '' !important; display: block !important; width: 52px !important; height: 2px !important; margin-top: 11px !important; background: linear-gradient(90deg, #e0af51, rgba(224,175,81,.1)) !important; }
        body .site-footer.footer-exact .footer-links nav { display: grid !important; gap: 10px !important; margin: 16px 0 0 !important; padding: 0 !important; }
        body .site-footer.footer-exact .footer-links nav a { display: block !important; width: fit-content !important; max-width: 100% !important; padding: 0 !important; color: #dce5f1 !important; font-size: 16px !important; line-height: 1.24 !important; font-weight: 500 !important; letter-spacing: -.02em !important; text-decoration: none !important; }
        body .site-footer.footer-exact .footer-contact-exact ul { list-style: none !important; display: grid !important; gap: 0 !important; margin: 18px 0 0 !important; padding: 0 !important; }
        body .site-footer.footer-exact .footer-contact-exact li { display: grid !important; grid-template-columns: 48px minmax(0, 1fr) !important; gap: 14px !important; align-items: center !important; padding: 0 0 13px !important; margin: 0 0 13px !important; border-bottom: 1px solid rgba(255,255,255,.10) !important; }
        body .site-footer.footer-exact .footer-contact-exact li:last-child { margin-bottom: 0 !important; border-bottom: 0 !important; }
        body .site-footer.footer-exact .contact-icon { width: 42px !important; height: 42px !important; min-width: 42px !important; border: 1.4px solid rgba(213,158,60,.95) !important; border-radius: 999px !important; display: grid !important; place-items: center !important; background: rgba(207,160,70,.08) !important; color: #e5b156 !important; }
        body .site-footer.footer-exact .contact-icon svg { width: 48% !important; height: 48% !important; fill: none !important; stroke: currentColor !important; stroke-width: 1.85 !important; stroke-linecap: round !important; stroke-linejoin: round !important; }
        body .site-footer.footer-exact .footer-contact-exact b { display: block !important; margin: 0 0 4px !important; color: #f0c66e !important; font-size: 11px !important; line-height: 1 !important; font-weight: 900 !important; letter-spacing: .1em !important; text-transform: uppercase !important; }
        body .site-footer.footer-exact .footer-contact-exact a, body .site-footer.footer-exact .footer-contact-exact strong { display: block !important; color: #eef3f8 !important; font-size: 15px !important; line-height: 1.32 !important; font-weight: 500 !important; overflow-wrap: anywhere !important; letter-spacing: 0 !important; }
        body .site-footer.footer-exact .footer-copy { color: #aebbcc !important; font-size: 13px !important; line-height: 1.45 !important; padding-top: 0 !important; }
        body .site-footer.footer-exact .footer-legal-exact { display: flex !important; flex-wrap: wrap !important; align-items: center !important; gap: 8px 14px !important; color: #dce5f1 !important; font-size: 13px !important; line-height: 1.25 !important; padding-top: 0 !important; }
        body .site-footer.footer-exact .footer-legal-exact span { display: none !important; }
        body .site-footer.footer-exact .footer-legal-exact a { display: inline-flex !important; width: auto !important; max-width: 100% !important; padding: 4px 0 !important; color: #dce5f1 !important; text-decoration: none !important; }
        body .whatsapp-float { width: 58px !important; height: 58px !important; min-width: 58px !important; min-height: 58px !important; right: 16px !important; bottom: max(18px, env(safe-area-inset-bottom)) !important; z-index: 95 !important; box-shadow: 0 14px 30px rgba(0,0,0,.28), 0 0 0 7px rgba(75,211,75,.13) !important; }
        body .whatsapp-float::after { inset: -6px !important; }
        body .whatsapp-float-icon, body .whatsapp-float-icon svg { width: 34px !important; height: 34px !important; }
      }
    `;
    document.head.appendChild(style);
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
