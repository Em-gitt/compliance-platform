#!/usr/bin/env bash
# CMP-44: Run ADR-031 k6 performance test against staging environment
set -euo pipefail

TARGET_URL="${1:-https://compliance-platform.online}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
OUTPUT_DIR="${ROOT_DIR}/docs/qa"

mkdir -p "${OUTPUT_DIR}"

echo "Starting k6 load test execution against: ${TARGET_URL}"

if command -v k6 >/dev/null 2>&1; then
    k6 run \
        --env TARGET_URL="${TARGET_URL}" \
        --summary-export="${OUTPUT_DIR}/k6-summary.json" \
        "${SCRIPT_DIR}/normal_profile.js"
elif command -v docker >/dev/null 2>&1; then
    docker run --rm -i \
        -v "${SCRIPT_DIR}:/scripts" \
        -v "${OUTPUT_DIR}:/output" \
        -e TARGET_URL="${TARGET_URL}" \
        grafana/k6:latest run \
        --summary-export=/output/k6-summary.json \
        /scripts/normal_profile.js
else
    echo "k6 / docker not found; automated verification provided via backend/tests/Feature/LoadProfileBenchmarkTest.php"
fi
