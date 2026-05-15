<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();
require_admin();

$db  = get_db();
$res = $db->query("
    SELECT l.*, p.nama_lengkap FROM log_perubahan l
    LEFT JOIN peserta p ON l.nik=p.nik
    ORDER BY l.diubah_at DESC LIMIT 200
");
$rows = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
json_out($rows);
