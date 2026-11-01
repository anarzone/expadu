#!/usr/bin/env bash
# Weekly MOTIS timetable refresh, run by .github/workflows/motis-refresh.yml.
#
# The box (3.8 GB) serves MOTIS (~150 MB) but can't build it (an import peaks at
# ~3.5 GB), so a GitHub runner builds the store from VRS's current GTFS plus the
# map extract and config kept on the box (/data/motis/inputs), test-plans a
# Cologne trip, uploads the store and swaps it in (infra/motis/swap.sh). If MOTIS
# plans no trips after the swap, the previous store comes back.
#
#   MOTIS_HOST=<ssh host> infra/motis/refresh.sh           # skips when the box runs this feed
#   FORCE=1 MOTIS_HOST=<ssh host> infra/motis/refresh.sh   # rebuild and swap anyway
#
# Exits 2 when VRS's timetable runs out within MOTIS_WARN_DAYS days, so the run
# fails and GitHub mails the owner: VRS publishes the next timetable year only
# weeks before the December change, and without it MOTIS finds no trips at all.

set -euo pipefail

# Same image as the box serves: a store only loads in the MOTIS version that built it.
IMG="ghcr.io/motis-project/motis@sha256:f1757c12ddec0ed182e5b4efb57d4049508c3ec55e5fc98f83dac2678b20ef77"
FEED="https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip"
HOST="${MOTIS_HOST:?set MOTIS_HOST to the ssh host of the box}"
WORK="${MOTIS_WORK:-$PWD/motis-build}"
WARN_DAYS="${MOTIS_WARN_DAYS:-21}"
PORT=18080
# Dom → Rudolfplatz: the trip every build must be able to plan.
TRIP="fromPlace=50.9413,6.9583&toPlace=50.9366,6.9408"
HERE="$(cd "$(dirname "$0")" && pwd)"

say() { # <notice|warning|error> <message>: an annotation on GitHub, plain text elsewhere
  if [ -n "${GITHUB_ACTIONS:-}" ]; then echo "::$1::$2"; else echo "$1: $2"; fi
  if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then echo "- $2" >> "$GITHUB_STEP_SUMMARY"; fi
}

mkdir -p "$WORK"
cd "$WORK"
curl -fsSL -o vrs-gtfs.zip "$FEED"

# feed_info.txt: …,feed_start_date,feed_end_date,feed_version
info=$(unzip -p vrs-gtfs.zip feed_info.txt | tail -1 | tr -d '\r')
end=$(echo "$info" | awk -F, '{print $5}')
version=$(echo "$info" | awk -F, '{print $6}')
days_left=$(( ($(date -u -d "$end" +%s) - $(date -u +%s)) / 86400 ))
say notice "VRS feed $version, valid until $end ($days_left days)"

status=0
if [ "$days_left" -lt "$WARN_DAYS" ]; then
  say warning "VRS timetable ends $end ($days_left days left) and no newer one is published yet"
  status=2
fi

live=$(ssh "$HOST" 'cat /data/motis/store/feed_version 2>/dev/null' || true)
if [ "$version" = "$live" ] && [ "${FORCE:-0}" != 1 ]; then
  say notice "The box already runs $version; nothing to do."
  exit "$status"
fi

scp -q "$HOST:/data/motis/inputs/nrw.osm.pbf" "$HOST:/data/motis/inputs/config.yml" .
if ! docker run --rm -w /data -v "$WORK:/data" "$IMG" /motis import > import.log 2>&1; then
  tail -20 import.log
  say error "MOTIS import failed; the box keeps its current store"
  exit 1
fi

sid=$(docker run -d -p "127.0.0.1:$PORT:8080" -v "$WORK/data:/data" "$IMG" /motis server /data)
trap 'docker rm -f "$sid" >/dev/null 2>&1 || true' EXIT
for _ in $(seq 1 30); do
  curl -fsS -o /dev/null "http://127.0.0.1:$PORT/api/v1/geocode?text=Neumarkt" 2>/dev/null && break
  sleep 1
done
found=$(curl -fsS "http://127.0.0.1:$PORT/api/v1/plan?$TRIP&time=$(date -u +%FT%TZ)" |
  python3 -c 'import json,sys; print(len(json.load(sys.stdin).get("itineraries", [])))' || echo 0)
docker rm -f "$sid" >/dev/null
if [ "$found" -eq 0 ]; then
  say error "The new MOTIS build plans no trips; the box keeps its current store"
  exit 1
fi
echo "Runner check: $found trips Dom → Rudolfplatz"

# The import runs as root in the container: make the store readable for the copy.
sudo chmod -R a+rX data
echo "$version" | sudo tee data/feed_version > /dev/null
rsync -a --delete --exclude 'logs/' data/ "$HOST:/data/motis/store.new/"
if ! ssh "$HOST" 'bash -s' < "$HERE/swap.sh"; then
  say error "MOTIS planned no trips on the new store on the box; the previous store is back"
  exit 1
fi

say notice "MOTIS now runs VRS $version (timetable until $end)"
exit "$status"
