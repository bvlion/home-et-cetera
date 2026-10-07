#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVICE_NAME="pi-steward.service"
SERVICE_SRC="${SCRIPT_DIR}/${SERVICE_NAME}"
SERVICE_DST="/etc/systemd/system/${SERVICE_NAME}"
CRON_LINE="10 23 * * * /usr/local/bin/node ${SCRIPT_DIR}/holiday_notification.js"

if [[ ${EUID} -ne 0 ]]; then
  echo "root権限で実行してください: sudo ./setup.sh" >&2
  exit 1
fi

if [[ ! -f "${SCRIPT_DIR}/.env" || ! -f "${SCRIPT_DIR}/firebase-adminsdk.json" ]]; then
  echo "pi-stewardディレクトリに.envとfirebase-adminsdk.jsonを配置してください。" >&2
  exit 1
fi

sed "s|@REPOSITORY_DIRECTORY@|${SCRIPT_DIR}|g" "${SERVICE_SRC}" > "${SERVICE_DST}"

systemctl daemon-reload
systemctl enable "${SERVICE_NAME}"

CURRENT_CRON="$(crontab -l 2>/dev/null || true)"
if ! grep -Fxq "${CRON_LINE}" <<< "${CURRENT_CRON}"; then
  if [[ -z "${CURRENT_CRON}" ]]; then
    NEW_CRON="${CRON_LINE}"
  else
    NEW_CRON="${CURRENT_CRON}"$'\n'"${CRON_LINE}"
  fi
  printf '%s\n' "${NEW_CRON}" | crontab -
fi

echo "setup.sh completed."
echo "続けて以下を実行してください: sudo systemctl start ${SERVICE_NAME}"
