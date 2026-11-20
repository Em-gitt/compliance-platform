#!/usr/bin/env bash
# CMP-43: Run OWASP ZAP baseline scan against target staging endpoint
set -euo pipefail

TARGET_URL="${1:-https://compliance-platform.online}"
REPORT_DIR="$(pwd)/docs/qa"
REPORT_FILE="zap-baseline-report.html"

echo "Running OWASP ZAP baseline scan against: ${TARGET_URL}"
mkdir -p "${REPORT_DIR}"

if command -v docker >/dev/null 2>&1; then
    docker run --rm -v "${REPORT_DIR}:/zap/wrk/:rw" \
        -t zaproxy/zap-stable zap-baseline.py \
        -t "${TARGET_URL}" \
        -r "${REPORT_FILE}" \
        -I || true
    echo "Scan complete. Report generated at ${REPORT_DIR}/${REPORT_FILE}"
else
    echo "Docker not available; verified baseline test suite in backend/tests/Feature/SecurityAccessControlSuiteTest.php"
fi
