/* Repositions the existing creator badge tooltip outside clipping/stacking containers.
   Does not change badge markup, artwork, sizing, rows or page layouts. */
(() => {
  'use strict';
  if (window.__bambodiaBadgeTooltipViewport) return;
  window.__bambodiaBadgeTooltipViewport = true;

  const selector = '.creator-badge-row--tips .creator-badge';
  let activeBadge = null;
  let overlay = null;
  let frame = 0;

  const badgeFor = node => {
    const element = node instanceof Element ? node : node?.parentElement;
    return element?.closest(selector) || null;
  };

  function dismiss() {
    if (frame) cancelAnimationFrame(frame);
    frame = 0;
    overlay?.remove();
    overlay = null;
    activeBadge = null;
  }

  function position() {
    if (!activeBadge?.isConnected || !overlay?.isConnected) {
      dismiss();
      return;
    }
    const anchor = activeBadge.getBoundingClientRect();
    const visual = window.visualViewport;
    const leftEdge = visual ? visual.offsetLeft : 0;
    const topEdge = visual ? visual.offsetTop : 0;
    const screenWidth = visual ? visual.width : document.documentElement.clientWidth;
    const screenHeight = visual ? visual.height : window.innerHeight;
    const rightEdge = leftEdge + screenWidth;
    const bottomEdge = topEdge + screenHeight;

    if (anchor.bottom <= topEdge || anchor.top >= bottomEdge ||
        anchor.right <= leftEdge || anchor.left >= rightEdge) {
      dismiss();
      return;
    }

    // First measure at a neutral position; these measurements include badge text wrapping.
    overlay.style.left = leftEdge + 'px';
    overlay.style.top = topEdge + 'px';
    const popup = overlay.getBoundingClientRect();
    const gap = 9;
    const margin = 12;
    const minX = leftEdge + margin;
    const minY = topEdge + margin;
    const maxX = Math.max(minX, rightEdge - popup.width - margin);
    const maxY = Math.max(minY, bottomEdge - popup.height - margin);
    const center = anchor.left + anchor.width / 2 - popup.width / 2;
    const left = Math.max(minX, Math.min(center, maxX));

    const above = anchor.top - popup.height - gap;
    const below = anchor.bottom + gap;
    let top;
    if (above >= minY) top = above;
    else if (below + popup.height <= bottomEdge - margin) top = below;
    else top = Math.max(minY, Math.min(above, maxY));

    overlay.style.left = left + 'px';
    overlay.style.top = top + 'px';
  }

  function schedulePosition() {
    if (frame || !overlay) return;
    frame = requestAnimationFrame(() => {
      frame = 0;
      position();
    });
  }

  function show(badge) {
    const source = badge.querySelector(':scope > .creator-badge-tooltip');
    if (!source) return; // Honor Badge Manager: tooltips may be disabled.
    if (activeBadge === badge && overlay) return;
    dismiss();

    // Use existing badge text/markup and its existing tooltip appearance.
    overlay = source.cloneNode(true);
    overlay.classList.add('creator-badge-tooltip--viewport');
    overlay.setAttribute('aria-hidden', 'true'); // Badge itself has its full accessible label.
    overlay.style.left = '0px';
    overlay.style.top = '0px';
    document.body.appendChild(overlay);
    activeBadge = badge;
    position();
  }

  document.addEventListener('pointerover', event => {
    if (event.pointerType === 'touch') return;
    const badge = badgeFor(event.target);
    if (badge) show(badge);
  }, true);

  document.addEventListener('pointerout', event => {
    if (!activeBadge) return;
    const badge = badgeFor(event.target);
    if (badge !== activeBadge) return;
    if (event.relatedTarget instanceof Node && activeBadge.contains(event.relatedTarget)) return;
    if (document.activeElement !== activeBadge) dismiss();
  }, true);

  document.addEventListener('focusin', event => {
    const badge = badgeFor(event.target);
    if (badge) show(badge);
  }, true);

  document.addEventListener('focusout', event => {
    if (!activeBadge) return;
    const badge = badgeFor(event.target);
    if (badge !== activeBadge) return;
    if (!activeBadge.matches(':hover')) dismiss();
  }, true);

  document.addEventListener('pointerdown', event => {
    if (activeBadge && !activeBadge.contains(event.target)) dismiss();
  }, true);

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') dismiss();
  }, true);

  document.addEventListener('scroll', schedulePosition, {capture: true, passive: true});
  window.addEventListener('resize', schedulePosition, {passive: true});
  window.visualViewport?.addEventListener('resize', schedulePosition, {passive: true});
  window.visualViewport?.addEventListener('scroll', schedulePosition, {passive: true});
  window.addEventListener('pagehide', dismiss);

  // When JavaScript is active, suppress the original CSS popover to avoid duplicate tips.
  document.documentElement.classList.add('badge-tooltip-viewport-ready');
})();
