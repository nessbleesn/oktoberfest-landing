const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
const friday = html.split('id="friday" data-panel="friday"')[1].split('id="saturday" data-panel="saturday"')[0];
const saturday = html.split('id="saturday" data-panel="saturday"')[1].split('id="sunday" data-panel="sunday"')[0];
const sunday = html.split('id="sunday" data-panel="sunday"')[1].split('</section>')[0];

test('Friday game stations and food workshop have separate time windows', () => {
  assert.match(friday, /16:00–19:00<\/time>.*Игровые станции/);
  assert.match(friday, /17:00–22:00<\/time>.*Фудовая площадка «Брецели»/);
  assert.doesNotMatch(friday, /16:00–22:00<\/time>/);
});

test('Saturday host and DJ times match the festival schedule', () => {
  assert.match(saturday, /14:00–19:00<\/time>.*Ведущий и интерактивы/);
  assert.match(saturday, /19:00–21:00<\/time>.*DJ-сет/);
  assert.match(html, /Суббота · 19:00–21:00/);
  assert.match(html, /24 октября, с 19:00 до 21:00.*DJ-сет/);
});

test('Weekend opening remains at 11:00', () => {
  assert.match(html, /24–25 октября<\/span><strong>11:00–22:00/);
  assert.match(html, /25 ОКТЯБРЯ<\/span><strong>Воскресенье<\/strong><span class="day-hours"[^>]*>11:00–22:00/);
});

test('Indoor activities and FAQ point to the loft', () => {
  assert.match(friday, /Фудовая площадка «Брецели».*?<span>ЛОФТ<\/span>/);
  assert.match(saturday, /Детские мастер-классы.*?<span>ЛОФТ<\/span>/);
  assert.match(saturday, /Ведущий и интерактивы.*?<span>ЛОФТ<\/span>/);
  assert.match(saturday, /DJ-сет.*?<span>ЛОФТ<\/span>/);
  assert.match(sunday, /Ведущий и конкурсы.*?<span>ЛОФТ<\/span>/);
  assert.match(sunday, /Трансляция матча.*?Смотрим игру вместе в лофте\.<\/p><span>ЛОФТ · 18\+<\/span>/);
  assert.doesNotMatch(sunday, /спорт-бар|СПОРТ-БАР/i);
  assert.match(html, /Что будет при плохой погоде\?<\/summary><p>Мастер-классы, конкурсы и DJ-сет запланированы в лофте/);
  assert.doesNotMatch(friday + saturday + sunday, /ПЛОЩАДЬ ПЕРЕД СЦЕНОЙ|<span>СЦЕНА/);
});
