#!/usr/bin/env bash
# Runs on the box (piped over ssh by infra/motis/refresh.sh): swaps the uploaded
# /data/motis/store.new in for the store MOTIS serves, keeping the previous one as
# store.prev, and puts it back if MOTIS then plans no trips. The live feed's settings
# carry over from the store being replaced.
set -euo pipefail

# The live feed (VRS GTFS-RT through the app's relay) and its update interval carry over from
# the store being replaced: the relay's token lives only on the box, never in the image's config,
# this repo or CI. Checked on the box: last week's config plus this equals the live one.
live=/data/motis/store/config.yml
new=/data/motis/store.new/config.yml
if [ -f "$live" ] && grep -q '^      rt:' "$live" && ! grep -q '^      rt:' "$new"; then
  awk '/^      rt:/{f=1; print; next} f && /^        /{print; next} {f=0}' "$live" > "$new.rt"
  sed -i "/^    vrs-gtfs:\$/r $new.rt" "$new"
  rm -f "$new.rt"
  interval=$(sed -n 's/^  update_interval: //p' "$live")
  if [ -n "$interval" ]; then sed -i "s/^  update_interval: .*/  update_interval: $interval/" "$new"; fi
fi

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
