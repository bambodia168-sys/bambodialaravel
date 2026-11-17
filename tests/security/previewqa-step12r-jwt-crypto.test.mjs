/**
 * Step 12R: RSA-SHA256 cryptographic test with fictional Access JWT fixtures.
 * Ephemeral private keys are generated in memory. This does NOT inspect real
 * Cloudflare tokens/JWKS and is NOT a production JWT validator.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { generateKeyPairSync, sign, verify } from 'node:crypto';

const ISSUER = 'https://review-only-team.cloudflareaccess.com';
const AUD = 'REVIEW_ONLY_FAKE_APP_AUD';
const NOW = 1_800_000_000;
const first = generateKeyPairSync('rsa', { modulusLength: 2048 });
const next = generateKeyPairSync('rsa', { modulusLength: 2048 });
const encode = (value) => Buffer.from(JSON.stringify(value)).toString('base64url');

function issue(privateKey, header = {}, payload = {}) {
  const h = { alg: 'RS256', typ: 'JWT', kid: 'synthetic-k1', ...header };
  const p = { iss: ISSUER, aud: [AUD], iat: NOW - 60, nbf: NOW - 60,
              exp: NOW + 300, sub: 'fictional-user', ...payload };
  const data = encode(h) + '.' + encode(p);
  return data + '.' + sign('RSA-SHA256', Buffer.from(data), privateKey).toString('base64url');
}

function strictDecode(part) {
  if (typeof part !== 'string' || !/^[A-Za-z0-9_-]+$/.test(part)) {
    throw new Error('MALFORMED_BASE64URL');
  }
  const bytes = Buffer.from(part, 'base64url');
  if (bytes.toString('base64url') !== part) throw new Error('NONCANONICAL_BASE64URL');
  return bytes;
}

/* Test fixture helper only; do not import from application or production. */
function verifyFixture(token, jwks, opts = {}) {
  const { issuer = ISSUER, audience = AUD, now = NOW } = opts;
  if (typeof token !== 'string') throw new Error('JWT_MISSING');
  const parts = token.split('.');
  if (parts.length !== 3) throw new Error('JWT_MALFORMED');
  const header = JSON.parse(strictDecode(parts[0]).toString('utf8'));
  const payload = JSON.parse(strictDecode(parts[1]).toString('utf8'));
  const signature = strictDecode(parts[2]);
  if (!header || Array.isArray(header) || header.alg !== 'RS256') {
    throw new Error('JWT_ALGORITHM_REJECTED');
  }
  if (typeof header.kid !== 'string' || !header.kid) {
    throw new Error('JWT_KID_MISSING');
  }
  if (!(jwks instanceof Map) || !jwks.has(header.kid)) {
    throw new Error('JWT_JWKS_MISSING_KID');
  }
  if (!verify('RSA-SHA256', Buffer.from(parts[0] + '.' + parts[1]),
              jwks.get(header.kid), signature)) {
    throw new Error('JWT_BAD_SIGNATURE');
  }
  if (payload.iss !== issuer) throw new Error('JWT_WRONG_ISSUER');
  if (!(payload.aud === audience ||
      (Array.isArray(payload.aud) && payload.aud.includes(audience)))) {
    throw new Error('JWT_WRONG_AUDIENCE');
  }
  if (!Number.isFinite(payload.exp) || now >= payload.exp) throw new Error('JWT_EXPIRED');
  if (!Number.isFinite(payload.nbf) || now < payload.nbf) throw new Error('JWT_NOT_YET_VALID');
  return payload;
}

const keys = new Map([['synthetic-k1', first.publicKey], ['synthetic-k2', next.publicKey]]);
const token = issue(first.privateKey);

test('valid signed RS256 fictional assertion verifies cryptographically', () => {
  const result = verifyFixture(token, keys);
  assert.equal(result.iss, ISSUER);
  assert.ok(result.aud.includes(AUD));
});
test('missing, malformed and cookie-only assertion rejected', () => {
  for (const val of [undefined, null, '', 'not-a-jwt', 'e30.e30.', 'a.b.c.d']) {
    assert.throws(() => verifyFixture(val, keys));
  }
});
test('algorithm none, HS256, RS384 and missing kid rejected', () => {
  for (const alg of ['none', 'HS256', 'RS384']) {
    assert.throws(() => verifyFixture(issue(first.privateKey, { alg }), keys),
                  /JWT_ALGORITHM_REJECTED/);
  }
  assert.throws(() => verifyFixture(issue(first.privateKey, { kid: '' }), keys),
                /JWT_KID_MISSING/);
});
test('wrong issuer and wrong application audience rejected after valid signature', () => {
  assert.throws(() => verifyFixture(issue(first.privateKey, {}, { iss: 'https://wrong.cloudflareaccess.com' }), keys),
                /JWT_WRONG_ISSUER/);
  assert.throws(() => verifyFixture(issue(first.privateKey, {}, { aud: ['wrong-app'] }), keys),
                /JWT_WRONG_AUDIENCE/);
});
test('tampered header, payload and signature are rejected', () => {
  const [h, p, sig] = token.split('.');
  const forgedP = encode({ iss: ISSUER, aud: [AUD], exp: NOW + 6000, nbf: NOW - 5 });
  assert.throws(() => verifyFixture([h, forgedP, sig].join('.'), keys), /JWT_BAD_SIGNATURE/);
  const forgedH = encode({ alg: 'RS256', kid: 'synthetic-k2' });
  assert.throws(() => verifyFixture([forgedH, p, sig].join('.'), keys), /JWT_BAD_SIGNATURE/);
  const corrupted = Buffer.from(strictDecode(sig));
  corrupted[0] ^= 0x40;
  assert.throws(() => verifyFixture([h, p, corrupted.toString('base64url')].join('.'), keys),
                /JWT_BAD_SIGNATURE/);
});
test('expired, future nbf and nonnumeric exp fail closed', () => {
  assert.throws(() => verifyFixture(issue(first.privateKey, {}, { exp: NOW }), keys), /JWT_EXPIRED/);
  assert.throws(() => verifyFixture(issue(first.privateKey, {}, { nbf: NOW + 20 }), keys),
                /JWT_NOT_YET_VALID/);
  assert.throws(() => verifyFixture(issue(first.privateKey, {}, { exp: 'text' }), keys),
                /JWT_EXPIRED/);
});
test('unknown signing kid and missing JWKS fail closed', () => {
  assert.throws(() => verifyFixture(issue(first.privateKey, { kid: 'unknown' }), keys),
                /JWT_JWKS_MISSING_KID/);
  assert.throws(() => verifyFixture(token, new Map()), /JWT_JWKS_MISSING_KID/);
  assert.throws(() => verifyFixture(token, null), /JWT_JWKS_MISSING_KID/);
});
test('key-rotation overlap and old-key revocation', () => {
  const newToken = issue(next.privateKey, { kid: 'synthetic-k2' });
  assert.ok(verifyFixture(token, keys));
  assert.ok(verifyFixture(newToken, keys));
  const newOnly = new Map([['synthetic-k2', next.publicKey]]);
  assert.throws(() => verifyFixture(token, newOnly), /JWT_JWKS_MISSING_KID/);
  assert.ok(verifyFixture(newToken, newOnly));
});
test('all names and keys remain fictional and offline', () => {
  assert.ok(ISSUER.includes('review-only'));
  assert.ok(AUD.startsWith('REVIEW_ONLY'));
});
