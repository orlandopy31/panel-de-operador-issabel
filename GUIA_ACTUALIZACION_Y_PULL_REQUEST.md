# Panel de Operador Issabel — actualización de roles y pull request

## Cambios en esta versión

- Reconoce la cuenta `admin` y los **usuarios miembros de los grupos** `Administrador`, `Administradores`, `Administrator`, `Administrators`, `Admin`, `Supervisor`, `Supervisores` o `Supervisors` (sin importar mayúsculas/minúsculas).
- Tanto administradores como supervisores pueden acceder a la API y mover extensiones entre los grupos 1–17 y **Sin asignar (55)**. El movimiento a 55 elimina la asignación de `groups.json`; no borra la extensión SIP.
- La autorización se verifica en el servidor en cada petición, no mediante un indicador de JavaScript solamente. Las personas fuera de esos grupos siguen sin acceso.
- Los tamaños de bloques son independientes por nombre de usuario. Se guardan en `/var/lib/issabel-panel-lite/layouts/<hash>.json`, con copia local por usuario en el navegador. El tamaño de un usuario no modifica el de otro.
- Se preservan las asignaciones de `groups.json` y el daemon existente al actualizar.

## Antes de aplicar en producción

1. Realice snapshot o copia de seguridad de Issabel. Pruebe en un entorno de ensayo si es posible.
2. Verifique grupos y membresías existentes (consultas de solo lectura):

```bash
sqlite3 /var/www/db/acl.db "SELECT id,name FROM acl_group ORDER BY id;"
sqlite3 /var/www/db/acl.db "SELECT u.name,g.name FROM acl_user u JOIN acl_membership m ON m.id_user=u.id JOIN acl_group g ON g.id=m.id_group ORDER BY u.name;"
```

3. Revise que el usuario realmente pertenezca al grupo correspondiente. Una etiqueta visible en otra pantalla no equivale a una membresía en `acl_membership`.

## Actualizar el panel existente, sin reinstalar

Suba el archivo ZIP al servidor en `/root` y ejecute:

```bash
cd /root
unzip -o panel-de-operador-issabel-roles-20261029.zip
cd panel-de-operador-issabel-main
bash -n actualizar_panel.sh
bash actualizar_panel.sh
```

El script crea respaldo del módulo y de `acl.db`, reemplaza los archivos web y concede `access` al recurso `panel_lite` para los grupos administrativos y supervisores presentes. No reinicia Asterisk ni reemplaza `groups.json` ni los diseños por usuario. **Si tiene personalizaciones locales, compárelas con el respaldo antes de actualizar.**

Reinicie la sesión en Issabel para renovar la autorización del menú y haga `Ctrl+F5`.

## Instalación desde cero

```bash
cd /root/panel-de-operador-issabel-main
bash install.sh
```

`install.sh` sustituye todo el módulo web instalado: use `actualizar_panel.sh` para equipos que ya tienen funcionando el panel.

## Pruebas de aceptación

1. Inicie sesión con la cuenta `admin`: compruebe lectura de estados y arrastrar extensiones del grupo 1 al grupo 55 y de regreso.
2. Entre con un usuario **distinto de admin** que pertenezca a Administradores: debe ver el panel sin HTTP 403 y poder mover extensiones hacia y desde 55.
3. Entre con un usuario de Supervisores: compruebe el mismo comportamiento.
4. Con cada usuario, cambie el ancho y alto de un bloque y recargue. Compruebe que persista y que el mismo grupo tenga otro tamaño en la sesión de otro usuario.
5. Con un usuario ajeno a los grupos autorizados, la API debe responder 403.
6. Compruebe que el servicio `issabel-panel-lite` sigue activo y que el estado se actualiza.

Para diagnosticar HTTP 403, revise la sesión activa de Issabel y las membresías reales (comandos anteriores). Si la API sigue devolviendo 403, revise además los registros PHP/Apache y la ruta `/var/www/db/acl.db` accesible para el proceso PHP.

Para diagnosticar guardado de tamaños, revise la propiedad/permisos de `/var/lib/issabel-panel-lite/layouts` y la respuesta de `api.php?action=layout` en las herramientas de desarrollador del navegador. El proceso web debe tener lectura y escritura en el directorio de layouts. No otorgue `chmod 777`.

## Crear un pull request (PR) en GitHub

**Opción recomendada: desde un clon Git del repositorio**, en su computadora o en un entorno de desarrollo con Git instalado:

```bash
git clone https://github.com/orlandopy31/panel-de-operador-issabel.git
cd panel-de-operador-issabel
git switch -c fix/permisos-supervisores-administradores
```

Copie dentro del repositorio clonado el contenido del ZIP de esta versión, **sin copiar la carpeta contenedora** y conservando `.git`. Después:

```bash
git status
git add panel_lite/ install.sh actualizar_panel.sh GUIA_ACTUALIZACION_Y_PULL_REQUEST.md README.md
git commit -m "Fix: acceso ACL y movimientos del panel para administradores y supervisores"
git push -u origin fix/permisos-supervisores-administradores
```

Visite `https://github.com/orlandopy31/panel-de-operador-issabel/pulls` y pulse **New pull request**. Seleccione la rama base `main` y compare con `fix/permisos-supervisores-administradores`. Revise cambios, cree el PR, pruebe y fusione cuando corresponda. Es necesario tener permisos de escritura en el repositorio; en caso contrario, cree un fork y proponga el PR desde él. **Este ZIP no crea ni sube el PR automáticamente.**

Tras fusionar, podrá actualizar en el servidor con `git pull` en un clon limpio y ejecutar `bash actualizar_panel.sh`, realizando respaldo antes.
