/* Profile UI v4: progressive enhancement shared by Account, Creator and Admin. */
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  function initNames() {
    $$('[data-profile-name-viewport]').forEach(view => {
      const name = $('[data-profile-scroll-name]', view);
      if (!name) return;
      const distance = Math.ceil(name.scrollWidth - view.clientWidth);
      view.classList.toggle('is-overflowing', distance > 3);
      view.style.setProperty('--profile-name-travel', Math.max(0, distance) + 'px');
    });
  }

  let toastTimeout;
  function feedback(message) {
    let toast = $('.profile-v3-copy-toast');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'profile-v3-copy-toast';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.hidden = false;
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => { toast.hidden = true; }, 5000);
  }

  async function copy(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const fallback = document.createElement('textarea');
    fallback.value = text;
    fallback.style.position = 'fixed';
    fallback.style.opacity = '0';
    document.body.appendChild(fallback);
    fallback.select();
    const ok = document.execCommand('copy');
    fallback.remove();
    if (!ok) throw new Error('Copy is not supported');
  }

  let openMenu = null;
  function closeMenu() {
    if (!openMenu) return;
    const {menu, trigger, parent, next} = openMenu;
    menu.hidden = true;
    menu.classList.remove('is-open');
    if (next && next.parentNode === parent) parent.insertBefore(menu, next);
    else parent.appendChild(menu);
    trigger.setAttribute('aria-expanded', 'false');
    openMenu = null;
  }
  function toggleMenu(trigger) {
    if (openMenu?.trigger === trigger) { closeMenu(); return; }
    closeMenu();
    const menu = $('[data-profile-menu]', trigger.parentElement);
    if (!menu) return;
    const parent = menu.parentNode, next = menu.nextSibling;
    document.body.appendChild(menu);
    menu.hidden = false;
    menu.classList.add('is-open');
    const rect = trigger.getBoundingClientRect();
    const width = menu.offsetWidth || 175;
    const top = (rect.bottom + menu.offsetHeight + 7 > window.innerHeight)
      ? Math.max(8, rect.top - menu.offsetHeight - 7) : rect.bottom + 7;
    menu.style.top = top + 'px';
    menu.style.left = Math.max(8, Math.min(window.innerWidth - width - 8, rect.right - width)) + 'px';
    trigger.setAttribute('aria-expanded', 'true');
    openMenu = {menu, trigger, parent, next};
    $('[role="menuitem"]', menu)?.focus();
  }

  function initAvatar() {
    const zone = $('[data-avatar-dropzone]');
    if (!zone) return;
    const input = $('[data-avatar-file]', zone);
    const preview = $('[data-avatar-preview]', zone);
    if (!input || !preview) return;
    const status = $('[data-avatar-status]');
    const removal = $('[data-avatar-remove]');
    const original = preview.innerHTML;
    let objectUrl = null;
    function message(value) {
      if (!status) return;
      status.hidden = !value;
      status.textContent = value || '';
    }
    function show(file) {
      if (!file) return;
      if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        message('Select a JPEG, PNG or WebP image.');
        return;
      }
      if (file.size > 2 * 1024 * 1024 || !file.size) {
        message('Image must be under 2 MB and must not be empty.');
        return;
      }
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = URL.createObjectURL(file);
      const img = document.createElement('img');
      img.src = objectUrl;
      img.alt = 'New profile image preview — not saved yet';
      img.width = 240; img.height = 240;
      preview.replaceChildren(img);
      if (removal) removal.checked = false;
      message('Preview updated. Select Save profile to upload your image.');
      zone.classList.remove('is-drop-active');
    }
    zone.addEventListener('click', event => {
      if (event.target === input) return;
      input.click();
    });
    zone.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        input.click();
      }
    });
    zone.addEventListener('focus', () => zone.classList.add('is-active'));
    zone.addEventListener('blur', () => zone.classList.remove('is-active'));
    input.addEventListener('change', () => show(input.files?.[0]));
    ['dragenter','dragover'].forEach(name => zone.addEventListener(name, event => {
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
      zone.classList.add('is-active','is-drop-active');
    }));
    zone.addEventListener('dragleave', event => {
      if (!zone.contains(event.relatedTarget)) {
        zone.classList.remove('is-active','is-drop-active');
      }
    });
    zone.addEventListener('drop', event => {
      event.preventDefault();
      zone.classList.remove('is-active','is-drop-active');
      const file = event.dataTransfer?.files?.[0];
      if (!file) return;
      if (!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 2*1024*1024 || !file.size) {
        message('Choose a JPEG, PNG or WebP smaller than 2 MB.');
        return;
      }
      const transfer = new DataTransfer();
      transfer.items.add(file);
      input.files = transfer.files;
      show(file);
    });
    removal?.addEventListener('change', () => {
      if (removal.checked) {
        input.value = '';
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        preview.innerHTML = '';
        const empty = document.createElement('span');
        empty.textContent = ($('input[name="name"]')?.value || '?').charAt(0).toUpperCase();
        preview.appendChild(empty);
        message('Image will be removed after you select Save profile.');
      } else {
        preview.innerHTML = original;
        message('');
      }
    });
    window.addEventListener('pagehide', () => { if (objectUrl) URL.revokeObjectURL(objectUrl); }, {once:true});
  }

  const ISO_COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS XK YE YT ZA ZM ZW'.split(' ');

  function initLocation() {
    const wrapper = $('[data-profile-location]');
    if (!wrapper) return;
    const select = $('[data-location-country]', wrapper);
    const city = $('[data-location-city]', wrapper);
    const value = $('[data-location-value]', wrapper);
    const detect = $('[data-location-detect]', wrapper);
    const status = $('[data-location-status]', wrapper);
    if (!select || !city || !value) return;
    const display = typeof Intl.DisplayNames === 'function'
      ? new Intl.DisplayNames(['en'], {type:'region'}) : null;
    const names = ISO_COUNTRIES.map(code => ({
      code, name: display?.of(code) || code
    })).sort((a,b) => a.name.localeCompare(b.name));
    names.forEach(({code,name}) => {
      const option = document.createElement('option');
      option.value = name;
      option.dataset.code = code;
      option.textContent = name;
      select.appendChild(option);
    });
    const saved = (wrapper.dataset.currentLocation || '').trim();
    const match = names.find(({name}) => saved === name || saved.endsWith(', ' + name));
    if (match) {
      select.value = match.name;
      city.value = saved === match.name ? '' : saved.slice(0, -match.name.length - 2);
    } else {
      city.value = saved;
    }
    const sync = () => {
      const c = city.value.trim(), country = select.value;
      value.value = [c, country].filter(Boolean).join(', ').slice(0,120);
    };
    [select,city].forEach(field => field.addEventListener('change',sync));
    city.addEventListener('input',sync);
    $('[data-profile-identity-form]')?.addEventListener('submit',sync);
    detect?.addEventListener('click', () => {
      if (!navigator.geolocation) {
        status.textContent = 'Geolocation is not supported. Choose a country manually.';
        return;
      }
      detect.disabled = true;
      status.textContent = 'Requesting location permission…';
      navigator.geolocation.getCurrentPosition(async ({coords}) => {
        const abort = new AbortController();
        const id = setTimeout(() => abort.abort(), 12000);
        try {
          const url = new URL('https://nominatim.openstreetmap.org/reverse');
          url.searchParams.set('format','jsonv2');
          url.searchParams.set('addressdetails','1');
          url.searchParams.set('zoom','10');
          url.searchParams.set('lat',String(coords.latitude));
          url.searchParams.set('lon',String(coords.longitude));
          const response = await fetch(url.toString(), {signal:abort.signal,headers:{Accept:'application/json'}});
          if (!response.ok) throw new Error('Reverse geocoding unavailable');
          const result = await response.json();
          const a = result.address || {};
          const countryCode = String(a.country_code || '').toUpperCase();
          const country = names.find(x => x.code === countryCode);
          if (!country) throw new Error('Country could not be identified');
          select.value = country.name;
          city.value = a.city || a.town || a.village || a.municipality || a.county || a.state || '';
          sync();
          status.textContent = 'Location filled. Review before saving. © OpenStreetMap contributors.';
          feedback('Location detected. Save your profile to keep it.');
        } catch {
          status.textContent = 'Could not resolve your location. Please choose a country and city manually.';
        } finally {
          clearTimeout(id);
          detect.disabled = false;
        }
      }, () => {
        detect.disabled = false;
        status.textContent = 'Permission denied or location unavailable. You can select your location manually.';
      }, {enableHighAccuracy:false,timeout:12000,maximumAge:600000});
    });
  }

  function initOrdering() {
    let armed = null, moving = null, snapshot = null;
    const selector = '[data-profile-item-id], [data-profile-section-id]';
    const directEntries = container => Array.from(container.children).filter(
      el => el.matches(selector)
    );
    const identifier = (el, type) => el.getAttribute(type === 'sections' ? 'data-profile-section-id' : 'data-profile-item-id');
    async function persist(container, original) {
      const type = container.dataset.profileSortable;
      const payload = {ids: directEntries(container).map(el => Number(identifier(el, type)))};
      if (type === 'items') payload.section_id = Number(container.dataset.profileSection);
      const token = $('meta[name="csrf-token"]')?.content || $('input[name="_token"]')?.value;
      try {
        const res = await fetch(container.dataset.profileOrderUrl, {
          method:'PATCH', credentials:'same-origin',
          headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token || ''},
          body:JSON.stringify(payload)
        });
        if (!res.ok) throw new Error('Failed to save order');
        feedback(type === 'sections' ? 'Section order saved' : 'Entry order saved');
      } catch {
        original.forEach(el => container.appendChild(el));
        feedback('Could not save the new order. The original order has been restored.');
      }
    }
    document.addEventListener('pointerdown', event => {
      const handle = event.target.closest('[data-profile-sort-handle]');
      armed = handle ? handle.closest(selector) : null;
    });
    document.addEventListener('dragstart', event => {
      const target = event.target.closest(selector);
      if (!target || target !== armed) {
        event.preventDefault();
        return;
      }
      const parent = target.parentElement;
      if (!parent?.matches('[data-profile-sortable]')) { event.preventDefault(); return; }
      moving = target;
      snapshot = directEntries(parent);
      target.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', 'profile-sort');
    });
    document.addEventListener('dragover', event => {
      if (!moving) return;
      const parent = moving.parentElement;
      const target = event.target.closest(selector);
      if (!target || target === moving || target.parentElement !== parent) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      const r = target.getBoundingClientRect();
      const vertical = parent.dataset.profileSortable === 'sections' || parent.getBoundingClientRect().width < 540;
      const after = vertical ? event.clientY > r.top + r.height / 2 : event.clientX > r.left + r.width / 2;
      if (after) target.after(moving);
      else target.before(moving);
    });
    document.addEventListener('drop', event => {
      if (!moving) return;
      event.preventDefault();
      const parent = moving.parentElement;
      const before = snapshot;
      moving.classList.remove('is-dragging');
      moving = null; snapshot = null; armed = null;
      if (before && before.some((el, i) => directEntries(parent)[i] !== el)) persist(parent, before);
    });
    document.addEventListener('dragend', () => {
      if (moving && snapshot) snapshot.forEach(el => moving.parentElement.appendChild(el));
      moving?.classList.remove('is-dragging');
      moving = null; snapshot = null; armed = null;
    });
    document.addEventListener('keydown', event => {
      const handle = event.target.closest('[data-profile-sort-handle]');
      if (!handle || !['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key)) return;
      const target = handle.closest(selector), parent = target?.parentElement;
      if (!parent?.matches('[data-profile-sortable]')) return;
      event.preventDefault();
      const old = directEntries(parent), current = old.indexOf(target);
      const delta = ['ArrowUp','ArrowLeft'].includes(event.key) ? -1 : 1;
      const other = old[current + delta];
      if (!other) return;
      if (delta < 0) other.before(target);
      else other.after(target);
      persist(parent, old);
      handle.focus();
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initNames();
    window.addEventListener('resize', () => { initNames(); closeMenu(); }, {passive:true});
    window.addEventListener('scroll', closeMenu, {passive:true,capture:true});
    initAvatar();
    initLocation();
    initOrdering();
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeMenu();
  });
  document.addEventListener('click', async event => {
    const copyTarget = event.target.closest('[data-profile-copy]');
    if (copyTarget) {
      const fullUrl = copyTarget.getAttribute('data-profile-copy');
      try { await copy(fullUrl); feedback('Full creator profile URL copied'); }
      catch { feedback('Unable to copy. Please copy the address from your browser.'); }
      return;
    }
    const trigger = event.target.closest('[data-profile-menu-trigger]');
    if (trigger) { toggleMenu(trigger); return; }
    const open = event.target.closest('[data-profile-dialog-open]');
    if (open) {
      const id = open.getAttribute('data-profile-dialog-open');
      closeMenu();
      const dialog = document.getElementById(id);
      if (dialog instanceof HTMLDialogElement) dialog.showModal();
      return;
    }
    const remove = event.target.closest('[data-profile-delete]');
    if (remove) {
      const url = remove.getAttribute('data-profile-delete');
      const name = remove.getAttribute('data-profile-delete-label') || 'this item';
      const kind = remove.getAttribute('data-profile-delete-kind');
      closeMenu();
      const dialog = $('#profile-confirm-delete');
      if (dialog instanceof HTMLDialogElement) {
        const form = $('[data-profile-confirm-form]', dialog);
        const summary = $('[data-profile-confirm-text]', dialog);
        if (form && summary) {
          form.action = url;
          summary.textContent = kind === 'custom section and all its entries'
            ? 'Delete "' + name + '" and all of its entries? This cannot be undone.'
            : kind === 'section visibility' ? 'Hide "' + name + '" from your public profile? You can restore it through Edit section.'
              : 'Delete "' + name + '"? This cannot be undone.';
          dialog.showModal();
        }
      }
      return;
    }
    const close = event.target.closest('[data-profile-dialog-close]');
    if (close) {
      close.closest('dialog')?.close();
      return;
    }
    if (openMenu && !event.target.closest('[data-profile-menu]')) closeMenu();
    if (event.target instanceof HTMLDialogElement && event.target.classList.contains('profile-v3-dialog')) {
      const box = event.target.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
        event.target.close();
      }
    }
  });
})();
