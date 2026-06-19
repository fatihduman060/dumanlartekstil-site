(() => {
  const menuToggle = document.querySelector('.menu-toggle');
  const nav = document.querySelector('.main-nav');
  const isMobile = () => window.innerWidth <= 900;

  const setMenuState = (open) => {
    if (!nav || !menuToggle) return;
    nav.classList.toggle('open', open);
    menuToggle.setAttribute('aria-expanded', String(open));
    menuToggle.setAttribute('aria-label', open ? 'Menüyü kapat' : 'Menüyü aç');
    if (!open) closeCorporateDropdown();
  };

  const closeCorporateDropdown = () => {
    document.querySelectorAll('.nav-dropdown-corporate.is-open').forEach((dropdown) => {
      dropdown.classList.remove('is-open');
      dropdown.querySelector('a[href="kurumsal.html"]')?.setAttribute('aria-expanded', 'false');
    });
  };

  const injectCorporateNavStyles = () => {
    if (document.getElementById('corporate-nav-control-styles')) return;
    const style = document.createElement('style');
    style.id = 'corporate-nav-control-styles';
    style.textContent = `
      .site-header, .main-nav { overflow: visible !important; }
      .main-nav .nav-dropdown { position: relative; display: flex; align-items: center; }
      .main-nav .nav-dropdown > a { display: inline-flex; align-items: center; gap: 6px; }
      .main-nav .nav-dropdown > a::after { content: '▾'; font-size: 10px; line-height: 1; margin-left: 2px; color: #c99a3f; transition: transform .2s ease; }
      .nav-dropdown-corporate { position: relative !important; padding-bottom: 18px !important; margin-bottom: -18px !important; }
      .nav-dropdown-corporate::before { content: ''; position: absolute; left: -34px; right: -34px; top: 100%; height: 24px; pointer-events: auto; }
      .nav-dropdown-corporate .nav-dropdown-panel {
        position: absolute !important;
        left: 50% !important;
        top: calc(100% + 2px) !important;
        width: min(520px, calc(100vw - 32px)) !important;
        padding: 18px !important;
        display: grid !important;
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px !important;
        background: radial-gradient(circle at 12% 0%, rgba(225,189,104,.22), transparent 36%), linear-gradient(145deg, rgba(6,18,31,.98), rgba(9,31,52,.98)) !important;
        border: 1px solid rgba(225,189,104,.42) !important;
        border-radius: 24px !important;
        box-shadow: 0 34px 90px rgba(0,0,0,.38), inset 0 1px 0 rgba(255,255,255,.08) !important;
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        transform: translateX(-50%) translateY(12px) scale(.985) !important;
        opacity: 0 !important;
        visibility: hidden !important;
        pointer-events: none !important;
        overflow: hidden !important;
        transition: opacity .18s ease, transform .18s ease, visibility .18s ease, max-height .25s ease !important;
        z-index: 9999 !important;
      }
      .nav-dropdown-corporate .nav-dropdown-panel::before { content: 'Kurumsal İçerikler'; grid-column: 1 / -1; display: block; padding: 4px 4px 12px; color: #e1bd68; font-size: 11px; font-weight: 900; letter-spacing: .22em; text-transform: uppercase; border-bottom: 1px solid rgba(225,189,104,.18); }
      .nav-dropdown-corporate .nav-dropdown-panel::after { content: ''; position: absolute; top: -7px; left: 50%; width: 14px; height: 14px; background: #091f34; border-left: 1px solid rgba(225,189,104,.42); border-top: 1px solid rgba(225,189,104,.42); transform: translateX(-50%) rotate(45deg); }
      @media (min-width: 901px) {
        .nav-dropdown-corporate:hover .nav-dropdown-panel,
        .nav-dropdown-corporate:focus-within .nav-dropdown-panel { opacity: 1 !important; visibility: visible !important; pointer-events: auto !important; transform: translateX(-50%) translateY(0) scale(1) !important; }
      }
      .nav-dropdown-corporate .nav-dropdown-panel a { position: relative !important; min-height: 78px !important; display: grid !important; grid-template-columns: 38px minmax(0, 1fr) !important; align-items: center !important; gap: 12px !important; padding: 14px 14px 14px 12px !important; border: 1px solid rgba(255,255,255,.08) !important; border-radius: 16px !important; background: rgba(255,255,255,.045) !important; color: #fff !important; font-size: 14px !important; font-weight: 900 !important; line-height: 1.15 !important; letter-spacing: -.01em !important; text-transform: none !important; white-space: normal !important; box-shadow: inset 0 1px 0 rgba(255,255,255,.05); transition: transform .18s ease, border-color .18s ease, background .18s ease, box-shadow .18s ease !important; }
      .nav-dropdown-corporate .nav-dropdown-panel a::before { width: 38px; height: 38px; display: inline-flex; align-items: center; justify-content: center; border-radius: 14px; background: linear-gradient(145deg, rgba(225,189,104,.22), rgba(201,154,63,.08)); border: 1px solid rgba(225,189,104,.34); color: #e1bd68; font-size: 18px; line-height: 1; box-shadow: 0 12px 24px rgba(0,0,0,.12); }
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
      .nav-dropdown-corporate .nav-dropdown-panel a:hover, .nav-dropdown-corporate .nav-dropdown-panel a:focus { transform: translateY(-2px) !important; border-color: rgba(225,189,104,.55) !important; background: rgba(225,189,104,.11) !important; color: #e1bd68 !important; box-shadow: 0 18px 34px rgba(0,0,0,.2), inset 0 1px 0 rgba(255,255,255,.08) !important; }
      .corporate-info-premium { display: none !important; }
      #hakkimizda, #tarihce, #uretim-gucu, #misyonumuz, #vizyonumuz, #degerlerimiz, #kalite-politikasi { scroll-margin-top: 120px; }
      @media (max-width: 900px) {
        .main-nav.open { max-height: calc(100vh - 128px) !important; overflow-y: auto !important; -webkit-overflow-scrolling: touch !important; padding-bottom: 34px !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate { width: 100% !important; display: block !important; padding-bottom: 0 !important; margin-bottom: 0 !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate > a { width: 100% !important; display: flex !important; align-items: center !important; justify-content: space-between !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate.is-open > a::after { transform: rotate(180deg) !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open) > .nav-dropdown-panel,
        .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open):hover > .nav-dropdown-panel,
        .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open):focus-within > .nav-dropdown-panel {
          position: static !important; display: grid !important; grid-template-columns: 1fr !important; width: 100% !important; height: 0 !important; max-height: 0 !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; overflow: hidden !important; transform: none !important; border: 0 !important; box-shadow: none !important; background: transparent !important;
        }
        .main-nav .nav-dropdown.nav-dropdown-corporate.is-open > .nav-dropdown-panel {
          position: static !important; display: grid !important; grid-template-columns: 1fr !important; gap: 10px !important; width: 100% !important; height: auto !important; max-height: 820px !important; margin: 14px 0 18px !important; padding: 18px !important; opacity: 1 !important; visibility: visible !important; pointer-events: auto !important; overflow: hidden !important; transform: none !important; border: 1px solid rgba(225,189,104,.36) !important; border-radius: 24px !important; background: rgba(7,21,35,.96) !important; box-shadow: none !important;
        }
        .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel::before,
        .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel::after { display: none !important; }
        .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel a { min-height: 0 !important; padding: 14px 16px !important; white-space: normal !important; font-size: 15px !important; }
        .whatsapp-float { right: 18px !important; bottom: max(22px, env(safe-area-inset-bottom)) !important; width: 68px !important; height: 68px !important; }
        .site-footer { padding-bottom: 108px !important; }
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

  const setupNormalMenuLinks = () => {
    if (!nav) return;
    nav.addEventListener('click', (event) => {
      const link = event.target.closest('a');
      if (!link) return;
      if (link.closest('.nav-dropdown-corporate')) return;
      if (isMobile()) setMenuState(false);
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
    const revealItems = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window && revealItems.length) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12 });
      revealItems.forEach((item) => observer.observe(item));
    } else {
      revealItems.forEach((item) => item.classList.add('is-visible'));
    }
  };

  const setupCookieBanner = () => {
    const cookieBanner = document.getElementById('cookie-banner');
    const cookieChoiceKey = 'dumanlar_cookie_choice';
    if (cookieBanner && !localStorage.getItem(cookieChoiceKey)) cookieBanner.hidden = false;
    document.querySelectorAll('[data-cookie-accept], [data-cookie-decline]').forEach((button) => {
      button.addEventListener('click', () => {
        localStorage.setItem(cookieChoiceKey, button.hasAttribute('data-cookie-accept') ? 'accepted' : 'necessary');
        if (cookieBanner) cookieBanner.hidden = true;
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
      gallery.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') setActive(index - 1);
        if (event.key === 'ArrowRight') setActive(index + 1);
      });
      gallery.setAttribute('tabindex', '0');
      setActive(0);
    });
  };

  const setupHomeRefresh = () => {
    const refreshHref = 'assets/css/bitke-refresh.css';
    if (!document.querySelector(`link[href="${refreshHref}"]`)) {
      const refreshStyles = document.createElement('link');
      refreshStyles.rel = 'stylesheet';
      refreshStyles.href = refreshHref;
      document.head.appendChild(refreshStyles);
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

    const statContent = [['Toptan', 'Satış Kanalı'], ['Özel Marka', 'Üretim Desteği'], ['Erbaa / Tokat', 'Üretim Merkezi']];
    document.querySelectorAll('.hero-stats div').forEach((item, index) => {
      const [title, text] = statContent[index] || [];
      if (!title) return;
      const strong = item.querySelector('strong');
      const span = item.querySelector('span');
      if (strong) strong.textContent = title;
      if (span) span.textContent = text;
    });

    const featureTexts = [
      ['Güven & Kalite', 'Ürün, iplik ve sevkiyat süreçlerinde kalite odaklı ilerliyoruz.'],
      ['Planlı Üretim', 'Talep, numune, üretim ve teslimat adımlarını kontrollü şekilde yönetiyoruz.'],
      ['Markaya Uyum', 'Renk, desen, etiket ve ambalaj detaylarını satış kanalınıza göre planlıyoruz.'],
      ['Uzun Vadeli İş Birliği', 'Toptan satış ve özel üretim taleplerinde sürdürülebilir çözümler sunuyoruz.'],
    ];
    document.querySelectorAll('.feature-band article').forEach((article, index) => {
      const [title, text] = featureTexts[index] || [];
      const heading = article.querySelector('h2');
      const paragraph = article.querySelector('p');
      if (heading && title) heading.textContent = title;
      if (paragraph && text) paragraph.textContent = text;
    });

    const featureBand = document.querySelector('.feature-band');
    if (featureBand && !document.querySelector('.b2b-proof-strip')) {
      const proof = document.createElement('section');
      proof.className = 'b2b-proof-strip reveal is-visible';
      proof.setAttribute('aria-label', 'Dumanlar üretim avantajları');
      proof.innerHTML = '<article><strong>Toptan Satış Odaklı</strong><span>Mağaza, pazar yeri ve bayi kanallarına uygun ürün grupları.</span></article><article><strong>Özel Marka Üretimi</strong><span>Etiket, ambalaj, desen ve iplik seçeneklerinde esnek planlama.</span></article><article><strong>Numune Süreci</strong><span>Üretim öncesi beklentiyi netleştiren kontrollü hazırlık adımları.</span></article><article><strong>Profesyonel İletişim</strong><span>Teklif ve üretim talepleri için doğrudan WhatsApp iletişimi.</span></article>';
      featureBand.insertAdjacentElement('afterend', proof);
    }

    const productLinks = new Map([['Erkek Çorap', 'erkek-corap.html'], ['Kadın Çorap', 'urunler.html#kadin-corap'], ['Çocuk Çorap', 'urunler.html#cocuk-corap'], ['Spor Çorap', 'urunler.html'], ['Havlu & Bambu Çorap', 'bambu-corap.html'], ['Özel Marka Üretimi', 'ozel-marka-uretimi.html']]);
    document.querySelectorAll('.products-section .product-card').forEach((card) => {
      if (card.querySelector('.product-link')) return;
      const title = card.querySelector('h3')?.textContent?.trim();
      const href = productLinks.get(title);
      if (!href) return;
      const link = document.createElement('a');
      link.className = 'product-link';
      link.href = href;
      link.textContent = 'Detayları Gör';
      card.appendChild(link);
    });
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
    const image = document.querySelector('img[src$="/fabrikadis.png"], img[src="assets/img/bitkekurumsal/fabrikadis.png"]');
    if (image) image.src = 'assets/img/bitkekurumsal/fabrikadış.png';
    if (window.location.hash) setTimeout(() => document.querySelector(window.location.hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
  };

  menuToggle?.addEventListener('click', () => setMenuState(!nav?.classList.contains('open')));
  setupCorporateDropdown();
  setupNormalMenuLinks();
  injectCorporateNavStyles();
  setupForms();
  setupReveal();
  setupCookieBanner();
  setupProductHotspots();
  setupGallery();
  setupHomeRefresh();
  setupFooterIcons();
  setupCorporatePage();

  window.addEventListener('resize', () => {
    if (!isMobile()) closeCorporateDropdown();
  });
})();
