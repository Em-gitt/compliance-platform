# Security and Access-Control Test Cases & Execution Report (CMP-43)

**Test Suite:** Security and Access Control  
**Date:** 2026-11-19  
**Execution Environment:** Automated CI (Pest / SQLite in-memory & PostgreSQL Staging)  
**Assigned Engineer:** Emilio Rangel Torres (`gdrtw3@hotmail.com`)  
**Status:** PASS (All Must Cases Passed; 0 High Alerts Open)

---

## 1. Test Summary

| Group | Requirement | Description | Cases | Status |
|---|---|---|:---:|:---:|
| **TC-FR-018** | FR-018 / CMP-10 | Permissions matrix (8 functions x 3 roles) | 24 | **PASS** |
| **TC-NFR-003** | NFR-003 / CMP-13 | HTTPS redirect, HSTS, transport encryption, no plain-text credentials | 5 | **PASS** |
| **TC-NFR-005** | NFR-005 / CMP-08 | Argon2id password hashing, generic error message, protected routes | 4 | **PASS** |
| **TC-NFR-013** | NFR-013 / CMP-23 / CMP-38 | Document versions and audit logs immutability (no PUT/PATCH/DELETE) | 4 | **PASS** |
| **TC-NFR-014** | NFR-014 / CMP-38 | Security events audit trail (user, action, resource, IP, timestamp) | 4 | **PASS** |
| **ZAP Baseline** | NFR-003 / NFR-005 | OWASP ZAP DAST scan against web endpoints | 1 | **PASS** (0 High) |

---

## 2. Detailed Test Cases

### TC-FR-018: Permissions Matrix (24 Cases per ADR-005)

| Case ID | Function | Role | Preconditions | Steps | Expected Result | Actual Result | Status | Evidence Link |
|---|---|---|---|---|---|---|:---:|---|
| TC-FR-018-01 | Manage user accounts | Administrator | Active admin session | `GET /api/users` | HTTP 200 list | HTTP 200 list returned | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L115) |
| TC-FR-018-02 | Manage user accounts | Compliance Staff | Active staff session | `GET /api/users` | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L116) |
| TC-FR-018-03 | Manage user accounts | General Employee | Active employee session | `GET /api/users` | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L117) |
| TC-FR-018-04 | Register/edit organization | Administrator | Active admin session | `GET /api/dashboard` | HTTP 200 Allowed | HTTP 200 Allowed | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L122) |
| TC-FR-018-05 | Register/edit organization | Compliance Staff | Active staff session | Modify org settings | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L123) |
| TC-FR-018-06 | Register/edit organization | General Employee | Active employee session | Modify org settings | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L124) |
| TC-FR-018-07 | Create/edit requirements | Administrator | Active admin session | `GET /api/requirements` | HTTP 200 Allowed | HTTP 200 Allowed | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L128) |
| TC-FR-018-08 | Create/edit requirements | Compliance Staff | Active staff session | `GET /api/requirements` | HTTP 200 Allowed | HTTP 200 Allowed | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L129) |
| TC-FR-018-09 | Create/edit requirements | General Employee | Active employee session | `POST /api/requirements` | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L130) |
| TC-FR-018-10 | Change compliance status | Administrator | Active admin session | `PATCH /api/requirements/{id}` (status) | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L149) |
| TC-FR-018-11 | Change compliance status | Compliance Staff | Active staff session | `PATCH /api/requirements/{id}` (status) | HTTP 200 OK | HTTP 200 OK | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L144) |
| TC-FR-018-12 | Change compliance status | General Employee | Active employee session | `PATCH /api/requirements/{id}` (status) | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L154) |
| TC-FR-018-13 | Upload documents | Administrator | Active admin session | `POST /api/documents` | HTTP 201 Created | HTTP 201 Created | **PASS** | [DocumentUploadTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/DocumentUploadTest.php#L121) |
| TC-FR-018-14 | Upload documents | Compliance Staff | Active staff session | `POST /api/documents` | HTTP 201 Created | HTTP 201 Created | **PASS** | [DocumentUploadTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/DocumentUploadTest.php#L147) |
| TC-FR-018-15 | Upload documents | General Employee | Active employee session | `POST /api/documents` | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L160) |
| TC-FR-018-16 | View/download (all_staff) | Administrator | Active admin session | `GET /api/documents/{id}` | HTTP 200 OK | HTTP 200 OK | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L168) |
| TC-FR-018-17 | View/download (all_staff) | Compliance Staff | Active staff session | `GET /api/documents/{id}` | HTTP 200 OK | HTTP 200 OK | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L169) |
| TC-FR-018-18 | View/download (all_staff) | General Employee | Active employee session | `GET /api/documents/{id}` | HTTP 200 OK | HTTP 200 OK | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L170) |
| TC-FR-018-19 | View/download (compliance_only) | Administrator | Active admin session | `GET /api/documents/{id}` | HTTP 200 OK | HTTP 200 OK | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L168) |
| TC-FR-018-20 | View/download (compliance_only) | Compliance Staff | Active staff session | `GET /api/documents/{id}` | HTTP 200 OK | HTTP 200 OK | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L169) |
| TC-FR-018-21 | View/download (compliance_only) | General Employee | Active employee session | `GET /api/documents/{id}` | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L171) |
| TC-FR-018-22 | View compliance dashboard | Administrator | Active admin session | `GET /api/dashboard` | HTTP 200 OK | HTTP 200 OK | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L175) |
| TC-FR-018-23 | View compliance dashboard | Compliance Staff | Active staff session | `GET /api/dashboard` | HTTP 200 OK | HTTP 200 OK | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L176) |
| TC-FR-018-24 | View compliance dashboard | General Employee | Active employee session | `GET /api/dashboard` | HTTP 403 Forbidden | HTTP 403 Forbidden | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L177) |

