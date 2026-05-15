<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();

$body = get_json_body();
$nik  = trim($body['nik'] ?? '');
if (!$nik) json_out(['ok' => false, 'msg' => 'NIK tidak boleh kosong'], 400);

$db  = get_db();
$n   = $db->escapeString($nik);
$row = $db->querySingle("SELECT * FROM peserta WHERE nik='$n'", true);
if (!$row) json_out(['ok' => false, 'msg' => 'NIK tidak terdaftar sebagai peserta'], 404);

$sesi_aktif    = get_sesi_aktif($nik);
$jml_kesempatan = get_jumlah_kesempatan($nik);
$skor_tertinggi = get_skor_tertinggi($nik);

json_out([
    'ok'             => true,
    'peserta'        => $row,
    'sesi_aktif'     => $sesi_aktif !== null,
    'jml_kesempatan' => $jml_kesempatan,
    'skor_tertinggi' => $skor_tertinggi,
    'max_kesempatan' => MAX_KESEMPATAN,
]);
