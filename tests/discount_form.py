"""Browser contract tests; all form requests are intercepted (no real leads)."""

import json
import os
from playwright.sync_api import sync_playwright


BASE_URL = os.getenv("OKTOBERFEST_TEST_URL", "http://127.0.0.1:8769/")
SUCCESS = "Спасибо! Заявка принята. Промокод отправлен на указанную почту"
PENDING = "Спасибо! Заявка принята. Отправка письма задерживается — промокод придёт на указанную почту позже"
DUPLICATE = "Для этого адреса электронной почты купон уже был оформлен"


def scenario(browser, marketing, duplicate=False, pending=False, width=390):
    context = browser.new_context(viewport={"width": width, "height": 844 if width < 700 else 900})
    page = context.new_page()
    requests = []
    page.route("**/mc.yandex.ru/**", lambda route: route.abort())
    page.route("**/top-fwz1.mail.ru/**", lambda route: route.abort())

    def promo(route):
        requests.append(json.loads(route.request.post_data))
        if duplicate:
            route.fulfill(status=409, content_type="application/json", body='{"success":false,"error":"duplicate_email"}')
        else:
            route.fulfill(status=200, content_type="application/json", body=json.dumps({"success": True, "promo_code": "EDA42", "delivery_pending": pending, "bitrix_success": True, "bitrix_new_lead": True}))

    page.route("**/oktoberfest-api/promo", promo)
    page.goto(BASE_URL, wait_until="networkidle")
    notice = page.locator("[data-cookie-dismiss]")
    if notice.is_visible():
        notice.click()
    page.locator("[data-discount-open]").first.click()
    assert page.evaluate("document.documentElement.scrollWidth <= innerWidth + 1")
    personal = page.locator("#discount-personal")
    advertising = page.locator("#discount-marketing")
    assert personal.is_checked() and personal.get_attribute("aria-disabled") == "true"
    personal.click(force=True)
    assert personal.is_checked()
    personal.focus()
    personal.press("Space")
    assert personal.is_checked()
    assert advertising.is_checked()
    if not marketing:
        advertising.uncheck()
    page.locator("#discount-email").fill("visitor@example.test")
    page.locator('#discount-form [type="submit"]').click()
    expected = DUPLICATE if duplicate else PENDING if pending else SUCCESS
    page.locator("#discount-status").get_by_text(expected, exact=True).wait_for()
    assert len(requests) == 1
    assert requests[0]["personal_consent"] is True
    assert requests[0]["marketing_consent"] is marketing
    assert "EDA42" not in page.locator("#discount-dialog").inner_text()
    screenshots = os.getenv("OKTOBERFEST_SCREENSHOT_DIR")
    if screenshots and marketing and not duplicate:
        page.locator("#discount-dialog").screenshot(path=os.path.join(screenshots, f"form-{browser.browser_type.name}-{width}.png"))
    context.close()


with sync_playwright() as playwright:
    for engine in (playwright.chromium, playwright.webkit):
        browser = engine.launch(headless=True)
        for width in (320, 390, 1440):
            scenario(browser, marketing=True, width=width)
            scenario(browser, marketing=False, width=width)
            scenario(browser, marketing=False, duplicate=True, width=width)
        scenario(browser, marketing=False, pending=True, width=390)
        print(f"PASS {engine.name}: 320/390/1440, consent on/off, success/pending/duplicate, no visible code")
        browser.close()
