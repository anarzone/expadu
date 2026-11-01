"""Compatibility entry point for the shared, burst-safe prototype server."""
from pathlib import Path
from runpy import run_path


if __name__ == '__main__':
    run_path(str(Path(__file__).resolve().parents[4] / 'serve.py'), run_name='__main__')
