/* Buyer-only Baray order status updates.
 * Never marks an order paid: server reports only bank-confirmed webhook state.
 * No Baray secret, API request, QR payload or card data is handled here.
 */
(() => {
    const panel = document.querySelector('[data-baray-payment]');
    if (!panel) return;

    const endpoint = panel.getAttribute('data-baray-status-url');
    const fallback = panel.getAttribute('data-baray-order-url');
    const statusText = panel.querySelector('[data-baray-status-text]');
    const refreshButton = panel.querySelector('[data-baray-check-now]');
    if (!endpoint || !fallback || !statusText) return;

    let timer = null;
    let running = false;
    let finished = false;
    let nextDelay = 9000;

    const schedule = (delay = nextDelay) => {
        clearTimeout(timer);
        if (!finished && !document.hidden) {
            timer = window.setTimeout(checkStatus, delay);
        }
    };

    const checkStatus = async () => {
        if (running || finished || document.hidden) return;
        running = true;
        clearTimeout(timer);

        try {
            const response = await fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
                signal: AbortSignal.timeout(12000),
            });

            if ([401, 403, 404, 419].includes(response.status)) {
                finished = true;
                statusText.textContent = 'Your session needs refreshing. Sign in again to view your order.';
                refreshButton?.setAttribute('disabled', 'disabled');
                return;
            }
            if (!response.ok) throw new Error('Payment status unavailable');
            const state = await response.json();

            if (state.confirmed === true && state.payment_status === 'paid'
                && state.order_status === 'completed') {
                finished = true;
                statusText.textContent = 'Your payment is confirmed. Opening your receipt and order details…';
                window.location.assign(fallback);
                return;
            }
            if (['expired', 'failed', 'rejected', 'paid'].includes(state.payment_status)
                || state.order_status !== 'awaiting_payment') {
                finished = true;
                statusText.textContent = 'Payment status changed. Refreshing your order details…';
                window.location.assign(fallback);
                return;
            }

            statusText.textContent = 'Waiting for Baray to confirm your payment with the bank. You can keep this page open.';
            nextDelay = 9000;
        } catch {
            statusText.textContent = 'Payment status is temporarily unavailable. We will check again; please do not pay twice.';
            nextDelay = 25000;
        } finally {
            running = false;
            schedule();
        }
    };

    refreshButton?.addEventListener('click', () => {
        if (!finished) checkStatus();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearTimeout(timer);
        } else if (!finished) {
            schedule(500);
        }
    });
    window.addEventListener('focus', () => {
        if (!finished && !document.hidden) schedule(500);
    });
    window.addEventListener('pagehide', () => {
        finished = true;
        clearTimeout(timer);
    });
    schedule(4000);
})();
