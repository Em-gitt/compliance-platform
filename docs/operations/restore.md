# Database Restoration Runbook (CMP-41)

* **Related requirements:** NFR-016
* **Decisions:** [ADR-033](../decisions/ADR-033-backup-recovery.md)
* **Ticket:** [CMP-41] Configure 30-day backup retention and test restoration
* **RTO Limit:** **4 hours** (ADR-033 section 3.1)
* **RPO Objective:** **24 hours**

---

## 1. Overview and Objectives

This runbook documents the standard operating procedure to restore the PostgreSQL compliance database and verify data integrity following a hardware loss, corruption, or disaster event.

Every step in this procedure is automated through `infra/scripts/restore-backup.sh` and verified against the target test database `compliance_restore_test`.

---

## 2. Retention Policy (30 Days)

In accordance with NFR-016:
* Daily backups are automatically retained for **30 days**.
* Backups older than 30 days are automatically purged from remote object storage using `rclone delete --min-age 30d` and from local host backup volumes.
* At all times, at least 30 daily snapshots (`compliance_YYYYMMDD_HHMM.dump.gpg`) are maintained.

---

## 3. Step-by-Step Restoration Procedure

### Step 1: Download the backup file
Locate and download the desired encrypted snapshot from off-site S3 storage:
```bash
rclone copy "s3:compliance-backups/db/compliance_YYYYMMDD_HHMM.dump.gpg" /tmp/restore/
```

### Step 2: Decrypt with GPG AES-256
Retrieve the GPG passphrase from the team's password manager (Bitwarden Organization Vault) or `/run/secrets/backup_passphrase.key`:
```bash
gpg --batch --yes --passphrase "$BACKUP_ENCRYPTION_PASSPHRASE" -d \
    -o /tmp/restore/compliance_restored.dump \
    /tmp/restore/compliance_YYYYMMDD_HHMM.dump.gpg
```

### Step 3: Create an empty test database
Prepare a clean database instance `compliance_restore_test`:
```bash
dropdb -h db -U compliance_user --if-exists compliance_restore_test
createdb -h db -U compliance_user compliance_restore_test
```

### Step 4: Restore database schema and data
Run `pg_restore` against the target database:
```bash
pg_restore -h db -U compliance_user -d compliance_restore_test \
    --no-owner --clean --if-exists /tmp/restore/compliance_restored.dump
```
Immediately shred or delete the decrypted file:
```bash
rm -f /tmp/restore/compliance_restored.dump
```

### Step 5: Verify restore with Laravel artisan command
Run the automated verification command to compare row counts of core tables with the production snapshot:
```bash
php artisan app:verify-restore --database=compliance_restore_test --snapshot=snapshot_report.json
```

Expected output:
```
+-------------------------+---------------+-------------------+--------+
| Table                   | Restored Rows | Snapshot Expected | Status |
+-------------------------+---------------+-------------------+--------+
| organizations           | 1             | 1                 | MATCH  |
| users                   | 5             | 5                 | MATCH  |
| documents               | 12            | 12                | MATCH  |
| document_versions       | 15            | 15                | MATCH  |
| compliance_requirements | 8             | 8                 | MATCH  |
| audit_logs              | 42            | 42                | MATCH  |
| alerts                  | 6             | 6                 | MATCH  |
+-------------------------+---------------+-------------------+--------+
Restore verification PASSED: all tables and row counts verified successfully.
```

### Step 6: Record duration and verify against RTO
Record the elapsed time. The restoration must complete well within the **4-hour RTO** (target: < 15 minutes for typical workloads).

---

## 4. Automated Execution Script

The entire workflow is packaged into a single command:
```bash
./infra/scripts/restore-backup.sh /path/to/compliance_20261119_0230.dump.gpg
```
