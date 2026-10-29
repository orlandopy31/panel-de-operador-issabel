# Panel de Operador Issabel

**Nombre oficial:** Panel de Operador Issabel. **Repositorio sugerido:** `panel-de-operador-issabel`.

**Compatibilidad:** las rutas internas `panel_lite`, el recurso ACL `panel_lite` y el servicio `issabel-panel-lite.service` conservan sus identificadores originales para permitir actualizaciones sin romper instalaciones existentes.

Panel de monitoreo de extensiones para Issabel 5 con 17 grupos configurables y un grupo **Sin asignar (55)**. Incluye arrastrar y soltar extensiones para administradores y supervisores y redimensionamiento de los grupos guardado por usuario.

> **Estado:** paquete preparado para instalación y actualización; verificar en un entorno de pruebas antes de desplegar en producción. No es un proyecto oficial de Issabel.

## Características

- Estados de extensiones obtenidos por un servicio AMI compartido: disponible, no registrada, sonando, marcando, en llamada.
- Grupos 1–17 y grupo 55 **Sin asignar**. Arrastrar una extensión a 55 elimina su asignación explícita; arrastrarla desde 55 a 1–17 crea su asignación.
- El administrador `admin` y los miembros autorizados de **Supervisores** pueden mover extensiones mediante arrastrar y soltar.
- El administrador `admin` puede modificar nombres/configuración de grupos.
- Tamaños de los grupos independientes para cada usuario autenticado, persistidos en `/var/lib/issabel-panel-lite/layouts/` y con respaldo en el almacenamiento local del navegador.
- Las asignaciones de extensiones son compartidas y se guardan en `/var/lib/issabel-panel-lite/groups.json`.
- No modifica la configuración de extensiones SIP/PJSIP ni las colas de Asterisk cuando se mueve una tarjeta.

## Requisitos previos

- Issabel 5 con acceso `root` por SSH y Asterisk operativo.
- SQLite CLI (`sqlite3`), PHP CLI con SQLite3, Apache y systemd.
- Acceso AMI configurado para el daemon del panel. **Revisar** `panel_lite/configs/default.conf.php` y `service/daemon.php` según la instalación del cliente; no publicar claves ni archivos con secretos.
- Copia de seguridad/snapshot del servidor, especialmente si ya existen modificaciones personalizadas del panel.

## Instalación desde cero

1. Copiar el repositorio al servidor (por Git o con el ZIP del proyecto) y entrar al directorio.

  ```bash
  cd /root
  dnf install -y git
  git clone https://github.com/orlandopy31/panel-de-operador-issabel.git
  php -m | grep -i sqlite
   ```
2. Comprobar el servicio Asterisk, los archivos de ACL y menú de Issabel:

   ```bash
   test -f /var/www/db/acl.db && test -f /var/www/db/menu.db
   systemctl status asterisk --no-pager
   php -m | grep -i sqlite
   cd /root/panel-de-operador-issabel
   ls -lah
   
   ```
3. Revisar **antes de ejecutar** el instalador, especialmente `/var/www/html/modules/panel_lite` si ya contiene archivos. `install.sh` **reemplaza por completo** dicho directorio después de crear respaldo; no lo uses para una actualización habitual.
4. Instalar como administrador del sistema:

   ```bash
   sudo bash install.sh
   ```
5. Verificar:

   ```bash
   systemctl status issabel-panel-lite --no-pager
   journalctl -u issabel-panel-lite -n 100 --no-pager
   ls -lah /var/lib/issabel-panel-lite/state.json
   ```
6. Abrir Issabel > **PBX > Panel de Operador Issabel**, iniciar sesión y actualizar con `Ctrl+F5`.

El instalador guarda una copia del módulo anterior y copias de `menu.db` y `acl.db` bajo `/root/` con marca de tiempo.

## Actualización de una instalación existente (recomendado)

Para conservar el servicio, menú, bases y configuración existente, utilizar **el script de actualización**, no `install.sh`:

```bash
cd /root/panel-liviano-issabel
bash -n actualizar_grupo55_redimension.sh
sudo bash actualizar_grupo55_redimension.sh
```

El script crea un respaldo bajo `/root/panel_lite_pre_resize_YYYYMMDD_HHMMSS` y sustituye `api.php`, `index.php`, `themes/default/js/panel.js` y `themes/default/css/panel.css`. **Advertencia:** si el cliente tiene personalizaciones en esos cuatro archivos, compararlas con `diff -u` antes de actualizar: la actualización las sobrescribe. El instalador no modifica Asterisk ni el servicio durante este proceso.

Después de actualizar, hacer `Ctrl+F5`. Comprobar que se guardan movimientos entre 1–17 y 55 y que dos usuarios pueden guardar tamaños diferentes.

## Cuando NO existe el grupo Supervisores en Issabel

