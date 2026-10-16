'use strict';
// Run: node --test tests/chat-media-url-regression.cjs
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const chat = fs.readFileSync(path.join(root, 'assets/js/chat/chat.js'), 'utf8');
const profile = fs.readFileSync(path.join(root, 'assets/js/chat/profile-view.js'), 'utf8');

function resolver(source, from, until, exportName, baseUrl = 'https://ecollab.tech') {
  const start = source.indexOf(from);
  const end = source.indexOf(until, start);
  assert.ok(start >= 0 && end > start, 'resolver must remain discoverable');
  const sandbox = { URL, window: { ECOLLAB: { baseUrl }, location: { origin: 'https://ecollab.tech' } } };
  // The profile resolver depends on the module-level base() helper.
  vm.runInNewContext('const base = () => window.ECOLLAB.baseUrl;\n' + source.slice(start, end) + '\nthis.resolved = ' + exportName + ';', sandbox);
  return sandbox.resolved;
}

test('chat avatars accept API-relative, root-relative and HTTPS paths', () => {
  const resolve = resolver(chat, 'function chatAvatarUrl(', '\nconst API_BASE', 'chatAvatarUrl');
  assert.equal(resolve('uploads/avatars/a.webp'), 'https://ecollab.tech/uploads/avatars/a.webp');
  assert.equal(resolve('/uploads/avatars/a.webp'), 'https://ecollab.tech/uploads/avatars/a.webp');
  assert.equal(resolve('https://lh3.googleusercontent.com/a.png'), 'https://lh3.googleusercontent.com/a.png');
  assert.equal(resolve('javascript:alert(1)'), '');
});

test('chat attachments use safe, canonical URLs', () => {
  const resolve = resolver(chat, 'function chatAvatarUrl(', '\nconst API_BASE', 'chatMediaUrl');
  assert.equal(resolve('uploads/test.png'), 'https://ecollab.tech/uploads/test.png');
  assert.equal(resolve('/uploads/test.png'), 'https://ecollab.tech/uploads/test.png');
  assert.equal(resolve('data:text/html,<svg/>'), '');
  assert.equal(resolve('blob:example'), '');
  assert.match(chat, /onerror="this\.remove\(\)"/, 'broken chat avatars keep fallback initials');
  assert.match(chat, /rel="noopener noreferrer"/, 'attachment links open without opener access');
});

test('profile modal preserves avatar fallback with canonical URLs', () => {
  const resolve = resolver(profile, '  function avatarUrl(', '  function ensureStyles(', 'avatarUrl');
  assert.equal(resolve('/uploads/avatars/example.webp'), 'https://ecollab.tech/uploads/avatars/example.webp');
  assert.equal(resolve('uploads/avatars/example.webp'), 'https://ecollab.tech/uploads/avatars/example.webp');
  assert.equal(resolve('javascript:alert(1)'), '');
  assert.match(profile, /photo\.onload=/);
  assert.match(profile, /photo\.onerror=/);
});
