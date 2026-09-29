import os
from playwright.sync_api import sync_playwright, expect, TimeoutError as PlaywrightTimeout

URL = os.environ.get('BROWSER_RELEASE_URL', 'http://127.0.0.1:8850/')
COOKIE_KEY = 'oktoberfest_cookie_choice_v1'


def block_trackers(page):
    page.route('https://mc.yandex.ru/**', lambda route: route.abort())
    page.route('https://top-fwz1.mail.ru/**', lambda route: route.abort())
    page.route('**/*', lambda route: route.abort() if route.request.resource_type in ('image', 'font', 'media') else route.fallback())


def goals(page, name):
    return page.evaluate("""goal => (window._tmr || []).filter(item => item.goal === goal).length""", name)


with sync_playwright() as playwright:
    for engine in (playwright.chromium, playwright.webkit):
        browser = engine.launch(headless=True)
        try:
            no_consent = browser.new_page(viewport={'width': 390, 'height': 844})
            requests = []
            no_consent.on('request', lambda req: requests.append(req.url))
            block_trackers(no_consent)
            no_consent.goto(URL, wait_until='domcontentloaded')
            try:
                no_consent.wait_for_load_state('networkidle', timeout=5000)
            except PlaywrightTimeout:
                pass
            assert not any('mc.yandex.ru/metrika' in req or 'top-fwz1.mail.ru/js' in req for req in requests)
            assert no_consent.locator('[data-discount-open]').count() == 3
            no_consent.close()

            for width, height in ((320, 700), (390, 844), (1440, 900), (1920, 1080)):
                page = browser.new_page(viewport={'width': width, 'height': height})
                block_trackers(page)
                page.add_init_script(f"localStorage.setItem('{COOKIE_KEY}', 'analytics')")
                errors = []
                page.on('pageerror', lambda err: errors.append(str(err)))
                page.goto(URL, wait_until='domcontentloaded')
                try:
                    page.wait_for_load_state('networkidle', timeout=5000)
                except PlaywrightTimeout:
                    pass  # Analytics keep a network connection active in some engines.
                assert not errors, f'{engine.name} {width}: {errors}'
                assert not page.evaluate('document.documentElement.scrollWidth > innerWidth + 1'), f'{engine.name} {width}: horizontal overflow'
                assert page.locator('h1').is_visible()
                assert page.locator('#discount-dialog').count() == 1
                if width == 390:
                    buttons = page.locator('[data-discount-open]')
                    for index in range(3):
                        buttons.nth(index).scroll_into_view_if_needed()
                        buttons.nth(index).click()
                        assert page.locator('#discount-dialog').is_visible()
                        page.locator('[data-discount-close]').first.click()
                    assert goals(page, 'click-get-sale') == 3

                    page.route('**/oktoberfest-api/promo', lambda route: route.fulfill(
                        status=200, content_type='application/json',
                        body='{"success":true,"promo_code":"EDA1","delivery_pending":false,"bitrix_success":true,"bitrix_new_lead":true}'))
                    buttons.first.click()
                    page.locator('#discount-email').fill('qa@example.test')
                    page.locator('#discount-personal').check()
                    page.locator('#discount-form button[type="submit"]').click()
                    expect(page.locator('#discount-status')).to_contain_text('EDA1')
                    assert goals(page, 'send-get-sale') == 1
                    assert page.evaluate("(window.ym.a || []).filter(args => args[2] === 'send_get_sale').length") == 1
                page.close()
            print(f'PASS {engine.name}: no pre-consent trackers, four widths, three CTA, CRM-only goals')
        finally:
            browser.close()
