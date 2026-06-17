# Provisioning MOTIS on the staging box

Staging deploys via SSH to `SERVER_IP` into `/data/staging` (Coolify box,
`staging-app` container). This stands a MOTIS service up next to it and points
the app at it. **Run on the box** (Linux → clean Geofabrik egress, and the
Docker-Desktop-Mac named-volume/mmap quirk from the benchmark does not apply).

Footprint to expect (from the local benchmark, VRS-core region): ~4 GB peak
RAM during import, ~380 MB data; full NRW will be larger (~1.5–2 GB data,
~6–8 GB import RAM). Confirm the box has the headroom before starting.

## 1. Build the MOTIS data (one-time, on the box)

```bash
mkdir -p /data/motis/data && cd /data/motis/data
# Geofabrik works directly from the box (no egress proxy).
curl -fSL -o nrw.osm.pbf https://download.geofabrik.de/europe/germany/nordrhein-westfalen-latest.osm.pbf
curl -fSL -o vrs-gtfs.zip https://download.vrsinfo.de/gtfs/GTFS_VRS_mit_SPNV.zip

# Generate config, then trim tiles for now (separate disk concern — see README).
docker run --rm -w /data -v /data/motis/data:/data ghcr.io/motis-project/motis:latest \
  /motis config nrw.osm.pbf vrs-gtfs.zip
# (optional) add the VRS GTFS-RT url under timetable.datasets.vrs-gtfs: rt: [ { url: <VRS_GTFSRT_URL> } ]
docker run --rm -w /data -v /data/motis/data:/data ghcr.io/motis-project/motis:latest /motis import
```

## 2. Add the service to `/data/staging/docker-compose.yml`

```yaml
  motis:
    image: ghcr.io/motis-project/motis:latest
    command: ['/motis', 'server', '/data']
    volumes:
      - /data/motis/data/data:/data    # the import output dir
    restart: unless-stopped
    # same network as staging-app so the app reaches it by name
    networks: [default]
    healthcheck:
      test: ['CMD', 'wget', '-qO-', 'http://127.0.0.1:8080/api/v1/reverse-geocode?place=50.94,6.96']
      interval: 30s
      timeout: 5s
      retries: 3
```

## 3. Point the app at it

Set on `staging-app` (Coolify env or the compose `environment:`):

```
MOTIS_URL=http://motis:8080
```

`config/services.php` already reads `MOTIS_URL` (default `http://localhost:8080`).
With it unset, the app gracefully fails routing over to Transitous — so this
step is what flips staging onto self-hosted MOTIS.

## 4. Bring up + verify

```bash
cd /data/staging && docker compose up -d motis staging-app
# gate: a Cologne plan with legGeometry (note ISO time needs the trailing Z)
docker exec staging-app php artisan tinker --execute '
  $r = app(App\Transit\MotisAdapter::class)->plan(
    new App\Transit\Dto\GeoPoint(50.9413,6.9583),
    new App\Transit\Dto\GeoPoint(50.9365,6.9350));
  echo $r->source." ".count($r->journeys)." journeys".PHP_EOL;'
# expect: motis 6 journeys
```

## Notes
- Keep the OSM/GTFS refresh on a schedule later (GTFS changes; OSM monthly).
- Tiles (basemap, decision B) are deferred — add the `tiles` block + a bigger
  disk, or use Protomaps, when the map (Phase 4) lands.
- Prod is the same procedure under `/data` (prod compose), gated behind a
  staging soak first per the infra rule.
