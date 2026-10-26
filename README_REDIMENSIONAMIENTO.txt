PANEL LITE ISSABEL 5 — GRUPO 55 + DIMENSIONES PERSONALES

INSTALACIÓN SOBRE PANEL EXISTENTE
1. Copiar ZIP a /root y extraerlo, por ejemplo en /root/panel_update.
2. bash /root/panel_update/actualizar_grupo55_redimension.sh
3. Abrir panel y presionar Ctrl+F5.

INCLUYE
- Movimiento Drag & Drop entre grupos 1 a 17 y Sin asignar (55) con permisos por rol.
- Redimensionamiento individual de cajas de grupos mediante esquina inferior derecha.
- Tamaños independientes por usuario autenticado, con almacenamiento servidor
  en /var/lib/issabel-panel-lite/layouts/<sha256_usuario>.json; respaldo local en navegador.
- El refresco de monitoreo no reemplaza las tarjetas mientras se redimensionan.

ATENCIÓN
El paquete original y la revisión 55 no contienen el redimensionamiento personalizado
previamente instalado en el cliente. Esta actualización incorpora una implementación
nueva y no puede recuperar automáticamente preferencias almacenadas con otro esquema.
El script crea respaldo de los cuatro archivos reemplazados. No modifica daemon,
Asterisk ni archivos groups.json. Probar con admin y supervisor antes de desplegar.
Si el usuario PHP no puede escribir en /var/lib/issabel-panel-lite/layouts,
los tamaños quedarán guardados solo en ese navegador; revisar dueño y permisos.
