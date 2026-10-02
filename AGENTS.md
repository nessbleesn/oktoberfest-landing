# Oktoberfest landing release invariant

- `index.html` is the GitHub Pages version and must retain `<meta name="robots" content="noindex,nofollow">`.
- `https://parkskazka.ru/oktoberfest/` is the indexable production version. Never upload the root `index.html` there directly.
- Before each VPS static release, run `node --test tests/seo-variants.test.cjs` and `node scripts/build-production-html.cjs`; upload the generated `deploy/production/index.html` instead.
- See `RELEASE.md` for backup, checksum and public verification steps. Do not change form/API/database or publish unrelated files as part of this invariant.
