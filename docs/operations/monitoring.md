# Availability Monitoring and Alerting Operations (CMP-42 / ADR-032 / NFR-012)

## 1. Overview

To satisfy non-functional requirement **NFR-012** (minimum 99% monthly availability) and Jira requirement **CMP-42**, continuous synthetic availability monitoring is configured across the platform infrastructure.

The monitoring stack utilizes **Uptime Kuma** (running in Docker container `uptime-kuma` on port 3001) paired with automated notification dispatchers to monitor the platform health probe and compute real-time and historical uptime.

---

## 2. Target Probe Specification

* **Endpoint:** `GET https://compliance-platform.online/api/health` (Internal: `http://backend:8000/api/health`)
* **Interval:** 60 seconds
* **Timeout:** 10 seconds
* **Validation Criteria:**
  1. HTTP response status code must be `200 OK`.
  2. Response payload must contain keyword `"ok"` (verifying both PostgreSQL connection and AI service availability).
* **Retry Threshold:** 2 consecutive failed probes before declaring an outage.

---

## 3. Alerting and Notifications

* **Notification Channel:** SMTP / Email (e.g. `team@compliance-platform.local`, Mailpit / SES)
* **Incident Declaration (Service DOWN):**
  * Triggered after **2 consecutive probe failures** (2 minutes).
  * Exact Email Subject: `[Compliance Platform] Service DOWN`
  * Body details failure status code, error message, and timestamp.
* **Incident Resolution (Service UP):**
  * Triggered immediately upon the first successful probe following an active outage.
  * Exact Email Subject: `[Compliance Platform] Service UP`
  * Body details recovery latency, timestamp, and status.

---

## 4. Scheduled Maintenance Window (ADR-032)

Per **ADR-032**, scheduled maintenance is formally designated:

* **Frequency:** Every Sunday
* **Window:** `02:00` to `04:00` (2 hours)
* **Time Zone:** `America/Mexico_City` (UTC-6)
* **Downtime Exclusion Policy:**
  * Probes that return non-200 or timeout during this recurring window are tagged as **MAINTENANCE**.
  * Downtime accumulated during this window is strictly **excluded** from the monthly availability calculation under NFR-012 and does not trigger emergency DOWN alerts.

---

## 5. Status Page Metrics

The public/private status page exposes three key rolling availability periods:
* **24 Hours:** Immediate operational health.
* **7 Days:** Short-term trend analysis.
* **30 Days:** Formal contractual SLO assessment against the 99.0% availability target.

---

## 6. Monthly Availability Calculation Formula

Per ADR-032, monthly availability is calculated as:

$$\text{Availability } (\%) = \left( 1 - \frac{\text{Unscheduled Downtime (minutes)}}{T_{\text{total}} - T_{\text{scheduled\_maintenance}}} \right) \times 100\%$$

Where:
* $T_{\text{total}}$: Total minutes in the calendar measurement period (e.g., $30\text{ days} \times 1440 = 43,200\text{ minutes}$).
* $T_{\text{scheduled\_maintenance}}$: Total minutes in approved maintenance windows (e.g., $4 \text{ Sundays} \times 120 = 480\text{ minutes}$).
* $\text{Unscheduled Downtime}$: Actual minutes of unexpected outages outside maintenance windows.
* Target: $\ge 99.00\%$.
