#!/usr/bin/env bash
set -euo pipefail

# Configuration with defaults
POSTGRES_HOST="${POSTGRES_HOST:-db}"
POSTGRES_PORT="${POSTGRES_PORT:-5432}"
POSTGRES_DB="${POSTGRES_DB:-compliance}"
POSTGRES_USER="${POSTGRES_USER:-compliance_user}"
POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-}"
BACKUP_DIR="${BACKUP_DIR:-/tmp/backups}"
BACKUP_REMOTE="${BACKUP_REMOTE:-}"
BACKUP_LOG_FILE="${BACKUP_LOG_FILE:-/var/log/backup.log}"
HEALTHCHECK_URL="${HEALTHCHECK_URL:-}"
ALERT_EMAIL_TO="${ALERT_EMAIL_TO:-team@compliance-platform.local}"
SMTP_HOST="${SMTP_HOST:-localhost}"
SMTP_PORT="${SMTP_PORT:-1025}"
DOCUMENTS_STORAGE_PATH="${DOCUMENTS_STORAGE_PATH:-/var/www/html/storage/app/documents}"
PASSPHRASE="${BACKUP_ENCRYPTION_PASSPHRASE:-}"
PASSPHRASE_FILE="${BACKUP_ENCRYPTION_KEY_FILE:-/run/secrets/backup_passphrase.key}"
FORCE_DOCUMENTS_ARCHIVE="${FORCE_DOCUMENTS_ARCHIVE:-0}"

START_TIME=$(date -Iseconds)
TIMESTAMP=$(date +%Y%m%d_%H%M)
DUMP_FILENAME="compliance_${TIMESTAMP}.dump"
ENCRYPTED_FILENAME="${DUMP_FILENAME}.gpg"
DUMP_PATH="${BACKUP_DIR}/${DUMP_FILENAME}"
ENCRYPTED_PATH="${BACKUP_DIR}/${ENCRYPTED_FILENAME}"

mkdir -p "${BACKUP_DIR}"
mkdir -p "$(dirname "${BACKUP_LOG_FILE}")"

# Resolve passphrase: either environment variable or secret key file
get_passphrase() {
    if [[ -n "${PASSPHRASE}" ]]; then
        echo "${PASSPHRASE}"
    elif [[ -f "${PASSPHRASE_FILE}" ]]; then
        cat "${PASSPHRASE_FILE}"
    else
        echo "Error: Backup encryption passphrase is required per ADR-033 but none provided." >&2
        return 1
    fi
}

# Function to send alert email on failure
send_failure_alert() {
    local error_msg="$1"
    python3 - "$error_msg" << 'PYEOF'
import smtplib
from email.mime.text import MIMEText
import sys
import os

error_msg = sys.argv[1] if len(sys.argv) > 1 else "Unknown error"
smtp_host = os.getenv("SMTP_HOST", "localhost")
smtp_port = int(os.getenv("SMTP_PORT", "1025"))
to_email = os.getenv("ALERT_EMAIL_TO", "team@compliance-platform.local")
subject = "[Compliance Platform] Daily backup FAILED"
body = f"""Daily automated database backup failed.

Host: {os.getenv("POSTGRES_HOST", "db")}
Database: {os.getenv("POSTGRES_DB", "compliance")}

Error details:
{error_msg}
"""

msg = MIMEText(body)
msg['Subject'] = subject
msg['From'] = 'backup-service@compliance-platform.local'
msg['To'] = to_email

try:
    with smtplib.SMTP(smtp_host, smtp_port, timeout=10) as server:
        server.send_message(msg)
    print("Alert email sent successfully.")
except Exception as e:
    print(f"Could not send alert email via SMTP: {e}")
PYEOF
}

# Error handler trap
on_error() {
    local exit_code="$1"
    local line_no="$2"
    local end_time
    end_time=$(date -Iseconds)
    local err_text="Backup command failed at line ${line_no} with exit code ${exit_code}"
    
    echo "${START_TIME}, ${end_time}, ${ENCRYPTED_FILENAME}, 0, FAILED" >> "${BACKUP_LOG_FILE}"
    
    # Notify monitoring failure endpoint
    if [[ -n "${HEALTHCHECK_URL}" ]]; then
        curl -fsS --retry 3 "${HEALTHCHECK_URL}/fail" --data-raw "${err_text}" 2>/dev/null || true
    fi
    
    # Send alert email
    send_failure_alert "${err_text}" || true
    
    # Securely remove any plain unencrypted dump
    rm -f "${DUMP_PATH}"
    
    exit "${exit_code}"
}

trap 'on_error "$?" "$LINENO"' ERR

# 1. Execute database dump
echo "Starting daily backup for ${POSTGRES_DB}..."
if command -v pg_dump >/dev/null 2>&1; then
    export PGPASSWORD="${POSTGRES_PASSWORD}"
    pg_dump -h "${POSTGRES_HOST}" -p "${POSTGRES_PORT}" -U "${POSTGRES_USER}" -d "${POSTGRES_DB}" -Fc -f "${DUMP_PATH}"
