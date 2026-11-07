#!/usr/bin/env bash
# The server's routes become the app's types: a backend change shows up as a compile error.
# Run from anywhere after changing anything under /api/v1, and commit what changes.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
# The export outgrew the default memory limit as the API grew.
php -d memory_limit=1G artisan scramble:export
api=ios/Packages/Sources/API
swift run --package-path ios/Tools swift-openapi-generator generate \
    --config "$api/openapi-generator-config.yaml" \
    --output-directory "$api/Generated" \
    "$api/openapi.json"
