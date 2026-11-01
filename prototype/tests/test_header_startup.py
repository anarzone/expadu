"""The navigation must be usable in the initial HTML, before any scripts run."""

from html.parser import HTMLParser
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1] / "dev/design"
PAGES = (
    "departures-next", "events-next", "bureaucracy-next", "services-next",
    "today-next/brief", "composer-studio",
)


class HeaderParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.in_header = False
        self.in_nav = False
        self.current_link = None
        self.headers = 0
        self.nav_in_header = False
        self.links = []
        self.utilities = set()

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == "header" and "app-header" in attrs.get("class", "").split():
            self.in_header = True
            self.headers += 1
        if tag == "nav" and attrs.get("aria-label") == "Main navigation":
            self.in_nav = True
            self.nav_in_header = self.in_header
        if self.in_nav and tag == "a":
            self.current_link = {"label": "", "href": attrs.get("href"), "icons": 0}
            self.links.append(self.current_link)
        if self.current_link is not None and tag == "svg":
            self.current_link["icons"] += 1
        if self.in_header:
            self.utilities.update(attrs.get("class", "").split())

    def handle_data(self, data):
        if self.current_link is not None:
            self.current_link["label"] += data.strip()

    def handle_endtag(self, tag):
        if tag == "header":
            self.in_header = False
        if tag == "nav":
            self.in_nav = False
        if tag == "a":
            self.current_link = None


class HeaderStartupTests(unittest.TestCase):
    def test_navigation_and_icons_exist_before_javascript(self):
        """An empty shell or the old two-row shell must fail this contract."""
        for page in PAGES:
            with self.subTest(page=page):
                header = HeaderParser()
                header.feed((ROOT / page / "index.html").read_text())
                self.assertEqual(header.headers, 1, "Initial HTML needs one app header")
                self.assertTrue(header.nav_in_header, "Navigation must already be in the compact header")
                self.assertEqual([link["label"] for link in header.links],
                                 ["Today", "Events", "Paperwork", "Travel", "Services"])
                for link in header.links:
                    self.assertEqual(link["icons"], 1, "Icons must not wait for deferred scripts")
                    self.assertTrue(link["href"], "Navigation must work before enhancement")
                self.assertTrue({"brand", "city", "my-day", "account-button"} <= header.utilities)


if __name__ == "__main__":
    unittest.main()
