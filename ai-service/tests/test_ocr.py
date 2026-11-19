import io
import pytest
from app.extraction.ocr import (
    OCR_FAILED_MESSAGE,
    SCANNED_PAGE_LIMIT_MESSAGE,
    OcrError,
    OcrPageLimitExceededError,
    extract_ocr,
)
from fastapi.testclient import TestClient
from app.main import app
from app.security import get_expected_token
from tests import documents

client = TestClient(app)
TOKEN = get_expected_token()
AUTH_HEADERS = {"X-Internal-Token": TOKEN}

INGEST_FORM = {
    "organization_id": 1,
    "document_id": 10,
    "document_version_id": 1,
    "mime_type": "application/pdf",
    "access_level": "all_staff",
}


def test_ocr_extracts_known_sentences_from_all_three_scanned_fixtures():
    # 1. Constancia uso de suelo
    pdf_bytes = documents.read("constancia_uso_suelo_escaneada.pdf")
    segments, conf = extract_ocr(pdf_bytes)
    assert len(segments) == 1
    assert segments[0].page == 1
    assert "CONSTANCIA DE USO DE SUELO" in segments[0].text
    assert "INDUSTRIA LIGERA" in segments[0].text
    assert conf > 80.0

    # 2. Dictamen instalaciones eléctricas
    pdf_bytes = documents.read("dictamen_instalaciones_electricas_escaneado.pdf")
    segments, conf = extract_ocr(pdf_bytes)
    assert len(segments) == 1
    assert segments[0].page == 1
    assert "DICTAMEN DE VERIFICACIÓN" in segments[0].text or "Instalaciones eléctricas" in segments[0].text
    assert conf > 80.0

    # 3. Fire extinguisher inspection
    pdf_bytes = documents.read("fire_extinguisher_inspection_scanned.pdf")
    segments, conf = extract_ocr(pdf_bytes)
    assert len(segments) == 2
    assert segments[0].page == 1
    assert "FIRE EXTINGUISHER INSPECTION" in segments[0].text
    assert segments[1].page == 2
    assert "NEXT INSPECTION" in segments[1].text
    assert conf > 80.0


def test_ocr_blank_scan_raises_ocr_failed_error():
    blank_bytes = documents.read("invalid/blank_scan.pdf")
    with pytest.raises(OcrError) as exc_info:
        extract_ocr(blank_bytes)
    assert exc_info.value.message == OCR_FAILED_MESSAGE
    assert exc_info.value.error_code == "OCR_FAILED"


def test_ocr_endpoint_blank_scan_returns_422_with_exact_message():
    blank_bytes = documents.read("invalid/blank_scan.pdf")
    files = {"file": ("blank_scan.pdf", io.BytesIO(blank_bytes), "application/pdf")}
    r = client.post("/v1/documents/ingest", headers=AUTH_HEADERS, files=files, data=INGEST_FORM)
    assert r.status_code == 422
    assert r.json() == {
        "error_code": "OCR_FAILED",
        "message": OCR_FAILED_MESSAGE,
    }


def test_ocr_page_limit_exceeded(monkeypatch):
    from PIL import Image

    # Mock convert_from_bytes to return 101 blank pages
    dummy_img = Image.new("RGB", (100, 100), color="white")
    monkeypatch.setattr("app.extraction.ocr.convert_from_bytes", lambda *args, **kwargs: [dummy_img] * 101)

    pdf_bytes = documents.read("constancia_uso_suelo_escaneada.pdf")
    with pytest.raises(OcrPageLimitExceededError) as exc_info:
        extract_ocr(pdf_bytes)
    assert exc_info.value.message == SCANNED_PAGE_LIMIT_MESSAGE
    assert exc_info.value.error_code == "VALIDATION_ERROR"
