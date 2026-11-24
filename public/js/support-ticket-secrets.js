/* Ticket secrets: no caching, DOM storage, logging or automatic revelation. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-revealed-vault]');
    const error = document.querySelector('[data-secret-error]');
    let clearTimer = null;
    const clear = () => {
      if (clearTimer) window.clearTimeout(clearTimer);
      clearTimer = null;
      if (!root) return;
      root.hidden = true;
      root.querySelectorAll('[data-secret-value]').forEach(input => {
        input.value = '';
        input.closest('[data-secret-row]').hidden = true;
      });
    };
    const errorMessage = message => {
      if (!error) return;
      error.textContent = message;
      error.hidden = false;
    };
    document.querySelectorAll('[data-open-secure-fields]').forEach(button => {
      button.addEventListener('click', () => {
        const panel = document.querySelector('[data-ticket-secret-panel]');
        if (!panel) return;
        panel.hidden = false;
        panel.scrollIntoView({behavior:'smooth',block:'center'});
        panel.querySelector('input[name="credential_label"]')?.focus({preventScroll:true});
      });
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
              ? 'This credential is available only to its assigned support agent or authorized administrator.'
              : 'This temporary access share is unavailable or expired.');
            return;
          }
          const payload = await response.json();
          const values = payload.details || {};
          root.querySelectorAll('[data-secret-value]').forEach(input => {
            const value = String(values[input.dataset.secretValue] || '');
            input.value = value;
            input.closest('[data-secret-row]').hidden = value === '';
          });
          root.hidden = false;
          root.scrollIntoView({behavior:'smooth',block:'nearest'});
          clearTimer = window.setTimeout(clear, 30000);
        } catch {
          errorMessage('Unable to reveal credentials securely. Retry after confirming your access.');
        } finally {
          button.disabled = false;
        }
      });
    });
    root?.querySelector('[data-hide-revealed]')?.addEventListener('click', clear);
    root?.querySelectorAll('[data-secret-copy]').forEach(button => {
      button.addEventListener('click', async () => {
        const field = root.querySelector('[data-secret-value="' + button.dataset.secretCopy + '"]');
        if (!field || !field.value) return;
        try {
          await navigator.clipboard.writeText(field.value);
          const prior = button.textContent;
          button.textContent = 'Copied';
          window.setTimeout(() => { button.textContent = prior; }, 1200);
        } catch {
          errorMessage('Clipboard not available. Select and copy the field manually.');
        }
      });
    });
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) clear();
    });
    window.addEventListener('pagehide', clear);
  });
})();
