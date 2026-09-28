/* Presentation only; coupon form behavior remains in discount.js. */
(() => {
  const header = document.querySelector('.site-header');
  const float = document.querySelector('.coupon-float');
  const footer = document.querySelector('.legal-footer');
  const closingStage = document.querySelector('.closing-stage');
  if (!header || !float) return;

  const setHeader = isScrolled => header.classList.toggle('is-scrolled', isScrolled);
  let visualFrame = 0;
  const updateScrollVisuals = () => {
    visualFrame = 0;
    setHeader(window.scrollY > 12);
    const maxScroll = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    document.documentElement.style.setProperty('--page-progress', String(Math.min(1, Math.max(0, window.scrollY / maxScroll))));
    if (footer) {
      const visibleFooter = Math.max(0, window.innerHeight - Math.max(0, footer.getBoundingClientRect().top));
      const restingOffset = window.innerWidth <= 700 ? 14 : 18;
      let clearance = visibleFooter ? visibleFooter + 12 : restingOffset;
      if (visibleFooter && closingStage && window.innerWidth <= 700) {
        const stage = closingStage.getBoundingClientRect();
        if (stage.top >= 0 && stage.top < window.innerHeight) {
          const skyBottom = stage.top + Math.min(102, stage.height * .33);
          clearance = Math.max(clearance, window.innerHeight - skyBottom);
        }
      }
      float.style.setProperty('--coupon-clearance', `${Math.round(clearance)}px`);
    }
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
