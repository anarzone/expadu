"""Frozen additional-place preparation; only bounded public reads and local checks."""
from collections import Counter
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
from selection import select_candidates

HERE = Path(__file__).resolve().parent
APP = HERE.parents[2]
SOURCE = Path('/Users/anar/Projects/Own/Startups/expadu-app')
PRIVATE = SOURCE/'storage/app/private/places-local/2026-10-01'
OUTPUT = PRIVATE/'ten-thousand-catalogue'
REPORTS = HERE/'2026-10-01'
os.umask(0o077)
OUTPUT.mkdir(exist_ok=True)
REPORTS.mkdir(exist_ok=True)


def digest(path):
    with path.open('rb') as handle:
        return hashlib.file_digest(handle, 'sha256').hexdigest()


def save(path, value):
    with path.open('x') as handle:
        json.dump(value, handle, indent=2, ensure_ascii=False)
        handle.write('\n')


def report(name, value):
    (REPORTS/name).write_text(json.dumps(value, indent=2, ensure_ascii=False)+'\n')
    print(json.dumps(value, ensure_ascii=False), flush=True)


def check_inputs(summary):
    for name, expected in summary['inputs_sha256'].items():
        path = SOURCE/'docs/places/cologne-expansion/2026-09-28/inventory.sqlite' if name == 'inventory' else PRIVATE/name
        if digest(path) != expected:
            raise ValueError('Frozen selection input changed')
    if digest(OUTPUT/'selection.json') != summary['selection_sha256']:
        raise ValueError('Frozen selection changed')


