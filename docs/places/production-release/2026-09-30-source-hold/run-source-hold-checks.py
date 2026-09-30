"""Rehearse only in the existing lab; export an aggregate read-only staging package summary."""
import json
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parent
primary = root.parents[3]
candidate = Path('/Users/anar/.codex/worktrees/places-production/expadu-app')
lab = '/tmp/exp69-production-rehearsal-20260930'

setup = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'hetzner', 'docker', 'exec', 'staging-app', 'mkdir', '-p', lab + '/app/docs/places/production-release'], capture_output=True, timeout=30)
if setup.returncode:
    raise SystemExit('Isolated source-hold helper directory setup failed.')

files = [(candidate / 'docs/places/production-release/SourceProvenanceHoldJournal.php', lab + '/app/docs/places/production-release/SourceProvenanceHoldJournal.php')]
files += [(root / name, lab + '/' + name) for name in ['rehearse-source-hold.php', 'prepare-staging-source-hold.php', 'source-hold-readonly-connection.php', 'source-hold-runtime-fingerprint.json']]
for path, target in files:
    for args, data in [(['docker', 'exec', '-i', 'staging-app', 'tee', target], path.read_bytes()),
                       (['docker', 'exec', 'staging-app', 'chmod', '600', target], None)]:
        result = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'hetzner', *args], input=data, capture_output=True, timeout=120)
        if result.returncode:
            raise SystemExit('Isolated source-hold setup failed; diagnostic retained privately.')

for script, output in [('rehearse-source-hold.php', 'source-hold-rehearsal-summary.json'), ('prepare-staging-source-hold.php', 'staging-source-hold-package-summary.json')]:
    result = subprocess.run(['ssh', '-o', 'BatchMode=yes', 'hetzner', 'docker', 'exec', '-w', lab + '/app', 'staging-app', 'php', '-d', 'memory_limit=1G', lab + '/' + script], capture_output=True, timeout=300)
    if result.returncode:
        raise SystemExit('Source-hold check refused; diagnostic retained in the protected server directory.')
    summary = json.loads(result.stdout)
    assert summary['raw_rows_exported'] is False and summary['staging_changed'] is False and summary['production_changed'] is False
    if script.startswith('rehearse'):
        assert summary['persistent_lab_mutation'] is False and summary['protected_tables_restored'] == 14
    else:
        assert summary['live_hold_applied'] is False and summary['safety_guard_refusals_verified'] == 6
    (root / output).write_text(json.dumps(summary, indent=2) + '\n')
    print(json.dumps(summary), flush=True)
