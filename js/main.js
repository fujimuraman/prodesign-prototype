/* ============================================
   ProDesign - Premium BtoB
============================================ */

// ===== Header scroll effect =====
const header = document.getElementById('header');
const onScroll = () => {
  if (window.scrollY > 30) header.classList.add('is-scrolled');
  else header.classList.remove('is-scrolled');
};
window.addEventListener('scroll', onScroll);
onScroll();

// ===== Hero slideshow =====
const slides = document.querySelectorAll('.hero__slide');
let current = 0;
if (slides.length > 1) {
  setInterval(() => {
    slides[current].classList.remove('hero__slide--active');
    current = (current + 1) % slides.length;
    slides[current].classList.add('hero__slide--active');
  }, 5000);
}

// ===== Scroll reveal (IntersectionObserver) =====
const targets = document.querySelectorAll(
  '.section__title, .section__label, .section__lead, .intro__text, ' +
  '.service, .strength__item, .news__item, .cta__title, .cta__lead, .cta__button, ' +
  '.biz-block, .work-card, .history__item, .message, .company-info, ' +
  '.contact-info__item, .contact-form .form-row'
);
targets.forEach(el => el.classList.add('reveal'));

const io = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('is-visible');
      io.unobserve(entry.target);
    }
  });
}, { threshold: 0, rootMargin: '0px 0px 200px 0px' });

targets.forEach(el => io.observe(el));

// Fallback: scroll でも反応するように
const revealOnScroll = () => {
  document.querySelectorAll('.reveal:not(.is-visible)').forEach(el => {
    const r = el.getBoundingClientRect();
    if (r.top < window.innerHeight + 200 && r.bottom > -200) {
      el.classList.add('is-visible');
    }
  });
};
window.addEventListener('scroll', revealOnScroll, { passive: true });
window.addEventListener('load', () => setTimeout(revealOnScroll, 50));
// 初回も即実行
setTimeout(revealOnScroll, 100);

// ===== Smooth scroll for in-page anchors =====
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', (e) => {
    const target = document.querySelector(a.getAttribute('href'));
    if (target) {
      e.preventDefault();
      const offset = 80;
      const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
      window.scrollTo({ top, behavior: 'smooth' });
    }
  });
});

// ===== Hamburger (placeholder for mobile menu) =====
const hamburger = document.getElementById('hamburger');
if (hamburger) {
  hamburger.addEventListener('click', () => {
    alert('モバイルメニューは次フェーズで実装予定です。');
  });
}
