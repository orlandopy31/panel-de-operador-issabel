CAMBIO GRUPO SIN ASIGNAR (55) - PANEL LITE ISSABEL 5

- Arrastrar extensiones de cualquier grupo 1..17 al grupo Sin asignar (55).
- Arrastrar extensiones desde 55 a cualquiera de los 17 grupos.
- Grupo 55 siempre visible, incluso vacío.
- Acceso a movimientos para admin y miembros del grupo ACL 'Supervisores' con permiso panel_lite/access.
- Solo admin puede renombrar grupos.
- Backend valida CSRF, rol, extensión existente y grupo de destino.
- Grupo 55 representa ausencia de asignación en groups.json; no modifica colas, SIP, Asterisk ni el registro de extensiones.
- Para supervisores de grupo ACL con otro nombre, hay que ajustar identificación de rol.

INSTALAR EN EL CLIENTE:
  unzip panel_liviano_grupo55_dragdrop.zip -d /root/panel_grupo55
  bash /root/panel_grupo55/actualizar_grupo_55.sh
  Ctrl+F5 en el navegador

PARA RESTAURAR:
  Copiar los cuatro archivos del respaldo /root/panel_lite_pre_grupo55_FECHA/ a /var/www/html/modules/panel_lite conservando estructura.

NOTA: Este script solamente actualiza interfaz y API, sin ejecutar install.sh del paquete original.
