"""RAG parameters from config/rag.yaml (source of truth: ADR-013)."""

import os
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path

import yaml

DEFAULT_RAG_CONFIG_PATH = Path(__file__).resolve().parents[2] / "config" / "rag.yaml"


@dataclass(frozen=True)
class ChunkingConfig:
    chunk_size_tokens: int
    overlap_tokens: int
    min_chunk_tokens: int
    tokenizer: str


@dataclass(frozen=True)
class EmbeddingConfig:
    batch_size: int
    max_retries: int
    backoff_seconds: float


@dataclass(frozen=True)
class RetrievalConfig:
    candidates: int
    max_fragments_to_llm: int
    similarity_threshold: float
    hnsw_ef_search: int


@dataclass(frozen=True)
class RagConfig:
    chunking: ChunkingConfig
    embedding: EmbeddingConfig
    retrieval: RetrievalConfig
    hnsw_ef_search: int


@lru_cache
def _load(path: str) -> RagConfig:
    data = yaml.safe_load(Path(path).read_text(encoding="utf-8")) or {}
    chunking = data["chunking"]
    embedding = data.get("embedding") or {}
    retrieval = data.get("retrieval") or {}
    if os.getenv("APP_ENV") == "testing":
        default_threshold = float(retrieval.get("similarity_threshold", 0.55))
    elif os.getenv("EMBEDDING_PROVIDER") == "mock":
        default_threshold = 0.15
    else:
        default_threshold = float(retrieval.get("similarity_threshold", 0.55))
    threshold = float(os.getenv("SIMILARITY_THRESHOLD", default_threshold))

    retrieval_cfg = RetrievalConfig(
        candidates=int(retrieval.get("candidates", 8)),
        max_fragments_to_llm=int(retrieval.get("max_fragments_to_llm", 4)),
        similarity_threshold=threshold,
        hnsw_ef_search=int(retrieval.get("hnsw_ef_search", 40)),
    )
    return RagConfig(
        chunking=ChunkingConfig(
            chunk_size_tokens=int(chunking["chunk_size_tokens"]),
            overlap_tokens=int(chunking["overlap_tokens"]),
            min_chunk_tokens=int(chunking["min_chunk_tokens"]),
            tokenizer=str(chunking["tokenizer"]),
        ),
        embedding=EmbeddingConfig(
            batch_size=int(embedding.get("batch_size", 64)),
            max_retries=int(embedding.get("max_retries", 3)),
            backoff_seconds=float(embedding.get("backoff_seconds", 1.0)),
        ),
        retrieval=retrieval_cfg,
        hnsw_ef_search=retrieval_cfg.hnsw_ef_search,
    )


def get_rag_config() -> RagConfig:
    return _load(os.getenv("RAG_CONFIG_PATH", str(DEFAULT_RAG_CONFIG_PATH)))
