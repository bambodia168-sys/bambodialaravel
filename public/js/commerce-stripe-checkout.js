const stripeCheckout = document.querySelector('[data-stripe-checkout]');

if (stripeCheckout) {
    const status = stripeCheckout.querySelector('[data-stripe-checkout-status]');
    const appearance = {
        theme: 'flat',
        labels: 'auto',
        inputs: 'spaced',
        variables: {
            borderRadius: '10px',
            colorBackground: '#ffffff',
            colorDanger: '#df1b41',
            colorPrimary: '#0570de',
            colorSuccess: '#00c853',
            colorText: '#30313d',
            fontFamily: 'Be Vietnam Pro',
            fontSizeBase: '16px',
            spacingUnit: '5px',
        },
    };

    for (const startForm of document.querySelectorAll('[data-stripe-checkout-start]')) {
        const button = startForm.querySelector('button[type="submit"], button:not([type])');
        startForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (button.disabled) return;
            button.disabled = true;
            stripeCheckout.hidden = false;
            status.textContent = 'Loading Stripe Checkout…';

            try {
                const stripe = window.Stripe?.(stripeCheckout.dataset.publishableKey);
                if (!stripe?.initCheckoutFormSdk) throw new Error('Stripe Checkout could not load. Try again.');

                const response = await fetch(startForm.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    body: new FormData(startForm),
                });
                if (!response.ok) throw new Error('Unable to open Stripe Checkout. Reload the order and try again.');
                const session = await response.json();
                if (session.redirect_url) {
                    window.location.assign(session.redirect_url);
                    return;
                }

                const checkout = stripe.initCheckoutFormSdk({ clientSecret: session.client_secret, appearance });
                const result = await checkout.loadActions();
                if (result.type !== 'success') throw new Error(result.error.message);
                const form = checkout.createForm({ layout: 'expanded' });
                let confirming = false;
                form.on('confirm', async (formConfirmEvent) => {
                    if (confirming) return;
                    confirming = true;
                    status.textContent = 'Confirming payment…';
                    try {
                        const confirmation = await result.actions.confirm({ formConfirmEvent });
                        if (confirmation?.type === 'error') throw new Error(confirmation.error.message);
                        window.location.assign(session.order_url);
                    } catch (error) {
                        status.textContent = error.message || 'Payment could not be confirmed. Try again.';
                    } finally {
                        confirming = false;
                    }
                });
                form.mount('#checkout-form');
                status.textContent = '';
                stripeCheckout.scrollIntoView({ block: 'nearest' });
            } catch (error) {
                status.textContent = error.message || 'Unable to open Stripe Checkout. Try again.';
                button.disabled = false;
            }
        });
    }
}
