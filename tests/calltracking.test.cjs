const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

test('Calltracking.ru loader is included exactly once', () => {
  assert.equal((html.match(/phone\.4d847\.16824\.async\.js/g) || []).length, 1);
  assert.match(html, /ct\.async\s*=\s*true/);
});

test('both phone links retain the real number as fallback', () => {
  assert.equal((html.match(/href="tel:\+74951336556"/g) || []).length, 2);
  assert.equal((html.match(/\+7 \(495\) 133-65-56/g) || []).length, 2);
  assert.doesNotMatch(html, /class="header-phone"[^>]*aria-label=/);
});
