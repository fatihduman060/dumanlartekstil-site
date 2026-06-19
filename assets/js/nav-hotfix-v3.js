// Final mobile navigation hotfix.
// Runs after main.js and overrides any older dropdown rule that keeps Kurumsal open on mobile.
(() => {
  const MOBILE_MAX = 900;
  const isMobile = () => window.innerWidth <= MOBILE_MAX;

  const injectStyle = () => {
    document.getElementById('nav-hotfix-v3-style')?.remove();
    const style = document.createElement('style');
    style.id = 'nav-hotfix-v3-style';
    style.textContent = `
      @media (max-width: 900px) {
        html body .site-header .main-nav.open {
          max-height: calc(100vh - 128px) !important;
          overflow-y: auto !important;
          -webkit-overflow-scrolling: touch !important;
          padding-bottom: 34px !important;
        }

        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate {
          display: block !important;
          width: 100% !important;
          padding: 0 !important;
          margin: 0 !important;
        }

        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate > a {
          display: flex !important;
          width: 100% !important;
          align-items: center !important;
          justify-content: space-between !important;
          padding: 28px 0 !important;
        }

        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate > a::after {
          content: "▾" !important;
          display: inline-block !important;
          position: static !important;
          width: auto !important;
          height: auto !important;
          margin-left: 12px !important;
          background: transparent !important;
          color: #c99a3f !important;
          transform: rotate(0deg) !important;
          transition: transform .2s ease !important;
        }

        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate.is-open > a::after {
          transform: rotate(180deg) !important;
        }

        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open) > .nav-dropdown-panel,
        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open):hover > .nav-dropdown-panel,
        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate:not(.is-open):focus-within > .nav-dropdown-panel {
          position: static !important;
          display: block !important;
          width: 100% !important;
          height: 0 !important;
          max-height: 0 !important;
          min-height: 0 !important;
          margin: 0 !important;
          padding: 0 !important;
          border: 0 !important;
          border-radius: 0 !important;
          box-shadow: none !important;
          background: transparent !important;
          opacity: 0 !important;
          visibility: hidden !important;
          pointer-events: none !important;
          overflow: hidden !important;
          transform: none !important;
        }

        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate.is-open > .nav-dropdown-panel {
          position: static !important;
          display: grid !important;
          grid-template-columns: 1fr !important;
          gap: 10px !important;
          width: 100% !important;
          height: auto !important;
          max-height: 820px !important;
          min-height: 0 !important;
          margin: 12px 0 18px !important;
          padding: 18px !important;
          opacity: 1 !important;
          visibility: visible !important;
          pointer-events: auto !important;
          overflow: hidden !important;
          transform: none !important;
          border: 1px solid rgba(225,189,104,.36) !important;
          border-radius: 24px !important;
          background: rgba(7,21,35,.96) !important;
          box-shadow: none !important;
        }

        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel::before,
        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel::after {
          display: none !important;
          content: none !important;
        }

        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel a,
        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel a:hover,
        html body .site-header .main-nav .nav-dropdown.nav-dropdown-corporate > .nav-dropdown-panel a:focus {
          display: block !important;
          min-height: 0 !important;
          padding: 14px 16px !important;
          white-space: normal !important;
          font-size: 15px !important;
          transform: none !important;
        }

        html body .whatsapp-float {
          right: 18px !important;
          bottom: max(22px, env(safe-area-inset-bottom)) !important;
          width: 68px !important;
          height: 68px !important;
        }

        html body .site-footer {
          padding-bottom: 108px !important;
        }
      }
    `;
    document.head.appendChild(style);
  };

  const closeCorporate = () => {
    document.querySelectorAll('.nav-dropdown-corporate.is-open').forEach((dropdown) => {
      dropdown.classList.remove('is-open');
      dropdown.querySelector('a[href="kurumsal.html"]')?.setAttribute('aria-expanded', 'false');
    });
  };

  const wireDropdown = () => {
    const dropdown = document.querySelector('.nav-dropdown-corporate');
    const corporateLink = dropdown?.querySelector('a[href="kurumsal.html"]');
    const panel = dropdown?.querySelector('.nav-dropdown-panel');
    const nav = document.querySelector('.main-nav');
    const menuToggle = document.querySelector('.menu-toggle');

    if (!dropdown || !corporateLink || !panel) return false;
    if (dropdown.dataset.hotfixV3 === '1') return true;

    dropdown.dataset.hotfixV3 = '1';
    closeCorporate();
    corporateLink.setAttribute('aria-haspopup', 'true');
    corporateLink.setAttribute('aria-expanded', 'false');

    corporateLink.addEventListener('click', (event) => {
      if (!isMobile()) return;
      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();
      const open = !dropdown.classList.contains('is-open');
      dropdown.classList.toggle('is-open', open);
      corporateLink.setAttribute('aria-expanded', String(open));
    }, true);

    panel.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', () => {
        if (!isMobile()) return;
        closeCorporate();
        nav?.classList.remove('open');
        menuToggle?.setAttribute('aria-expanded', 'false');
        menuToggle?.setAttribute('aria-label', 'Menüyü aç');
      });
    });

    menuToggle?.addEventListener('click', () => {
      setTimeout(() => {
        if (isMobile() && nav?.classList.contains('open')) closeCorporate();
      }, 0);
    });

    return true;
  };

  const init = () => {
    injectStyle();
    let attempts = 0;
    const timer = setInterval(() => {
      attempts += 1;
      if (wireDropdown() || attempts > 30) clearInterval(timer);
    }, 80);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();

  window.addEventListener('resize', () => {
    injectStyle();
    if (!isMobile()) closeCorporate();
  });
})();
