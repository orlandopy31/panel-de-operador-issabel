<?php
/*
 * Panel de Operador Issabel
 * Arquitectura: un daemon AMI compartido + polling HTTP pequeño con ETag.
 */
function _moduleContent(&$smarty, $module_name)
{
    $user = isset($_SESSION['issabel_user']) ? (string)$_SESSION['issabel_user'] : '';
    if (!isset($_SESSION['panel_lite_csrf'])) {
        try {
            $_SESSION['panel_lite_csrf'] = bin2hex(random_bytes(24));
        } catch (Exception $e) {
            $_SESSION['panel_lite_csrf'] = hash('sha256', session_id().microtime(true));
        }
    }

    $isAdmin = ($user === 'admin');
    $canMove = $isAdmin;
    if (!$canMove && $user !== '' && class_exists('SQLite3') && is_readable('/var/www/db/acl.db')) {
        try {
            $db = new SQLite3('/var/www/db/acl.db', SQLITE3_OPEN_READONLY);
            $stmt = $db->prepare("SELECT 1 FROM acl_user u JOIN acl_membership m ON m.id_user=u.id JOIN acl_group g ON g.id=m.id_group JOIN acl_group_permission gp ON gp.id_group=g.id JOIN acl_resource r ON r.id=gp.id_resource JOIN acl_action a ON a.id=gp.id_action WHERE u.name=:u AND g.name='Supervisores' AND r.name='panel_lite' AND a.name='access' LIMIT 1");
            if ($stmt) { $stmt->bindValue(':u',$user,SQLITE3_TEXT); $result=$stmt->execute(); $canMove=(bool)($result && $result->fetchArray(SQLITE3_NUM)); }
            $db->close();
        } catch (Throwable $e) { $canMove=false; }
    }
    $csrf = htmlspecialchars($_SESSION['panel_lite_csrf'], ENT_QUOTES, 'UTF-8');
    $module = htmlspecialchars($module_name, ENT_QUOTES, 'UTF-8');
    $adminFlag = $isAdmin ? '1' : '0';
    $moveFlag = $canMove ? '1' : '0';
    $userKey = htmlspecialchars(hash('sha256', $user), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<link rel="stylesheet" href="modules/{$module}/themes/default/css/panel.css?v=20261026b">
<div id="panel-lite-app" data-module="{$module}" data-admin="{$adminFlag}" data-can-move="{$moveFlag}" data-user-key="{$userKey}" data-csrf="{$csrf}">
    <div class="pl-toolbar">
        <div class="pl-title-wrap">
            <div class="pl-title">Panel de Operador Issabel</div>
            <div class="pl-subtitle">Estado de extensiones en tiempo casi real sin SSE persistente</div>
        </div>
        <div class="pl-health" id="pl-health"><span class="pl-dot"></span><span id="pl-health-text">Conectando…</span></div>
    </div>

    <div class="pl-summary">
        <div class="pl-stat"><strong id="pl-total">0</strong><span>Extensiones</span></div>
        <div class="pl-stat pl-stat-green"><strong id="pl-registered">0</strong><span>Registradas</span></div>
        <div class="pl-stat pl-stat-gray"><strong id="pl-unregistered">0</strong><span>No registradas</span></div>
        <div class="pl-stat pl-stat-orange"><strong id="pl-ringing">0</strong><span>Sonando</span></div>
        <div class="pl-stat pl-stat-red"><strong id="pl-incall">0</strong><span>En llamada</span></div>
        <div class="pl-search"><input id="pl-search" type="search" placeholder="Buscar extensión o nombre…" autocomplete="off"></div>
    </div>

    <div id="pl-warning" class="pl-warning" hidden></div>
    <div id="pl-unassigned-wrap" class="pl-unassigned-wrap"></div>
    <div id="pl-groups" class="pl-groups"></div>

    <div class="pl-footnote">
        Actualización de estado: ~1 s con la página visible; el cronómetro corre localmente en el navegador.
        <span id="pl-last-update"></span>
    </div>
</div>
<script src="modules/{$module}/themes/default/js/panel.js?v=20261026b"></script>
HTML;
}
?>
