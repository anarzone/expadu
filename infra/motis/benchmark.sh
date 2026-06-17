#!/usr/bin/env bash
#
# MOTIS NRW + VRS footprint benchmark (Phase 0 of the routing overhaul).
#
# Measures the three unknowns the plan flagged before we size a Hetzner box:
#   - import wall-clock time
#   - peak RAM during import and while serving
#   - on-disk data-directory size
# ...and proves the gate: a Cologne A→B `plan` returns walk+transit
# itineraries WITH legGeometry.
#
# Data lives OUTSIDE the repo (it's gigabytes). Default scratch dir:
#   $HOME/motis-bench/data  containing nrw.osm.pbf + vrs-gtfs.zip
#
# Usage:  infra/motis/benchmark.sh [DATA_DIR]
#
# Sources (reachable from this network):
#   NRW OSM : https://download.openstreetmap.fr/extracts/europe/germany/nordrhein_westfalen.osm.pbf
#             (Geofabrik is blocked by the egress proxy here; the .fr mirror works)
#   VRS GTFS: https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip  (same feed gtfs:import uses)
set -euo pipefail

DATA="${1:-$HOME/motis-bench/data}"
IMG="ghcr.io/motis-project/motis:latest"
RUN="docker run --rm -w /data -v ${DATA}:/data ${IMG}"

cd "$DATA"
echo "==> inputs"
ls -lh nrw.osm.pbf vrs-gtfs.zip

# 1. Generate config.yml from the inputs (MOTIS writes it into the cwd = /data).
echo "==> motis config"
$RUN /motis config nrw.osm.pbf vrs-gtfs.zip
echo "--- config.yml ---"; cat config.yml; echo "------------------"

# 2. Import — timed, with peak-RAM sampling via `docker stats`.
echo "==> motis import (timed; sampling docker stats)"
CID=$(docker run -d -w /data -v "${DATA}:/data" "${IMG}" /motis import)
START=$(date +%s)
PEAK_IMPORT=0
while docker ps -q --no-trunc | grep -q "$CID"; do
    MEM=$(docker stats --no-stream --format '{{.MemUsage}}' "$CID" 2>/dev/null | awk '{print $1}')
    MB=$(numfmt --from=iec "${MEM:-0}" 2>/dev/null | awk '{print int($1/1048576)}' || echo 0)
    [ "${MB:-0}" -gt "$PEAK_IMPORT" ] && PEAK_IMPORT=$MB
    sleep 3
done
docker logs "$CID" 2>&1 | tail -5
docker rm "$CID" >/dev/null 2>&1 || true
IMPORT_SECS=$(( $(date +%s) - START ))

# 3. Footprint on disk.
DISK=$(du -sh "${DATA}/data" 2>/dev/null | awk '{print $1}')

# 4. Serve + smoke a Cologne A→B plan (Dom/Hbf → Rudolfplatz, lon,lat order).
echo "==> motis server + smoke plan"
SID=$(docker run -d -p 8080:8080 -w /data -v "${DATA}:/data" "${IMG}" /motis server)
sleep 20
PEAK_SERVE=$(docker stats --no-stream --format '{{.MemUsage}}' "$SID" 2>/dev/null | awk '{print $1}')
PLAN=$(curl -sS "http://localhost:8080/api/v1/plan?fromPlace=6.9603,50.9430&toPlace=6.9350,50.9365&time=$(date +%FT%T)" 2>&1)
HAS_GEOM=$(echo "$PLAN" | grep -c "legGeometry" || true)
echo "$PLAN" | head -c 600; echo
docker stop "$SID" >/dev/null 2>&1 || true; docker rm "$SID" >/dev/null 2>&1 || true

echo
echo "================ BENCHMARK RESULT ================"
echo "import wall-clock : ${IMPORT_SECS}s"
echo "peak RAM (import) : ${PEAK_IMPORT} MB"
echo "peak RAM (serve)  : ${PEAK_SERVE}"
echo "data dir on disk  : ${DISK}"
echo "legGeometry in plan response: ${HAS_GEOM} occurrences (gate: >0)"
echo "================================================="
