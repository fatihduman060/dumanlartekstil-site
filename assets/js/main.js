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

  const ensureStylesheet = (href) => {
    if (document.querySelector(`link[href="${href}"]`)) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    document.head.appendChild(link);
  };

  const ensureScript = (src) => {
    if (document.querySelector(`script[src="${src}"]`)) return;
    const script = document.createElement('script');
    script.src = src;
    script.defer = true;
    document.body.appendChild(script);
  };

  const setupPremiumFooter = () => {
    const footer = document.querySelector('.site-footer') || document.createElement('footer');
    footer.className = 'site-footer footer-premium';
    footer.innerHTML = `
      <div class="footer-premium-stage">
        <div class="footer-premium-overlay">
          <section class="footer-premium-col footer-premium-pages" aria-label="Sayfalar">
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

          <section class="footer-premium-col footer-premium-products" aria-label="Üretim alanları">
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

          <section class="footer-premium-contact" aria-label="İletişim">
            <h3>İletişim</h3>
            <ul>
              <li><span class="contact-icon contact-icon-phone"></span><div><b>Telefon</b><a href="tel:+903567158283">0 (356) 715-8283</a></div></li>
              <li><span class="contact-icon contact-icon-whatsapp"></span><div><b>WhatsApp</b><a href="https://wa.me/905321798707">0532 179 87 07</a></div></li>
              <li><span class="contact-icon contact-icon-email"></span><div><b>E-posta</b><a href="mailto:info@dumanlartekstil.com.tr">info@dumanlartekstil.com.tr</a></div></li>
              <li><span class="contact-icon contact-icon-pin"></span><div><b>Adres</b><strong>Organize Sanayi Bölgesi No:8, Erbaa / Tokat</strong></div></li>
            </ul>
            <div class="footer-premium-social" aria-label="Sosyal medya">
              <a href="#" aria-label="LinkedIn">in</a>
              <a href="#" aria-label="Instagram">◎</a>
              <a href="#" aria-label="YouTube">▶</a>
            </div>
          </section>

          <div class="footer-premium-bottom">
            <p>© 2026 Dumanlar A.Ş. Tüm hakları saklıdır.</p>
            <nav aria-label="Yasal bağlantılar">
              <a href="kvkk-aydinlatma-metni.html">KVKK Aydınlatma Metni</a>
              <a href="gizlilik-politikasi.html">Gizlilik Politikası</a>
              <a href="cerez-politikasi.html">Çerez Politikası</a>
            </nav>
          </div>
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

  const setupHomePage = () => {
    if (!document.body.classList.contains('home-page')) return;

    ensureStylesheet('assets/css/bitke-refresh.css');
    ensureStylesheet('assets/css/home-brand-clean-v1.css');
    ensureScript('assets/js/home-brand-clean-v1.js');

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

  ensureStylesheet('assets/css/site-runtime.css');
  setupPremiumFooter();
  setupCorporateDropdown();
  setupMenu();
  setupForms();
  setupReveal();
  setupCookieBanner();
  setupProductHotspots();
  setupGallery();
  setupHomePage();
  setupFooterIcons();
  setupCorporatePage();
  ensureStylesheet('assets/css/responsive-premium-v1.css?v=hero-split-9552b4d');
})();
