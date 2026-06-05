const toggle = document.querySelector('.menu-toggle');
const nav = document.querySelector('.main-nav');

if (toggle && nav) {
  toggle.addEventListener('click', () => {
    const isOpen = nav.classList.toggle('open');
    toggle.setAttribute('aria-expanded', String(isOpen));
    toggle.setAttribute('aria-label', isOpen ? 'Menüyü kapat' : 'Menüyü aç');
  });
  nav.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      nav.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      toggle.setAttribute('aria-label', 'Menüyü aç');
    });
  });
}

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
    const text = textParts.join('\n');
    messageInput.value = text;
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


// V44 product gallery carousel
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