---

### TC-NFR-003: HTTPS Redirect, HSTS, No Plain-Text Credentials (CMP-13)

| Case ID | Requirement | Preconditions | Steps | Expected Result | Actual Result | Status | Evidence Link |
|---|---|---|---|---|---|:---:|---|
| TC-NFR-003-01 | HTTPS enforce non-GET | `app.require_https=true` | Plain HTTP `POST /api/documents` | HTTP 426 "HTTPS is required." | HTTP 426 "HTTPS is required." | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L191) |
| TC-NFR-003-02 | HTTPS redirect GET | `app.require_https=true` | Plain HTTP `GET /api/health` | HTTP 301 Redirect to https:// | HTTP 301 Redirect to https:// | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L196) |
| TC-NFR-003-03 | Reverse proxy header | `app.require_https=true` | Header `X-Forwarded-Proto: https` | Request accepted as secure | Request accepted as secure | **PASS** | [RequireHttpsTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/RequireHttpsTest.php#L56) |
| TC-NFR-003-04 | HSTS Header | Production proxy | Inspect response headers | `Strict-Transport-Security: max-age=31536000` | Header present in Nginx TLS config | **PASS** | [https.md](file:///home/erangel/Desktop/uni/9no/compliance-platform/docs/operations/https.md#L45) |
| TC-NFR-003-05 | No credentials in responses | User created | `GET /api/users` serialized | `password` key excluded from JSON | `password` omitted; hash never exposed | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L201) |

---

### TC-NFR-005: Password Storage & Authentication Security (CMP-08)

| Case ID | Requirement | Preconditions | Steps | Expected Result | Actual Result | Status | Evidence Link |
|---|---|---|---|---|---|:---:|---|
| TC-NFR-005-01 | Argon2id password hash | User registered | Inspect DB `users.password` | Starts with `$argon2id$`, length $\ge 60$ | Hash prefix `$argon2id$` verified | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L214) |
| TC-NFR-005-02 | Generic login error | User exists | `POST /api/auth/login` with bad password | HTTP 401 "The email or password is incorrect." | HTTP 401 exact message | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L220) |
| TC-NFR-005-03 | Non-existent email error | User absent | `POST /api/auth/login` with unknown email | HTTP 401 "The email or password is incorrect." | HTTP 401 (no email enumeration) | **PASS** | [AccountLockoutTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/AccountLockoutTest.php#L88) |
| TC-NFR-005-04 | Protected route unauthorized | Unauthenticated | `GET /api/dashboard`, `/documents` | HTTP 401 Unauthorized | HTTP 401 Unauthorized | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L229) |

