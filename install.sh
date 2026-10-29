#!/usr/bin/env bash
set -euo pipefail
if [[ $EUID -ne 0 ]]; then echo "Ejecute como root"; exit 1; fi
SRC="$(cd "$(dirname "$0")" && pwd)"
MOD=/var/www/html/modules/panel_lite
DATA=/var/lib/issabel-panel-lite
MENU=/var/www/db/menu.db
ACL=/var/www/db/acl.db
TS=$(date +%Y%m%d_%H%M%S)

echo "[1/7] Backup de bases Issabel"
cp -a "$MENU" "/root/menu.db.panel_lite_$TS"
cp -a "$ACL" "/root/acl.db.panel_lite_$TS"
[[ -d "$MOD" ]] && cp -a "$MOD" "/root/panel_lite_backup_$TS"

echo "[2/7] Instalando módulo"
rm -rf "$MOD"
cp -a "$SRC/panel_lite" "$MOD"
chown -R root:asterisk "$MOD"
find "$MOD" -type d -exec chmod 0755 {} +
find "$MOD" -type f -exec chmod 0644 {} +

echo "[3/7] Estado y configuración"
install -d -o asterisk -g asterisk -m 0750 "$DATA"
install -o asterisk -g asterisk -m 0755 "$SRC/service/daemon.php" /usr/local/libexec/issabel-panel-lite-daemon.php

echo "[4/7] Registrando menú sin duplicados"
COUNT=$(sqlite3 "$MENU" "SELECT COUNT(*) FROM menu WHERE id='panel_lite';")
if [[ "$COUNT" == "0" ]]; then
  sqlite3 "$MENU" "INSERT INTO menu (id,IdParent,Link,Name,Type,order_no) VALUES ('panel_lite','pbxconfig','','Panel de Operador Issabel','module',8);"
else
  sqlite3 "$MENU" "UPDATE menu SET Name='Panel de Operador Issabel',IdParent='pbxconfig',Type='module' WHERE id='panel_lite';"
fi

echo "[5/7] Registrando ACL sin duplicados"
RID=$(sqlite3 "$ACL" "SELECT id FROM acl_resource WHERE name='panel_lite' ORDER BY id LIMIT 1;")
if [[ -z "$RID" ]]; then RID=$(sqlite3 "$ACL" "SELECT COALESCE(MAX(id),0)+1 FROM acl_resource;"); sqlite3 "$ACL" "INSERT INTO acl_resource(id,name,description) VALUES ($RID,'panel_lite','Panel de Operador Issabel');"; fi
AID=$(sqlite3 "$ACL" "SELECT id FROM acl_action WHERE name='access' ORDER BY id LIMIT 1;")
[[ -z "$AID" ]] && AID=1
add_perm(){ local gid="$1"; [[ -z "$gid" ]] && return 0; local c; c=$(sqlite3 "$ACL" "SELECT COUNT(*) FROM acl_group_permission WHERE id_action=$AID AND id_group=$gid AND id_resource=$RID;"); if [[ "$c" == "0" ]]; then local id; id=$(sqlite3 "$ACL" "SELECT COALESCE(MAX(id),0)+1 FROM acl_group_permission;"); sqlite3 "$ACL" "INSERT INTO acl_group_permission(id,id_action,id_group,id_resource) VALUES($id,$AID,$gid,$RID);"; fi; }
# Registrar acceso para todos los grupos administrativos y de supervisión existentes.
while IFS= read -r gid; do
  [[ -n "$gid" ]] && add_perm "$gid"
done < <(sqlite3 "$ACL" "SELECT id FROM acl_group WHERE lower(trim(name)) IN ('administrador','administradores','administrator','administrators','admin','supervisor','supervisores','supervisors');")

echo "[6/7] Instalando servicio"
install -o root -g root -m 0644 "$SRC/service/issabel-panel-lite.service" /etc/systemd/system/issabel-panel-lite.service
systemctl daemon-reload
systemctl enable --now issabel-panel-lite.service
sleep 2

echo "[7/7] Verificación"
systemctl --no-pager --full status issabel-panel-lite.service || true
[[ -s "$DATA/state.json" ]] && echo "Estado generado: OK" || echo "AVISO: state.json aún no fue generado; revise journalctl -u issabel-panel-lite"
echo
echo "Instalado. Abra Issabel > PBX > Panel de Operador Issabel. Haga Ctrl+F5."
echo "Backups: /root/menu.db.panel_lite_$TS y /root/acl.db.panel_lite_$TS"
