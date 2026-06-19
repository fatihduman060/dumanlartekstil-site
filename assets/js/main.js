const toggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('.main-nav');

if (toggle && nav) {
  toggle.addEventListener('click', () => {
    const isOpen = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(isOpen));
    toggle.setAttribute('aria-label', isOpen ? 'Menüyü kapat' : 'Menüyü aç');
  });
}

document.querySelectorAll('.main-nav a').forEach((link) => {
  link.addEventListener('click', () => {
    if (!link.closest('.nav-dropdown') && nav && toggle) {
      nav.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Menüyü aç');
    }
  });
});

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
    const textParts = ['Merhaba, Dumanlar A.Ş. web sitesinden teklif talebi oluşturmak istiyorum.', '', `Ad Soyad / Firma: ${name}`, `Telefon veya E-posta: ${contact}`];
    if (subject) textParts.push(`Talep Konusu: ${subject}`);
    textParts.push(`Talep Detayı: ${message}`);
    messageInput.value = textParts.join('\n');
  });
});

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

const cookieBanner = document.getElementById('cookie-banner');
const cookieChoiceKey = 'dumanlar_cookie_choice';
if (cookieBanner && !localStorage.getItem(cookieChoiceKey)) cookieBanner.hidden = false;
document.querySelectorAll('[data-cookie-accept], [data-cookie-decline]').forEach((button) => {
  button.addEventListener('click', () => {
    const choice = button.hasAttribute('data-cookie-accept') ? 'accepted' : 'necessary';
    localStorage.setItem(cookieChoiceKey, choice);
    if (cookieBanner) cookieBanner.hidden = true;
  });
});

// Ürün grupları görseli üzerindeki tıklama alanlarını düzeltir.
(() => {
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
      Object.assign(link.style, {
        position: 'absolute',
        left: item.left,
        top: item.top,
        width: item.width,
        height: item.height,
        display: 'block',
        zIndex: '20',
        cursor: 'pointer',
        background: 'rgba(255,255,255,0)',
        pointerEvents: 'auto',
      });
    });
  });
})();

// Product gallery carousel
(() => {
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
})();

// Homepage refresh: lightweight premium layer without touching the accounting panel/storage.
(() => {
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

  const statContent = [
    ['Toptan', 'Satış Kanalı'],
    ['Özel Marka', 'Üretim Desteği'],
    ['Erbaa / Tokat', 'Üretim Merkezi'],
  ];
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
    proof.innerHTML = `
      <article><strong>Toptan Satış Odaklı</strong><span>Mağaza, pazar yeri ve bayi kanallarına uygun ürün grupları.</span></article>
      <article><strong>Özel Marka Üretimi</strong><span>Etiket, ambalaj, desen ve iplik seçeneklerinde esnek planlama.</span></article>
      <article><strong>Numune Süreci</strong><span>Üretim öncesi beklentiyi netleştiren kontrollü hazırlık adımları.</span></article>
      <article><strong>Profesyonel İletişim</strong><span>Teklif ve üretim talepleri için doğrudan WhatsApp iletişimi.</span></article>
    `;
    featureBand.insertAdjacentElement('afterend', proof);
  }

  const productLinks = new Map([
    ['Erkek Çorap', 'erkek-corap.html'],
    ['Kadın Çorap', 'urunler.html#kadin-corap'],
    ['Çocuk Çorap', 'urunler.html#cocuk-corap'],
    ['Spor Çorap', 'urunler.html'],
    ['Havlu & Bambu Çorap', 'bambu-corap.html'],
    ['Özel Marka Üretimi', 'ozel-marka-uretimi.html'],
  ]);

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
})();

// Footer icon fallback: fills empty contact icon circles on pages where SVG was omitted.
(() => {
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
})();

