#!/usr/bin/env bash
#
# Side-effect-free post-deploy HTTP connectivity check.
#
# Sends an Authorization-header-less POST to the two Bearer-token-protected
# APIs and verifies each responds HTTP 401. This only proves routing reaches
# each endpoint and the Bearer-token-protected endpoint rejects an
# unauthenticated request with 401; it never sends a real Bearer Token and
# never triggers Slack posts, IMAP access, or database writes.
#
# Usage:
#   bin/check-deploy-connectivity.sh <base-url>
#   DEPLOY_BASE_URL=<base-url> bin/check-deploy-connectivity.sh
#
# Reusable from GitHub Actions, a local shell, or an SSH session on the
# production host — it has no dependency beyond curl.

set -euo pipefail

BASE_URL="${1:-${DEPLOY_BASE_URL:-}}"

if [ -z "${BASE_URL}" ]; then
    echo "Usage: $0 <base-url> (or set DEPLOY_BASE_URL)" >&2
    exit 1
fi

ENDPOINTS=(
    "/api/mail/process"
    "/api/dating/notify"
)

FAILED=0

for endpoint in "${ENDPOINTS[@]}"; do
    url="${BASE_URL%/}${endpoint}"
    status="$(curl -sS -o /dev/null -w '%{http_code}' -X POST "${url}")"

    if [ "${status}" = "401" ]; then
        echo "OK  401 ${endpoint}"
    else
        echo "NG  ${status} ${endpoint}" >&2
        FAILED=1
    fi
done

if [ "${FAILED}" -ne 0 ]; then
    echo "Unauthenticated connectivity check failed." >&2
    exit 1
fi

echo "Unauthenticated connectivity check succeeded (2/2 returned 401)."
