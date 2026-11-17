/* Site Identity upload assistance. Server validation stays authoritative. */
(() => {
  const MAX_BYTES = 3 * 1024 * 1024;

  const loadImage = (file) => new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const image = new Image();
    image.onload = () => {
      URL.revokeObjectURL(url);
      resolve(image);
    };
    image.onerror = () => {
      URL.revokeObjectURL(url);
      reject(new Error('This file could not be opened as an image.'));
    };
    image.src = url;
  });

  for (const input of document.querySelectorAll('[data-brand-file]')) {
    const form = input.closest('form');
    const message = form?.querySelector('[data-brand-feedback]');
    if (!form || !message) continue;

    const feedback = (text, invalid) => {
      input.setCustomValidity(invalid ? text : '');
      message.textContent = text;
      message.style.color = invalid ? '#ef4444' : '';
    };

    input.addEventListener('change', async () => {
      feedback('', false);
      const file = input.files?.[0];
      if (!file) return;

      const accepted = input.accept.split(',').map((type) => type.trim());
      if (!accepted.includes(file.type)) {
        feedback('Wrong file type. Choose one of the supported formats shown above.', true);
        return;
      }
      if (file.size > MAX_BYTES) {
        feedback('The file exceeds the 3 MB maximum.', true);
        return;
      }

      try {
        const image = await loadImage(file);
        if (input.files?.[0] !== file) return;
        const width = Number(input.dataset.width);
        const height = Number(input.dataset.height);
        if (input.dataset.exact === '1' &&
            (image.naturalWidth !== width || image.naturalHeight !== height)) {
          feedback('This icon is ' + image.naturalWidth + ' × ' + image.naturalHeight +
            ' px. Upload exactly ' + width + ' × ' + height + ' px.', true);
          return;
        }

        if (input.dataset.transparentCorners === '1') {
          const canvas = document.createElement('canvas');
          canvas.width = canvas.height = 64;
          const context = canvas.getContext('2d', { willReadFrequently: true });
          if (context) {
            context.clearRect(0, 0, 64, 64);
            context.drawImage(image, 0, 0, 64, 64);
            const pixels = context.getImageData(0, 0, 64, 64).data;
            const alpha = (x, y) => pixels[(y * 64 + x) * 4 + 3];
            if ([[0, 0], [63, 0], [0, 63], [63, 63]].some(([x, y]) => alpha(x, y) > 55)) {
              feedback('The favicon has an opaque square background. Export it as a transparent PNG without white corners.', true);
              return;
            }
          }
        }

        const dimensions = image.naturalWidth + ' × ' + image.naturalHeight + ' px';
        feedback(input.dataset.exact === '1'
          ? dimensions + ' · correct dimensions and file type.'
          : dimensions + ' · selected. The recommended logo size is ' +
            width + ' × ' + height + ' px.', false);
      } catch (error) {
        feedback(error instanceof Error ? error.message : 'Invalid image file.', true);
      }
    });

    form.addEventListener('submit', (event) => {
      if (!input.checkValidity()) {
        event.preventDefault();
        input.reportValidity();
      }
    });
  }
})();
