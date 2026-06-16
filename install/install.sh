#!/usr/bin/env bash
set -euo pipefail

SRC_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="/var/www/html/modules/control_panel"
STAMP="$(date +%F_%H-%M-%S)"
BACKUP_DIR="/root/control_panel_backup_${STAMP}"

if [ "$(id -u)" != "0" ]; then
  echo "Execute como root."
  exit 1
fi

echo "Projeto: ${SRC_ROOT}"
echo "Destino: ${DEST}"

if [ -d "${DEST}" ]; then
  mkdir -p "${BACKUP_DIR}"
  cp -a "${DEST}" "${BACKUP_DIR}/control_panel"
  echo "Backup criado em: ${BACKUP_DIR}/control_panel"
fi

mkdir -p "${DEST}" "${DEST}/api" "${DEST}/assets" "${DEST}/lib"
cp -f "${SRC_ROOT}/frontend/index.php" "${DEST}/index.php"
cp -a "${SRC_ROOT}/frontend/assets/." "${DEST}/assets/"
cp -a "${SRC_ROOT}/backend/api/." "${DEST}/api/"
cp -a "${SRC_ROOT}/backend/lib/." "${DEST}/lib/"

chown -R asterisk:asterisk "${DEST}" 2>/dev/null || chown -R apache:apache "${DEST}" 2>/dev/null || true

php -l "${DEST}/api/status.php"
php -l "${DEST}/index.php"
find "${DEST}/lib" -type f -name '*.php' -print0 | xargs -0 -n1 php -l

echo "Instalação concluída."
echo "Acesse: /index.php?menu=control_panel"
