<?php
/* Endpoint liviano: no carga el framework completo de Issabel. */
session_name('issabelSession');
session_start();
$user = isset($_SESSION['issabel_user']) ? (string)$_SESSION['issabel_user'] : '';
$csrf = isset($_SESSION['panel_lite_csrf']) ? (string)$_SESSION['panel_lite_csrf'] : '';
session_write_close();

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-cache, must-revalidate');

function jsonOut($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__.'/auth.php';

$role = panelLiteRole($user);
if ($role === '') {
    jsonOut(['ok' => false, 'error' => 'No autorizado'], 403);
}

$base = '/var/lib/issabel-panel-lite';
$stateFile = $base.'/state.json';
$configFile = $base.'/groups.json';
$action = isset($_GET['action']) ? $_GET['action'] : 'state';

/* Per-user layout. This does not change the shared groups.json assignments. */
if ($action === 'layout') {
    $layoutDir = $base.'/layouts';
    $layoutFile = $layoutDir.'/'.hash('sha256',$user).'.json';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $saved = is_readable($layoutFile) ? json_decode(file_get_contents($layoutFile), true) : null;
        jsonOut(['ok'=>true,'layout'=>is_array($saved)?$saved:[]]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['ok'=>false,'error'=>'Método inválido'],405);
    $token = (string)($_SERVER['HTTP_X_PANEL_TOKEN'] ?? '');
    if ($csrf === '' || !hash_equals($csrf,$token)) jsonOut(['ok'=>false,'error'=>'Token CSRF inválido'],403);
    $raw = file_get_contents('php://input');
    if (strlen($raw)>8192) jsonOut(['ok'=>false,'error'=>'Diseño demasiado grande'],413);
    $input = json_decode($raw,true);
    if (!is_array($input) || !isset($input['layout']) || !is_array($input['layout'])) jsonOut(['ok'=>false,'error'=>'Diseño inválido'],400);
    $clean=[];
    foreach ($input['layout'] as $gid=>$dimensions) {
        $key=(string)$gid;
        if (!ctype_digit($key) || !((int)$gid===55 || ((int)$gid>=1 && (int)$gid<=17)) || !is_array($dimensions)) continue;
        $w=$dimensions['width']??null; $h=$dimensions['height']??null;
        if (!is_numeric($w)||!is_numeric($h)) continue;
        $w=(int)round((float)$w);$h=(int)round((float)$h);
        if ($w>=260 && $w<=4000 && $h>=90 && $h<=4000) $clean[$key]=['width'=>$w,'height'=>$h];
    }
    if (!is_dir($layoutDir) && !@mkdir($layoutDir,0750,true) && !is_dir($layoutDir)) jsonOut(['ok'=>false,'error'=>'No se pudo crear carpeta de diseños'],500);
    $temp=@tempnam($layoutDir,'.layout-');
    if ($temp===false) jsonOut(['ok'=>false,'error'=>'No se puede escribir el diseño'],500);
    $body=json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (file_put_contents($temp,$body,LOCK_EX)===false || !@chmod($temp,0640) || !@rename($temp,$layoutFile)) {
        @unlink($temp);jsonOut(['ok'=>false,'error'=>'No se pudo guardar diseño individual'],500);
    }
    jsonOut(['ok'=>true]);
}

if ($action === 'state') {
    if (!is_readable($stateFile)) {
        jsonOut(['ok' => false, 'error' => 'Servicio de monitoreo aún no generó estado'], 503);
    }
    $st = stat($stateFile);
    $etag = '"'.dechex($st['mtime']).'-'.dechex($st['size']).'"';
    header('ETag: '.$etag);
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    readfile($stateFile);
    exit;
}

if ($action === 'config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!is_readable($configFile)) {
        jsonOut(['ok' => true, 'groups' => [], 'assignments' => []]);
    }
    header('Content-Type: application/json; charset=utf-8');
    readfile($configFile);
    exit;
}

if ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'admin' && $role !== 'supervisor') jsonOut(['ok'=>false,'error'=>'Sin permiso para mover'],403);
    $token = isset($_SERVER['HTTP_X_PANEL_TOKEN']) ? (string)$_SERVER['HTTP_X_PANEL_TOKEN'] : '';
    if ($csrf === '' || !hash_equals($csrf, $token)) jsonOut(['ok'=>false,'error'=>'Token CSRF inválido'],403);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) jsonOut(['ok'=>false,'error'=>'JSON inválido'],400);
    $ext = trim((string)($input['extension'] ?? ''));
    $gidRaw = $input['group'] ?? null;
    if (!preg_match('/^[A-Za-z0-9_.#*+-]{1,32}$/', $ext) || !in_array($gidRaw, [55,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17], true)) {
        jsonOut(['ok'=>false,'error'=>'Extensión o grupo inválido'],400);
    }
    if (!is_readable($stateFile)) jsonOut(['ok'=>false,'error'=>'Estado de extensiones no disponible'],503);
    $state = json_decode(file_get_contents($stateFile), true);
    $found = false;
    foreach (($state['extensions'] ?? []) as $extension) {
        if ((string)($extension['extension'] ?? '') === $ext) { $found=true; break; }
    }
    if (!$found) jsonOut(['ok'=>false,'error'=>'Extensión no encontrada en el panel'],404);
    if (!is_dir($base) || !is_writable($base)) jsonOut(['ok'=>false,'error'=>'Directorio de configuración sin escritura'],500);
    $lock = @fopen($base.'/groups.lock', 'c');
    if (!$lock || !flock($lock,LOCK_EX)) jsonOut(['ok'=>false,'error'=>'No se pudo bloquear la configuración'],500);
    $data = is_readable($configFile) ? json_decode(file_get_contents($configFile),true) : null;
    if (!is_array($data)) $data = ['ok'=>true,'version'=>1,'groups'=>[], 'assignments'=>[]];
    if (!isset($data['assignments']) || !is_array($data['assignments'])) $data['assignments']=[];
    if ((int)$gidRaw === 55) unset($data['assignments'][$ext]);
    else $data['assignments'][$ext]=(int)$gidRaw;
    $data['updated_at']=time(); $data['ok']=true;
    $tmp=$configFile.'.tmp.'.getmypid();
    $written = file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
    if ($written===false || !@chmod($tmp,0640) || !@rename($tmp,$configFile)) {
        @unlink($tmp); flock($lock,LOCK_UN); fclose($lock);
        jsonOut(['ok'=>false,'error'=>'No se pudo guardar el movimiento'],500);
    }
    flock($lock,LOCK_UN); fclose($lock);
    jsonOut(['ok'=>true,'extension'=>$ext,'group'=>(int)$gidRaw]);
}

if ($action === 'config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($role !== 'admin') jsonOut(['ok' => false, 'error' => 'Solo admin puede editar grupos'], 403);
    $token = isset($_SERVER['HTTP_X_PANEL_TOKEN']) ? (string)$_SERVER['HTTP_X_PANEL_TOKEN'] : '';
    if ($csrf === '' || !hash_equals($csrf, $token)) jsonOut(['ok' => false, 'error' => 'Token CSRF inválido'], 403);

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) jsonOut(['ok' => false, 'error' => 'JSON inválido'], 400);

    $groups = isset($input['groups']) && is_array($input['groups']) ? $input['groups'] : [];
    $assignments = isset($input['assignments']) && is_array($input['assignments']) ? $input['assignments'] : [];
    $cleanGroups = [];
    for ($i = 1; $i <= 17; $i++) {
        $name = 'Grupo '.$i;
        foreach ($groups as $g) {
            if (isset($g['id']) && (int)$g['id'] === $i) {
                $name = trim((string)($g['name'] ?? $name));
                if ($name === '') $name = 'Grupo '.$i;
                $name = mb_substr($name, 0, 80, 'UTF-8');
                break;
            }
        }
        $cleanGroups[] = ['id' => $i, 'name' => $name];
    }

    $cleanAssignments = [];
    foreach ($assignments as $ext => $groupId) {
        $ext = trim((string)$ext);
        $gid = (int)$groupId;
        if ($ext !== '' && preg_match('/^[A-Za-z0-9_.#*+-]{1,32}$/', $ext) && $gid >= 1 && $gid <= 17) {
            $cleanAssignments[$ext] = $gid;
        }
    }

    $payload = [
        'ok' => true,
        'version' => 1,
        'updated_at' => time(),
        'groups' => $cleanGroups,
        'assignments' => $cleanAssignments,
    ];
    if (!is_dir($base)) @mkdir($base, 0750, true);
    $tmp = $configFile.'.tmp.'.getmypid();
    if (file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        jsonOut(['ok' => false, 'error' => 'No se pudo guardar configuración'], 500);
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $configFile)) {
        @unlink($tmp);
        jsonOut(['ok' => false, 'error' => 'No se pudo activar configuración'], 500);
    }
    jsonOut($payload);
}

jsonOut(['ok' => false, 'error' => 'Acción desconocida'], 404);
?>
