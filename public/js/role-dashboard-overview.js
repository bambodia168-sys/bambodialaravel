/* Local search and status filtering of already-authorized displayed rows only.
   Full record search and pagination remain in the existing management screens. */
(() => {
  document.querySelectorAll('[data-role-dashboard] [data-dashboard-table]').forEach(table => {
    const controls = table.querySelector('[data-dashboard-controls]');
    if (!controls) return;
    const search = controls.querySelector('[data-dashboard-search]');
    const status = controls.querySelector('[data-dashboard-status]');
    const reset = controls.querySelector('[data-dashboard-reset]');
    const count = controls.querySelector('[data-dashboard-count]');
    const empty = table.querySelector('[data-dashboard-empty]');
    const rows = Array.from(table.querySelectorAll('[data-dashboard-row]'));
    const apply = () => {
      const query = search.value.trim().toLocaleLowerCase();
      let visible = 0;
      rows.forEach(row => {
        const match = (!status || !status.value || status.value === row.dataset.status)
          && (!query || row.textContent.toLocaleLowerCase().includes(query));
        row.hidden = !match;
        if (match) visible++;
      });
      if (empty) empty.hidden = visible !== 0;
      count.textContent = visible + ' of ' + rows.length + ' displayed records';
    };
    search.addEventListener('input', apply);
    status?.addEventListener('change', apply);
    reset.addEventListener('click', () => {
      search.value = '';
      if (status) status.value = '';
      apply();
      search.focus();
    });
    controls.hidden = false;
    apply();
  });
})();

/* Period selector changes only the server-rendered, authorized historical series. */
document.querySelectorAll('[data-role-dashboard]').forEach(root => {
  const controls = root.querySelector('[data-dashboard-period-filter]');
  const charts = root.querySelectorAll('[data-trend-period]');
  if (!controls || !charts.length) return;
  const buttons = controls.querySelectorAll('[data-dashboard-period]');
  buttons.forEach(button => button.addEventListener('click', () => {
    const period = button.dataset.dashboardPeriod;
    buttons.forEach(item => {
      const active = item === button;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-pressed', String(active));
    });
    charts.forEach(chart => { chart.hidden = chart.dataset.trendPeriod !== period; });
  }));
  controls.hidden = false;
});