def local_environment():
    values = dict(line.split('=',1) for line in (SOURCE/'.env').read_text().splitlines() if line and not line.startswith('#') and '=' in line)
    env = os.environ.copy()
    for key in ['DB_CONNECTION','DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD']:
        if key in values:
            env[key] = values[key].strip('\"\'')
    if env.get('DB_HOST') not in {'127.0.0.1','localhost','::1'}:
        raise ValueError('Dedicated loopback database required')
    env.update(APP_ENV='testing',APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',DB_URL='',DB_PORT='15434',DB_DATABASE='exp69_local_catalogue_20261001',CACHE_STORE='array',SESSION_DRIVER='array',QUEUE_CONNECTION='sync',PLACES_LOCAL_PRIVATE=str(PRIVATE),PLACES_SOURCE_ROOT=str(SOURCE),PLACES_LOCAL_REPORTS=str(REPORTS),PLACES_AUTOMATION_ENABLED='false',PLACES_CURATED_SEEDING_ENABLED='false')
    env['PATH']='/Users/anar/Library/Application Support/Herd/bin:'+env['PATH']
    return env


action = sys.argv[1]
if action == 'select':
    inventory = SOURCE/'docs/places/cologne-expansion/2026-09-28/inventory.sqlite'
    paths = {'inventory':inventory,'places-only.json':PRIVATE/'places-only.json','supported-package/records.jsonl':PRIVATE/'supported-package/records.jsonl','held-public-facilities/candidate-places.jsonl':PRIVATE/'held-public-facilities/candidate-places.jsonl'}
    expected = json.loads((PRIVATE/'supported-package/manifest.json').read_text())
    assert digest(inventory) == expected['inventory_sha256'] and digest(paths['places-only.json']) == expected['baseline_sha256']
    baseline = json.loads(paths['places-only.json'].read_text())
    additions = [json.loads(line) for line in paths['supported-package/records.jsonl'].read_text().splitlines()]
    manual = json.loads((HERE.parent/'production-pack/review-holds.json').read_text())
    with sqlite3.connect(inventory.resolve().as_uri()+'?mode=ro',uri=True) as db:
        db.row_factory=sqlite3.Row
        rows = db.execute("SELECT * FROM source_records WHERE source='osm' AND inside_city=1 ORDER BY id")
        result = select_candidates(rows,baseline['tables']['spots'],additions,manual)
    if len(result['records']) < 6000:
        raise ValueError('Additional verified discovery is needed before this package can reach 10k')
    save(OUTPUT/'selection.json',result)
    report('selection-summary.json',result['summary']|{'status':'selected_pending_current_source_and_native_geometry','inputs_sha256':{name:digest(path) for name,path in paths.items()},'selection_sha256':digest(OUTPUT/'selection.json'),'category_map_sha256':digest(HERE/'categories.py'),'remote_changed':False})
elif action == 'fetch':
    summary=json.loads((REPORTS/'selection-summary.json').read_text());check_inputs(summary)
    if (OUTPUT/'source-proof.json').exists():
        raise FileExistsError('Preserve completed source proof')
    spec=importlib.util.spec_from_file_location('public_source_fetch',HERE.parent/'held-facilities/fetch.py')
    reader=importlib.util.module_from_spec(spec);spec.loader.exec_module(reader)
    selected=json.loads((OUTPUT/'selection.json').read_text())['records']
    all_records=[];nodes={};parts=[];requests=[];checked=[]
    for index,start in enumerate(range(0,len(selected),750)):
        path=OUTPUT/('source-cohort-%02d.json'%index)
        cohort=selected[start:start+750]
        if path.exists():
            part=json.loads(path.read_text())
            if part['summary']['selection_sha256'] != summary['selection_sha256'] or [r['source_id'] for r in part['records']] != [r['source_id'] for r in cohort]:
                raise ValueError('Existing source cohort differs')
        else:
            part=reader.collect(cohort)
            part['summary']['selection_sha256']=summary['selection_sha256']
            save(path,part)
        for number,node in part['geometry_nodes'].items():
            if str(number) in nodes and nodes[str(number)] != node:
                raise ValueError('Geometry changed between bounded source cohorts')
            nodes[str(number)]=node
        all_records.extend(part['records']);requests.extend(part['summary']['requests']);checked.append(part['summary']['checked_at'])
        parts.append({'path':path.name,'sha256':digest(path),'records':len(cohort)})
        print(json.dumps({'phase':'source_check','checked':len(all_records),'selected':len(selected)}),flush=True)
    proof={'records':all_records,'geometry_nodes':nodes,'summary':{'checked_at':min(checked),'latest_cohort_checked_at':max(checked),'selection_sha256':summary['selection_sha256'],'requested':len(selected),'matching_source_objects':sum(not r['reasons'] for r in all_records),'refusal_reasons':dict(Counter(reason for r in all_records for reason in r['reasons'])),'geometry_nodes':len(nodes),'cohorts':parts,'requests':requests,'contributor_identities_retained':False,'source':'https://api.openstreetmap.org/api/0.6'}}
    save(OUTPUT/'source-proof.json',proof)
    report('source-summary.json',{key:value for key,value in proof['summary'].items() if key!='requests'}|{'source_proof_sha256':digest(OUTPUT/'source-proof.json'),'remote_changed':False})
elif action == 'freeze-runtime':
    if (OUTPUT/'runtime-manifest.json').exists():
        raise FileExistsError('Preserve runtime manifest')
    paths=set(subprocess.check_output(['git','ls-files','app','config','bootstrap','routes'],cwd=APP,text=True).splitlines())
    paths |= {str(path.relative_to(APP)) for path in HERE.glob('*.php')} | {str(path.relative_to(APP)) for path in HERE.glob('*.py')}
    paths |= {'docs/places/production-pack/apply-pack.php','docs/places/local-catalogue/FacilityQualificationJournal.php','docs/places/held-facilities/native-checks.php','docs/places/held-facilities/fetch.py','docs/places/local-catalogue/bootstrap-local.php','docs/places/local-catalogue/common.php'}
    manifest={'files':{name:digest(APP/name) for name in sorted(paths) if (APP/name).is_file()}}
    save(OUTPUT/'runtime-manifest.json',manifest)
    report('runtime-summary.json',{'runtime_manifest_sha256':digest(OUTPUT/'runtime-manifest.json'),'files':len(manifest['files'])})
elif action in {'rehearse','failure-check','native-source-check'}:
    if action=='failure-check':
        env=local_environment();env['PLACES_EXPECTED_FAILURE']='true'
    else:env=local_environment()
    script='verify-source-proof.php' if action=='native-source-check' else 'rehearse.php'
    result=subprocess.run(['/Users/anar/Library/Application Support/Herd/bin/php','-d','memory_limit=1G',str(HERE/script)],cwd=APP,env=env)
    raise SystemExit(result.returncode)
elif action == 'verify-snapshot':
    result=subprocess.run(['/Users/anar/Library/Application Support/Herd/bin/php','-d','memory_limit=1G',str(HERE.parent/'local-catalogue/verify-snapshot.php')],cwd=APP,env=local_environment())
    raise SystemExit(result.returncode)
else:raise SystemExit('Choose select, fetch, freeze-runtime, rehearse, native-source-check, failure-check or verify-snapshot')
