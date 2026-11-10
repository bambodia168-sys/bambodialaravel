/* Sync uploaded dark/light favicons to the existing UI theme toggle. */
(() => {
  function update() {
    const icon = document.querySelector('[data-site-theme-icon]');
    if (!icon) return;
    const current = document.documentElement.dataset.theme === 'dark'
      ? icon.dataset.darkHref : icon.dataset.lightHref;
    if (current && icon.getAttribute('href') !== current) icon.setAttribute('href', current);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', update, {once:true});
  else update();
  new MutationObserver(update).observe(document.documentElement, {attributes:true,attributeFilter:['data-theme']});
})();