**Importante: el código usa el nombre `Supervisores` (plural), no `Supervisor` (singular).** El instalador solamente concede acceso si ese grupo ya existe. El rol de supervisor requiere pertenecer al grupo `Supervisores` y que ese grupo tenga acceso al recurso ACL `panel_lite`.

### Opción recomendada: crear el grupo desde la interfaz de Issabel

1. Ingresar a Issabel como `admin`.
2. Abrir el apartado **Sistema / Usuarios / Grupos** (los nombres exactos pueden variar según la traducción y personalizaciones de Issabel 5).
3. Crear un grupo **`Supervisores`**, respetando el nombre en plural. No hace falta duplicar el grupo `Administradores` ni dar acceso completo al PBX.
4. Agregar al grupo los usuarios que supervisarán el panel. Si el usuario todavía no existe, crearlo primero.
5. En la administración de permisos de grupos, conceder al grupo **Supervisores** permiso de **acceso** al recurso/módulo **`panel_lite` / Panel de Operador Issabel**. Si el grupo se creó después de instalar el panel, hay que dar ese permiso manualmente; no se asigna retroactivamente.
6. Cerrar sesión e ingresar de nuevo como supervisor. Abrir **PBX > Panel de Operador Issabel**. Probar mover una extensión hacia y desde el grupo 55.

### Diagnóstico por consola (solo lectura)

```bash
sqlite3 /var/www/db/acl.db "SELECT id,name FROM acl_group WHERE lower(name) LIKE '%supervis%';"
sqlite3 /var/www/db/acl.db "SELECT id,name FROM acl_resource WHERE name='panel_lite';"
sqlite3 /var/www/db/acl.db "SELECT u.name AS usuario,g.name AS grupo FROM acl_user u JOIN acl_membership m ON m.id_user=u.id JOIN acl_group g ON g.id=m.id_group WHERE g.name='Supervisores';"
sqlite3 /var/www/db/acl.db "SELECT g.name,r.name,a.name FROM acl_group_permission gp JOIN acl_group g ON g.id=gp.id_group JOIN acl_resource r ON r.id=gp.id_resource JOIN acl_action a ON a.id=gp.id_action WHERE g.name='Supervisores' AND r.name='panel_lite';"
```

Si el primer comando solamente muestra `Supervisor` (singular), el código **no reconocerá** ese nombre para mover extensiones; crear un grupo `Supervisores` y asignarle miembros/permisos, o adaptar de forma consistente el nombre en `api.php`, `index.php` e instalador con una revisión de código y respaldo. No modificar directamente `acl.db` para crear grupos sin conocer el esquema de esa versión de Issabel.

## Rutas importantes

| Ubicación | Uso |
|---|---|
| `/var/www/html/modules/panel_lite/` | Módulo web |
| `/usr/local/libexec/issabel-panel-lite-daemon.php` | Servicio de monitoreo |
| `/etc/systemd/system/issabel-panel-lite.service` | Unidad systemd |
| `/var/lib/issabel-panel-lite/state.json` | Estado monitoreado |
| `/var/lib/issabel-panel-lite/groups.json` | Asignaciones compartidas |
| `/var/lib/issabel-panel-lite/layouts/` | Dimensiones por usuario |
| `/var/www/db/acl.db` | ACL de Issabel |
| `/var/www/db/menu.db` | Menú de Issabel |

## Pruebas de aceptación

1. Administrador: mover 101 desde grupo 1 a grupo 55 y de regreso; recargar y verificar persistencia.
2. Supervisor con permisos: repetir el traslado entre el grupo 55 y otro grupo.
3. Supervisor sin permisos: debe carecer de acceso autorizado; nunca otorgar privilegios por omisión.
4. Dos usuarios: definir dimensiones distintas del mismo grupo, cerrar sesión y comprobar que cada cual conserva su tamaño.
5. Consultar `journalctl -u issabel-panel-lite -n 100` si las extensiones no aparecen.

## Seguridad y solución de problemas

- Restringir acceso administrativo, mantener parches de Issabel, Asterisk y PHP al día, y proteger acceso AMI.
- Para prevenir pérdida de datos, respaldar `/var/lib/issabel-panel-lite` además de las bases `acl.db` y `menu.db`.
- Si los tamaños solo persisten en un navegador, verificar permisos de escritura del usuario PHP sobre `layouts/` y revisar respuestas HTTP de `api.php?action=layout`. No aplicar `chmod 777`.
- Si el panel no aparece para un supervisor, confirmar su pertenencia al grupo **Supervisores**, su permiso `access` sobre `panel_lite` y volver a iniciar sesión.
- Para revertir una actualización, restaurar los cuatro archivos respaldados por el script y recargar la página. Para revertir una instalación inicial, usar los respaldos de `/root/` y el procedimiento apropiado para ese servidor.

## Licencia y publicación

**Este sistema es de código abierto**, en el afán de poder ayudar a la comunidad a utilizar un panel practico y liviano, nos dimos a la tarea de trabajar en este panel. 
