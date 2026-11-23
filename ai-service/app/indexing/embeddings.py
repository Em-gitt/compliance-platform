"""Embedding providers (ADR-010) and batched embedding with retries (CMP-29).

Only providers listed in authorized_providers.yaml can be selected (validated at
startup by CMP-28). ``local-embeddings`` runs bge-m3 inside this process, so no
document fragment leaves the server. Any future HTTP-based provider must call
through ``app.providers.ProviderClient``.
"""

import hashlib
import math
import os
import re
import threading
import time
from collections.abc import Callable
from typing import Protocol

from app.indexing.config import get_rag_config
from app.providers import ProviderNotAuthorizedError, get_settings

INDEXING_FAILED_MESSAGE = "The document could not be indexed."
DEFAULT_EMBEDDING_MODEL = "BAAI/bge-m3"
DEFAULT_EMBEDDING_DIM = 1024


class EmbeddingError(Exception):
    """The embedding provider failed after all retries. Mapped to 502 EMBEDDING_FAILED."""


class EmbeddingProvider(Protocol):
    name: str
    dimensions: int

    def embed(self, texts: list[str]) -> list[list[float]]: ...


class MockEmbeddingProvider:
    """Deterministic bag-of-words vectors. Allowed only in APP_ENV testing/loadtest (ADR-011)."""

    name = "mock"

    def __init__(self, dimensions: int = DEFAULT_EMBEDDING_DIM) -> None:
        self.dimensions = dimensions

    def embed(self, texts: list[str]) -> list[list[float]]:
        return [self._vector(text) for text in texts]

    def _vector(self, text: str) -> list[float]:
        vector = [0.0] * self.dimensions
        for word in re.findall(r"\w+", text.lower()):
            bucket = int(hashlib.sha256(word.encode("utf-8")).hexdigest()[:8], 16) % self.dimensions
            vector[bucket] += 1.0
        norm = math.sqrt(sum(value * value for value in vector))
        if norm == 0:
            vector[0] = 1.0
            return vector
        return [value / norm for value in vector]


class LocalEmbeddingProvider:
    """bge-m3 with sentence-transformers on CPU. Weights are baked into the image at build time."""

    name = "local-embeddings"
    _models: dict[str, object] = {}
    _lock = threading.Lock()

    def __init__(self, model_name: str = DEFAULT_EMBEDDING_MODEL, dimensions: int = DEFAULT_EMBEDDING_DIM) -> None:
        self.model_name = model_name
        self.dimensions = dimensions

    def _model(self):
        with self._lock:
            if self.model_name not in self._models:
                from sentence_transformers import SentenceTransformer  # heavy import, loaded on first use

                self._models[self.model_name] = SentenceTransformer(self.model_name, device="cpu")
            return self._models[self.model_name]

    def embed(self, texts: list[str]) -> list[list[float]]:
        vectors = self._model().encode(texts, normalize_embeddings=True, batch_size=16)
        return [[float(value) for value in vector] for vector in vectors]


class CloudflareAiEmbeddingProvider:
    """Cloudflare Workers AI @cf/baai/bge-m3 embeddings (dense, 1024 dims)."""

    name = "cloudflare-ai"

    def __init__(self, dimensions: int = DEFAULT_EMBEDDING_DIM) -> None:
        self.dimensions = dimensions
        self.account_id = os.getenv("CLOUDFLARE_ACCOUNT_ID", "").strip()
        self.api_token = os.getenv("CLOUDFLARE_API_TOKEN", "").strip()
        if not self.account_id or not self.api_token:
            raise EmbeddingError("CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN must be configured.")
        self.url = f"https://api.cloudflare.com/client/v4/accounts/{self.account_id}/ai/run/@cf/baai/bge-m3"

    def embed(self, texts: list[str]) -> list[list[float]]:
        from app.providers.client import ProviderClient

        headers = {
            "Authorization": f"Bearer {self.api_token}",
            "Content-Type": "application/json",
        }
        try:
            with ProviderClient(timeout=30.0) as client:
                resp = client.post(
                    self.url,
                    headers=headers,
                    json={"text": texts},
                    purpose="embeddings",
                )
        except Exception as exc:
            raise EmbeddingError(f"Cloudflare Workers AI request failed: {exc}") from exc

        if resp.status_code != 200:
            raise EmbeddingError(f"Cloudflare Workers AI error (HTTP {resp.status_code}): {resp.text}")

        data = resp.json()
        if not data.get("success"):
            raise EmbeddingError(f"Cloudflare Workers AI unsuccessful: {data.get('errors')}")

        vectors = data.get("result", {}).get("data", [])
        if not vectors:
            raise EmbeddingError("Cloudflare Workers AI returned no vectors.")

        result: list[list[float]] = []
        for v in vectors:
            vec = [float(val) for val in v]
            if len(vec) != self.dimensions:
                raise EmbeddingError(
                    f"Embedding dimension mismatch: expected {self.dimensions}, got {len(vec)}"
                )
            result.append(vec)

        return result


def get_embedding_provider() -> EmbeddingProvider:
    settings = get_settings()
    dimensions = int(os.getenv("EMBEDDING_DIM", str(DEFAULT_EMBEDDING_DIM)))
    if settings.embedding_provider == "mock":
        return MockEmbeddingProvider(dimensions)
    if settings.embedding_provider == "local-embeddings":
        return LocalEmbeddingProvider(os.getenv("EMBEDDING_MODEL", DEFAULT_EMBEDDING_MODEL), dimensions)
    if settings.embedding_provider == "cloudflare-ai":
        return CloudflareAiEmbeddingProvider(dimensions)
    # validate_configured_providers() blocks any other value at startup.
    raise EmbeddingError(f"Unsupported embedding provider {settings.embedding_provider!r}")


def embed_texts(
    texts: list[str],
    provider: EmbeddingProvider | None = None,
    *,
    batch_size: int | None = None,
    max_retries: int | None = None,
    backoff_seconds: float | None = None,
    sleep: Callable[[float], None] | None = None,
) -> list[list[float]]:
    """Embed texts in batches; each batch is retried with exponential backoff.

    Raises EmbeddingError when a batch still fails after ``max_retries`` retries.
    ProviderNotAuthorizedError is never retried (CMP-28).
    """
    config = get_rag_config().embedding
    provider = provider or get_embedding_provider()
    batch_size = batch_size or config.batch_size
    max_retries = config.max_retries if max_retries is None else max_retries
    backoff_seconds = config.backoff_seconds if backoff_seconds is None else backoff_seconds
    sleep = sleep or time.sleep

    vectors: list[list[float]] = []
    for start in range(0, len(texts), batch_size):
        batch = texts[start : start + batch_size]
        for attempt in range(max_retries + 1):
            try:
                result = provider.embed(batch)
                _check_result(result, len(batch), provider.dimensions)
                vectors.extend(result)
                break
            except ProviderNotAuthorizedError:
                raise
            except Exception as exc:
                if attempt == max_retries:
                    raise EmbeddingError(INDEXING_FAILED_MESSAGE) from exc
                sleep(backoff_seconds * (2**attempt))
    return vectors


def _check_result(result: list[list[float]], expected: int, dimensions: int) -> None:
    if len(result) != expected or any(len(vector) != dimensions for vector in result):
        raise ValueError(f"Embedding provider returned an unexpected shape (expected {expected} x {dimensions}).")
