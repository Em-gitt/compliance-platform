"""OCR pipeline for scanned PDFs (CMP-26, FR-003)."""

from pdf2image import convert_from_bytes
import pytesseract

from app.extraction.normalize import normalize_text
from app.extraction.segment import Segment

SCANNED_PAGE_LIMIT = 100
OCR_LANG = "spa+eng"
OCR_DPI = 300

OCR_FAILED_MESSAGE = "The scanned document could not be read (OCR failed). Upload a clearer scan or a text-based PDF."
SCANNED_PAGE_LIMIT_MESSAGE = "Scanned documents can have up to 100 pages."


class OcrError(Exception):
    """Raised when OCR produces no text or fails."""

    def __init__(self, message: str = OCR_FAILED_MESSAGE, error_code: str = "OCR_FAILED") -> None:
        super().__init__(message)
        self.message = message
        self.error_code = error_code


class OcrPageLimitExceededError(OcrError):
    """Raised when a scanned PDF has more than 100 pages."""

    def __init__(self) -> None:
        super().__init__(message=SCANNED_PAGE_LIMIT_MESSAGE, error_code="VALIDATION_ERROR")


def extract_ocr(file_bytes: bytes) -> tuple[list[Segment], float]:
    """Convert each page to an image at 300 DPI, perform OCR with lang='spa+eng',
    and return segments with page numbers and average OCR confidence.

    Raises:
        OcrPageLimitExceededError: if document exceeds 100 pages.
        OcrError: if OCR fails or extracts no text.
    """
    try:
        images = convert_from_bytes(file_bytes, dpi=OCR_DPI)
    except Exception as exc:
        raise OcrError() from exc

    if not images:
        raise OcrError()

    if len(images) > SCANNED_PAGE_LIMIT:
        raise OcrPageLimitExceededError()

    segments: list[Segment] = []
    all_confidences: list[int] = []

    for page_number, image in enumerate(images, start=1):
        try:
            data = pytesseract.image_to_data(image, lang=OCR_LANG, output_type=pytesseract.Output.DICT)
            page_confs = [int(c) for c in data.get("conf", []) if str(c) != "-1" and int(c) >= 0]
            all_confidences.extend(page_confs)

            raw_text = pytesseract.image_to_string(image, lang=OCR_LANG)
            text = normalize_text(raw_text)
            if text:
                segments.append(Segment(text=text, page=page_number, section=None))
        except Exception as exc:
            raise OcrError() from exc

    total_chars = sum(len(s.text) for s in segments)
    if not segments or total_chars == 0:
        raise OcrError()

    avg_confidence = (
        round(sum(all_confidences) / len(all_confidences), 2)
        if all_confidences
        else 0.0
    )

    return segments, avg_confidence
