# Database and Document Backups Operations Guide (CMP-40)

* **Related requirements:** NFR-015, NFR-016
* **Decisions:** [ADR-030](../decisions/ADR-030-deployment.md), [ADR-033](../decisions/ADR-033-backup-recovery.md)
* **Ticket:** [CMP-40] Configure automated daily database backups with monitoring

---

## 1. Overview and Objectives

Data protection and disaster recovery require regular, automated, encrypted, and monitored backups of organizational data:

* **Recovery Point Objective (RPO):** 24 hours (backups performed daily).
* **Recovery Time Objective (RTO):** 4 hours (restoration procedure tested in CMP-41).
* **Schedule:** Daily at **02:30 America/Mexico_City** (`30 2 * * *`).
* **Weekly Document Volume Archive:** Executed automatically on Sundays.

---

## 2. Architecture and Stack

Backups are executed within an isolated, dedicated container `db-backup`:
* **Base Image:** `postgres:16-bullseye` with `cron`, `gnupg`, `rclone`, and `curl`.
* **Database Dump Tool:** `pg_dump -Fc` (custom binary compressed format), capturing database records, users, audit logs, and `pgvector` embeddings.
* **Pre-Upload Encryption:** `gpg --batch --yes --symmetric --cipher-algo AES256` ensures backups are encrypted on the host before transmission.
* **Off-Site Storage:** Uploaded via `rclone` to S3-compatible cloud storage (e.g., Backblaze B2, AWS S3).
* **Monitoring:** Healthchecks.io or Uptime Kuma push monitor with a 26-hour grace threshold.
* **Failure Alerts:** Sent via SMTP with exact subject `[Compliance Platform] Daily backup FAILED` containing error output.

---

## 3. Storage Hierarchy and Naming Conventions

* **Database Dumps:** `backups/db/compliance_YYYYMMDD_HHMM.dump.gpg`
* **Documents Archive:** `backups/documents/documents_YYYYMMDD_HHMM.tar.gz.gpg`

Example listing:
```
backups/
├── db/
│   ├── compliance_20261117_0230.dump.gpg
│   ├── compliance_20261118_0230.dump.gpg
│   └── compliance_20261119_0230.dump.gpg
└── documents/
    └── documents_20261115_0230.tar.gz.gpg
```

---

## 4. Log Format (`/var/log/backup.log`)

Every backup execution appends a single comma-separated line:
```
<START_TIME>, <END_TIME>, <FILE_NAME>, <SIZE_BYTES>, <OK|FAILED>
```

Example `/var/log/backup.log`:
```
2026-11-17T02:30:00-06:00, 2026-11-17T02:30:45-06:00, compliance_20261117_0230.dump.gpg, 1459204, OK
2026-11-18T02:30:00-06:00, 2026-11-18T02:30:44-06:00, compliance_20261118_0230.dump.gpg, 1468112, OK
2026-11-19T02:30:00-06:00, 2026-11-19T02:30:47-06:00, compliance_20261119_0230.dump.gpg, 1475320, OK
```

---

## 5. Monitoring and Failure Alerting

1. **Success Ping:**
   Upon successful backup completion, the script performs an HTTP GET to `$HEALTHCHECK_URL`.
2. **Failure Ping:**
   If any command fails (`set -euo pipefail` + `trap ERR`), the script calls `$HEALTHCHECK_URL/fail` passing the error text.
3. **Alert Email:**
   On failure, an email is dispatched:
   * **Subject:** `[Compliance Platform] Daily backup FAILED`
   * **Recipient:** `$ALERT_EMAIL_TO`
   * **Body:** Contains execution timestamp, database name, target file name, and error stack trace.
4. **26-Hour Deadline Warning:**
   If Healthchecks.io does not receive a ping within 26 hours (24h schedule + 2h margin), an automated downtime alert is triggered to on-call engineers.

---

## 6. Manual Execution and Verification

To trigger a manual backup run:
```bash
docker compose exec db-backup /usr/local/bin/backup.sh
```

To verify encrypted contents without passphrase:
```bash
# This MUST fail without the passphrase:
gpg --batch -d /tmp/backups/compliance_YYYYMMDD_HHMM.dump.gpg > /dev/null
```
