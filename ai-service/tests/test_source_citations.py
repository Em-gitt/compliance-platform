"""Tests for Source Citations in AI Answers (CMP-32 / FR-012 / AIR-004)."""

import json
from uuid import uuid4
import pytest
from fastapi.testclient import TestClient

from app.main import app
from app.indexing.store import RetrievedChunk
from app.security import get_expected_token

client = TestClient(app)
AUTH_HEADERS = {"X-Internal-Token": get_expected_token()}


def test_every_answered_response_includes_at_least_one_citation_with_document_name(monkeypatch):
    """AC #1: 100% of answers with status 'answered' include at least one citation with document name."""
    monkeypatch.setenv("APP_ENV", "testing")

    chunk = RetrievedChunk(
        id=501,
        document_id=10,
        document_version_id=1,
        chunk_index=0,
        content="Access badges must be returned upon termination.",
        page_start=5,
        page_end=5,
        section="Badge Policy",
        similarity=0.89,
        document_name="Security_Guidelines.pdf",
        version_label="1.0",
    )

    from app.indexing import store as chunk_store
    monkeypatch.setattr(chunk_store, "find_similar_chunks", lambda *args, **kwargs: [chunk])

    import app.main as main_module
    monkeypatch.setattr(
        main_module,
        "generate_answer",
        lambda *a, **k: "Badges must be returned upon termination per policy [1].",
    )

    resp = client.post(
        "/v1/ask",
        headers=AUTH_HEADERS,
        json={
            "question": "What happens to access badges when leaving the company?",
            "user_id": 1,
            "organization_id": 1,
            "allowed_document_ids": [10],
            "request_id": str(uuid4()),
        },
    )

    assert resp.status_code == 200
    data = resp.json()
    assert data["status"] == "answered"
    assert len(data["citations"]) >= 1

    for c in data["citations"]:
        assert c["document_name"] == "Security_Guidelines.pdf"
        assert c["number"] == 1
        assert c["chunk_id"] == "501"


def test_page_or_section_is_shown_whenever_the_chunk_has_it(monkeypatch):
    """AC #2: Page or section is shown whenever the chunk has it."""
    monkeypatch.setenv("APP_ENV", "testing")

    # Chunk with page only
    chunk_page = RetrievedChunk(
        id=601,
        document_id=10,
        document_version_id=1,
        chunk_index=0,
        content="Environmental permit content.",
        page_start=4,
        page_end=5,
        section="",
        similarity=0.90,
        document_name="Environmental Permit 2026.pdf",
        version_label="2.0",
    )

    # Chunk with section only
    chunk_section = RetrievedChunk(
        id=602,
        document_id=11,
        document_version_id=1,
        chunk_index=1,
        content="Safety procedures content.",
        page_start=None,
        page_end=None,
        section="Emergency procedures",
        similarity=0.85,
        document_name="Safety Policy.docx",
        version_label="1.0",
    )

    from app.indexing import store as chunk_store
    monkeypatch.setattr(chunk_store, "find_similar_chunks", lambda *args, **kwargs: [chunk_page, chunk_section])

    import app.main as main_module
    monkeypatch.setattr(
        main_module,
        "generate_answer",
        lambda *a, **k: "Follow environmental permit [1] and emergency procedures [2].",
    )

    resp = client.post(
        "/v1/ask",
        headers=AUTH_HEADERS,
        json={
            "question": "What are the environmental and emergency policies?",
            "user_id": 1,
            "organization_id": 1,
            "allowed_document_ids": [10, 11],
            "request_id": str(uuid4()),
        },
    )

    assert resp.status_code == 200
    citations = resp.json()["citations"]
    assert len(citations) == 2

    # Citation 1: PDF with pages 4-5
    c1 = citations[0]
    assert c1["page_start"] == 4
    assert c1["page_end"] == 5
    assert c1["document_name"] == "Environmental Permit 2026.pdf"

    # Citation 2: DOCX with section
    c2 = citations[1]
    assert c2["section"] == "Emergency procedures"
    assert c2["document_name"] == "Safety Policy.docx"


def test_cited_chunk_ids_are_always_subset_of_retrieved_chunk_ids_in_logs(tmp_path, monkeypatch):
    """AC #3: pytest: cited chunk_ids are always a subset of retrieved chunk_ids (verified in logs for 20 questions)."""
    log_file = tmp_path / "citations-20-test.log"
    monkeypatch.setenv("AI_RETRIEVAL_LOG_PATH", str(log_file))
    monkeypatch.setenv("APP_ENV", "testing")

    chunks_pool = [
        RetrievedChunk(
            id=700 + i,
            document_id=10 + (i % 3),
            document_version_id=1,
            chunk_index=i,
            content=f"Compliance standard chunk content number {i}.",
            page_start=i + 1,
            page_end=i + 1,
            section=f"Section {i}",
            similarity=0.70 + (i % 20) * 0.01,
            document_name=f"Policy_{i % 3}.pdf",
            version_label="1.0",
        )
        for i in range(5)
    ]

    from app.indexing import store as chunk_store
    monkeypatch.setattr(chunk_store, "find_similar_chunks", lambda *args, **kwargs: chunks_pool)

    import app.main as main_module
    # Alternate citing chunk [1], [2], or [1] and [3]
    monkeypatch.setattr(
        main_module,
        "generate_answer",
        lambda *a, **k: "Standard fact according to policy [1] and guideline [2].",
    )

    for q_idx in range(20):
        req_id = str(uuid4())
        resp = client.post(
            "/v1/ask",
            headers=AUTH_HEADERS,
            json={
                "question": f"Question {q_idx + 1}: What is the compliance rule?",
                "user_id": 1,
                "organization_id": 1,
                "allowed_document_ids": [10, 11, 12],
                "request_id": req_id,
            },
        )
        assert resp.status_code == 200

    lines = [json.loads(line) for line in log_file.read_text(encoding="utf-8").splitlines()]
    by_req: dict[str, list[dict]] = {}
    for entry in lines:
        by_req.setdefault(entry["request_id"], []).append(entry)

    assert len(by_req) == 20

    for req_id, events in by_req.items():
        ret_events = [e for e in events if e.get("event") == "retrieval_finished"]
        gen_events = [e for e in events if e.get("event") == "generation_finished"]

        assert len(ret_events) == 1
        assert len(gen_events) == 1

        retrieved_ids = set(str(cid) for cid in ret_events[0]["retrieved_chunk_ids"])
        cited_ids = set(str(cid) for cid in gen_events[0]["cited_chunk_ids"])

        assert cited_ids.issubset(retrieved_ids), (
            f"Cited chunk IDs {cited_ids} must be a subset of retrieved chunk IDs {retrieved_ids}"
        )
