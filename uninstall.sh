#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "Ejecute como root"; exit 1; }
systemctl disable --now issabel-panel-lite.service 2>/dev/null || true
rm -f /etc/systemd/system/issabel-panel-lite.service /usr/local/libexec/issabel-panel-lite-daemon.php
systemctl daemon-reload
MENU=/var/www/db/menu.db; ACL=/var/www/db/acl.db
if [[ -f "$ACL" ]]; then RID=$(sqlite3 "$ACL" "SELECT id FROM acl_resource WHERE name='panel_lite' LIMIT 1;"); if [[ -n "$RID" ]]; then sqlite3 "$ACL" "DELETE FROM acl_group_permission WHERE id_resource=$RID; DELETE FROM acl_user_permission WHERE id_resource=$RID; DELETE FROM acl_resource WHERE id=$RID;"; fi; fi
[[ -f "$MENU" ]] && sqlite3 "$MENU" "DELETE FROM menu WHERE id='panel_lite';"
rm -rf /var/www/html/modules/panel_lite
# Se conserva /var/lib/issabel-panel-lite para no perder grupos.
echo "Desinstalado. Se conservó /var/lib/issabel-panel-lite/groups.json."