// Kurumsal dropdown menu, anchor sections and corporate page cleanup.
(() => {
  const dropdownItems = [
    ['Hakkımızda', 'kurumsal.html#hakkimizda'],
    ['Tarihçe', 'kurumsal.html#tarihce'],
    ['Üretim Gücü', 'kurumsal.html#uretim-gucu'],
    ['Misyonumuz', 'kurumsal.html#misyonumuz'],
    ['Vizyonumuz', 'kurumsal.html#vizyonumuz'],
    ['Değerlerimiz', 'kurumsal.html#degerlerimiz'],
    ['Kalite Politikası', 'kurumsal.html#kalite-politikasi'],
  ];

  const isMobileMenu = () => window.innerWidth <= 900;

  document.querySelectorAll('.main-nav').forEach((menu) => {
    if (menu.querySelector('.nav-dropdown-corporate')) return;
    const corporateLink = Array.from(menu.querySelectorAll('a')).find((link) => link.getAttribute('href') === 'kurumsal.html');
    if (!corporateLink) return;

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
      if (!isMobileMenu()) return;
      event.preventDefault();
      const nextState = !wrapper.classList.contains('is-open');
      wrapper.classList.toggle('is-open', nextState);
      corporateLink.setAttribute('aria-expanded', String(nextState));
    });

    panel.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        if (!isMobileMenu()) return;
        wrapper.classList.remove('is-open');
        corporateLink.setAttribute('aria-expanded', 'false');
        if (nav) nav.classList.remove('open');
        if (toggle) {
          toggle.setAttribute('aria-expanded', 'false');
          toggle.setAttribute('aria-label', 'Menüyü aç');
        }
      });
    });
  });

  window.addEventListener('resize', () => {
    if (isMobileMenu()) return;
    document.querySelectorAll('.nav-dropdown-corporate.is-open').forEach((dropdown) => {
      dropdown.classList.remove('is-open');
      dropdown.querySelector('a[href="kurumsal.html"]')?.setAttribute('aria-expanded', 'false');
    });
  });

  const infoSection = document.querySelector('.corporate-info-premium');
  if (infoSection) infoSection.remove();

  if (document.body.classList.contains('corporate-page')) {
    const setId = (selector, id) => {
      const element = document.querySelector(selector);
      if (element) element.id = id;
    };
    setId('.corporate-story', 'hakkimizda');
    setId('.corporate-timeline', 'tarihce');
    setId('.corporate-split.section-light', 'uretim-gucu');
    setId('.corporate-values-premium', 'degerlerimiz');
    setId('.corporate-quality', 'kalite-politikasi');
    const valueArticles = document.querySelectorAll('.values-grid article');
    if (valueArticles[0]) valueArticles[0].id = 'misyonumuz';
    if (valueArticles[1]) valueArticles[1].id = 'vizyonumuz';

    if (window.location.hash) {
      setTimeout(() => {
        document.querySelector(window.location.hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 120);
    }
  }

  if (!document.getElementById('corporate-dropdown-styles')) {
    const style = document.createElement('style');
    style.id = 'corporate-dropdown-styles';
    style.textContent = `
      .site-header, .main-nav { overflow: visible !important; }
      .main-nav .nav-dropdown { position: relative; display: flex; align-items: center; }
      .main-nav .nav-dropdown > a { display: inline-flex; align-items: center; gap: 6px; }
      .main-nav .nav-dropdown > a::after { content: '▾'; font-size: 10px; line-height: 1; margin-left: 2px; color: #c99a3f; }
      .nav-dropdown-panel {
        position: absolute;
        left: 50%;
        top: calc(100% + 18px);
        width: 245px;
        padding: 12px;
        background: rgba(7,21,35,.98);
        border: 1px solid rgba(201,154,63,.42);
        border-radius: 18px;
        box-shadow: 0 24px 60px rgba(0,0,0,.28);
        transform: translateX(-50%) translateY(10px);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity .18s ease, transform .18s ease, visibility .18s ease, max-height .25s ease;
        z-index: 9999;
      }
      .nav-dropdown:hover .nav-dropdown-panel,
      .nav-dropdown:focus-within .nav-dropdown-panel,
      .nav-dropdown.is-open .nav-dropdown-panel {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateX(-50%) translateY(0);
      }
      .nav-dropdown-panel a {
        display: block !important;
        padding: 11px 12px !important;
        color: #eef3f8 !important;
        border-radius: 12px;
        font-size: 13px !important;
        font-weight: 800 !important;
        letter-spacing: .02em;
        text-transform: none !important;
        white-space: nowrap;
      }
      .nav-dropdown-panel a:hover,
      .nav-dropdown-panel a:focus {
        background: rgba(201,154,63,.14);
        color: #e1bd68 !important;
      }
      .corporate-info-premium { display: none !important; }
      #hakkimizda, #tarihce, #uretim-gucu, #misyonumuz, #vizyonumuz, #degerlerimiz, #kalite-politikasi { scroll-margin-top: 120px; }
      @media (max-width: 900px) {
        .main-nav.open {
          max-height: calc(100vh - 128px) !important;
          overflow-y: auto !important;
          -webkit-overflow-scrolling: touch !important;
          padding-bottom: 34px !important;
        }
        .main-nav .nav-dropdown { width: 100%; flex-direction: column; align-items: stretch; }
        .main-nav .nav-dropdown > a { width: 100%; justify-content: space-between; }
        .main-nav .nav-dropdown.is-open > a::after { transform: rotate(180deg); }
        .nav-dropdown-panel {
          position: static !important;
          width: 100% !important;
          display: grid !important;
          grid-template-columns: 1fr !important;
          gap: 10px !important;
          max-height: 0 !important;
          margin: 0 !important;
          padding: 0 !important;
          opacity: 0 !important;
          visibility: hidden !important;
          pointer-events: none !important;
          overflow: hidden !important;
          transform: none !important;
          border: 0 !important;
          box-shadow: none !important;
          background: transparent !important;
        }
        .nav-dropdown.is-open .nav-dropdown-panel {
          max-height: 820px !important;
          margin: 14px 0 18px !important;
          padding: 18px !important;
          opacity: 1 !important;
          visibility: visible !important;
          pointer-events: auto !important;
          border: 1px solid rgba(225,189,104,.36) !important;
          border-radius: 24px !important;
          background: rgba(7,21,35,.96) !important;
        }
        .nav-dropdown-panel a {
          min-height: 0 !important;
          padding: 14px 16px !important;
          white-space: normal !important;
          font-size: 15px !important;
        }
        .whatsapp-float {
          right: 18px !important;
          bottom: max(22px, env(safe-area-inset-bottom)) !important;
          width: 68px !important;
          height: 68px !important;
        }
        .site-footer { padding-bottom: 108px !important; }
      }
    `;
    document.head.appendChild(style);
  }
})();

// Corporate page compatibility fixes for uploaded image filenames and KPI visibility.
(() => {
  if (!document.body.classList.contains('corporate-page')) return;

  document.querySelectorAll('img[src$="/fabrikadis.png"], img[src="assets/img/bitkekurumsal/fabrikadis.png"]').forEach((img) => {
    img.src = 'assets/img/bitkekurumsal/fabrikadış.png';
  });

  if (!document.getElementById('corporate-runtime-fixes')) {
    const style = document.createElement('style');
    style.id = 'corporate-runtime-fixes';
    style.textContent = `
      .corporate-kpi-strip article {
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        min-height: 132px !important;
        padding: 24px 18px !important;
      }
      .corporate-kpi-strip strong {
        display: block !important;
        color: #e1bd68 !important;
        font-size: clamp(25px, 2.45vw, 38px) !important;
        letter-spacing: -.04em !important;
        line-height: 1 !important;
        margin: 0 0 10px !important;
      }
      .corporate-kpi-strip span {
        display: block !important;
        color: #d7e0eb !important;
        font-size: 12px !important;
        line-height: 1.55 !important;
        text-transform: uppercase !important;
        letter-spacing: .08em !important;
      }
    `;
    document.head.appendChild(style);
  }
})();
