# Publicar en GitHub

El paquete no incluye credenciales de ningún cliente. Aun así, **revisar todo el contenido antes de subirlo**.

## Opción A — GitHub web + Git en el equipo

1. En GitHub, pulsar **New repository**.
2. Nombre recomendado: `panel-de-operador-issabel`.
3. Seleccionar **Private** primero. Crear repositorio **vacío** (sin README inicial).
4. Descomprimir el ZIP de este proyecto en una computadora que tenga Git; abrir la consola en esa carpeta.
5. Revisar que no haya claves ni datos del cliente y ejecutar:

```bash
git init
git branch -M main
git add .
git status
git commit -m "Panel de Operador Issabel: grupo 55 y layouts por usuario"
git remote add origin https://github.com/TU_USUARIO/panel-de-operador-issabel.git
git push -u origin main
```

GitHub requerirá autenticación; para HTTPS utilizar el gestor de credenciales de Git o un token apropiado, no la contraseña de la cuenta.

## Opción B — GitHub CLI

En el directorio del proyecto y tras autenticarse con `gh auth login`:

```bash
git init && git branch -M main
git add .
git commit -m "Primera versión documentada"
gh repo create panel-de-operador-issabel --private --source=. --remote=origin --push
```

## Clonar en Issabel 5

```bash
cd /root
git clone https://github.com/TU_USUARIO/panel-de-operador-issabel.git
cd panel-de-operador-issabel
# Primera instalación: bash install.sh
# Actualización existente: bash actualizar_grupo55_redimension.sh
```

Consultar `README.md` para requisitos, procedimiento completo y creación del grupo **Supervisores**.
