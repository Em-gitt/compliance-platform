"""Tests for Permission-Aware Retrieval (CMP-30 / FR-011 / AIR-002 / AIR-006 / BR-002)."""

import json
from uuid import uuid4
from fastapi.testclient import TestClient

from app.main import app
from app.indexing.store import RetrievedChunk
from app.security import get_expected_token

client = TestClient(app)
AUTH_HEADERS = {"X-Internal-Token": get_expected_token()}


def test_retrieval_finished_before_generation_started_logged_for_ten_questions(tmp_path, monkeypatch):
    """AC #1: Logs of 10 test questions show retrieval_finished before generation_started for every request."""
    log_file = tmp_path / "test-retrieval.log"
    monkeypatch.setenv("AI_RETRIEVAL_LOG_PATH", str(log_file))
    monkeypatch.setenv("APP_ENV", "testing")

    for i in range(10):
        req_id = str(uuid4())
        payload = {
            "question": f"Question {i + 1}: What is the retention policy?",
            "user_id": 1,
            "organization_id": 1,
            "allowed_document_ids": [10, 11],
            "request_id": req_id,
        }
        resp = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload)
        assert resp.status_code == 200

    assert log_file.exists()
    lines = [json.loads(line) for line in log_file.read_text(encoding="utf-8").splitlines()]

    # Group by request_id
    by_req: dict[str, list[dict]] = {}
    for entry in lines:
        by_req.setdefault(entry["request_id"], []).append(entry)

    assert len(by_req) == 10

    for req_id, events in by_req.items():
        event_names = [e["event"] for e in events]
        assert "retrieval_started" in event_names
        assert "retrieval_finished" in event_names
        assert "generation_started" in event_names
        assert "generation_finished" in event_names

        ret_idx = event_names.index("retrieval_finished")
        gen_idx = event_names.index("generation_started")
        assert ret_idx < gen_idx, f"retrieval_finished must appear before generation_started for req {req_id}"

        # Timestamps prove order
        ret_ts = events[ret_idx]["timestamp"]
        gen_ts = events[gen_idx]["timestamp"]
        assert ret_ts <= gen_ts


def test_permission_filtering_between_compliance_staff_and_general_employee(tmp_path, monkeypatch):
    """
    AC #2: Test with 2 users:
    - 'compliance_only' document is cited for Compliance Staff.
    - For General Employee the same question returns the insufficient-information response and no chunk of that document appears in the logs.
    """
    log_file = tmp_path / "permission-test.log"
    monkeypatch.setenv("AI_RETRIEVAL_LOG_PATH", str(log_file))
    monkeypatch.setenv("APP_ENV", "testing")

    # Document 10 is 'compliance_only' (only visible to Compliance Staff).
    # General employee only has allowed_document_ids = [] (or only all_staff documents).

    # 1. Compliance Staff (allowed_document_ids=[10])
    staff_req_id = str(uuid4())
    staff_payload = {
        "question": "What are the internal investigation rules?",
        "user_id": 2,  # Compliance staff
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": staff_req_id,
    }
    staff_resp = client.post("/v1/ask", headers=AUTH_HEADERS, json=staff_payload)
    assert staff_resp.status_code == 200
    staff_data = staff_resp.json()
    assert staff_data["status"] == "answered"
    assert len(staff_data["citations"]) > 0
    assert staff_data["citations"][0]["document_id"] == 10

    # 2. General Employee asking the exact same question (allowed_document_ids=[] because doc 10 is compliance_only)
    emp_req_id = str(uuid4())
    emp_payload = {
        "question": "What are the internal investigation rules?",
        "user_id": 3,  # General employee
        "organization_id": 1,
        "allowed_document_ids": [],
        "request_id": emp_req_id,
    }
    emp_resp = client.post("/v1/ask", headers=AUTH_HEADERS, json=emp_payload)
    assert emp_resp.status_code == 200
    emp_data = emp_resp.json()
    assert emp_data["status"] == "insufficient_information"
    assert emp_data["answer"] == "I could not find enough information in your organization's documents to answer this question."
    assert emp_data["citations"] == []

    # Verify logs: no chunk from doc 10 appears in logs for General Employee
    lines = [json.loads(line) for line in log_file.read_text(encoding="utf-8").splitlines()]
    emp_events = [e for e in lines if e.get("request_id") == emp_req_id]
    assert len(emp_events) > 0
    for e in emp_events:
        assert 10 not in e.get("retrieved_chunk_ids", [])
        assert 10 not in e.get("chunks_to_llm", [])
        assert "generation_started" != e.get("event")


def test_fragments_below_threshold_are_never_passed_to_llm(tmp_path, monkeypatch):
    """AC #3: pytest: fragments with similarity below the threshold (0.55) are never passed to the LLM."""
    log_file = tmp_path / "threshold-test.log"
    monkeypatch.setenv("AI_RETRIEVAL_LOG_PATH", str(log_file))
    monkeypatch.setenv("APP_ENV", "testing")

    # Mock chunk_store.find_similar_chunks returning candidates below 0.55
    low_sim_chunk = RetrievedChunk(
        id=999,
        document_id=10,
        document_version_id=1,
        chunk_index=0,
        content="Irrelevant text about gardening and plants.",
        page_start=1,
        page_end=1,
        section="Gardening",
        similarity=0.32,  # Well below 0.55 threshold
        document_name="Irrelevant.pdf",
        version_label="1.0",
    )

    from app.indexing import store as chunk_store
    monkeypatch.setattr(chunk_store, "find_similar_chunks", lambda *args, **kwargs: [low_sim_chunk])

    llm_called = False

    def mock_gen(*args, **kwargs):
        nonlocal llm_called
        llm_called = True
        return "Should not be called"

    import app.main as main_module
    monkeypatch.setattr(main_module, "generate_answer", mock_gen)

    req_id = str(uuid4())
    payload = {
        "question": "What is the fire safety procedure?",
        "user_id": 1,
        "organization_id": 1,
        "allowed_document_ids": [10],
        "request_id": req_id,
    }

    resp = client.post("/v1/ask", headers=AUTH_HEADERS, json=payload)
    assert resp.status_code == 200
    data = resp.json()

    assert data["status"] == "insufficient_information"
    assert data["answer"] == "I could not find enough information in your organization's documents to answer this question."
    assert data["citations"] == []
    assert not llm_called, "LLM must NOT be called when no fragment passes the similarity threshold"

    # Verify in logs
    lines = [json.loads(line) for line in log_file.read_text(encoding="utf-8").splitlines()]
    req_events = [e for e in lines if e.get("request_id") == req_id]
    ret_finished = [e for e in req_events if e.get("event") == "retrieval_finished"][0]
    assert ret_finished["fragments_considered"] == 1
    assert ret_finished["fragments_kept"] == 0
    assert "generation_started" not in [e.get("event") for e in req_events]
