FROM postgis/postgis:16-3.4

# Install pgvector for embeddings (Personalisation phase 2)
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-16-pgvector \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Bake the init scripts so the image is self-sufficient without volume
# mounts (CI uses `docker run` directly; compose.yaml still works the
# same since these files are idempotent).
COPY create-postgis-extensions.sql /docker-entrypoint-initdb.d/20-create-postgis-extensions.sql
COPY create-testing-database.sql /docker-entrypoint-initdb.d/05-create-testing-database.sql
