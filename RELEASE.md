# Публикация Октоберфеста

Индексация двух адресов намеренно различается:

- GitHub Pages `https://nessbleesn.github.io/oktoberfest-landing/` получает корневой `index.html` с `noindex,nofollow`.
- Основной сайт `https://parkskazka.ru/oktoberfest/` должен получать HTML **без** этой meta-строки. Нельзя напрямую копировать корневой `index.html` на VPS.

Перед каждым статическим выпуском:

```powershell
node --test tests/seo-variants.test.cjs
node scripts/build-production-html.cjs
```

Второй шаг создаёт `deploy/production/index.html` (каталог игнорируется Git). Используйте **этот** файл как HTML для VPS. Остальные утверждённые статические файлы берите из того же commit. Генератор меняет только одну строку и останавливается при неожиданной разметке; проверка также запускается в GitHub Actions при push и pull request.

Перед заменой на VPS сверьте исходный commit и точные файлы, сохраните резервную копию старого HTML, проверьте SHA-256 staging и замените файл атомарно. После публикации проверьте чистые URL без query-параметров: основной адрес отвечает 200 без meta `noindex` и без `X-Robots-Tag`; GitHub Pages отвечает 200 с `noindex,nofollow`. Форму проверяйте без отправки реальной заявки, если нет отдельного разрешения.

Этот скрипт только готовит HTML и **не** публикует его автоматически.
