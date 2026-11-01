"""Exercise cold asset loads against the real preview server configuration."""

from concurrent.futures import ThreadPoolExecutor
from functools import partial
from http.server import SimpleHTTPRequestHandler
import importlib.util
from pathlib import Path
import socket
import threading
import unittest


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("preview_server", ROOT / "serve.py")
preview = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preview)


class QuietHandler(SimpleHTTPRequestHandler):
    def log_message(self, *args):
        pass


class PreviewServerTests(unittest.TestCase):
    def test_pending_asset_burst_is_served_completely(self):
        """Queue a multi-tab burst before accepting, so a small backlog fails reliably."""
        asset = "/dev/design/departures-next/workspace.css"
        expected = (ROOT / asset.lstrip("/")).read_bytes()
        clients, failures = [], []
        with preview.create_server(0) as server:
            server.RequestHandlerClass = partial(QuietHandler, directory=str(ROOT))
            # No accept loop yet: every connection must survive in the backlog.
            for index in range(24):
                client = None
                try:
                    client = socket.create_connection(server.server_address, timeout=0.25)
                    client.sendall(
                        f"GET {asset}?cold={index} HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n".encode()
                    )
                    clients.append(client)
                except OSError as error:
                    failures.append(str(error))
                    if client:
                        client.close()

            worker = threading.Thread(target=server.serve_forever, daemon=True)
            worker.start()

            def read_response(client):
                with client:
                    client.settimeout(3)
                    chunks = []
                    try:
                        while chunk := client.recv(65536):
                            chunks.append(chunk)
                    except OSError as error:
                        return str(error)
                response = b"".join(chunks)
                headers, _, body = response.partition(b"\r\n\r\n")
                if not headers.startswith(b"HTTP/1.0 200 OK") or body != expected:
                    return "Incomplete or incorrect stylesheet response"
                return None

            try:
                with ThreadPoolExecutor(max_workers=24) as pool:
                    failures.extend(error for error in pool.map(read_response, clients) if error)
            finally:
                server.shutdown()
                worker.join(timeout=3)

        self.assertEqual(failures, [], f"Dropped {len(failures)} of 24 queued asset requests")
        self.assertEqual(len(clients), 24)


if __name__ == "__main__":
    unittest.main()
