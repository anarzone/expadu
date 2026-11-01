"""Serve the connected previews, including bursts of parallel asset requests."""

import argparse
from functools import partial
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


PROTOTYPE = Path(__file__).resolve().parent


class PreviewServer(ThreadingHTTPServer):
    # The five-connection default resets queued requests on this macOS host.
    # A cold page has 40 styles/scripts; several preview tabs may load together.
    request_queue_size = 128


class PreviewHandler(SimpleHTTPRequestHandler):
    # Always revalidate, so an edited preview never shows a stale page or
    # script. Unchanged files still answer 304 from Last-Modified.
    def end_headers(self):
        self.send_header("Cache-Control", "no-cache")
        super().end_headers()


def create_server(port=8765):
    return PreviewServer(
        ("127.0.0.1", port),
        partial(PreviewHandler, directory=str(PROTOTYPE)),
    )


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, default=8765)
    args = parser.parse_args()
    with create_server(args.port) as server:
        print(f"Expadu prototype ready at http://127.0.0.1:{server.server_port}", flush=True)
        try:
            server.serve_forever()
        except KeyboardInterrupt:
            pass


if __name__ == "__main__":
    main()
