"""Tests for Grounded Answer Generation & Insufficient Information Response (CMP-31 / NFR-018 / AIR-003 / AIR-005 / AIR-007 / BR-005 / BR-006 / BR-007)."""

from uuid import uuid4
from fastapi.testclient import TestClient

from app.main import (
    DISCLAIMER_EN,
    DISCLAIMER_ES,
    INSUFFICIENT_INFO_EN,
    INSUFFICIENT_INFO_ES,
    app,
)
from app.indexing.store import RetrievedChunk
from app.security import get_expected_token

client = TestClient(app)
AUTH_HEADERS = {"X-Internal-Token": get_expected_token()}


def test_prompt_sent_contains_only_retrieved_fragments(monkeypatch):
    """AC #1: pytest with mocked LLM: the prompt sent contains only retrieved fragments."""
    monkeypatch.setenv("APP_ENV", "testing")

    chunk1 = RetrievedChunk(
        id=201,
        document_id=10,
        document_version_id=1,
        chunk_index=0,
        content="Chemical waste must be stored in secondary containment units.",
        page_start=12,
        page_end=12,
        section="Hazardous Materials",
        similarity=0.88,
        document_name="Environmental_Policy.pdf",
        version_label="1.0",
    )
    chunk2 = RetrievedChunk(
        id=202,
        document_id=11,
        document_version_id=1,
        chunk_index=1,
        content="Emergency eyewash stations must be inspected weekly.",
        page_start=None,
        page_end=None,
        section="Lab Safety",
        similarity=0.79,
        document_name="Safety_Manual.docx",
        version_label="2.0",
    )

    from app.indexing import store as chunk_store
    monkeypatch.setattr(chunk_store, "find_similar_chunks", lambda *args, **kwargs: [chunk1, chunk2])

    captured_messages = []

    def mock_gen(messages, *args, **kwargs):
        nonlocal captured_messages
        captured_messages = messages
        return "Chemical waste requires secondary containment [1] and eyewash stations must be inspected weekly [2]."

    import app.main as main_module
    monkeypatch.setattr(main_module, "generate_answer", mock_gen)

    payload = {
        "question": "What are the rules for chemical waste and eyewash stations?",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10, 11],
        "request_id": str(uuid4()),
    }

    resp = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload)
    assert resp.status_code == 200
    data = resp.json()

    assert data["status"] == "answered"
    assert len(captured_messages) == 2
    user_prompt = captured_messages[1]["content"]

    # Verify user prompt includes both chunks and their locations
    assert "Chemical waste must be stored in secondary containment units." in user_prompt
    assert "Emergency eyewash stations must be inspected weekly." in user_prompt
    assert "Environmental_Policy.pdf (v1.0)" in user_prompt
    assert "Safety_Manual.docx (v2.0)" in user_prompt
    assert "page 12" in user_prompt
    assert 'section "Lab Safety"' in user_prompt


def test_no_fragment_over_threshold_returns_insufficient_info_and_does_not_call_llm(monkeypatch):
    """AC #2: With no fragment over the threshold the exact insufficient-information text is returned and the LLM is not called."""
    monkeypatch.setenv("APP_ENV", "testing")

    low_chunk = RetrievedChunk(
        id=301,
        document_id=10,
        document_version_id=1,
        chunk_index=0,
        content="Unrelated cafeteria lunch menu from 2024.",
        page_start=1,
        page_end=1,
        section="Menu",
        similarity=0.35,  # Below 0.55 threshold
        document_name="Lunch.pdf",
        version_label="1.0",
    )

    from app.indexing import store as chunk_store
    monkeypatch.setattr(chunk_store, "find_similar_chunks", lambda *args, **kwargs: [low_chunk])

    llm_called = False

    def mock_gen(*args, **kwargs):
        nonlocal llm_called
        llm_called = True
        return "Hallucinated response"

    import app.main as main_module
    monkeypatch.setattr(main_module, "generate_answer", mock_gen)

    # 1. English question
    payload_en = {
        "question": "What is the fire drill evacuation route?",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": str(uuid4()),
    }
    resp_en = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload_en)
    assert resp_en.status_code == 200
    data_en = resp_en.json()
    assert data_en["status"] == "insufficient_information"
    assert data_en["answer"] == INSUFFICIENT_INFO_EN
    assert data_en["disclaimer"] == DISCLAIMER_EN
    assert not llm_called

    # 2. Spanish question
    payload_es = {
        "question": "¿Cuál es la ruta de evacuación en caso de incendio?",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": str(uuid4()),
    }
    resp_es = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload_es)
    assert resp_es.status_code == 200
    data_es = resp_es.json()
    assert data_es["status"] == "insufficient_information"
    assert data_es["answer"] == INSUFFICIENT_INFO_ES
    assert data_es["disclaimer"] == DISCLAIMER_ES
    assert not llm_called


def test_every_answer_includes_exact_disclaimer_in_correct_language(monkeypatch):
    """AC #3: Every answer (answered or insufficient) includes the exact disclaimer and the UI shows it."""
    monkeypatch.setenv("APP_ENV", "testing")

    # Answered question in English
    payload_en = {
        "question": "What is the retention period?",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": str(uuid4()),
    }
    resp_en = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload_en)
    assert resp_en.status_code == 200
    assert resp_en.json()["disclaimer"] == DISCLAIMER_EN

    # Answered question in Spanish
    payload_es = {
        "question": "¿Cuál es el período de retención de registros?",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": str(uuid4()),
    }
    resp_es = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload_es)
    assert resp_es.status_code == 200
    assert resp_es.json()["disclaimer"] == DISCLAIMER_ES


def test_post_check_replaces_unsupported_answers_without_valid_citations(monkeypatch):
    """Post-check: if the answer contains no valid [n] reference to a provided fragment, replace with insufficient-info."""
    monkeypatch.setenv("APP_ENV", "testing")

    chunk = RetrievedChunk(
        id=401,
        document_id=10,
        document_version_id=1,
        chunk_index=0,
        content="Servers must be backed up daily at 02:00.",
        page_start=3,
        page_end=3,
        section="Backup Policy",
        similarity=0.85,
        document_name="IT_Policy.pdf",
        version_label="1.0",
    )

    from app.indexing import store as chunk_store
    monkeypatch.setattr(chunk_store, "find_similar_chunks", lambda *args, **kwargs: [chunk])

    # LLM outputs answer without any [n] marker
    import app.main as main_module
    monkeypatch.setattr(main_module, "generate_answer", lambda *a, **k: "Backups are done daily without citing.")

    payload = {
        "question": "When are servers backed up?",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": str(uuid4()),
    }

    resp = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload)
    assert resp.status_code == 200
    data = resp.json()

    assert data["status"] == "insufficient_information"
    assert data["answer"] == INSUFFICIENT_INFO_EN
    assert data["citations"] == []
