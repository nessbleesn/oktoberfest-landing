const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'analytics.js'), 'utf8');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
assert.equal((html.match(/data-discount-open data-event="discount_open"/g) || []).length, 3);
assert.match(html, /class="discount-dialog ym-hide-content"/);
assert.match(html, /class="ym-disable-submit"/);
assert.doesNotMatch(html, /<noscript>.*(?:mc\.yandex|top-fwz1)/s);

function fixture(initialChoice = null) {
  const storage = new Map();
  if (initialChoice) storage.set('oktoberfest_cookie_choice_v1', initialChoice);
  const scripts = [];
  const listeners = new Map();
  const docListeners = new Map();
  let reloads = 0;
  const on = (map, name, callback) => map.set(name, [...(map.get(name) || []), callback]);
  const dispatch = (map, name, detail) => (map.get(name) || []).forEach(callback => callback({detail}));
  const window = {addEventListener: (name, callback) => on(listeners, name, callback)};
  const document = {
    referrer: '',
    head: {append: script => scripts.push(script)},
    getElementById: id => scripts.find(script => script.id === id) || null,
    createElement: () => ({}),
    addEventListener: (name, callback) => on(docListeners, name, callback)
  };
  const localStorage = {
    getItem: key => storage.get(key) || null,
    setItem: (key, value) => storage.set(key, value)
  };
  const location = {href: 'https://parkskazka.ru/oktoberfest/', reload: () => reloads++};
  vm.runInNewContext(source, {window, document, localStorage, location, Date});
  return {
    window, scripts, localStorage,
    fire: (name, detail) => dispatch(listeners, name, detail),
    cookie: choice => {localStorage.setItem('oktoberfest_cookie_choice_v1', choice); dispatch(docListeners, 'cookie-choice', {choice});},
    reloads: () => reloads
  };
}

const test = fixture();
assert.equal(test.scripts.length, 0, 'no tracking script before consent');
assert.equal(test.window.ym, undefined);
assert.equal(test.window._tmr, undefined);
test.fire('festival:analytics', {name: 'discount_open'});
test.fire('festival:bitrix-lead-confirmed', {success: true, newLead: true});
assert.equal(test.scripts.length, 0, 'events before consent are not replayed');

test.cookie('analytics');
assert.equal(test.scripts.length, 2, 'load exactly two tags after consent');
assert.deepEqual(test.scripts.map(script => script.src), [
  'https://mc.yandex.ru/metrika/tag.js?id=107188789',
  'https://top-fwz1.mail.ru/js/code.js'
]);
assert.equal(test.window._tmr.filter(item => item.type === 'pageView').length, 1);
test.cookie('analytics');
assert.equal(test.scripts.length, 2, 'repeated consent does not duplicate tags');
assert.equal(test.window._tmr.filter(item => item.type === 'pageView').length, 1);
for (let i = 0; i < 3; i++) test.fire('festival:analytics', {name: 'discount_open'});
assert.equal(test.window._tmr.filter(item => item.goal === 'click-get-sale').length, 3);
test.fire('festival:bitrix-lead-confirmed', {success: false, newLead: true});
test.fire('festival:bitrix-lead-confirmed', {success: true, newLead: false});
assert.equal(test.window._tmr.filter(item => item.goal === 'send-get-sale').length, 0);
test.fire('festival:bitrix-lead-confirmed', {success: true, newLead: true});
assert.equal(test.window._tmr.filter(item => item.goal === 'send-get-sale').length, 1);
assert.deepEqual(Array.from(test.window.ym.a.at(-1)), [107188789, 'reachGoal', 'send_get_sale']);
test.cookie('essential');
assert.equal(test.reloads(), 1, 'revoking consent reloads the page to stop loaded tags');
test.fire('festival:analytics', {name: 'discount_open'});
assert.equal(test.window._tmr.filter(item => item.goal === 'click-get-sale').length, 3, 'revoked consent blocks goals');

const denied = fixture('essential');
assert.equal(denied.scripts.length, 0);
const accepted = fixture('analytics');
assert.equal(accepted.scripts.length, 2);
console.log('PASS consent, 3 coupon clicks, Bitrix-only goals, revocation');
