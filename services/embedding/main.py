"""
Tiny embedding microservice for the Expadu personalisation engine.

Wraps sentence-transformers/all-MiniLM-L6-v2 behind a single HTTP endpoint.
Called by app/Services/EmbeddingService.php in the Laravel app.

Why a sidecar instead of inline PHP: sentence-transformers is a Python-only
library, the model is 90MB and takes ~3s to load — keeping it warm in a
long-running process avoids per-request load cost.
"""
from __future__ import annotations

import logging
import os

from fastapi import FastAPI
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("embedding")

MODEL_NAME = os.environ.get("EMBEDDING_MODEL", "sentence-transformers/all-MiniLM-L6-v2")

logger.info("Loading model %s ...", MODEL_NAME)
model = SentenceTransformer(MODEL_NAME)
DIM = model.get_sentence_embedding_dimension()
logger.info("Model loaded. Dim=%d", DIM)

app = FastAPI(title="Expadu Embedding Service")


class EmbedRequest(BaseModel):
    text: str


class EmbedResponse(BaseModel):
    vector: list[float]
    dim: int
    model: str


class BatchEmbedRequest(BaseModel):
    texts: list[str]


class BatchEmbedResponse(BaseModel):
    vectors: list[list[float]]
    dim: int
    model: str


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "model": MODEL_NAME, "dim": DIM}


@app.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest) -> EmbedResponse:
    text = (req.text or "").strip()
    if not text:
        return EmbedResponse(vector=[0.0] * DIM, dim=DIM, model=MODEL_NAME)
    vec = model.encode(text, normalize_embeddings=True).tolist()
    return EmbedResponse(vector=vec, dim=DIM, model=MODEL_NAME)


@app.post("/embed/batch", response_model=BatchEmbedResponse)
def embed_batch(req: BatchEmbedRequest) -> BatchEmbedResponse:
    texts = [(t or "").strip() for t in req.texts]
    nonempty_idx = [i for i, t in enumerate(texts) if t]
    nonempty_texts = [texts[i] for i in nonempty_idx]

    vectors: list[list[float]] = [[0.0] * DIM for _ in texts]
    if nonempty_texts:
        encoded = model.encode(nonempty_texts, normalize_embeddings=True).tolist()
        for i, v in zip(nonempty_idx, encoded):
            vectors[i] = v

    return BatchEmbedResponse(vectors=vectors, dim=DIM, model=MODEL_NAME)
