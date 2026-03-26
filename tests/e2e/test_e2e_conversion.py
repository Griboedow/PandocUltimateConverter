#!/usr/bin/env python3
"""
E2E browser test for PandocUltimateConverter on MediaWiki 1.39.

Flow:
  1. Log in as admin.
  2. Open Special:PandocUltimateConverter in a real (headless) Chromium browser.
  3. Select the URL source type and fill in the local fixture URL.
  4. Set the target page title and submit the conversion form.
  5. After the redirect, verify the converted page exists and contains the
     expected heading from the fixture HTML.

Screenshots are saved to /tmp/e2e_special_page.png and /tmp/e2e_result_page.png
so they can be uploaded as CI artifacts for visual inspection.
"""

import re
import sys

from playwright.sync_api import expect, sync_playwright

MW_BASE = "http://localhost:8080"
FIXTURE_URL = "http://localhost:8081/fixture.html"
TARGET_PAGE = "E2ETestConvertedPage"


def run() -> None:
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()

        # ------------------------------------------------------------------
        # Step 1: log in as admin so page-creation rights are available.
        # ------------------------------------------------------------------
        page.goto(f"{MW_BASE}/index.php?title=Special:UserLogin")
        page.fill("#wpName1", "admin")
        page.fill("#wpPassword1", "adminpassword")
        page.click("#wpLoginAttempt")
        # Wait until we leave the login page.
        page.wait_for_url(
            lambda url: "Special:UserLogin" not in url,
            timeout=15_000,
        )
        print("Logged in as admin")

        # ------------------------------------------------------------------
        # Step 2: open the special page and verify the form is present.
        # ------------------------------------------------------------------
        page.goto(f"{MW_BASE}/index.php?title=Special:PandocUltimateConverter")
        expect(page.locator("#mw-pandoc-upload-form")).to_be_visible()
        print("Special page loaded — form is visible")

        # Save a screenshot of the loaded special page.
        page.screenshot(path="/tmp/e2e_special_page.png")

        # ------------------------------------------------------------------
        # Step 3: choose the URL source type.
        # ------------------------------------------------------------------
        page.select_option("#wpConvertSourceType", "url")

        # ------------------------------------------------------------------
        # Step 4: fill the fixture URL and target page title.
        # ------------------------------------------------------------------
        url_input = page.locator("#wpUrlToConvert")
        url_input.wait_for(state="visible", timeout=5_000)
        url_input.fill(FIXTURE_URL)

        page.locator("#wpArticleTitle").fill(TARGET_PAGE)

        # ------------------------------------------------------------------
        # Step 5: submit and wait for redirect to the converted page.
        # ------------------------------------------------------------------
        page.click("#mw-pandoc-upload-form-submit")
        page.wait_for_url(
            re.compile(re.escape(TARGET_PAGE)),
            timeout=30_000,
        )
        print(f"Form submitted — redirected to: {page.url}")

        # Save a screenshot of the resulting page.
        page.screenshot(path="/tmp/e2e_result_page.png")

        # ------------------------------------------------------------------
        # Step 6: verify the page contains the heading from the fixture.
        # ------------------------------------------------------------------
        content_text = page.locator("#mw-content-text").inner_text()
        assert "E2E Test Heading" in content_text, (
            f"Expected 'E2E Test Heading' in page content.\n"
            f"Got (first 500 chars):\n{content_text[:500]}"
        )
        print("Page content validated — 'E2E Test Heading' found")

        browser.close()
    print("E2E test PASSED")


if __name__ == "__main__":
    try:
        run()
    except Exception as exc:
        print(f"E2E test FAILED: {exc}", file=sys.stderr)
        sys.exit(1)
