<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();

$body    = get_json_body();
$sesi_id = (int)($body['sesi_id'] ?? 0);
$nik     = trim($body['nik'] ?? '');
if (!$sesi_id || !$nik) json_out(['ok' => false, 'msg' => 'Parameter tidak lengkap'], 400);

$db      = get_db();
$nik_esc = $db->escapeString($nik);
$res     = $db->query("SELECT * FROM sesi_ujian WHERE id=$sesi_id AND nik='$nik_esc'");
$sesi    = $res->fetchArray(SQLITE3_ASSOC);
if (!$sesi) json_out(['ok' => false, 'msg' => 'Sesi tidak ditemukan'], 404);

$now = now_str();
if ($sesi['status'] === 'mulai') {
    $now_esc = SQLite3::escapeString($now);
    $db->exec("UPDATE sesi_ujian SET status='berlangsung', mulai_at='$now_esc' WHERE id=$sesi_id");
    $sisa_detik = DURASI_DETIK;
} else {
    $sisa_detik = sisa_detik_sesi($sesi);
}

json_out(['ok' => true, 'sisa_detik' => $sisa_detik]);
