document.querySelectorAll('[data-creator-badges]').forEach((badges) => {
  const rail = badges.querySelector('.public-creator-badge-rail');
  const track = badges.querySelector('.public-creator-badge-track');
  const row = track.firstElementChild;
  const toggle = badges.querySelector('.public-creator-badge-toggle');
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
    toggle.hidden = !overflowing;
    track.style.setProperty('--badge-duration', `${Math.max(12, width / 24)}s`);
  };

  new ResizeObserver(update).observe(badges);
  reducedMotion.addEventListener('change', update);

  toggle.addEventListener('click', () => {
    const paused = badges.toggleAttribute('data-paused');
    toggle.setAttribute('aria-pressed', String(paused));
    if (!paused && !rail.contains(document.activeElement)) rail.scrollLeft = 0;
  });

  rail.addEventListener('focusin', (event) => {
    event.target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  });

  rail.addEventListener('focusout', (event) => {
    if (!badges.hasAttribute('data-paused') && !rail.contains(event.relatedTarget)) rail.scrollLeft = 0;
  });
});
