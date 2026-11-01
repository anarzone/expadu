#!/usr/bin/env bash
# Runs on the box (piped over ssh by infra/motis/refresh.sh): swaps the uploaded
# /data/motis/store.new in for the store MOTIS serves, keeping the previous one as
# store.prev, and puts it back if MOTIS then plans no trips.
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
