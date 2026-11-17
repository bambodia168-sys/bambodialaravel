(() => {
  const form = document.querySelector('[data-media-upload]');
  if (!form) return;
  const get = selector => form.querySelector(selector);
  const queue = [], zone = get('[data-dropzone]'), picker = get('[data-files]');
  let running = false, depth = 0;
  const allowed = ['image/jpeg','image/png','image/webp','image/gif','video/mp4','video/webm'];
  const size = bytes => `${(bytes / 1024 / 1024).toFixed(2)} MB`;
  function update() {
    get('[data-count]').textContent = queue.length;
    get('[data-empty]').hidden = queue.length > 0;
    get('[data-start]').disabled = running || !queue.some(item => item.state === 'queued');
    const done = queue.filter(item => item.state === 'done').length;
    const errors = queue.filter(item => item.state === 'error').length;
    get('[data-summary]').textContent = `${done} uploaded · ${queue.filter(item => item.state === 'queued').length} queued${errors ? ` · ${errors} need attention` : ''}${running ? ' · Uploading…' : ''}`;
  }
  function state(item, value, message) {
    item.state = value;
    item.row.dataset.state = value;
    item.status.textContent = `${size(item.file.size)} · ${message}`;
    item.button.textContent = value === 'error' && item.valid ? 'Retry' : 'Remove';
    item.button.disabled = value === 'uploading';
    update();
  }
  function remove(item) {
    if (item.url) URL.revokeObjectURL(item.url);
    item.row.remove(); queue.splice(queue.indexOf(item), 1); update();
  }
  function add(files) {
    for (const file of files) {
      if (queue.some(item => item.file.name === file.name && item.file.size === file.size && item.file.lastModified === file.lastModified)) continue;
      const item = {file, state:'queued', valid:true};
      item.row = document.createElement('li'); item.row.className = 'media-upload-row';
      const thumb = document.createElement(file.type.startsWith('image/') ? 'img' : 'span');
      thumb.className = 'media-upload-thumb';
      if (thumb.tagName === 'IMG') {item.url = URL.createObjectURL(file); thumb.src = item.url; thumb.alt = '';}
      else {thumb.textContent = 'VIDEO'; thumb.style.cssText = 'display:grid;place-items:center;font-size:10px';}
      const info = document.createElement('div'); info.className = 'media-upload-info';
      const name = document.createElement('strong'); name.textContent = file.name;
      item.status = document.createElement('small');
      item.progress = document.createElement('progress'); item.progress.max = 100; item.progress.value = 0; item.progress.setAttribute('aria-label', `Upload progress for ${file.name}`);
      info.append(name,item.status,item.progress);
      item.button = document.createElement('button'); item.button.type = 'button'; item.button.className = 'btn subtle';
      item.button.addEventListener('click', () => {
        if (item.state === 'error' && item.valid) {item.progress.value = 0; state(item,'queued','Ready to retry');}
        else if (item.state !== 'uploading') remove(item);
      });
      item.row.append(thumb,info,item.button); get('[data-queue]').append(item.row); queue.push(item);
      const limit = file.type.startsWith('video/') ? 20 : file.type === 'image/gif' ? 8 : 4;
      item.valid = allowed.includes(file.type) && file.size <= limit*1024*1024 && file.size > 0;
      state(item,item.valid ? 'queued' : 'error',item.valid ? 'Ready to upload' : 'Unsupported format, empty file, or file too large. Remove and choose another file.');
    }
    update();
  }
  picker.addEventListener('change', () => {add(picker.files); picker.value = '';});
  zone.addEventListener('dragenter', event => {event.preventDefault(); depth++; zone.classList.add('is-over');});
  zone.addEventListener('dragover', event => {event.preventDefault(); event.dataTransfer.dropEffect = 'copy';});
  zone.addEventListener('dragleave', () => {if (--depth <= 0) zone.classList.remove('is-over');});
  zone.addEventListener('drop', event => {event.preventDefault(); depth = 0; zone.classList.remove('is-over'); add(event.dataTransfer.files);});
  get('[data-clear]').addEventListener('click', () => {queue.filter(item => item.state === 'done').forEach(remove);});
  function upload(item, metadata) {
    return new Promise(resolve => {
      const body = new FormData();
      for (const [key,value] of metadata) body.append(key,value);
      body.append('image',item.file);
      const xhr = new XMLHttpRequest(); xhr.open('POST',form.action); xhr.setRequestHeader('Accept','application/json'); xhr.timeout = 180000;
      xhr.upload.addEventListener('progress', event => {if (event.lengthComputable) item.progress.value = Math.round(event.loaded/event.total*100);});
      xhr.onload = () => {
        let response = {}; try {response = JSON.parse(xhr.responseText);} catch (_) {}
        if (xhr.status === 201 && response.id) {item.progress.value = 100; state(item,'done','Uploaded'); resolve(0); return;}
        if (xhr.status === 429) {state(item,'queued','Waiting for upload capacity…'); resolve(Math.max(1,Number(xhr.getResponseHeader('Retry-After')) || 60)); return;}
        const message = Object.values(response.errors || {}).flat().join(' ') || response.message || 'Upload failed. Please try again.';
        state(item,'error',xhr.status === 419 ? 'Session expired. Refresh this page and select your remaining files.' : message); resolve(0);
      };
      xhr.onerror = xhr.ontimeout = () => {state(item,'error','Connection interrupted. Check the library before retrying to avoid a duplicate.'); resolve(0);};
      state(item,'uploading','Uploading…'); xhr.send(body);
    });
  }
  form.addEventListener('submit', async event => {
    event.preventDefault(); if (running) return;
    running = true; update();
    const metadata = new FormData(form);
    // Process separately so a failed file never discards successful uploads.
    let rateRetries = 0;
    try {
      while (queue.some(item => item.state === 'queued')) {
        const item = queue.find(item => item.state === 'queued');
        const wait = await upload(item,metadata);
        if (wait) {
          if (++rateRetries > 5) {state(item,'error','Upload limit reached. Please retry shortly.'); break;}
          await new Promise(resolve => setTimeout(resolve,Math.min(wait,120)*1000));
        } else rateRetries = 0;
      }
    } finally {running = false; update();}
  });
  window.addEventListener('beforeunload', event => {if (running || queue.some(item => item.state === 'queued')) {event.preventDefault(); event.returnValue = '';}});
  update();
})();
