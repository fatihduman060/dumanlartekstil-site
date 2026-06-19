// Kurumsal dropdown behavior patch: desktop hover, mobile tap-to-toggle.
(() => {
  const MOBILE_BREAKPOINT = 900;

  const getDropdown = () => document.querySelector('.nav-dropdown-corporate');
  const getCorporateLink = () => document.querySelector('.nav-dropdown-corporate > a[href="kurumsal.html"]');

  const isMobile = () => window.innerWidth <= MOBILE_BREAKPOINT;

  const closeDropdown = () => {
    const dropdown = getDropdown();
    const link = getCorporateLink();
    if (!dropdown) return;
    dropdown.classList.remove('is-open');
    if (link) link.setAttribute('aria-expanded', 'false');
  };

  const openDropdown = () => {
    const dropdown = getDropdown();
    const link = getCorporateLink();
    if (!dropdown) return;
    dropdown.classList.add('is-open');
    if (link) link.setAttribute('aria-expanded', 'true');
  };

  const toggleDropdown = () => {
    const dropdown = getDropdown();
    if (!dropdown) return;
    if (dropdown.classList.contains('is-open')) closeDropdown();
    else openDropdown();
  };

  const style = document.createElement('style');
  style.id = 'corporate-nav-mobile-fix';
  style.textContent = `
    @media (min-width: 901px) {
      .nav-dropdown-corporate:hover .nav-dropdown-panel,
      .nav-dropdown-corporate:focus-within .nav-dropdown-panel {
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
        transform: translateX(-50%) translateY(0) scale(1) !important;
      }
    }

    @media (max-width: 900px) {
      .main-nav .nav-dropdown-corporate {
        width: 100% !important;
        display: block !important;
        padding-bottom: 0 !important;
        margin-bottom: 0 !important;
      }

      .main-nav .nav-dropdown-corporate > a {
        width: 100% !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
      }

      .main-nav .nav-dropdown-corporate > a::after {
        content: '▾' !important;
        margin-left: 12px !important;
        transition: transform .2s ease !important;
      }

      .main-nav .nav-dropdown-corporate.is-open > a::after {
        transform: rotate(180deg) !important;
      }

      .main-nav .nav-dropdown-corporate .nav-dropdown-panel {
        position: static !important;
        width: 100% !important;
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 10px !important;
        margin: 0 !important;
        padding: 0 !important;
        max-height: 0 !important;
        opacity: 0 !important;
        visibility: hidden !important;
        pointer-events: none !important;
        overflow: hidden !important;
        transform: none !important;
        border: 0 !important;
        box-shadow: none !important;
        background: transparent !important;
        transition: max-height .28s ease, opacity .2s ease, padding .2s ease, margin .2s ease !important;
      }

      .main-nav .nav-dropdown-corporate.is-open .nav-dropdown-panel {
        max-height: 820px !important;
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: auto !important;
        margin: 14px 0 18px !important;
        padding: 18px !important;
        border: 1px solid rgba(225,189,104,.36) !important;
        border-radius: 24px !important;
        background: rgba(7,21,35,.96) !important;
      }

      .main-nav .nav-dropdown-corporate .nav-dropdown-panel::after {
        display: none !important;
      }

      .main-nav .nav-dropdown-corporate .nav-dropdown-panel a {
        min-height: 0 !important;
        padding: 14px 16px !important;
        border-radius: 14px !important;
        font-size: 15px !important;
      }

      .main-nav.open {
        max-height: calc(100vh - 128px) !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
        padding-bottom: 34px !important;
      }

      .whatsapp-float {
        right: 18px !important;
        bottom: max(22px, env(safe-area-inset-bottom)) !important;
        width: 68px !important;
        height: 68px !important;
      }

      .site-footer {
        padding-bottom: 108px !important;
      }
    }
  `;
  document.head.appendChild(style);

  const init = () => {
    const dropdown = getDropdown();
    const corporateLink = getCorporateLink();
    if (!dropdown || !corporateLink || dropdown.dataset.mobileFixed === '1') return;

    dropdown.dataset.mobileFixed = '1';
    corporateLink.setAttribute('aria-haspopup', 'true');
    corporateLink.setAttribute('aria-expanded', dropdown.classList.contains('is-open') ? 'true' : 'false');

    corporateLink.addEventListener('click', (event) => {
      if (!isMobile()) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      toggleDropdown();
    }, true);

    dropdown.querySelectorAll('.nav-dropdown-panel a').forEach((link) => {
      link.addEventListener('click', () => {
        if (!isMobile()) return;
        closeDropdown();
        const nav = document.querySelector('.main-nav');
        const menuButton = document.querySelector('.menu-toggle');
        if (nav) nav.classList.remove('open');
        if (menuButton) {
          menuButton.setAttribute('aria-expanded', 'false');
          menuButton.setAttribute('aria-label', 'Menüyü aç');
        }
      });
    });

    window.addEventListener('resize', () => {
      if (!isMobile()) closeDropdown();
    });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
