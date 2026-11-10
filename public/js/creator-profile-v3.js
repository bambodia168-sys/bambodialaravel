/* Small progressive enhancements for the public profile and profile editor. */
(() => {
  function initNames() {
    document.querySelectorAll('[data-profile-name-viewport]').forEach(view => {
      const name = view.querySelector('[data-profile-scroll-name]');
      if (!name) return;
      const distance = Math.ceil(name.scrollWidth - view.clientWidth);
      view.classList.toggle('is-overflowing', distance > 3);
      view.style.setProperty('--profile-name-travel', Math.max(0, distance) + 'px');
    });
  }
  document.addEventListener('DOMContentLoaded', () => {
    initNames();
    window.addEventListener('resize', initNames, {passive:true});
  });
  let toastTimeout;
  function feedback(message) {
    let toast = document.querySelector('.profile-v3-copy-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'profile-v3-copy-toast';
      toast.setAttribute('role', 'status');
      document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => { toast.hidden = true; }, 5000);
  }
  document.addEventListener('click', async event => {
    const copy = event.target.closest('[data-profile-copy]');
    if (copy) {
      const id = copy.getAttribute('data-profile-copy');
      try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard API unavailable');
        await navigator.clipboard.writeText(id);
        feedback('User ID copied');
      } catch {
        feedback('Clipboard unavailable. Select the ID to copy it manually.');
      }
      return;
    }
    const open = event.target.closest('[data-profile-dialog-open]');
    if (open) {
      const dialog = document.getElementById(open.getAttribute('data-profile-dialog-open'));
      if (dialog instanceof HTMLDialogElement) dialog.showModal();
      return;
    }
    const close = event.target.closest('[data-profile-dialog-close]');
    if (close) {
      const dialog = close.closest('dialog');
      if (dialog instanceof HTMLDialogElement) dialog.close();
      return;
    }
    if (event.target instanceof HTMLDialogElement && event.target.classList.contains('profile-v3-dialog')) {
      const box = event.target.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
        event.target.close();
      }
    }
  });
})();
