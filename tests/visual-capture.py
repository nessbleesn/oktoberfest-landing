from pathlib import Path
import json
from playwright.sync_api import sync_playwright

url = 'https://parkskazka.ru/oktoberfest/?qa=20260929'
output = Path(__file__).parent / 'output'
output.mkdir(exist_ok=True)
with sync_playwright() as p:
    for engine, width, height, name in [
        (p.webkit, 390, 844, 'live-iphone.png'),
        (p.chromium, 1440, 900, 'live-desktop.png'),
    ]:
        browser = engine.launch(headless=True)
        page = browser.new_page(viewport={'width': width, 'height': height}, device_scale_factor=1)
        page.goto(url, wait_until='domcontentloaded', timeout=45000)
        page.wait_for_timeout(1500)
        page.screenshot(path=str(output / name), full_page=False)
        page.locator('footer').scroll_into_view_if_needed()
        page.wait_for_timeout(2500)
        style = page.locator('.closing .button').evaluate("e => ({text:e.textContent, color:getComputedStyle(e).color, fill:getComputedStyle(e).webkitTextFillColor, opacity:getComputedStyle(e).opacity, visibility:getComputedStyle(e).visibility})")
        print(name, json.dumps(style, ensure_ascii=True))
        page.screenshot(path=str(output / name.replace('.png', '-footer.png')), full_page=False)
        browser.close()