else
    # Fallback for environments / test runs without postgres-client installed
    if [[ "${BACKUP_TEST_MODE:-0}" == "1" ]] || [[ "${APP_ENV:-}" == "testing" ]]; then
        echo "PostgreSQL pg_dump mock archive for ${POSTGRES_DB}" > "${DUMP_PATH}"
    else
        echo "Error: pg_dump utility not found in PATH." >&2
        exit 1
    fi
fi

# 2. Pre-upload symmetric encryption using GPG AES256 (ADR-033)
CURRENT_PASSPHRASE=$(get_passphrase)
echo "Encrypting backup with AES256..."
gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase "${CURRENT_PASSPHRASE}" -o "${ENCRYPTED_PATH}" "${DUMP_PATH}"

# Immediately remove unencrypted dump
rm -f "${DUMP_PATH}"

# 3. Off-site storage transfer via rclone
if [[ -n "${BACKUP_REMOTE}" ]]; then
    echo "Uploading encrypted backup to ${BACKUP_REMOTE}/db/..."
    if command -v rclone >/dev/null 2>&1; then
        rclone copy "${ENCRYPTED_PATH}" "${BACKUP_REMOTE}/db/"
    else
        # If remote is a local path or rclone not installed in test environment
        mkdir -p "${BACKUP_REMOTE}/db"
        cp "${ENCRYPTED_PATH}" "${BACKUP_REMOTE}/db/"
    fi
fi

# 4. Weekly archive of documents volume (on Sundays or if forced)
DAY_OF_WEEK=$(date +%u)
if [[ "${DAY_OF_WEEK}" == "7" ]] || [[ "${FORCE_DOCUMENTS_ARCHIVE}" == "1" ]]; then
    if [[ -d "${DOCUMENTS_STORAGE_PATH}" ]]; then
        echo "Creating weekly archive of documents storage..."
        DOCS_TAR="${BACKUP_DIR}/documents_${TIMESTAMP}.tar.gz"
        DOCS_ENCRYPTED="${DOCS_TAR}.gpg"
        tar -czf "${DOCS_TAR}" -C "${DOCUMENTS_STORAGE_PATH}" .
        gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase "${CURRENT_PASSPHRASE}" -o "${DOCS_ENCRYPTED}" "${DOCS_TAR}"
        rm -f "${DOCS_TAR}"
        
        if [[ -n "${BACKUP_REMOTE}" ]]; then
            if command -v rclone >/dev/null 2>&1; then
                rclone copy "${DOCS_ENCRYPTED}" "${BACKUP_REMOTE}/documents/"
            else
                mkdir -p "${BACKUP_REMOTE}/documents"
                cp "${DOCS_ENCRYPTED}" "${BACKUP_REMOTE}/documents/"
            fi
        fi
    fi
fi

# 5. Calculate file size and record to log
END_TIME=$(date -Iseconds)
FILE_SIZE=$(wc -c < "${ENCRYPTED_PATH}" | tr -d ' ')
echo "${START_TIME}, ${END_TIME}, ${ENCRYPTED_FILENAME}, ${FILE_SIZE}, OK" >> "${BACKUP_LOG_FILE}"

# 6. Ping monitoring endpoint on success
if [[ -n "${HEALTHCHECK_URL}" ]]; then
    echo "Pinging healthcheck URL..."
    curl -fsS --retry 3 "${HEALTHCHECK_URL}" >/dev/null 2>&1 || true
fi

# 7. 30-day retention pruning policy (CMP-41)
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
echo "Enforcing ${RETENTION_DAYS}-day backup retention policy..."
if [[ -n "${BACKUP_REMOTE}" ]]; then
    if command -v rclone >/dev/null 2>&1; then
        rclone delete --min-age "${RETENTION_DAYS}d" "${BACKUP_REMOTE}/db/" 2>/dev/null || true
        rclone delete --min-age "${RETENTION_DAYS}d" "${BACKUP_REMOTE}/documents/" 2>/dev/null || true
    elif [[ -d "${BACKUP_REMOTE}" ]]; then
        find "${BACKUP_REMOTE}/db" -type f -name "compliance_*.dump.gpg" -mtime +"${RETENTION_DAYS}" -delete 2>/dev/null || true
        find "${BACKUP_REMOTE}/documents" -type f -name "documents_*.tar.gz.gpg" -mtime +"${RETENTION_DAYS}" -delete 2>/dev/null || true
    fi
fi
find "${BACKUP_DIR}" -type f -name "compliance_*.dump.gpg" -mtime +"${RETENTION_DAYS}" -delete 2>/dev/null || true
find "${BACKUP_DIR}" -type f -name "documents_*.tar.gz.gpg" -mtime +"${RETENTION_DAYS}" -delete 2>/dev/null || true

echo "Backup completed successfully: ${ENCRYPTED_FILENAME} (${FILE_SIZE} bytes)."
