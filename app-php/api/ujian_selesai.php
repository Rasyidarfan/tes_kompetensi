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
$res     = $db->query("SELECT * FROM sesi_ujian WHERE id=$sesi_id AND nik='$nik_esc' AND status='berlangsung'");
$sesi    = $res->fetchArray(SQLITE3_ASSOC);
if (!$sesi) json_out(['ok' => false, 'msg' => 'Sesi tidak ditemukan atau sudah selesai'], 400);

$skor    = hitung_skor($sesi_id);
$now_esc = SQLite3::escapeString(now_str());
$db->exec("UPDATE sesi_ujian SET status='selesai', selesai_at='$now_esc', skor=$skor WHERE id=$sesi_id");

$skor_tertinggi = $db->querySingle("SELECT MAX(skor) FROM sesi_ujian WHERE nik='$nik_esc' AND status='selesai'");
$jml_sesi       = (int)$db->querySingle("SELECT COUNT(*) FROM sesi_ujian WHERE nik='$nik_esc'");

json_out([
    'ok'             => true,
    'skor'           => $skor,
    'skor_tertinggi' => $skor_tertinggi !== null ? (float)$skor_tertinggi : null,
    'jml_sesi'       => $jml_sesi,
    'max_kesempatan' => MAX_KESEMPATAN,
]);