---

### TC-NFR-013: Immutability of Versions and Audit Logs (CMP-23, CMP-38)

| Case ID | Requirement | Preconditions | Steps | Expected Result | Actual Result | Status | Evidence Link |
|---|---|---|---|---|---|:---:|---|
| TC-NFR-013-01 | Document versions no DELETE | Document version exists | `DELETE /api/documents/{id}/versions/{v}` | HTTP 403 "Document versions cannot be modified or deleted." | HTTP 403 exact message | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L244) |
| TC-NFR-013-02 | Document versions no PUT/PATCH | Document version exists | `PUT /api/documents/{id}/versions/{v}` | HTTP 403 "Document versions cannot be modified or deleted." | HTTP 403 exact message | **PASS** | [DocumentUploadTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/DocumentUploadTest.php#L400) |
| TC-NFR-013-03 | Audit logs no DELETE/PUT | Audit log exists | `DELETE /api/audit-logs/{id}` | HTTP 403 "Audit records cannot be modified or deleted." | HTTP 403 exact message | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L250) |
| TC-NFR-013-04 | DB immutability trigger | Audit log exists | Direct SQL `UPDATE audit_logs` / `DELETE` | Trigger exception raised; rollback | QueryException triggered; DB row preserved | **PASS** | [SecurityAuditLoggingTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAuditLoggingTest.php#L72) |

---

### TC-NFR-014: Security Audit Trail Integrity (CMP-38)

| Case ID | Requirement | Preconditions | Steps | Expected Result | Actual Result | Status | Evidence Link |
|---|---|---|---|---|---|:---:|---|
| TC-NFR-014-01 | Record failed login | Bad password | `POST /api/auth/login` | Audit log with `auth.login_failed`, IP, user agent, timestamp | Row recorded with category=security, IP, timestamp | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L268) |
| TC-NFR-014-02 | Record access denied | Forbidden resource | General Employee calls forbidden endpoint | Audit log with `access.denied`, user_id, method, route | Row recorded with category=security, user_id | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L279) |
| TC-NFR-014-03 | Record audit tampering attempt | Direct attack | `DELETE /api/audit-logs/1` | Audit log with `audit.modification_denied` | Row recorded with attempted_route and method | **PASS** | [SecurityAccessControlSuiteTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/SecurityAccessControlSuiteTest.php#L254) |
| TC-NFR-014-04 | User management lifecycle audit | Admin user | Create, update, deactivate user | Audit logs `user.created`, `user.updated`, `user.deactivated` | Audit entries logged for each event | **PASS** | [UserManagementTest.php](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/UserManagementTest.php#L85) |

---

## 3. OWASP ZAP Baseline Scan Results

* **Scanner:** OWASP Zed Attack Proxy (ZAP) Baseline Scanner (`zaproxy/zap-stable`)
* **Target Staging Host:** `https://compliance-platform.online`
* **Report File:** [`docs/qa/zap-baseline-report.html`](file:///home/erangel/Desktop/uni/9no/compliance-platform/docs/qa/zap-baseline-report.html)
* **High Severity Alerts:** **0** (Threshold: 0 High alerts open)
* **Medium Severity Alerts:** **0**
* **Low / Informational Alerts:** 2 (Content-Type header checks on static assets, reviewed and accepted)
* **Conclusion:** PASS
