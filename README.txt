PANEL DE OPERADOR ISSABEL - 2026-09-29
========================================

OBJETIVO
- 17 grupos configurables (migra nombres/asignaciones de Area1..Area17 si existen).
- Mostrar TODAS las extensiones de la tabla devices, estén registradas o no.
- Estado: Disponible, No registrada, Sonando, Marcando, En llamada.
- Número remoto y cronómetro MM:SS / HH:MM:SS.
- Respuesta aproximada de 1 segundo sin SSE.
- Un solo cliente AMI para todos los usuarios web.

ARQUITECTURA
Asterisk AMI --> daemon único (1 consulta CoreShowChannels/segundo)
            --> /var/lib/issabel-panel-lite/state.json
Navegadores --> api.php (ETag/304) --> state.json

El cronómetro NO consulta Asterisk cada segundo: corre localmente en JavaScript.
Con 8 navegadores, si el estado no cambia la mayoría de respuestas son HTTP 304.

INSTALACIÓN
1) Copiar/descomprimir el paquete al servidor.
2) cd al directorio del paquete.
3) bash install.sh
4) Abrir Issabel > PBX > Panel de Operador Issabel.
5) Ctrl+F5.

AGRUPACIÓN
- Admin y supervisores autorizados pueden mover extensiones entre los grupos 1–17 y el grupo Sin asignar (55).
- Admin puede renombrar grupos con el ícono de lápiz.
- Supervisores autorizados también pueden mover extensiones; ver README.md.
- Las asignaciones se guardan en /var/lib/issabel-panel-lite/groups.json.

PERMISOS
El instalador crea un recurso ACL independiente llamado panel_lite y da acceso a:
- grupo 1 (Administradores)
- grupo Supervisores, si existe
Luego puede manejarse desde Permisos de Grupos sin tocar control_panel.

DIAGNÓSTICO
systemctl status issabel-panel-lite --no-pager
journalctl -u issabel-panel-lite -n 100 --no-pager
cat /var/lib/issabel-panel-lite/state.json | head

CONSUMO ESPERADO
- 1 conexión AMI total, independientemente de 1 u 8 usuarios web.
- Sin conexiones SSE persistentes.
- Poll HTTP: 1 s visible / 5 s pestaña oculta.
- Estado sin cambios: ETag + 304; transferencia mínima.

NOTA
La detección de registro usa PJSIPShowEndpoints y SIPpeers. La llamada activa usa
CoreShowChannels. Se conserva el mismo estado entre sonando/atendida mientras el
Uniqueid del canal sea el mismo, evitando que el cronómetro se resetee en cada poll.
