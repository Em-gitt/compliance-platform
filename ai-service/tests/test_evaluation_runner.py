"""Tests for AI Evaluation Runner (CMP-36 / AIR-008 / FR-012 / NFR-018 / ADR-013)."""

import json
from pathlib import Path

from eval.run import evaluate_dataset

EVAL_DIR = Path(__file__).resolve().parents[1] / "eval"
DATASET_PATH = EVAL_DIR / "dataset.jsonl"


def test_evaluation_dataset_meets_required_composition():
    """AC #1: Dataset committed with at least 40 questions: 30 answerable, 10 unanswerable, at least 10 in Spanish."""
    assert DATASET_PATH.exists(), "eval/dataset.jsonl must exist"

    items = []
    with DATASET_PATH.open("r", encoding="utf-8") as f:
        for line in f:
            if line.strip():
                items.append(json.loads(line))

    assert len(items) >= 40, f"Expected at least 40 questions, got {len(items)}"

    answerable = [q for q in items if q.get("answerable")]
    unanswerable = [q for q in items if not q.get("answerable")]
    spanish = [q for q in items if q.get("lang") == "es"]

    assert len(answerable) >= 30, f"Expected at least 30 answerable questions, got {len(answerable)}"
    assert len(unanswerable) >= 10, f"Expected at least 10 unanswerable questions, got {len(unanswerable)}"
    assert len(spanish) >= 10, f"Expected at least 10 Spanish questions, got {len(spanish)}"

    # Required fields per question
    for q in answerable:
        assert "question" in q
        assert "expected_document" in q and q["expected_document"] is not None
        assert "expected_page_or_section" in q
        assert "reference_answer" in q

    for q in unanswerable:
        assert "question" in q
        assert q.get("expected_status") == "insufficient_information"


def test_evaluation_run_produces_report_and_all_metrics_pass(tmp_path):
    """AC #2 & AC #3: Report report.md and results.csv committed with all metrics PASS against ADR-013."""
    out_dir = tmp_path / "report_test"
    metrics = evaluate_dataset(DATASET_PATH, out_dir)

    assert (out_dir / "report.md").exists()
    assert (out_dir / "results.csv").exists()

    assert metrics["answer_relevance"] >= 80.0
    assert metrics["source_accuracy"] >= 90.0
    assert metrics["citation_traceability"] == 100.0
    assert metrics["insufficient_info_accuracy"] >= 90.0
    assert metrics["document_name_present"] == 100.0
    assert metrics["latency_p90_seconds"] <= 10.0

    report_content = (out_dir / "report.md").read_text(encoding="utf-8")
    assert "**Overall Outcome:** **PASS**" in report_content
    assert "100% of answered questions include the source document name (FR-012)." in report_content
