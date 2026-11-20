(() => {
    const menu = document.querySelector('[data-mini-cart]');
    if (!menu) return;

    const trigger = menu.querySelector('summary');
    const badge = menu.querySelector('[data-cart-badge]');
    const countLabel = menu.querySelector('[data-cart-count]');
    const itemsList = menu.querySelector('[data-cart-items]');
    const note = menu.querySelector('[data-cart-note]');
    const floatingCart = document.querySelector('[data-cart-float]');
    const announcement = document.querySelector('[data-cart-announcement]');
    const syncKey = 'bambodia:cart-changed';
    const json = { Accept: 'application/json' };
    let adding = false;
    let revision = 0;
    let refreshRequest;
    let selections = {};

    const itemCount = (count) => `${count} ${count === 1 ? 'item' : 'items'}`;

    function isInCart(form) {
        const tier = form.elements.namedItem('tier')?.value;
        return Boolean(tier && selections[form.dataset.cartAdd] === tier);
    }

    function syncListingAction(form = document.querySelector('[data-cart-add]')) {
        const button = form?.querySelector('[data-cart-action]');
        if (!button) return;
        const pending = form.getAttribute('aria-busy') === 'true';
        const added = !pending && isInCart(form);
        button.dataset.cartState = pending ? 'adding' : added ? 'added' : 'ready';
        button.querySelector('[data-cart-action-label]').textContent =
            pending ? 'Adding…' : added ? 'In cart · View cart' : 'Add to cart';
        button.querySelector('[data-cart-add-icon]').hidden = added;
        button.querySelector('[data-cart-added-icon]').hidden = !added;
    }

    function render(cart) {
        if (!Number.isInteger(cart.count) || cart.count < 0 || !Array.isArray(cart.items)
            || cart.count !== cart.items.length || !cart.items.every((title) => typeof title === 'string')) {
            throw new Error('Invalid cart preview');
        }

        selections = cart.selections ?? {};
        syncListingAction();

        const filled = cart.count > 0;
        const label = itemCount(cart.count);
        badge.textContent = filled ? String(cart.count) : '';
        badge.hidden = !filled;
        trigger.setAttribute('aria-label', filled ? `Cart, ${label}` : 'Cart');
        countLabel.textContent = label;
        countLabel.hidden = false;
        note.hidden = filled;
        note.textContent = 'Your cart is empty.';
        itemsList.hidden = !filled;
        itemsList.replaceChildren(...cart.items.map((title) => {
            const item = document.createElement('li');
            item.textContent = title;
            return item;
        }));

        if (floatingCart) {
            floatingCart.querySelector('[data-cart-float-count]').textContent = label;
            if (!filled) floatingCart.hidden = true;
        }
    }

    function refresh() {
        if (adding || refreshRequest) return;
        const version = revision;
        refreshRequest = fetch(menu.dataset.cartUrl, { headers: json, cache: 'no-store' })
            .then((response) => {
                if (!response.ok) throw new Error('Cart preview unavailable');
                return response.json();
            })
            .then((cart) => {
                if (version === revision) render(cart);
            })
            .catch(() => {
                if (version === revision && countLabel.hidden) {
                    note.textContent = 'Cart preview unavailable. You can still open your cart.';
                }
            })
            .finally(() => { refreshRequest = null; });
    }

    menu.addEventListener('toggle', () => {
        if (menu.open) refresh();
    });
    document.addEventListener('click', (event) => {
        if (menu.open && !menu.contains(event.target)) menu.open = false;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.open) {
            menu.open = false;
            trigger.focus();
        }
    });
    window.addEventListener('focus', refresh);
    window.addEventListener('storage', (event) => {
        if (event.key === syncKey) refresh();
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        if (floatingCart) floatingCart.hidden = true;
        refresh();
    });
    floatingCart?.querySelector('[data-cart-dismiss]').addEventListener('click', (event) => {
        const restoreFocus = document.activeElement === event.currentTarget;
        floatingCart.hidden = true;
        if (restoreFocus) (document.querySelector('[data-cart-add] button[type="submit"]') ?? trigger).focus({ preventScroll: true });
    });

    document.addEventListener('change', (event) => {
        if (event.target.name === 'tier' && event.target.form?.matches('[data-cart-add]')) {
            syncListingAction(event.target.form);
        }
    });
    document.addEventListener('bambodia:detail-tab-changed', () => syncListingAction());

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (event.defaultPrevented || !form.matches('[data-cart-add]') || !floatingCart) return;
        const button = form.querySelector('[data-cart-action]');
        if (!button) return;
        event.preventDefault();
        if (adding) return;
        if (isInCart(form)) {
            window.location.assign(menu.dataset.cartUrl);
            return;
        }

        const feedback = form.nextElementSibling;
        const body = new FormData(form);
        adding = true;
        ++revision;
        form.setAttribute('aria-busy', 'true');
        button.setAttribute('aria-disabled', 'true');
        syncListingAction(form);
        feedback.hidden = true;

        try {
            const response = await fetch(form.action, { method: 'POST', headers: json, body, cache: 'no-store' });
            if (response.status === 422) {
                const error = await response.json();
                feedback.textContent = error.errors?.tier?.[0] ?? error.errors?.cart?.[0] ?? 'Check your selection and try again.';
                feedback.hidden = false;
                return;
            }
            if (response.status === 419) {
                feedback.textContent = 'Refresh this page to continue adding items.';
                feedback.hidden = false;
                return;
            }
            if (!response.ok) throw new Error('Cart add failed');

            const cart = await response.json();
            if (typeof cart.added !== 'string') throw new Error('Cart add not confirmed');
            render(cart);
            floatingCart.hidden = false;
            announcement.textContent = `${cart.added} added to cart. ${itemCount(cart.count)}.`;
            try {
                localStorage.setItem(syncKey, String(Date.now()));
            } catch (_) {
                // The cart is stored on the server. This signal only refreshes other tabs.
            }
        } catch (_) {
            feedback.textContent = 'Could not confirm the addition. Open your cart to check or try again.';
            feedback.hidden = false;
        } finally {
            adding = false;
            form.removeAttribute('aria-busy');
            button.removeAttribute('aria-disabled');
            syncListingAction();
            if (!feedback.hidden) refresh();
        }
    });

    refresh();
})();
