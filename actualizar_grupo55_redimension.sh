#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'ERROR: ejecutar como root'; exit 1; }
SRC="$(cd "$(dirname "$0")" && pwd)/panel_lite"
DST='/var/www/html/modules/panel_lite'
[[ -f "$DST/api.php" && -f "$DST/index.php" && -f "$DST/themes/default/js/panel.js" ]] || { echo "ERROR: panel_lite no instalado en $DST"; exit 1; }
php -l "$SRC/api.php" && php -l "$SRC/index.php"
command -v node >/dev/null && node --check "$SRC/themes/default/js/panel.js" || true
BACKUP="/root/panel_lite_pre_resize_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP/themes/default/js" "$BACKUP/themes/default/css"
for f in api.php index.php themes/default/js/panel.js themes/default/css/panel.css; do
  if [[ -f "$DST/$f" ]]; then cp -a "$DST/$f" "$BACKUP/$f"; fi
done
for f in api.php index.php themes/default/js/panel.js themes/default/css/panel.css; do
  if [[ -f "$DST/$f" ]]; then
    cp "$SRC/$f" "$DST/$f"
  else
    install -m 0644 "$SRC/$f" "$DST/$f"
  fi
done
# Layouts are saved separately for each authenticated user. Only grant the web process access to its layout store.
if [[ -d /var/lib/issabel-panel-lite ]]; then
  owner=$(stat -c '%U' /var/lib/issabel-panel-lite)
  group=$(stat -c '%G' /var/lib/issabel-panel-lite)
  if [[ "$owner" != root || "$group" != root ]]; then
    install -d -m 0750 -o "$owner" -g "$group" /var/lib/issabel-panel-lite/layouts
  else
    echo 'AVISO: /var/lib/issabel-panel-lite es root:root; verificar qué usuario PHP ejecuta la API y conceder acceso a layouts.'
  fi
fi
echo "Actualización completada. Respaldo: $BACKUP"
echo 'Recargar navegador con Ctrl+F5. No se reinicia Asterisk ni se cambia la base de datos.'
