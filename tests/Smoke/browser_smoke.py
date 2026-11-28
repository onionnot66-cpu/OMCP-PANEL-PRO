"""Exercise the compiled login and owner dashboard in a real headless Chrome."""
import json
import os
import shutil
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support import expected_conditions as condition
from selenium.webdriver.support.ui import WebDriverWait

BASE = "http://127.0.0.1:8080"


def check_account(username, own, other, fixture):
    options = webdriver.ChromeOptions()
    options.binary_location = shutil.which("google-chrome")
    options.add_argument("--headless=new")
    options.add_argument("--window-size=1200,900")
    options.add_argument("--disable-background-networking")
    with webdriver.Chrome(service=Service(shutil.which("chromedriver")), options=options) as browser:
        wait = WebDriverWait(browser, 30)
        browser.get(BASE + "/auth/login")
        wait.until(condition.visibility_of_element_located((By.NAME, "username"))).send_keys(username)
        browser.find_element(By.NAME, "password").send_keys(os.environ["OMCP_SMOKE_PASSWORD"])
        browser.find_element(By.CSS_SELECTOR, "button[type=submit]").click()
        selector = (By.CSS_SELECTOR, "section[aria-label='OMCP reservations']")
        wait.until(condition.text_to_be_present_in_element(selector, own))
        if other in browser.find_element(*selector).text:
            raise RuntimeError("Dashboard displayed another owner's reservation")
        if username == "omcp_ci_admin":
            browser.get(BASE + f"/admin/nodes/view/{fixture['node']}/omcp/instances")
            runtime = wait.until(condition.visibility_of_element_located((By.ID, "runtime")))
            for name in ("native", "podman", "docker"):
                runtime.find_element(By.CSS_SELECTOR, f"option[value='{name}']")
            wait.until(condition.presence_of_element_located((By.CSS_SELECTOR, "button[data-action='retire']")))
        else:
            controls = wait.until(condition.visibility_of_element_located((By.CSS_SELECTOR, "[aria-label='Trusted server controls']")))
            for action in ("start", "stop", "restart", "status", "metrics", "console"):
                if not any(button.text.lower() == action for button in controls.find_elements(By.TAG_NAME, "button")):
                    raise RuntimeError("Missing owner control: " + action)
            browser.set_window_size(390, 844)
            if not browser.find_element(*selector).is_displayed():
                raise RuntimeError("Owner reservations disappeared at mobile width")


if __name__ == "__main__":
    if os.environ.get("DB_DATABASE") != "omcp_test_ci":
        raise RuntimeError("Browser smoke requires the disposable panel test environment")
    with open(os.environ["OMCP_SMOKE_FIXTURE"], encoding="utf-8") as source:
        fixture = json.load(source)
    check_account("omcp_ci_owner", "Owner test instance", "Admin test instance", fixture)
    check_account("omcp_ci_admin", "Admin test instance", "Owner test instance", fixture)
    print("Chrome login, owner dashboard, mobile view and admin runtime selector passed.")
