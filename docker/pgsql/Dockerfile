FROM postgis/postgis:16-3.4

# Install pgvector for embeddings (Personalisation phase 2)
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-16-pgvector \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
