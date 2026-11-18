document.querySelectorAll('dialog[data-storefront-open]').forEach((dialog) => {
    if (!dialog.open) {
        dialog.showModal();
    }
});
