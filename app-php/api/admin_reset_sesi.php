<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();
require_admin();

$body = get_json_body();
$nik  = $body['nik'] ?? null;
if (!$nik) json_out(['ok' => false, 'msg' => 'NIK diperlukan'], 400);

$db    = get_db();
$n     = $db->escapeString($nik);
$res   = $db->query("SELECT id FROM sesi_ujian WHERE nik='$n'");
while ($r = $res->fetchArray(SQLITE3_NUM)) {
    $db->exec("DELETE FROM jawaban WHERE sesi_id={$r[0]}");
}
$db->exec("DELETE FROM sesi_ujian WHERE nik='$n'");
json_out(['ok' => true]);
