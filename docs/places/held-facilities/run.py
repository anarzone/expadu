"""Prepare and rehearse held public facilities only in the dedicated local catalogue."""
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
from selection import select_candidates, pack

HERE = Path(__file__).resolve().parent
APP = HERE.parents[2]
SOURCE = Path('/Users/anar/Projects/Own/Startups/expadu-app')
PRIVATE = SOURCE/'storage/app/private/places-local/2026-10-01'
OUTPUT = PRIVATE/'held-public-facilities'
REPORTS = HERE/'2026-10-01'
os.umask(0o077)
OUTPUT.mkdir(exist_ok=True)
REPORTS.mkdir(exist_ok=True)

def digest(path):
    with path.open('rb') as handle: return hashlib.file_digest(handle, 'sha256').hexdigest()

def save(path, value):
    with path.open('x') as handle: json.dump(value, handle, indent=2, ensure_ascii=False); handle.write('\n')

def report(name, value):
    (REPORTS/name).write_text(json.dumps(value, indent=2, ensure_ascii=False)+'\n')
    print(json.dumps(value, ensure_ascii=False), flush=True)

action = sys.argv[1]
if action == 'select':
    inventory = SOURCE/'docs/places/cologne-expansion/2026-09-28/inventory.sqlite'
    baseline = PRIVATE/'places-only.json'
    expected = json.loads((PRIVATE/'supported-package/manifest.json').read_text())
    assert digest(inventory) == expected['inventory_sha256'] and digest(baseline) == expected['baseline_sha256']
    document = json.loads(baseline.read_text())
    with sqlite3.connect(inventory.resolve().as_uri()+'?mode=ro', uri=True) as db:
        db.row_factory = sqlite3.Row
        records = [r for row in db.execute("SELECT * FROM source_records WHERE source='osm' AND inside_city=1 ORDER BY id") if (r := pack.normalized(row)) is not None]
    db.close()
    holds = [json.loads(x) for x in (PRIVATE/'supported-package/holds.jsonl').read_text().splitlines()]
    edges = [json.loads(x) for x in (PRIVATE/'supported-package/identity-review.jsonl').read_text().splitlines()]
    selected = select_candidates(holds, edges, document['tables']['spots'], records, document['tables']['place_fact_observations'])
    assert 0 < len(selected['records']) <= 602
    save(OUTPUT/'selection.json', selected)
    summary = selected['summary'] | {'status': 'selected_pending_source_and_native_checks', 'prepared_at': datetime.now(timezone.utc).isoformat(),
              'input_sha256': {str(p.relative_to(PRIVATE)) if p.is_relative_to(PRIVATE) else 'inventory': digest(p) for p in [inventory, baseline, PRIVATE/'supported-package/holds.jsonl', PRIVATE/'supported-package/identity-review.jsonl']},
              'selection_sha256': digest(OUTPUT/'selection.json'), 'remote_changed': False}
    report('selection-summary.json', summary)
elif action == 'fetch':
    from fetch import collect
    expected = json.loads((REPORTS/'selection-summary.json').read_text())
    assert digest(OUTPUT/'selection.json') == expected['selection_sha256']
    if (OUTPUT/'source-proof.json').exists(): raise FileExistsError('Preserve previous source proof')
    result = collect(json.loads((OUTPUT/'selection.json').read_text())['records'])
    result['summary']['selection_sha256'] = expected['selection_sha256']
    save(OUTPUT/'source-proof.json', result)
    report('source-summary.json', result['summary'] | {'source_proof_sha256': digest(OUTPUT/'source-proof.json')})
elif action == 'rehearse':
    subprocess.run([sys.executable, str(HERE.parent/'local-catalogue/run.py'), '--source-root', str(SOURCE), 'held-public-facilities'], check=True)
elif action == 'verify':
    subprocess.run([sys.executable, '-m', 'unittest', 'discover', '-s', str(HERE), '-p', 'test_*.py'], check=True)
    summary = json.loads((REPORTS/'rehearsal-summary.json').read_text())
    assert summary['status'] == 'passed' and summary['exact_baseline_restored'] and summary['sequences_restored']
    assert digest(OUTPUT/'selection.json') == summary['selection_sha256']
    assert digest(OUTPUT/'source-proof.json') == summary['source_proof_sha256']
    assert digest(OUTPUT/'candidate-places.jsonl') == summary['candidate_export_sha256']
    subprocess.run([sys.executable, str(HERE.parent/'local-catalogue/run.py'), '--source-root', str(SOURCE), 'verify-snapshot'], check=True)
    print(json.dumps({'status': 'passed', 'qualified_facilities': summary['qualified_facilities'], 'combined_candidates': summary['combined_candidates'], 'remote_changed': False}))
else:
    raise SystemExit('Choose select, fetch, rehearse or verify')
