/* Presentation only; coupon form behavior remains in discount.js. */
(() => {
  const header = document.querySelector('.site-header');
  const float = document.querySelector('.coupon-float');
  if (!header || !float) return;

  const setHeader = isScrolled => header.classList.toggle('is-scrolled', isScrolled);
  let visualFrame = 0;
  const updateScrollVisuals = () => {
    visualFrame = 0;
    setHeader(window.scrollY > 12);
    const maxScroll = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    document.documentElement.style.setProperty('--page-progress', String(Math.min(1, Math.max(0, window.scrollY / maxScroll))));
  };
  const scheduleScrollVisuals = () => {
    if (!visualFrame) visualFrame = requestAnimationFrame(updateScrollVisuals);
  };
  window.addEventListener('scroll', scheduleScrollVisuals, { passive: true });
  window.addEventListener('resize', scheduleScrollVisuals);
  window.addEventListener('load', scheduleScrollVisuals, { once: true });
  scheduleScrollVisuals();

  document.documentElement.classList.add('coupon-enhanced');
  float.classList.add('is-visible');
})();
