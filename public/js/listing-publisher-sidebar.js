const publisher = document.querySelector('#publisher.premium-side-creator');

if (publisher) {
    const lines = [...publisher.querySelectorAll('[data-publisher-line]')];
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const identity = publisher.querySelector('.listing-publisher-identity');
    const location = publisher.querySelector('[data-publisher-location]');
    let identityVisible = false;

    function scrollText(line) {
        if (reducedMotion.matches || line.hasAttribute('data-scrolling')) return;

        const overflow = line.firstElementChild.scrollWidth - line.clientWidth;
        if (overflow <= 1) return;

        line.style.setProperty('--publisher-overflow', `${overflow}px`);
        line.setAttribute('data-scrolling', '');
    }

    for (const line of lines) {
        line.addEventListener('animationend', () => line.removeAttribute('data-scrolling'));
        line.addEventListener('pointerenter', () => scrollText(line));
        line.addEventListener('focus', () => scrollText(line));
    }

    reducedMotion.addEventListener('change', () => {
        for (const line of lines) line.removeAttribute('data-scrolling');
    });

    const observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting && !identityVisible) lines.forEach(scrollText);
        identityVisible = entry.isIntersecting;
    }, { threshold: .25 });
    observer.observe(identity);

    if (location) {
        const trigger = location.querySelector('button');
        const fullLabel = location.querySelector('[data-publisher-province-country]');
        const country = location.querySelector('[data-publisher-country]');
        const detail = location.querySelector('[role="tooltip"]');
        let pinned = false;

        function fitLocation() {
            location.removeAttribute('data-country-only');
            if (fullLabel.textContent !== country.textContent && fullLabel.scrollWidth > fullLabel.clientWidth + 1) {
                location.setAttribute('data-country-only', '');
            }
        }

        const locationResize = new ResizeObserver(fitLocation);
        locationResize.observe(location);
        document.fonts.ready.then(fitLocation);
        fitLocation();

        function showLocation(visible) {
            detail.hidden = !visible;
            trigger.setAttribute('aria-expanded', String(visible));
        }

        function closeLocation() {
            pinned = false;
            showLocation(false);
        }

        location.addEventListener('pointerenter', (event) => {
            if (event.pointerType === 'mouse' || event.pointerType === 'pen') showLocation(true);
        });
        location.addEventListener('pointerleave', () => {
            if (!pinned && !trigger.matches(':focus-visible')) showLocation(false);
        });
        trigger.addEventListener('focus', () => {
            if (trigger.matches(':focus-visible')) showLocation(true);
        });
        trigger.addEventListener('blur', () => {
            if (!location.matches(':hover')) closeLocation();
        });
        trigger.addEventListener('click', () => {
            pinned = !pinned;
            showLocation(pinned);
        });
        document.addEventListener('pointerdown', (event) => {
            if (!location.contains(event.target)) closeLocation();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !detail.hidden) closeLocation();
        });
    }
}
