"""Freeze real pages immediately before Composer initializes, to expose startup flashes.

Run on its own QA origin: python3 prototype/tests/composer_boot_preview.py
Use --phase initial to inspect the first HTML render with all scripts withheld.
Inspect /dev/design/{departures-next,events-next,bureaucracy-next,services-next}/
and /dev/design/today-next/brief/. The legacy Composer must not be visible even
though composer-entry.js has never run. No production HTML is copied or edited.
"""

import argparse
from functools import partial
from http.server import SimpleHTTPRequestHandler
import importlib.util
from pathlib import Path
import re
from urllib.parse import urlsplit


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("preview_server", ROOT / "serve.py")
preview = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preview)
PAGES = {
    f"/dev/design/{page}/": ROOT / "dev/design" / page / "index.html"
    for page in (
        "departures-next", "events-next", "bureaucracy-next", "services-next",
        "today-next/brief",
        "composer-studio",
    )
}


class BootPhaseHandler(SimpleHTTPRequestHandler):
    phase = "composer"

    def do_GET(self):
        page = PAGES.get(urlsplit(self.path).path)
        if page is None:
            return super().do_GET()
        source = page.read_text()
        paused = False

        def stop_at_composer(match):
            nonlocal paused
            if self.phase == "initial" or "composer-entry.js" in match.group():
                paused = True
            return "" if paused else match.group()

        source = re.sub(r"<script\b[^>]*>[\s\S]*?</script\s*>", stop_at_composer, source)
        if not paused:
            return self.send_error(500, "Composer entry was not found")
        label = "Initial HTML QA" if self.phase == "initial" else "Paused Composer QA"
        source = source.replace("<title>", f"<title>[{label}] ", 1)
        body = source.encode()
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=8766)
    parser.add_argument("--phase", choices=("composer", "initial"), default="composer")
    args = parser.parse_args()
    BootPhaseHandler.phase = args.phase
    with preview.PreviewServer(
        ("127.0.0.1", args.port), partial(BootPhaseHandler, directory=str(ROOT))
    ) as server:
        print(f"Paused Composer QA: http://127.0.0.1:{server.server_port}", flush=True)
        try:
            server.serve_forever()
        except KeyboardInterrupt:
            pass
