/* Private support ticket editor. Visual editor, HTML source, media and programming code.
   Server-side SupportMessageContent sanitizes again before every database write. */
(() => {
  'use strict';
  const allowedTags = new Set('p div br h2 h3 h4 strong b em i u s del mark ul ol li blockquote pre code a hr table thead tbody tr th td span sup sub img audio video iframe'.split(' '));
  const languages = new Set('plaintext php html css javascript typescript python java sql bash json xml go rust c cpp csharp ruby swift kotlin'.split(' '));
  const htmlEscape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const validUrl = (value, httpsOnly = true) => {
    try {
      const url = new URL(value);
      if ((httpsOnly ? url.protocol !== 'https:' : !['https:', 'http:'].includes(url.protocol)) ||
          url.username || url.password || /[\s<>"\\]/.test(value)) return null;
      return url.href;
    } catch { return null; }
  };
  const youtubeEmbed = value => {
    const url = validUrl(value);
    if (!url) return null;
    const u = new URL(url);
    if (u.search || u.hash || u.port) return null;
    if (!['youtube.com','www.youtube.com','youtube-nocookie.com','www.youtube-nocookie.com'].includes(u.hostname)) return null;
    const m = u.pathname.match(/^\/embed\/([A-Za-z0-9_-]{11})$/);
    return m ? 'https://www.youtube-nocookie.com/embed/' + m[1] : null;
  };
  const toEmbed = value => {
    const url = validUrl(value);
    if (!url) return null;
    const u = new URL(url);
    const host = u.hostname.toLowerCase();
    let id = '';
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com'].includes(host)) {
      id = u.pathname === '/watch' ? (u.searchParams.get('v') || '') : (u.pathname.match(/^\/(?:shorts|embed)\/([A-Za-z0-9_-]{11})$/)?.[1] || '');
    } else if (host === 'youtu.be' || host === 'www.youtu.be') {
      id = u.pathname.slice(1);
    }
    return /^[A-Za-z0-9_-]{11}$/.test(id) ? 'https://www.youtube-nocookie.com/embed/' + id : null;
  };

  // Browser-side preview sanitation is defense-in-depth for HTML source editing;
  // all messages are sanitized independently on the Laravel server.
  const safePreview = html => {
    const doc = new DOMParser().parseFromString('<div id="ticket-root">' + html + '</div>', 'text/html');
    const root = doc.getElementById('ticket-root');
    const dropped = new Set('script style svg math object embed form input textarea button template link meta base source picture'.split(' '));
    const walk = parent => {
      for (const node of Array.from(parent.childNodes)) {
        if (node.nodeType === Node.COMMENT_NODE) { node.remove(); continue; }
        if (node.nodeType !== Node.ELEMENT_NODE) continue;
        const tag = node.localName;
        if (dropped.has(tag)) { node.remove(); continue; }
        if (!allowedTags.has(tag)) { walk(node); node.replaceWith(...Array.from(node.childNodes)); continue; }
        const href = node.getAttribute('href') || '';
        const src = node.getAttribute('src') || '';
        const alt = node.getAttribute('alt') || '';
        const lang = node.getAttribute('class') || '';
        for (const attr of Array.from(node.attributes)) node.removeAttribute(attr.name);
        if (tag === 'a') {
          const safe = validUrl(href, false);
          if (safe) { node.setAttribute('href', safe); node.setAttribute('target', '_blank'); node.setAttribute('rel', 'noopener noreferrer nofollow'); }
        }
        if (tag === 'img' || tag === 'audio' || tag === 'video') {
          const safe = validUrl(src);
          if (!safe) { node.remove(); continue; }
          node.setAttribute('src', safe);
          if (tag === 'img') { node.setAttribute('alt', alt.slice(0, 160)); node.setAttribute('loading','lazy'); node.setAttribute('referrerpolicy','no-referrer'); }
          else { node.setAttribute('controls',''); node.setAttribute('preload','none'); }
        }
        if (tag === 'iframe') {
          const safe = youtubeEmbed(src);
          if (!safe) { node.remove(); continue; }
          node.setAttribute('src',safe);
          node.setAttribute('title','YouTube video');
          node.setAttribute('loading','lazy');
          node.setAttribute('referrerpolicy','no-referrer');
          node.setAttribute('sandbox','allow-scripts allow-same-origin allow-presentation');
          node.setAttribute('allowfullscreen','');
          node.replaceChildren();
        }
        if (tag === 'code' && /^language-[a-z]+$/.test(lang) && languages.has(lang.slice(9))) node.setAttribute('class',lang);
        if (tag !== 'iframe') walk(node);
      }
    };
    if (!root) return '';
    walk(root);
    return root.innerHTML;
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-ticket-editor]').forEach(root => {
      const form = root.closest('form[data-ticket-form]');
      const source = root.querySelector('[data-ticket-message]');
      const surface = root.querySelector('[data-ticket-surface]');
      const sourcePanel = root.querySelector('[data-ticket-source-panel]');
      const sourceToggle = root.querySelector('[data-ticket-toggle-source]');
      const count = root.querySelector('[data-ticket-count]');
      const dialog = root.querySelector('[data-ticket-media-dialog]');
      let sourceMode = false;
      let savedRange = null;
      if (!form || !source || !surface) return;
      source.hidden = true;
      source.required = false;
      surface.hidden = false;

      const rememberSelection = () => {
        const selection = window.getSelection();
        if (selection && selection.rangeCount && surface.contains(selection.anchorNode)) {
          savedRange = selection.getRangeAt(0).cloneRange();
        }
      };
      const restoreSelection = () => {
        surface.focus();
        if (savedRange) {
          const selection = window.getSelection();
          selection.removeAllRanges();
          selection.addRange(savedRange);
        }
      };
      const sync = () => {
        if (!sourceMode) source.value = surface.innerHTML.trim();
        else source.value = sourcePanel.value.trim();
        const readable = sourceMode ? new DOMParser().parseFromString(source.value, 'text/html').body.textContent : surface.textContent;
        count.textContent = (readable || '').trim().length + ' characters';
      };
      const insertHtml = markup => {
        if (sourceMode) {
          const p = sourcePanel.selectionStart;
          sourcePanel.setRangeText(markup, p, sourcePanel.selectionEnd, 'end');
          sourcePanel.focus();
        } else {
          restoreSelection();
          document.execCommand('insertHTML', false, markup);
          rememberSelection();
        }
        sync();
      };
      root.querySelectorAll('[data-ticket-command]').forEach(button => {
        button.addEventListener('mousedown', e => e.preventDefault());
        button.addEventListener('click', () => {
          const command = button.dataset.ticketCommand;
          if (sourceMode) return;
          rememberSelection();
          restoreSelection();
          if (command === 'createLink') {
            dialog.dataset.insertKind = 'link';
            root.querySelector('[data-ticket-media-type]').value = 'link';
            dialog.showModal();
            return;
          }
          if (command === 'table') {
            const rows = Math.min(10, Math.max(1, Number(window.prompt('Table rows (1–10)', '3')) || 0));
            const cols = Math.min(6, Math.max(1, Number(window.prompt('Table columns (1–6)', '3')) || 0));
            if (!rows || !cols) return;
            let markup = '<table><tbody>';
            for (let r = 0; r < rows; r++) {
              markup += '<tr>';
              for (let c = 0; c < cols; c++) markup += r === 0 ? '<th>Heading</th>' : '<td>Content</td>';
              markup += '</tr>';
            }
            insertHtml(markup + '</tbody></table><p></p>');
          } else if (command === 'divider') {
            insertHtml('<hr>');
          } else {
            document.execCommand(command, false);
          }
          sync();
        });
      });
      root.querySelector('[data-ticket-block]')?.addEventListener('change', e => {
        if (sourceMode) return;
        document.execCommand('formatBlock', false, e.target.value);
        sync();
      });
      root.querySelector('[data-ticket-code]')?.addEventListener('mousedown', e => e.preventDefault());
      root.querySelector('[data-ticket-code]')?.addEventListener('click', () => {
        const language = root.querySelector('[data-ticket-language]').value;
        const selected = sourceMode ? '' : (window.getSelection()?.toString() || '');
        insertHtml('<pre><code class="language-' + language + '">' + htmlEscape(selected || 'Your code here') + '</code></pre><p></p>');
      });
      root.querySelector('[data-ticket-emoji]')?.addEventListener('change', e => {
        if (e.target.value) insertHtml(htmlEscape(e.target.value));
        e.target.value = '';
      });
      sourceToggle?.addEventListener('click', () => {
        sourceMode = !sourceMode;
        if (sourceMode) {
          sourcePanel.value = surface.innerHTML;
          surface.hidden = true;
          sourcePanel.hidden = false;
          sourcePanel.focus();
        } else {
          surface.innerHTML = safePreview(sourcePanel.value);
          sourcePanel.hidden = true;
          surface.hidden = false;
          surface.focus();
        }
        sourceToggle.setAttribute('aria-pressed', String(sourceMode));
        sync();
      });
      root.querySelector('[data-ticket-media]')?.addEventListener('click', () => {
        rememberSelection();
        root.querySelector('[data-ticket-media-type]').value = 'image';
        root.querySelector('[data-ticket-media-url]').value = '';
        root.querySelector('[data-ticket-media-label]').value = '';
        root.querySelector('[data-ticket-media-error]').hidden = true;
        dialog.showModal();
      });
      root.querySelector('[data-ticket-media-cancel]')?.addEventListener('click', () => dialog.close());
      root.querySelector('[data-ticket-media-insert]')?.addEventListener('click', event => {
        event.preventDefault();
        const type = root.querySelector('[data-ticket-media-type]').value;
        const rawUrl = root.querySelector('[data-ticket-media-url]').value.trim();
        const label = root.querySelector('[data-ticket-media-label]').value.trim() || 'Shared media';
        const url = type === 'youtube' ? toEmbed(rawUrl) : validUrl(rawUrl, type !== 'link');
        const error = root.querySelector('[data-ticket-media-error]');
        if (!url) { error.textContent = type === 'youtube' ? 'Enter a valid YouTube URL.' : 'Enter a valid HTTPS URL (links may use HTTP).'; error.hidden = false; return; }
        let markup = '';
        if (type === 'image') markup = '<p><img src="' + htmlEscape(url) + '" alt="' + htmlEscape(label) + '"></p>';
        else if (type === 'audio') markup = '<p><audio src="' + htmlEscape(url) + '" controls preload="none"></audio></p>';
        else if (type === 'video') markup = '<p><video src="' + htmlEscape(url) + '" controls preload="none"></video></p>';
        else if (type === 'youtube') markup = '<p><iframe src="' + htmlEscape(url) + '" title="YouTube video"></iframe></p>';
        else markup = '<a href="' + htmlEscape(url) + '" rel="noopener noreferrer nofollow" target="_blank">' + htmlEscape(label) + '</a>';
        dialog.close();
        insertHtml(markup);
      });
      surface.addEventListener('mouseup', rememberSelection);
      surface.addEventListener('keyup', rememberSelection);
      surface.addEventListener('input', sync);
      surface.addEventListener('paste', e => {
        if (!e.clipboardData) return;
        e.preventDefault();
        document.execCommand('insertText', false, e.clipboardData.getData('text/plain'));
        sync();
      });
      sourcePanel.addEventListener('input', sync);
      form.addEventListener('submit', e => {
        if (sourceMode) {
          const safe = safePreview(sourcePanel.value);
          source.value = safe;
        } else {
          source.value = surface.innerHTML.trim();
        }
        const text = new DOMParser().parseFromString(source.value, 'text/html').body.textContent?.trim() || '';
        if (text.length < Number(root.dataset.minimum || 2) || source.value.length > 40000) {
          e.preventDefault();
          window.alert('Enter enough readable message text and keep it under 40,000 characters.');
          (sourceMode ? sourcePanel : surface).focus();
        }
      });
      sync();
    });
  });
})();
