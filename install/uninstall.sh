#!/usr/bin/env bash
set -euo pipefail

DEST="/var/www/html/modules/control_panel"
STAMP="$(date +%F_%H-%M-%S)"
BACKUP_DIR="/root/control_panel_removed_${STAMP}"

if [ "$(id -u)" != "0" ]; then
  echo "Execute como root."
  exit 1
fi

if [ ! -d "${DEST}" ]; then
  echo "Módulo não encontrado em ${DEST}."
  exit 0
fi

mkdir -p "${BACKUP_DIR}"
cp -a "${DEST}" "${BACKUP_DIR}/control_panel"
rm -rf "${DEST}"

echo "Módulo removido. Backup salvo em: ${BACKUP_DIR}/control_panel"
