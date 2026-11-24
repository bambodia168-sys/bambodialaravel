/* Temporary support access: explicit reveal only, no persistence, 30-second clear. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-revealed-vault]');
    const error = document.querySelector('[data-secret-error]');
    const countdown = root?.querySelector('[data-reveal-countdown]');
    let clearTimer = null;
    let countdownTimer = null;
    const resetTimers = () => {
      if (clearTimer) window.clearTimeout(clearTimer);
      if (countdownTimer) window.clearInterval(countdownTimer);
      clearTimer = null;
      countdownTimer = null;
    };
    const clear = () => {
      resetTimers();
      if (!root) return;
      root.hidden = true;
      root.querySelectorAll('[data-secret-value]').forEach(input => {
        input.value = '';
        const privateField = input.dataset.secretValue === 'password' || input.dataset.secretValue === 'key';
        input.type = privateField ? 'password' : 'text';
        input.closest('[data-secret-row]').hidden = true;
      });
      root.querySelectorAll('[data-secret-visibility]').forEach(button => {
        button.textContent = 'Show';
        button.setAttribute('aria-pressed', 'false');
      });
      root.querySelectorAll('[data-copy-label]').forEach(label => { label.textContent = 'Copy'; });
      const title = root.querySelector('[data-reveal-title]');
      if (title) title.textContent = 'Temporary access details';
      if (countdown) countdown.textContent = '30s';
    };
    const errorMessage = message => {
      if (!error) return;
      error.textContent = message;
      error.hidden = false;
    };
    const openSecurePanel = () => {
      const panel = document.querySelector('[data-ticket-secret-panel]');
      if (!panel) return;
      panel.hidden = false;
      const section = panel.closest('.ticket-secure-share-section') || panel;
      section.querySelector('[data-secure-submit]')?.removeAttribute('hidden');
      section.scrollIntoView({behavior:'smooth',block:'start'});
      panel.querySelector('input[name="credential_label"]')?.focus({preventScroll:true});
    };
    document.querySelectorAll('[data-open-secure-fields]').forEach(button => {
      button.addEventListener('click', openSecurePanel);
    });

    document.querySelectorAll('[data-credential-reveal]').forEach(button => {
      button.addEventListener('click', async () => {
        if (!root) return;
        clear();
        if (error) error.hidden = true;
        button.disabled = true;
        try {
          const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
          const response = await fetch(button.dataset.credentialReveal, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'error',
            cache: 'no-store',
            headers: {
              'Accept': 'application/json',
              'X-CSRF-TOKEN': token,
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          if (!response.ok) {
            errorMessage(response.status === 403
              ? 'Only the assigned support agent or an authorized administrator can reveal this share.'
              : 'This temporary access share is unavailable, expired or revoked.');
            return;
          }
          const payload = await response.json();
          const values = payload.details || {};
          root.querySelectorAll('[data-secret-value]').forEach(input => {
            const value = String(values[input.dataset.secretValue] || '');
            const row = input.closest('[data-secret-row]');
            input.value = value;
            row.hidden = value === '';
          });
          const title = root.querySelector('[data-reveal-title]');
          if (title) title.textContent = button.dataset.revealLabel || 'Temporary access details';
          root.hidden = false;
          root.scrollIntoView({behavior:'smooth',block:'start'});
          let seconds = 30;
          if (countdown) countdown.textContent = seconds + 's';
          countdownTimer = window.setInterval(() => {
            seconds = Math.max(0, seconds - 1);
            if (countdown) countdown.textContent = seconds + 's';
          }, 1000);
          clearTimer = window.setTimeout(clear, 30000);
        } catch {
          errorMessage('Unable to reveal credentials securely. Check your access and retry.');
        } finally {
          button.disabled = false;
        }
      });
    });
    root?.querySelector('[data-hide-revealed]')?.addEventListener('click', clear);
    root?.querySelectorAll('[data-secret-visibility]').forEach(button => {
      button.addEventListener('click', () => {
        const field = root.querySelector('[data-secret-value="' + button.dataset.secretVisibility + '"]');
        if (!field || root.hidden || field.closest('[data-secret-row]').hidden) return;
        const show = field.type === 'password';
        field.type = show ? 'text' : 'password';
        button.textContent = show ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', (show ? 'Hide ' : 'Show ') + button.dataset.secretVisibility);
      });
    });
    root?.querySelectorAll('[data-secret-copy]').forEach(button => {
      button.addEventListener('click', async () => {
        const field = root.querySelector('[data-secret-value="' + button.dataset.secretCopy + '"]');
        if (!field || root.hidden || !field.value) return;
        try {
          await navigator.clipboard.writeText(field.value);
          const label = button.querySelector('[data-copy-label]');
          if (!label) return;
          label.textContent = 'Copied';
          window.setTimeout(() => {
            if (label.isConnected) label.textContent = 'Copy';
          }, 1200);
        } catch {
          errorMessage('Clipboard access is unavailable. Select and copy the value manually.');
        }
      });
    });
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) clear();
    });
    window.addEventListener('pagehide', clear);
  });
})();
