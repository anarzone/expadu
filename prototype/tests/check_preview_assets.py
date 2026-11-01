"""Request the actual Travel styles/scripts concurrently, without browser cache."""
from concurrent.futures import ThreadPoolExecutor
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urlsplit, unquote
from urllib.request import urlopen, Request
import json
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
PAGE = '/dev/design/departures-next/'
BASE = 'http://127.0.0.1:' + (sys.argv[1] if len(sys.argv) > 1 else '8765')

class Assets(HTMLParser):
    def __init__(self):
        super().__init__()
        self.urls = []

    def handle_starttag(self, tag, attributes):
        values = dict(attributes)
        if tag == 'script' and values.get('src'):
            self.urls.append(urljoin(PAGE, values['src']))
        if tag == 'link' and values.get('rel') == 'stylesheet':
            self.urls.append(urljoin(PAGE, values['href']))

parser = Assets()
parser.feed((ROOT / PAGE.strip('/') / 'index.html').read_text())

def fetch(url):
    started = time.monotonic()
    try:
        with urlopen(Request(BASE + url, headers={'Cache-Control': 'no-cache'}), timeout=5) as response:
            actual = response.read()
            expected = (ROOT / unquote(urlsplit(url).path).lstrip('/')).read_bytes()
            result = {'url': url, 'status': response.status, 'matches': actual == expected}
    except Exception as error:
        result = {'url': url, 'error': str(error)}
    result['ms'] = round((time.monotonic() - started) * 1000)
    return result

rounds = []
for concurrency in [6, 6, 6, 24, 24, 24]:
    with ThreadPoolExecutor(max_workers=concurrency) as executor:
        results = list(executor.map(fetch, parser.urls))
    failures = [result for result in results if result.get('status') != 200 or not result.get('matches')]
    entry = {'concurrency': concurrency, 'requests': len(results), 'failures': failures,
             'slowest_ms': max(result['ms'] for result in results)}
    rounds.append(entry)
    print(json.dumps(entry), flush=True)
report = {'base': BASE, 'rounds': rounds, 'requests': sum(r['requests'] for r in rounds),
          'failures': sum(len(r['failures']) for r in rounds)}
if len(sys.argv) > 2:
    Path(sys.argv[2]).write_text(json.dumps(report, indent=2) + '\n')
print('TOTAL', report['requests'], 'FAILED', report['failures'])
sys.exit(bool(report['failures']))
