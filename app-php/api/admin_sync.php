<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();
require_admin();

$body    = get_json_body();
$token   = trim($body['token'] ?? '');
$pegawai = $body['pegawai'] ?? '1';
if (!$token) json_out(['ok' => false, 'msg' => 'Token diperlukan'], 400);

$url = "https://mitra-api.bps.go.id/api/mitra/listv4/REKRUTMEN26/96/97/02?pegawai=$pegawai";
$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
    'timeout' => 30,
    'ignore_errors' => true,
]]);
$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) json_out(['ok' => false, 'msg' => 'Koneksi ke API BPS gagal'], 502);

$data = json_decode($raw, true);
if (!is_array($data)) json_out(['ok' => false, 'msg' => 'Respons API tidak valid'], 502);

$db          = get_db();
$jumlah_baru = 0;
foreach ($data as $item) {
    $detail = $item['mitra_detail'] ?? [];
    $nik    = $detail['nik'] ?? null;
    if (!$nik) continue;
    $n = $db->escapeString($nik);
    if ($db->querySingle("SELECT nik FROM peserta WHERE nik='$n'")) continue;
    $db->exec("INSERT OR IGNORE INTO peserta
        (nik,nama_lengkap,sobat_id,nama_pos,pendidikan,no_telp,email,jns_kelamin,tgl_lahir,pekerjaan,is_motor,is_naik_motor,ket_status,skor_api)
        VALUES (
            '" . SQLite3::escapeString($nik) . "',
            '" . SQLite3::escapeString($detail['nama_lengkap'] ?? '') . "',
            '" . SQLite3::escapeString($item['sobat_id'] ?? '') . "',
            '" . SQLite3::escapeString($item['nama_pos'] ?? '') . "',
            '" . SQLite3::escapeString($detail['pendidikan'] ?? '') . "',
            '" . SQLite3::escapeString($detail['no_telp'] ?? '') . "',
            '" . SQLite3::escapeString($detail['email'] ?? '') . "',
            '" . SQLite3::escapeString($detail['jns_kelamin'] ?? '') . "',
            '" . SQLite3::escapeString($detail['tgl_lahir'] ?? '') . "',
            '" . SQLite3::escapeString($detail['pekerjaan'] ?? '') . "',
            " . (($item['is_motor'] ?? '') === '1' ? 1 : 0) . ",
            " . (($item['is_naik_motor'] ?? '') === '1' ? 1 : 0) . ",
            '" . SQLite3::escapeString($item['ket_status'] ?? '') . "',
            " . (isset($item['skor']) ? (float)$item['skor'] : 'NULL') . "
        )");
    $jumlah_baru++;
}

// Simpan token
file_put_contents(__DIR__ . '/../admin_token.txt', $token);
json_out(['ok' => true, 'total' => count($data), 'baru' => $jumlah_baru]);
