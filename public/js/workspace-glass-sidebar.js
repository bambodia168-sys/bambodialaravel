/* A second control for the existing role-aware navigation state, not a new menu. */
document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const primary = document.querySelector('[data-workspace-toggle]');
    const edge = document.querySelector('[data-workspace-glass-toggle]');
    if (!body?.classList.contains('workspace-body') || !primary || !edge) return;
    const mobile = window.matchMedia('(max-width:850px)');
    const sync = () => {
        const expanded = mobile.matches
            ? body.classList.contains('workspace-mobile-open')
            : !body.classList.contains('workspace-collapsed');
        const label = mobile.matches ? (expanded ? 'Close navigation' : 'Open navigation')
            : (expanded ? 'Collapse navigation' : 'Expand navigation');
        edge.setAttribute('aria-expanded', String(expanded));
        edge.setAttribute('aria-label', label);
        edge.title = mobile.matches ? label : (expanded ? 'Show icons only' : 'Show full menu');
        primary.setAttribute('aria-expanded', String(expanded));
        primary.setAttribute('aria-label', label);
        if (mobile.matches && !expanded && document.activeElement === edge) primary.focus();
    };
    edge.addEventListener('click', () => {
        primary.click();
        sync();
        if (mobile.matches && !body.classList.contains('workspace-mobile-open')) primary.focus();
    });
    // The existing toggle owns persisted state, mobile dismissal and menu search.
    new MutationObserver(sync).observe(body, {attributes:true,attributeFilter:['class']});
    mobile.addEventListener('change', sync);
    sync();
    edge.hidden = false;
});
