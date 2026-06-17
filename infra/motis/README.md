# Self-hosted MOTIS (routing overhaul — Phase 0)

We route on a self-hosted [MOTIS](https://github.com/motis-project/motis) v2
instance fed by **our VRS GTFS + an NRW OSM extract**, replacing the public
`api.transitous.org` shared instance. This directory holds the infra config and
the Phase 0 footprint benchmark. See the full plan in
`~/.claude/plans/routing-overhaul-motis.md`.

## Inputs

| Input | Source | Notes |
|---|---|---|
| OSM extract | **Geofabrik** `…/europe/germany/nordrhein-westfalen-latest.osm.pbf` | MOTIS needs a **reference-complete** extract (ways' nodes all present). ⚠️ The `openstreetmap.fr` mirror is **clipped, not reference-complete** → MOTIS aborts with `unable to import: invalid location` during "Connect ways". Geofabrik extracts are reference-complete — use them on the box (clean egress). |
| OSM (dev env workaround) | **BBBike** `extract.bbbike.org` → `download2.bbbike.org/osm/extract/planet_<bbox>.osm.pbf` | Geofabrik is **proxy-blocked here** (squid 502). BBBike produces reference-complete extracts and is reachable. Benchmark bbox = VRS-core Rhineland `6.2,50.55 × 7.35,51.15` (~5,421 km²: Köln/Bonn/Leverkusen/Rhein-Erft/Rhein-Sieg). Submit is a GET to `/` with all hidden fields incl. a real `as` (area km²) + `expire` token; builds in 2–7 min. |
| VRS GTFS | `https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip` | The exact feed `php artisan gtfs:import` already uses. ~37 MB zip, ~475 MB unzipped. |
| VRS GTFS-RT | `config('services.vrs.gtfsrt_url')` (`VRS_GTFSRT_URL`) | Live updates — wire into config after the static benchmark passes. |

MOTIS image: `ghcr.io/motis-project/motis:latest` (v2.10.2, 138 MB).
CLI flow: `motis config <inputs>` → `motis import` → `motis server` (API on :8080).

## Benchmark (local, before sizing the box)

Data lives outside the repo (gigabytes). Default scratch dir `~/motis-bench/data`.

```bash
mkdir -p ~/motis-bench/data && cd ~/motis-bench/data
curl -fSL -o nrw.osm.pbf "https://download.openstreetmap.fr/extracts/europe/germany/nordrhein_westfalen.osm.pbf"
curl -fSL -o vrs-gtfs.zip "https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip"
infra/motis/benchmark.sh            # measures import time, peak RAM, disk; smoke-plans Cologne A→B
```

## Phase 0 gate

A Cologne A→B `plan` returns sane walk+transit itineraries **with `legGeometry`**.

## Footprint results (2026-06-17, local Docker Desktop, M-series Mac)

Region: VRS-core Rhineland (5,421 km²), VRS GTFS, **365-day** timetable, **tiles off**, `street_routing` + geocoding on.

| Metric | Value |
|---|---|
| import wall-clock | **19 s** |
| peak RAM (import) | **3.7 GB** (Docker had 15.6 GB; no OOM) |
| peak RAM (serve) | 225 MiB RSS (mmap-lazy — true working set is the mmap'd 378 MB) |
| data dir on disk | **378 MB** |
| **gate: Cologne A→B plan** | ✅ **6 itineraries, 24 `legGeometry` polylines, BUS+TRAM+WALK** |

### Box-sizing read
- The VRS-core extract routes Cologne perfectly — **we only need OSM where VRS has stops**, not all of NRW. A VRS-area OSM extract is the lean, sufficient option (full NRW ~8× the OSM → ~1.5–2 GB data, ~6–8 GB import RAM).
- **+ vector tiles** (decision B, the basemap) is the big add: the `full` profile preallocates a 256 GB LMDB (sparse, actual usage GBs) and adds import time. Benchmark tiles separately on the box; if disk balloons, fall back to a single Protomaps `.pmtiles` + external basemap (per plan).
- **Recommendation:** a **16 GB RAM / ~60 GB disk** Hetzner box comfortably covers NRW + tiles + GTFS-RT with headroom; 8 GB suffices for VRS-area-only without self-hosted tiles.

### Gotchas proven here
- MOTIS import runs all tasks (osr/adr/tt/adr_extend/matches) **concurrently**; on **Docker Desktop for Mac named volumes** it dies instantly with `basic_ios::clear: iostream error` (concurrent-I/O quirk) — use a **bind mount** on Mac. On the Linux box this is moot.
- API: plan is `GET /api/v1/plan?fromPlace=<lat>,<lng>&toPlace=<lat>,<lng>&time=<ISO8601 **with Z/offset**>` (bare `2026-06-17T22:05:22` → `failed to parse timestamp`). reverse-geocode: `/api/v1/reverse-geocode?place=<lat>,<lng>`.
