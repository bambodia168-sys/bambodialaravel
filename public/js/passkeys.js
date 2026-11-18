function fromBase64Url(value) {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(base64), character => character.charCodeAt(0));
}

function toBase64Url(buffer) {
  const bytes = new Uint8Array(buffer);
  return btoa(Array.from(bytes, byte => String.fromCharCode(byte)).join(''))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function publicKeyOptions(options, enrollment) {
  const parse = enrollment ? PublicKeyCredential.parseCreationOptionsFromJSON : PublicKeyCredential.parseRequestOptionsFromJSON;
  if (parse) return parse.call(PublicKeyCredential, options);
  options.challenge = fromBase64Url(options.challenge);
  if (enrollment) options.user.id = fromBase64Url(options.user.id);
  for (const field of ['allowCredentials', 'excludeCredentials']) {
    for (const descriptor of options[field] ?? []) descriptor.id = fromBase64Url(descriptor.id);
  }
  return options;
}

function credentialJson(credential) {
  if (credential.toJSON) return credential.toJSON();
  const response = {};
  for (const field of ['clientDataJSON', 'attestationObject', 'authenticatorData', 'signature', 'userHandle']) {
    if (credential.response[field]) response[field] = toBase64Url(credential.response[field]);
  }
  if (credential.response.getTransports) response.transports = credential.response.getTransports();
  return {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response,
    clientExtensionResults: credential.getClientExtensionResults(),
  };
}

async function passkeyRequest(url, body) {
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
    },
    body: JSON.stringify(body),
  });
  const result = await response.json();
  if (!response.ok) throw new Error(result.message || 'The passkey request could not be completed.');
  return result;
}

for (const form of document.querySelectorAll('[data-passkey-form]')) {
  const status = form.querySelector('[role=status]');
  const button = form.querySelector('button[type=submit]');
  if (!window.PublicKeyCredential || !window.isSecureContext) {
    button.disabled = true;
    status.textContent = 'Passkeys are unavailable in this browser or connection.';
    continue;
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const enrollment = form.dataset.passkeyForm === 'register';
    const formData = new FormData(form);
    const password = formData.get('current_password');
    button.disabled = true;
    status.textContent = 'Waiting for your device…';

    try {
      const options = await passkeyRequest(form.dataset.options, enrollment ? {current_password: password} : {});
      const settings = {publicKey: publicKeyOptions(options, enrollment)};
      const credential = enrollment ? await navigator.credentials.create(settings) : await navigator.credentials.get(settings);
      const result = await passkeyRequest(form.action, {
        ...(enrollment ? {name: formData.get('name')} : {}),
        credential: JSON.stringify(credentialJson(credential)),
      });
      if (enrollment) {
        window.history.replaceState(null, '', result.redirect);
        window.location.reload();
      } else {
        window.location.assign(result.redirect);
      }
    } catch (error) {
      status.textContent = error.name === 'NotAllowedError' ? 'The passkey request was canceled or timed out.' : error.message;
    } finally {
      if (enrollment) form.elements.current_password.value = '';
      button.disabled = false;
    }
  });
}
