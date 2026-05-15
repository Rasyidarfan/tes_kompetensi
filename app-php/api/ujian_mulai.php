<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();

$body = get_json_body();
$nik  = trim($body['nik'] ?? '');
if (!$nik) json_out(['ok' => false, 'msg' => 'NIK diperlukan'], 400);

$db  = get_db();
$nik_esc = $db->escapeString($nik);

$peserta = $db->querySingle("SELECT nik FROM peserta WHERE nik='$nik_esc'", true);
if (!$peserta) json_out(['ok' => false, 'msg' => 'NIK tidak ditemukan'], 404);

// Cek sesi aktif
$res  = $db->query("SELECT * FROM sesi_ujian WHERE nik='$nik_esc' AND status IN ('mulai','berlangsung') ORDER BY kesempatan DESC LIMIT 1");
$sesi = $res->fetchArray(SQLITE3_ASSOC);

if ($sesi) {
    $paket      = json_decode($sesi['paket_soal'], true);
    $sisa_detik = sisa_detik_sesi($sesi);

    $jaw_res = $db->query("SELECT nomor_soal, jawaban, benar FROM jawaban WHERE sesi_id={$sesi['id']}");
    $jawaban_map = [];
    while ($j = $jaw_res->fetchArray(SQLITE3_ASSOC)) {
        $jawaban_map[$j['nomor_soal']] = $j;
    }

    json_out([
        'ok'          => true,
        'mode'        => 'lanjut',
        'sesi_id'     => $sesi['id'],
        'kesempatan'  => $sesi['kesempatan'],
        'soal'        => $paket,
        'jawaban_ada' => $jawaban_map,
        'sisa_detik'  => $sisa_detik,
        'status'      => $sesi['status'],
    ]);
}

// Cek batas kesempatan
$jml = (int)$db->querySingle("SELECT COUNT(*) FROM sesi_ujian WHERE nik='$nik_esc'");
if ($jml >= MAX_KESEMPATAN) {
    json_out(['ok' => false, 'msg' => 'Batas ' . MAX_KESEMPATAN . ' kesempatan telah habis'], 403);
}

// Buat sesi baru
$paket      = buat_paket_soal();
$kesempatan = $jml + 1;
$paket_json = SQLite3::escapeString(json_encode($paket, JSON_UNESCAPED_UNICODE));
$db->exec("INSERT INTO sesi_ujian (nik,kesempatan,paket_soal,status) VALUES ('$nik_esc',$kesempatan,'$paket_json','mulai')");
$sesi_id = $db->lastInsertRowID();

json_out([
    'ok'          => true,
    'mode'        => 'baru',
    'sesi_id'     => $sesi_id,
    'kesempatan'  => $kesempatan,
    'soal'        => $paket,
    'jawaban_ada' => [],
    'sisa_detik'  => DURASI_DETIK,
    'status'      => 'mulai',
]);
