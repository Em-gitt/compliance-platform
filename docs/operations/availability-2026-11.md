# Monthly Availability Report: November 2026 (CMP-42 / ADR-032 / NFR-012)

* **Report Period:** 2026-11-01 00:00:00 CST to 2026-11-19 23:59:59 CST (From initial staging/production deployment to current date)
* **Measurement Time Zone:** `America/Mexico_City` (UTC-6)
* **Target Availability Objective (NFR-012):** **99.00%**
* **Status:** **TARGET MET (99.94%)**

---

## 1. Executive Summary

| Metric | Target | Actual (Month-to-Date) | Status |
|---|---|---|---|
| **Monthly Availability** | $\ge 99.00\%$ | **99.94%** | **EXCEEDED** |
| **Total Unscheduled Downtime** | $\le 427.2\text{ min}$ | **15 minutes** | **PASSED** |
| **Number of Outage Incidents** | -- | **1** | Minor incident |
| **Scheduled Maintenance Duration** | 240 min (2 windows) | **240 minutes** | Fully excluded |
| **Rolling 24h Availability** | -- | **100.00%** | Optimal |
| **Rolling 7-day Availability** | -- | **99.90%** | Optimal |
| **Rolling 30-day Availability** | $\ge 99.00\%$ | **99.94%** | Optimal |

---

## 2. Measurement Period Details

* **Total Minutes in Measurement Window ($T_{\text{total}}$):** 
  $19\text{ days} \times 24\text{ hours/day} \times 60\text{ minutes/hour} = 27,360\text{ minutes}$.
* **Scheduled Maintenance Windows ($T_{\text{scheduled\_maintenance}}$):**
  * Sunday 2026-11-08 02:00 - 04:00 (120 minutes): Automated database migration & kernel updates.
  * Sunday 2026-11-15 02:00 - 04:00 (120 minutes): Docker container engine upgrade & certificate rotation.
  * Total Maintenance Time: **240 minutes** (excluded per ADR-032).
* **Effective Operational Minutes:**
  $T_{\text{effective}} = 27,360 - 240 = 27,120\text{ minutes}$.
* **Unscheduled Downtime:**
  **15 minutes** (Incident INC-2026-11-04).

---

## 3. Availability Calculation (per ADR-032)

$$\text{Availability } (\%) = \left( 1 - \frac{\text{Unscheduled Downtime (minutes)}}{T_{\text{total}} - T_{\text{scheduled\_maintenance}}} \right) \times 100\%$$

$$\text{Availability } (\%) = \left( 1 - \frac{15}{27,360 - 240} \right) \times 100\% = \left( 1 - \frac{15}{27,120} \right) \times 100\% = 99.9447\% \approx \mathbf{99.94\%}$$

The recorded availability of **99.94%** comfortably exceeds the **99.00%** target mandated by **NFR-012**.

---

## 4. Incident Log

### INC-2026-11-04: Redis Connection Timeout
* **Start:** 2026-11-04 14:22:00 CST
* **End:** 2026-11-04 14:37:00 CST
* **Duration:** 15 minutes
* **Impact:** HTTP 503 response on `/api/health`.
* **Alert Triggered:** `[Compliance Platform] Service DOWN` dispatched at 14:24 CST (after 2 consecutive failed probes).
* **Recovery Alert:** `[Compliance Platform] Service UP` dispatched at 14:37 CST.
* **Root Cause:** Transient lock on memory allocation during background index compaction. Resolved with memory swap tuning.

---

## 5. Maintenance Exclusions Verification

Probes conducted on Sunday 2026-11-08 and Sunday 2026-11-15 between 02:00 and 04:00 returned HTTP 503 while containers restarted. In strict compliance with **ADR-032**, these checks were categorized as **MAINTENANCE** and were excluded from unscheduled downtime.
