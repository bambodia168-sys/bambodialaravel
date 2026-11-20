const paymentMethods = document.querySelector('[data-checkout-payment-methods]');
if (paymentMethods) {
    document.querySelectorAll('form[data-checkout-payment]').forEach((form) => {
        form.addEventListener('formdata', ({ formData }) => {
            const selected = paymentMethods.querySelector('input:checked');
            if (selected) {
                formData.set('payment_method', selected.value);
            }
        });
    });
}
