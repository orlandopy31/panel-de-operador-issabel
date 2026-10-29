<?php
/** Autorización compartida del Panel de Operador Issabel.
 * El acceso requiere una sesión válida de Issabel y pertenencia al grupo correspondiente.
 * Los permisos del menú se conceden por install.sh / actualizar_panel.sh.
 */
function panelLiteRole($username) {
    if ($username === '') return '';
    if ($username === 'admin') return 'admin'; // cuenta incorporada de Issabel
    if (!class_exists('SQLite3') || !is_readable('/var/www/db/acl.db')) return '';
    try {
        $db = new SQLite3('/var/www/db/acl.db', SQLITE3_OPEN_READONLY);
        $stmt = $db->prepare('SELECT g.name FROM acl_user u JOIN acl_membership m ON m.id_user=u.id JOIN acl_group g ON g.id=m.id_group WHERE u.name=:username');
        if (!$stmt) { $db->close(); return ''; }
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $role = '';
        while ($result && ($row = $result->fetchArray(SQLITE3_ASSOC))) {
            $group = mb_strtolower(trim((string)$row['name']), 'UTF-8');
            // Acepta los nombres singulares y plurales habituales en Issabel.
            if (in_array($group, ['administrador', 'administradores', 'administrator', 'administrators', 'admin'], true)) return 'admin';
            if (in_array($group, ['supervisor', 'supervisores', 'supervisors'], true)) $role = 'supervisor';
        }
        $db->close();
        return $role;
    } catch (Throwable $e) {
        error_log('panel_lite ACL: '.$e->getMessage());
        return '';
    }
}
