"""Bundle only the verified public place records, evidence and operator code."""
import hashlib
import json
from pathlib import Path
import zipfile


ROOT = Path(__file__).resolve().parent
OUT = ROOT / "2026-09-28"
manifest = json.loads((OUT / "manifest.json").read_text())
assert manifest["status"] == "native_verified_local_pack"
assert (OUT / "REPORT.md").is_file()
assert json.loads((OUT / "rehearsal.json").read_text())["replay_exact_no_op"]
relative_files = [
    "README.md", "PLAN.md", "NOTICE.md", "prepare.py", "test_prepare.py",
    "review-holds.json", "apply-pack.php", "export-places.php", "load-baseline.php",
    "rehearse.php", "diagnose-replay.php", "run-local.py", "verify-websites.php",
    "finalize.py", "package.py", "test-fingerprint.php", "licenses/CDLA-Permissive-2.0.txt", "licenses/ODbL-1.0.txt",
]
evidence_files = [
    "REPORT.md", "manifest.json", "records.jsonl", "holds.jsonl",
    "identity-review.jsonl", "containment-evidence.jsonl", "rehearsal.json",
    "before-api.json", "refresh-retention.patch", "code-verification.json",
    "php-test-results.txt", "website-verification.json", "reproducibility.json",
    "replay-regression.json", "review-resolution.md", "jira-updates.json",
]
relative_files += ["2026-09-28/" + name for name in evidence_files]
for path in relative_files:
    assert (ROOT / path).is_file(), path
checksums = {path: hashlib.sha256((ROOT / path).read_bytes()).hexdigest() for path in sorted(relative_files)}
archive = OUT / "cologne-places-2026-09-28.zip"
with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
    for path in sorted(relative_files):
        entry = zipfile.ZipInfo("cologne-places/" + path, date_time=(2026, 9, 28, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        bundle.writestr(entry, (ROOT / path).read_bytes())
    bundle.writestr("cologne-places/SHA256SUMS.json", json.dumps(checksums, indent=2) + "\n")
with zipfile.ZipFile(archive) as bundle:
    assert bundle.testzip() is None
    assert all(hashlib.sha256(bundle.read("cologne-places/" + path)).hexdigest() == digest for path, digest in checksums.items())
receipt = {"archive": archive.name, "bytes": archive.stat().st_size, "sha256": hashlib.sha256(archive.read_bytes()).hexdigest(), "file_count": len(relative_files) + 1, "verified": True, "includes_private_baseline": False, "files": checksums}
(OUT / "package-verification.json").write_text(json.dumps(receipt, indent=2) + "\n")
print(json.dumps({k: v for k, v in receipt.items() if k != "files"}))
