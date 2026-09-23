/* Presentation only; coupon form behavior remains in discount.js. */
(() => {
  const header = document.querySelector('.site-header');
  const float = document.querySelector('.coupon-float');
  const heroAction = document.querySelector('.hero [data-discount-open]');
  const closingAction = document.querySelector('.closing [data-discount-open]');
  if (!header || !float || !heroAction || !closingAction) return;

  const setHeader = isScrolled => header.classList.toggle('is-scrolled', isScrolled);
  const inViewport = element => {
    const box = element.getBoundingClientRect();
    return box.bottom > 0 && box.top < window.innerHeight;
  };

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

  if (!('IntersectionObserver' in window)) {
    const update = () => {
      float.classList.toggle('is-visible', !inViewport(heroAction) && !inViewport(closingAction));
    };
    document.documentElement.classList.add('coupon-enhanced');
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
    return;
  }

  document.documentElement.classList.add('coupon-enhanced');
  let heroVisible = inViewport(heroAction);
  let closingVisible = inViewport(closingAction);
  const updateFloat = () => float.classList.toggle('is-visible', !heroVisible && !closingVisible);
  const actionObserver = new IntersectionObserver(entries => {
    for (const entry of entries) {
      if (entry.target === heroAction) heroVisible = entry.isIntersecting;
      if (entry.target === closingAction) closingVisible = entry.isIntersecting;
    }
    updateFloat();
  }, { threshold: 0 });
  actionObserver.observe(heroAction);
  actionObserver.observe(closingAction);
  updateFloat();
})();
