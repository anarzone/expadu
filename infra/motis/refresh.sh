#!/usr/bin/env bash
# Weekly MOTIS timetable refresh: build on this Mac, swap on the box.
#
# The box (3.8 GB) can serve MOTIS but not build it (an import peaks at ~3.5 GB),
# so this Mac builds from VRS's current GTFS and the map extract kept in
# ~/motis-bench/data, test-plans a Cologne trip, uploads the store and swaps it
# in. If MOTIS doesn't plan trips after the swap, the previous store comes back.
#
#   infra/motis/refresh.sh            # skips when the box already runs this feed version
#   FORCE=1 infra/motis/refresh.sh    # rebuild and swap anyway
#
# Warns (macOS notification + exit code 2) when VRS's timetable runs out within
# MOTIS_WARN_DAYS days: VRS publishes the next timetable year only weeks before
# the December change, and without it MOTIS finds no trips at all.

set -euo pipefail

# Same image as the box serves: a store only loads in the MOTIS version that built it.
IMG="ghcr.io/motis-project/motis@sha256:f1757c12ddec0ed182e5b4efb57d4049508c3ec55e5fc98f83dac2678b20ef77"
FEED="https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip"
HOST="${MOTIS_HOST:-hetzner}"
ROOT="${MOTIS_ROOT:-$HOME/motis-bench}"
INPUTS="${MOTIS_INPUTS:-$ROOT/data}"
WARN_DAYS="${MOTIS_WARN_DAYS:-21}"
PORT=18080
# Dom → Rudolfplatz: the trip every build must be able to plan.
TRIP="fromPlace=50.9413,6.9583&toPlace=50.9366,6.9408"

notify() {
  echo "$1"
  osascript -e "display notification \"$1\" with title \"MOTIS refresh\"" >/dev/null 2>&1 || true
}

trips_at() { # <base url> → number of itineraries for TRIP now
  curl -fsS "$1/api/v1/plan?$TRIP&time=$(date -u +%FT%TZ)" |
    python3 -c 'import json,sys; print(len(json.load(sys.stdin).get("itineraries", [])))'
}

# Downloaded beside the builds first: a build folder only exists for a feed we build.
zip=$(mktemp "$ROOT/vrs-gtfs.XXXXXX")
trap 'rm -f "$zip"' EXIT
curl -fsSL -o "$zip" "$FEED"

# feed_info.txt: …,feed_start_date,feed_end_date,feed_version
info=$(unzip -p "$zip" feed_info.txt | tail -1 | tr -d '\r')
end=$(echo "$info" | awk -F, '{print $5}')
version=$(echo "$info" | awk -F, '{print $6}')
days_left=$(( ($(date -j -f %Y%m%d "$end" +%s) - $(date +%s)) / 86400 ))
echo "VRS feed $version, valid until $end ($days_left days)"

status=0
if [ "$days_left" -lt "$WARN_DAYS" ]; then
  notify "VRS timetable ends $end ($days_left days left) and no newer one is published yet"
  status=2
fi

live=$(ssh "$HOST" 'cat /data/motis/store/feed_version 2>/dev/null' || true)
if [ "$version" = "$live" ] && [ "${FORCE:-0}" != 1 ]; then
  echo "The box already runs $version; nothing to do."
  exit "$status"
fi

build="$ROOT/build-$(date +%F-%H%M)"
mkdir -p "$build"
cd "$build"
mv "$zip" vrs-gtfs.zip
cp -p "$INPUTS/nrw.osm.pbf" "$INPUTS/config.yml" .
# A bind mount: on Docker for Mac a named volume breaks the concurrent import.
docker run --rm -w /data -v "$build:/data" "$IMG" /motis import > import.log 2>&1 ||
  { notify "MOTIS import failed (see $build/import.log)"; exit 1; }

sid=$(docker run -d -p "127.0.0.1:$PORT:8080" -v "$build/data:/data" "$IMG" /motis server /data)
trap 'docker rm -f "$sid" >/dev/null 2>&1 || true' EXIT
for _ in $(seq 1 30); do
  curl -fsS -o /dev/null "http://127.0.0.1:$PORT/api/v1/geocode?text=Neumarkt" 2>/dev/null && break
  sleep 1
done
found=$(trips_at "http://127.0.0.1:$PORT" || echo 0)
docker rm -f "$sid" >/dev/null
[ "$found" -gt 0 ] || { notify "The new MOTIS build plans no trips; the box keeps its current one"; exit 1; }
echo "Local check: $found trips Dom → Rudolfplatz"

echo "$version" > data/feed_version
rsync -a --delete --exclude 'logs/' data/ "$HOST:/data/motis/store.new/"

# Swap on the box; the previous store stays as store.prev for the rollback.
ssh "$HOST" 'bash -s' <<'SWAP'
set -euo pipefail
cd /data/staging
docker compose stop motis
rm -rf /data/motis/store.prev
mv /data/motis/store /data/motis/store.prev
mv /data/motis/store.new /data/motis/store
# Staging's compose pulls on up; the pinned image must stay.
docker compose up -d --pull never motis
plan='http://motis:8080/api/v1/plan?fromPlace=50.9413,6.9583&toPlace=50.9366,6.9408'
for _ in $(seq 1 30); do
  if docker exec staging-app sh -c "curl -fsS \"$plan&time=\$(date -u +%FT%TZ)\"" 2>/dev/null | grep -q '"itineraries":\[{'; then
    exit 0
  fi
  sleep 2
done
echo "MOTIS plans no trips on the new store; rolling back."
docker compose stop motis
rm -rf /data/motis/store.failed
mv /data/motis/store /data/motis/store.failed
mv /data/motis/store.prev /data/motis/store
docker compose up -d --pull never motis
exit 1
SWAP

notify "MOTIS now runs VRS $version (timetable until $end)"

# Keep the two newest builds here (BSD head and xargs have no -n -N or -r).
cd "$ROOT"
builds=$(ls -d build-* 2>/dev/null | sort)
echo "$builds" | awk -v n="$(echo "$builds" | grep -c .)" 'NF && NR <= n - 2' | while read -r old; do
  rm -rf "$old"
done
exit "$status"
