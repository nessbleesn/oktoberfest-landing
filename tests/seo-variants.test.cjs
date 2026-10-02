const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { buildProductionHtml } = require('../scripts/build-production-html.cjs');

const source = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const directiveLine = /^  <meta name="robots" content="noindex,nofollow">\r?\n/m;

test('GitHub Pages source remains noindex', () => {
  assert.match(source, directiveLine);
});

test('production variant removes only the Pages directive', () => {
  const production = buildProductionHtml(source);
  assert.equal(production, source.replace(directiveLine, ''));
  assert.doesNotMatch(production, /<meta\b[^>]*\bname\s*=\s*["']robots["']/i);
});

test('unexpected robots directives fail closed', () => {
  assert.throws(() => buildProductionHtml(source.replace(directiveLine, '')));
  assert.throws(() => buildProductionHtml(source.replace(directiveLine, '  <meta name="robots" content="index,follow">\n')));
  assert.throws(() => buildProductionHtml(source.replace(directiveLine, match => match + match)));
});
