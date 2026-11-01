"""Travel's primary action must not move when its controls initialize."""

from html.parser import HTMLParser
from pathlib import Path
import unittest


PAGE = Path(__file__).resolve().parents[1] / "dev/design/departures-next/index.html"


class PlannerParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.modes = []
        self.current = None
        self.before_submit = True

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == "button" and "find-routes" in attrs.get("class", "").split():
            self.before_submit = False
        if tag == "button" and "data-route-mode" in attrs:
            self.current = {
                "id": attrs["data-route-mode"], "label": "", "icons": 0,
                "pressed": attrs.get("aria-pressed"), "type": attrs.get("type"),
                "before_submit": self.before_submit,
            }
            self.modes.append(self.current)
        if tag == "svg" and self.current is not None:
            self.current["icons"] += 1

    def handle_data(self, data):
        if self.current is not None:
            self.current["label"] += data.strip()

    def handle_endtag(self, tag):
        if tag == "button":
            self.current = None


class TravelStartupTests(unittest.TestCase):
    def test_complete_mode_row_exists_before_javascript(self):
        """An empty mode row causes a 44px shift of Find a route on startup."""
        parser = PlannerParser()
        parser.feed(PAGE.read_text())
        self.assertEqual([mode["id"] for mode in parser.modes], ["transit", "bike", "walk"])
        self.assertEqual([mode["label"] for mode in parser.modes], ["Transit", "Cycle", "Walk"])
        self.assertEqual([mode["pressed"] for mode in parser.modes], ["true", "false", "false"])
        for mode in parser.modes:
            self.assertEqual(mode["type"], "button")
            self.assertEqual(mode["icons"], 1)
            self.assertTrue(mode["before_submit"])


if __name__ == "__main__":
    unittest.main()
