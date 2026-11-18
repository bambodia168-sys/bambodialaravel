const paymentForm = document.querySelector('[data-order-payment-poll]');

if (paymentForm) {
    const paymentStatus = document.querySelector('[data-order-payment-status]');
    let timer;
    let checking = false;

    async function checkPayment() {
        if (checking) return;
        clearTimeout(timer);
        checking = true;
        let delay = 7000;
        try {
            const response = await fetch(paymentForm.action, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new FormData(paymentForm),
                signal: AbortSignal.timeout(12000),
            });

            if ([401, 403, 419].includes(response.status)) {
                window.location.reload();
                return;
            }
            if (!response.ok) throw new Error('Payment status unavailable');

            const payment = await response.json();
            if (payment.status === 'paid' || payment.status === 'expired') {
                window.location.reload();
                return;
            }
            if (!payment.qr_visible) {
                document.querySelector('[data-order-payment-qr]')?.remove();
                document.querySelector('[data-payment-qr-hint]')?.remove();
            }
            if (payment.collection_paused) {
                paymentStatus.textContent = 'New payments are paused. Checking for a final payment result.';
            } else {
                paymentStatus.textContent = payment.qr_visible
                    ? 'Waiting for your bank to confirm payment.'
                    : 'This QR has expired. Checking for a final payment result.';
            }
            if (!payment.qr_visible) delay = 30000;
        } catch {
            paymentStatus.textContent = 'Payment status is temporarily unavailable. The page will check again.';
            delay = 30000;
        }
        checking = false;
        timer = setTimeout(checkPayment, delay);
    }

    paymentForm.addEventListener('submit', (event) => {
        event.preventDefault();
        checkPayment();
    });
    timer = setTimeout(checkPayment, 5000);
}
