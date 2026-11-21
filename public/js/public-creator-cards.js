document.querySelectorAll('[data-creator-badges]').forEach((badges) => {
  const rail = badges.querySelector('.public-creator-badge-rail');
  const track = badges.querySelector('.public-creator-badge-track');
  const row = track.firstElementChild;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const copy = row.cloneNode(true);

  copy.setAttribute('data-creator-badge-copy', '');
  copy.setAttribute('aria-hidden', 'true');
  copy.inert = true;
  copy.querySelectorAll('.creator-badge-tooltip').forEach((tooltip) => tooltip.remove());
  track.append(copy);
  badges.setAttribute('data-ready', '');

  const update = () => {
    const width = row.getBoundingClientRect().width - parseFloat(getComputedStyle(row).paddingInlineEnd);
    const overflowing = !reducedMotion.matches && width > badges.clientWidth + 1;
    badges.toggleAttribute('data-overflowing', overflowing);
    track.style.setProperty('--badge-duration', `${Math.max(12, width / 24)}s`);
  };

  new ResizeObserver(update).observe(badges);
  reducedMotion.addEventListener('change', update);

  rail.addEventListener('focusin', (event) => {
    event.target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  });

  rail.addEventListener('focusout', (event) => {
    if (!rail.contains(event.relatedTarget)) rail.scrollLeft = 0;
  });
});

document.querySelectorAll('[data-creator-location]').forEach((location) => {
  const trigger = location.querySelector('button');
  const detail = location.querySelector('[role="tooltip"]');
  const show = (visible) => {
    detail.hidden = !visible;
    trigger.setAttribute('aria-expanded', String(visible));
  };

  location.addEventListener('pointerenter', (event) => {
    if (event.pointerType !== 'touch') show(true);
  });
  location.addEventListener('pointerleave', (event) => {
    if (event.pointerType !== 'touch' && !trigger.matches(':focus-visible')) show(false);
  });
  trigger.addEventListener('focus', () => {
    if (trigger.matches(':focus-visible')) show(true);
  });
  trigger.addEventListener('blur', () => {
    if (!location.matches(':hover')) show(false);
  });
  trigger.addEventListener('click', (event) => {
    show(event.pointerType === 'mouse' || detail.hidden);
  });
  document.addEventListener('pointerdown', (event) => {
    if (!detail.hidden && !location.contains(event.target)) show(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !detail.hidden) show(false);
  });
});
