const controls = document.querySelector('[data-public-content-controls]');
if (controls) {
  const enabled = new Set(controls.dataset.publicContentControls.split(' '));
  const withinContent = element => element instanceof Element
    && element.closest('[data-protected-content]')
    && !element.closest('input, textarea, select, button, label, [contenteditable]:not([contenteditable="false"]), [data-allow-selection]');
  const stop = (event, checkSelection = false) => {
    const selected = checkSelection ? document.getSelection()?.anchorNode : null;
    if (withinContent(selected?.parentElement) || withinContent(event.target)) {
      event.preventDefault();
    }
  };

  if (enabled.has('right_click')) document.addEventListener('contextmenu', stop);
  if (enabled.has('content_protector')) {
    document.addEventListener('copy', event => stop(event, true));
    document.addEventListener('dragstart', stop);
  }
  if (enabled.has('selection_protector')) document.addEventListener('selectstart', stop);
}
