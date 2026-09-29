"""Run preparation checks only against the dedicated local places-only database."""
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[3]
APP = Path('/Users/anar/.codex/worktrees/exp72-catalogue-pilot/expadu-app')
DATABASE = 'exp72_ready_20260928'
values = dict(line.split('=', 1) for line in (ROOT / '.env').read_text().splitlines() if line and not line.startswith('#') and '=' in line)
env = os.environ.copy()
for key in ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD']:
    env[key] = values[key].strip('\"\'')
if env['DB_HOST'] not in {'127.0.0.1', 'localhost'}:
    raise RuntimeError('Only the local database is allowed')
env.update(APP_ENV='testing', APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', DB_URL='', DB_DATABASE=DATABASE, CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', PLACES_PACK_ROOT=str(ROOT))
mode = sys.argv[1]
if mode == 'create':
    env['PGPASSWORD'] = env['DB_PASSWORD']
    args = ['-h', env['DB_HOST'], '-p', env['DB_PORT'], '-U', env['DB_USERNAME']]
    result = subprocess.run(['/opt/homebrew/opt/libpq/bin/createdb', *args, DATABASE], env=env, check=True, capture_output=True)
    print('Created new empty local database: ' + DATABASE)
elif mode == 'migrate':
    env['PGPASSWORD'] = env['DB_PASSWORD']
    subprocess.run(['/opt/homebrew/opt/libpq/bin/psql', '-h', env['DB_HOST'], '-p', env['DB_PORT'], '-U', env['DB_USERNAME'], '-d', DATABASE, '-X', '-v', 'ON_ERROR_STOP=1', '-c', 'CREATE EXTENSION IF NOT EXISTS postgis'], env=env, check=True)
    subprocess.run(['/Users/anar/Library/Application Support/Herd/bin/php', 'artisan', 'migrate', '--force', '--no-interaction'], cwd=APP, env=env, check=True)
else:
    script = Path(__file__).parent / (mode + '.php')
    if mode not in {'load-baseline', 'rehearse', 'diagnose-replay'}:
        raise RuntimeError('Unsupported local preparation operation')
    raise SystemExit(subprocess.run(['/Users/anar/Library/Application Support/Herd/bin/php', '-d', 'memory_limit=1G', str(script), *sys.argv[2:]], cwd=APP, env=env).returncode)
