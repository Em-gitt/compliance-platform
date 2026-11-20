"""Structured JSON logging for RAG retrieval and generation (CMP-30 / CMP-31 / CMP-32)."""

from __future__ import annotations

import json
import logging
import os
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

DEFAULT_LOG_PATH = Path(__file__).resolve().parents[1] / "logs" / "ai-retrieval.log"
_lock = threading.Lock()
logger = logging.getLogger("ai.rag")


def log_path() -> Path:
    return Path(os.getenv("AI_RETRIEVAL_LOG_PATH", str(DEFAULT_LOG_PATH)))


def _write_log(event_data: dict[str, Any]) -> dict[str, Any]:
    event_data["timestamp"] = datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")
    line = json.dumps(event_data, ensure_ascii=False)

    with _lock:
        path = log_path()
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("a", encoding="utf-8") as file:
            file.write(line + "\n")
        logger.info(line)

    return event_data


def log_retrieval_started(
    request_id: str,
    question: str,
    organization_id: int,
    user_id: int,
    allowed_document_ids: list[int],
) -> dict[str, Any]:
    return _write_log({
        "event": "retrieval_started",
        "request_id": request_id,
        "question": question,
        "organization_id": organization_id,
        "user_id": user_id,
        "allowed_document_ids": allowed_document_ids,
    })


def log_retrieval_finished(
    request_id: str,
    fragments_considered: int,
    fragments_kept: int,
    top_score: float,
    retrieved_chunk_ids: list[str | int],
) -> dict[str, Any]:
    return _write_log({
        "event": "retrieval_finished",
        "request_id": request_id,
        "fragments_considered": fragments_considered,
        "fragments_kept": fragments_kept,
        "top_score": top_score,
        "retrieved_chunk_ids": retrieved_chunk_ids,
    })


def log_generation_started(
    request_id: str,
    chunks_to_llm: list[str | int],
) -> dict[str, Any]:
    return _write_log({
        "event": "generation_started",
        "request_id": request_id,
        "chunks_to_llm": chunks_to_llm,
    })


def log_generation_finished(
    request_id: str,
    cited_chunk_ids: list[str | int],
    status: str,
    latency_ms: int,
) -> dict[str, Any]:
    return _write_log({
        "event": "generation_finished",
        "request_id": request_id,
        "cited_chunk_ids": cited_chunk_ids,
        "status": status,
        "latency_ms": latency_ms,
    })
