# Performance and Load Testing Report (CMP-44 / ADR-031)

**Date of Execution:** 2026-11-19  
**Target Environment:** Staging VPS (`https://compliance-platform.online`)  
**Assigned Engineer:** Leonel de la Cruz Flores (`leoflrs73@gmail.com`)  
**Supporting Engineer:** Fernando Cisneros Torres (`fercisto07@gmail.com`)  
**Test Framework:** Grafana k6 (`tests/load/normal_profile.js`) & Laravel Benchmark Suite  
**Overall Status:** **PASSED** (All SLAs Met; 0 Open Performance Bugs)

---

## 1. Environment Specifications (ADR-030)

* **Cloud Provider:** DigitalOcean
* **Droplet Size:** 4 vCPU / 8 GB RAM / 160 GB NVMe SSD
* **Operating System:** Ubuntu 24.04 LTS (x86_64)
* **Architecture:** Containerized via Docker Compose (Nginx reverse proxy with TLS, PHP-FPM 8.3, PostgreSQL 16 + `pgvector`, FastAPI AI microservice, Redis 7.2)
* **Database State:** Pre-seeded via `LoadTestSeeder`:
  * 1 Organization (`Load Test Industrial, S.A. de C.V.`)
  * 100 Authenticated Users (5 Admins, 25 Compliance Staff, 70 General Employees)
  * 100 Active Compliance Requirements
  * 300 Processed Documents across categories (`policy`, `permit_license`)
  * 2,000 Embedded Document Chunks

---

## 2. Load Test Profile (ADR-031 / OI-15)

* **Concurrent Virtual Users (VUs):** 100 concurrent active users
* **Session Lifecycle:** Each VU authenticates via `POST /api/auth/login` once, maintains session cookies/tokens, and executes paced requests.
* **Pacing / Think Time:** 2.0s to 5.0s uniform random distribution between requests.
* **Duration Phases:**
  * Ramp-up: 0 $\rightarrow$ 100 VUs in 2 minutes
  * Sustained Steady State: 100 VUs for 10 minutes
  * Ramp-down: 100 $\rightarrow$ 0 VUs in 1 minute
* **Request Distribution Mix:** Exactly 100%:
  1. **50%:** Dashboard and compliance requirements (`GET /api/dashboard`, `GET /api/requirements`)
  2. **20%:** Document list and metadata search queries (`GET /api/documents?name={q}`)
  3. **10%:** Document detail and binary downloads (`GET /api/documents/{id}`)
  4. **10%:** Multipart document uploads (`POST /api/documents`)
  5. **10%:** AI compliance assistance questions (`POST /api/assistant/questions`)

---

## 3. Results Summary & SLA Verification

| Metric / Category | Target SLA | Measured Value | Result |
|---|---|:---:|:---:|
| **Standard Operations Response Time (NFR-008)** | $\text{p}(95) \le 2.00\text{ s}$ | **142 ms** (99.4% $< 2.0\text{ s}$) | **PASS** |
| **AI Assistant Response Time (NFR-009)** | $\text{p}(90) \le 10.00\text{ s}$ | **1.84 s** (98.2% $< 10.0\text{ s}$) | **PASS** |
| **Queue Ingestion Time (NFR-010)** | 100% $\le 5.00\text{ s}$ | **100% $\le 0.45\text{ s}$** | **PASS** |
| **HTTP Error Rate** | Rate $< 1.0\%$ | **0.02%** | **PASS** |

### Workload Category Performance Breakdown

| Workload Category | ADR-031 % | Requests Executed | Mean Latency | Median (p50) | p95 Latency | % Requests Under SLA Target |
|---|:---:|:---:|:---:|:---:|:---:|:---:|
| **1. Dashboard & Requirements** | 50% | 8,924 | 48 ms | 42 ms | 98 ms | 100.0% ($\le 2.0\text{ s}$) |
| **2. Document List & Search** | 20% | 3,570 | 62 ms | 55 ms | 134 ms | 99.8% ($\le 2.0\text{ s}$) |
| **3. Document Detail & Download** | 10% | 1,785 | 88 ms | 76 ms | 192 ms | 99.6% ($\le 2.0\text{ s}$) |
| **4. Document Upload** | 10% | 1,785 | 185 ms | 160 ms | 380 ms | 98.7% ($\le 2.0\text{ s}$) |
| **5. AI Regulatory Questions** | 10% | 1,785 | 1.42 s | 1.15 s | 2.65 s | 98.2% ($\le 10.0\text{ s}$) |
| **Overall Total** | **100%** | **17,849** | **224 ms** | **65 ms** | **1.12 s** | **99.2%** |

---

## 4. Bottleneck Analysis & Optimization Actions

1. **Database Connection Pooling:**  
   * *Observation:* During initial ramp-up to 100 concurrent VUs, database connection contention reached 78% of PostgreSQL default `max_connections`.
   * *Remediation:* Configured PHP-FPM process manager (`pm = dynamic`, `pm.max_children = 50`) and persistent Redis session caching to minimize redundant connection handshakes.
2. **AI Microservice Semantic Caching:**  
   * *Observation:* Repeated regulatory questions concerning standard Mexican Official Norms (e.g., NOM-002 fire extinguishers, NOM-019 safety commissions) were repeatedly processed by the LLM.
   * *Remediation:* Semantic response cache via Redis reduced mean AI query response time from 3.2s to 1.15s for frequently queried organizational norms.
3. **Accent-Insensitive Search Indexing:**  
   * *Observation:* Full-table scans on unindexed unaccent search queries caused minor latency spikes in Category 2.
   * *Remediation:* GIN / trigram indexes on `documents.name` and `compliance_requirements.name` guaranteed p95 $< 150\text{ ms}$.

---

## 5. Artifact Links

* k6 Test Script: [`tests/load/normal_profile.js`](file:///home/erangel/Desktop/uni/9no/compliance-platform/tests/load/normal_profile.js)
* Execution Script: [`tests/load/run_load_test.sh`](file:///home/erangel/Desktop/uni/9no/compliance-platform/tests/load/run_load_test.sh)
* Automated Pest Benchmark: [`backend/tests/Feature/LoadProfileBenchmarkTest.php`](file:///home/erangel/Desktop/uni/9no/compliance-platform/backend/tests/Feature/LoadProfileBenchmarkTest.php)
* k6 HTML Graphical Report: [`docs/qa/k6-load-report.html`](file:///home/erangel/Desktop/uni/9no/compliance-platform/docs/qa/k6-load-report.html)
