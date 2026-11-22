const printReceipt = document.querySelector('[data-print-receipt]');
if (printReceipt) {
    printReceipt.hidden = false;
    printReceipt.addEventListener('click', () => window.print());
}
