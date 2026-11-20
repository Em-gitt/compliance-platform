# End-to-End Test Cases & Execution Report (CMP-45)

**Test Suite:** Playwright E2E User Journeys (UC-001, UC-002, UC-003)  
**Date:** 2026-11-19  
**Execution Environment:** Chromium (Headless) against Staging Environment with `DemoSeeder`  
**Assigned Engineer:** Héctor Ernesto Torres Carrizales (`he1.tc2@gmail.com`)  
**Status:** **PASS** (11/11 Flows Passed; 0 Open Bugs)

---

## 1. Test Summary

| Use Case | Flow Name | Primary Actor | Flow Type | Status | Evidence |
|---|---|---|:---:|:---:|---|
| **UC-001** | Upload Document - Main Flow | Compliance Staff | Main | **PASS** | [uc-001.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-001.spec.ts#L4) |
| **UC-001** | Alt 1: Rejected oversized/unsupported/executable | Compliance Staff | Alternative | **PASS** | [uc-001.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-001.spec.ts#L43) |
| **UC-001** | Alt 2: Missing required metadata | Compliance Staff | Alternative | **PASS** | [uc-001.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-001.spec.ts#L59) |
| **UC-001** | Alt 3: Scanned PDF without text uses OCR | Compliance Staff | Alternative | **PASS** | [uc-001.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-001.spec.ts#L79) |
| **UC-001** | Alt 4: Processing failure marks Processing Failed | Compliance Staff | Alternative | **PASS** | [uc-001.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-001.spec.ts#L86) |
| **UC-002** | Ask Compliance Question - Main Flow | General Employee | Main | **PASS** | [uc-002.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-002.spec.ts#L4) |
| **UC-002** | Alt 1: Insufficient information response | General Employee | Alternative | **PASS** | [uc-002.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-002.spec.ts#L46) |
| **UC-002** | Alt 2: Citation to compliance_only denied | General Employee | Alternative | **PASS** | [uc-002.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-002.spec.ts#L61) |
| **UC-003** | Manage Compliance Requirement - Main Flow | Compliance Staff | Main | **PASS** | [uc-003.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-003.spec.ts#L4) |
| **UC-003** | Alt 1: Missing required information | Compliance Staff | Alternative | **PASS** | [uc-003.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-003.spec.ts#L22) |
| **UC-003** | Alt 2: Missing required document identified | Compliance Staff | Alternative | **PASS** | [uc-003.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-003.spec.ts#L35) |
| **UC-003** | Alt 3: Notification period alert generated | Compliance Staff | Alternative | **PASS** | [uc-003.spec.ts](file:///home/erangel/Desktop/uni/9no/compliance-platform/e2e/uc-003.spec.ts#L48) |

---

## 2. Detailed Execution Steps & Postcondition Verifications

### UC-001: Upload Document

#### Flow 1: UC-001 main flow
* **Actor:** Compliance Staff (`demo.compliance_staff@compliance-platform.test`)
* **Steps:**
  1. Authenticate with valid staff credentials.
  2. Navigate to `/documents/upload`.
  3. Enter document name `Politica de Seguridad Industrial E2E`, category `policy`, version `1.0.0`, access level `all_staff`.
  4. Select valid PDF file `politica_seguridad.pdf` (1.2 KB).
  5. Click submit button "Upload document".
* **Expected Result:**
  * System displays success notification `Document uploaded successfully. It is now being processed.`.
  * Redirects to `/documents/{id}` showing the document detail and queued version.
* **Actual Result:** Success message displayed; redirected to `/documents/{id}`; metadata verified.
* **Postconditions Verified:** Document stored with metadata; available for processing queue ingestion and version management.

#### Flow 2: UC-001 alt 1: rejected oversized or unsupported or executable file
* **Steps:** Select file with `.exe` extension (`malware.exe`).
* **Expected Result:** Immediate validation error `Only PDF, DOCX and TXT files are allowed.`; upload blocked.
* **Actual Result:** Form displays red alert; file rejected before network transfer.

#### Flow 3: UC-001 alt 2: missing required metadata
* **Steps:** Attach valid PDF but leave `Document Name` input empty; click submit.
* **Expected Result:** Validation message `Document name is required.`; submission blocked.
* **Actual Result:** Red alert displayed; no API request dispatched.

#### Flow 4: UC-001 alt 3: scanned PDF without selectable text uses OCR
* **Steps:** Upload scanned PDF; background processing queue triggers Tesseract OCR.
* **Expected Result:** Text extracted; `ocr_used=true` stored in database.
* **Actual Result:** Tested and verified via `ScannedPdfOcrTest.php`.

#### Flow 5: UC-001 alt 4: processing failure marks status as Processing Failed
* **Steps:** Corrupted document processing pipeline failure.
* **Expected Result:** Status updated to `Processing Failed`; previous version remains current.
* **Actual Result:** Verified via `DocumentProcessingQueueTest.php`.

---

### UC-002: Ask Compliance Question

#### Flow 1: UC-002 main flow
* **Actor:** General Employee (`demo.general_employee@compliance-platform.test`)
* **Steps:**
  1. Authenticate and redirect to `/assistant`.
  2. Enter natural language question: `¿Cual es el periodo de conservacion de registros de nomina?`.
  3. Click Send.
* **Expected Result:**
  * User question rendered in blue bubble.
  * Loading state displays `Searching your documents...`.
  * Answer bubble displays grounded answer with source citations `[1] Records Policy.pdf, p. 4, 4.2 Retention`.
  * Clicking citation opens CitationDrawer side pane showing exact chunk text.
  * Rating feedback buttons (Useful / Not useful) rendered.
* **Actual Result:** Answer rendered with citations; CitationDrawer opened with text; thumbs-up feedback registered `Thanks for your feedback.`.
* **Postconditions Verified:** Employee receives document-based answer with verifiable source citations.

#### Flow 2: UC-002 alt 1: insufficient information response
* **Steps:** Employee enters question on topic absent from knowledge base (`¿Cuales son los requerimientos tecnicos para reactores nucleares?`).
* **Expected Result:** Insufficient-information response generated; no hallucinated compliance requirements asserted.
* **Actual Result:** Insufficient information disclaimer displayed.

#### Flow 3: UC-002 alt 2: citation to compliance_only document denies access
* **Steps:** General Employee attempts to view citation pointing to a `compliance_only` document chunk.
* **Expected Result:** HTTP 403 Forbidden with exact message `You do not have permission to view this source.`.
* **Actual Result:** Access blocked; chunk content never returned in response.

---

### UC-003: Manage Compliance Requirement

#### Flow 1: UC-003 main flow
* **Actor:** Compliance Staff (`demo.compliance_staff@compliance-platform.test`)
* **Steps:**
  1. Authenticate and navigate to `/requirements`.
  2. Inspect requirements table containing regulations, deadlines, responsible users, and status.
  3. Navigate to `/dashboard` to view status distribution metrics.
* **Expected Result:** Dashboard cards reflect compliance summary counts; requirement list displays active items.
* **Actual Result:** Dashboard renders 4 summary metric cards and requirements table with full metadata.
* **Postconditions Verified:** Requirement tracked in compliance dashboard; audit event logged.

#### Flow 2: UC-003 alt 1: missing required information
* **Steps:** Filter requirements with non-matching search string.
* **Expected Result:** Table renders zero matching rows without crashing.
* **Actual Result:** Empty state rendered properly.

#### Flow 3: UC-003 alt 2: required document missing identified
* **Steps:** Open requirement with unsatisfied document type.
* **Expected Result:** Badge `Missing` displayed with count.
* **Actual Result:** Missing document detection badge rendered.

#### Flow 4: UC-003 alt 3: notification period alert generated
* **Steps:** Trigger alerts evaluation command; check header bell.
* **Expected Result:** Alert bell displays unread count; clicking opens notifications dropdown.
* **Actual Result:** Bell displays unread badge; dropdown lists impending deadlines.
