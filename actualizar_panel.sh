#!/usr/bin/env bash
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo 'Ejecutar como root'; exit 1; }
SRC="$(cd "$(dirname "$0")" && pwd)"
DST=/var/www/html/modules/panel_lite
ACL=/var/www/db/acl.db
[[ -f "$DST/api.php" && -f "$ACL" ]] || { echo 'No se detectó el módulo instalado o acl.db'; exit 1; }
for file in auth.php api.php index.php; do php -l "$SRC/panel_lite/$file"; done
if command -v node >/dev/null; then node --check "$SRC/panel_lite/themes/default/js/panel.js"; fi
STAMP=$(date +%Y%m%d_%H%M%S)
BACKUP="/root/panel_lite_backup_acl_$STAMP"
cp -a "$DST" "$BACKUP"
cp -a "$ACL" "/root/acl.db.panel_lite_$STAMP"
echo "Respaldo del módulo: $BACKUP"
echo 'Actualizando archivos del módulo sin modificar asignaciones ni preferencias...'
cp -a "$SRC/panel_lite/." "$DST/"
chown -R root:asterisk "$DST"
find "$DST" -type d -exec chmod 0755 {} +
find "$DST" -type f -exec chmod 0644 {} +
RID=$(sqlite3 "$ACL" "SELECT id FROM acl_resource WHERE name='panel_lite' LIMIT 1;")
AID=$(sqlite3 "$ACL" "SELECT id FROM acl_action WHERE name='access' LIMIT 1;")
[[ -n "$RID" && -n "$AID" ]] || { echo 'ACL panel_lite/access no encontrada, ejecutar instalación completa o revisar Issabel.'; exit 1; }
while IFS='|' read -r gid name; do
  [[ -n "$gid" ]] || continue
  count=$(sqlite3 "$ACL" "SELECT COUNT(*) FROM acl_group_permission WHERE id_action=$AID AND id_group=$gid AND id_resource=$RID;")
  if [[ "$count" == 0 ]]; then
    next=$(sqlite3 "$ACL" "SELECT COALESCE(MAX(id),0)+1 FROM acl_group_permission;")
    sqlite3 "$ACL" "INSERT INTO acl_group_permission(id,id_action,id_group,id_resource) VALUES($next,$AID,$gid,$RID);"
  fi
  echo "Permiso de acceso confirmado: $name (grupo ID $gid)"
done < <(sqlite3 -separator '|' "$ACL" "SELECT id,name FROM acl_group WHERE lower(trim(name)) IN ('administrador','administradores','administrator','administrators','admin','supervisor','supervisores','supervisors');")
# Preservar permisos y propiedad actuales de almacenamiento; crear layouts si falta.
DATA=/var/lib/issabel-panel-lite
if [[ -d "$DATA" ]]; then
  owner=$(stat -c %U "$DATA"); group=$(stat -c %G "$DATA")
  install -d -m 0750 -o "$owner" -g "$group" "$DATA/layouts"
fi
echo 'Actualización terminada. Cerrar sesión, ingresar nuevamente y pulsar Ctrl+F5.'
echo 'NO se modificaron groups.json, state.json ni los layouts existentes.'
