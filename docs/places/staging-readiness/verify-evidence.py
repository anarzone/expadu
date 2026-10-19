"""Verify the staging proposal's public aggregate evidence against this checkout."""
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PROJECT = ROOT.parents[2]
def read(name):
    return json.loads((ROOT/name).read_text())
def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

setup=read('setup-summary.json')
contracts=read('consumers-summary.json')
football=read('football-summary.json')
facilities=read('post-hold-facilities-summary.json')
tests=read('local-tests-summary.json')
calendar=read('ci-calendar-summary.json')
ci=read('ci-summary.json')
publication=read('publication-readback-summary.json')
files={}
for directory in ['app','config','routes','database/migrations']:
    for path in (PROJECT/directory).rglob('*.php'):
        if path.is_file():
            files[path.relative_to(PROJECT).as_posix()]=digest(path)
for name in ['composer.lock','bootstrap/app.php']:
    files[name]=digest(PROJECT/name)
runtime=hashlib.sha256(json.dumps(files,sort_keys=True,separators=(',',':')).replace('/', '\\/').encode()).hexdigest()
assert runtime == setup['runtime_sha256'] == contracts['runtime_sha256']
assert len(files) == setup['runtime_files_hashed'] == 570
assert setup['composer_lock_sha256'] == digest(PROJECT/'composer.lock')
assert setup['runtime_helper_sha256'] == '98c03f5e62ceedae24ca4da7c8217d0fa812051e4caad1ead272c64b36cb1399'
assert not setup['private_environment_copied'] and not setup['pinned_recovery_lab_modified']
assert not setup['live_application_booted'] and not setup['production_changed']
assert contracts['status']=='passed' and contracts['runtime']=='staging_port_candidate'
assert contracts['eligible_rows']==contracts['shared_contract_records_checked']==4235
assert contracts['general_destinations']==4224 and contracts['api_list_total']==4139
assert contracts['unknown_origin_eligible_rows']==0 and contracts['existing_source_null_aliases']==798
assert contracts['eligible_alias_api_checks']==contracts['eligible_alias_composer_checks']==791
assert contracts['held_unlinked_composer_exclusion_checks']==3558
assert contracts['held_unlinked_detail_api_checks']==12 and contracts['held_detail_missing_availability_field']==0
assert contracts['general_destinations_with_policy_publishable_hero']==119
assert contracts['policy_photo_coverage_percent']==round(119/4224*100,3)==2.817
assert contracts['readonly_guard_refusals_verified']==6 and contracts['staging_transaction_read_only']
assert contracts['query_logging_disabled'] and not contracts['synthetic_user_persisted']
assert not contracts['real_user_rows_read'] and not contracts['raw_rows_exported']
assert not contracts['staging_changed'] and not contracts['production_changed']
assert not contracts['external_authenticated_http_verified'] and not contracts['deployed_source_code_verified']
assert not contracts['photo_urls_freshly_revalidated'] and not contracts['every_source_freshly_reverified']
assert football['status']=='passed' and football['activities']==['soccer'] and football['categories']==['pitch']
assert football['free_football_candidates']==football['football_candidates_without_budget_filter']==0
assert football['staging_transaction_read_only'] and not football['staging_changed'] and not football['production_changed']
assert not football['real_user_rows_read'] and not football['raw_rows_exported']
assert tests['status']=='passed' and tests['passed']==1754 and tests['skipped']==2 and tests['assertions']==7349
assert not tests['application_data_changed'] and not tests['staging_changed'] and not tests['production_changed']
assert tests['observed_regression_red']=={'held_details_failed_before_fix':6,'facility_access_failed_before_fix':11,'startup_schedule_failed_before_fix':2,'legacy_access_binding_failed_before_fix':7}
assert facilities['runtime_sha256']==runtime and facilities['status']=='post_hold_native_facility_previews_passed'
assert facilities['reviewed_facility_previews']==facilities['known_public_unconditional_access']==facilities['unknown_fees_preserved']==90
assert facilities['retained_unavailable_details_checked']==facilities['composer_by_id_exclusions_checked']==90
assert facilities['categories']=={'basketball':3,'table_tennis':11,'pitch':2,'playground':74}
assert facilities['guard_refusals_verified']==6 and facilities['same_backend_and_snapshot_verified'] and facilities['staging_transaction_read_only']
assert not facilities['raw_rows_exported'] and not facilities['real_user_rows_read'] and not facilities['synthetic_user_persisted']
assert not facilities['staging_changed'] and not facilities['production_changed'] and facilities['facility_qualifications_applied']==0
assert not facilities['target_apply_package_prepared'] and not facilities['stacked_recovery_rehearsed_by_this_check'] and not facilities['sources_refetched_by_this_check']
assert not calendar['red']['labels_match'] and calendar['green']['labels_match']
assert calendar['red']['unchanged_base']==calendar['green']['unchanged_base']=='89289db9641bb75a563e74b44be9b4717bd61b22'
for path, sha in calendar['red']['base_source_sha256'].items():
    assert sha==calendar['green']['base_source_sha256'][path]==digest(PROJECT/path)
assert calendar['verified_conclusion']=='success' and not calendar['product_bureaucracy_rules_changed'] and not calendar['application_runtime_changed']
assert ci['status']=='success' and ci['head']==publication['ci_head']==calendar['verified_head']
assert ci['run_id']==publication['ci_run']==calendar['verified_run']==36784986627
assert ci['runtime_sha256']==publication['application_runtime_sha256']==runtime
jobs={j['name']:j for j in ci['jobs']}
for name in ['Lint','Test','Browser Tests']:
    assert jobs[name]['status']=='completed' and jobs[name]['conclusion']=='success'
for name in ['Build & Push Image','Build & Push pgsql Image','Deploy to Staging','Deploy to Production']:
    assert jobs[name]['conclusion']=='skipped'
assert ci['security_check']['status']=='completed' and ci['security_check']['conclusion']=='success'
assert publication['status']=='verified' and publication['security_conclusion']=='success'
assert len(publication['proofs'])==5
assert all(p.get('readback_verified',p.get('body_readback_verified',False)) for p in publication['proofs'])
assert all(p['full_history_preserved'] for p in publication['proofs'] if p['type'] in ['ticket','wiki'])
assert all(p['in_progress'] for p in publication['proofs'] if p['type']=='ticket')
assert not publication['production_changed'] and not publication['code_deployed']
print(json.dumps({'status':'passed','runtime_sha256':runtime,'eligible_contracts_checked':4235,
    'eligible_aliases_checked':791,'held_unavailable_details_checked':12,'tests_passed':tests['passed'],
    'code_deployed':False,'production_changed':False,'photo_coverage_percent':2.817}))
