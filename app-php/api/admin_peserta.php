<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Tambah peserta manual
    require_admin();
    $body = get_json_body();
    $nik  = trim($body['nik'] ?? '');
    $nama = trim($body['nama'] ?? '');
    if (!$nik)  json_out(['ok' => false, 'msg' => 'NIK tidak boleh kosong'], 400);
    if (!$nama) json_out(['ok' => false, 'msg' => 'Nama tidak boleh kosong'], 400);

    $db  = get_db();
    $n   = $db->escapeString($nik);
    $exist = $db->querySingle("SELECT nik FROM peserta WHERE nik='$n'");
    if ($exist) json_out(['ok' => false, 'msg' => 'NIK sudah terdaftar'], 409);

    $nama_esc = SQLite3::escapeString($nama);
    $db->exec("INSERT INTO peserta (nik, nama_lengkap) VALUES ('$n','$nama_esc')");
    json_out(['ok' => true]);
}

// GET — daftar semua peserta + status ujian
require_admin();
$db  = get_db();
$res = $db->query("
    SELECT p.nik, p.nama_lengkap, p.no_telp, p.email, p.pekerjaan,
        (SELECT COUNT(*) FROM sesi_ujian s WHERE s.nik=p.nik) AS jml_sesi,
        (SELECT MAX(skor) FROM sesi_ujian s WHERE s.nik=p.nik AND s.status='selesai') AS skor_tertinggi,
        (SELECT status FROM sesi_ujian s WHERE s.nik=p.nik ORDER BY kesempatan DESC LIMIT 1) AS status_sesi
    FROM peserta p ORDER BY p.nama_lengkap
");
$rows = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
json_out($rows);
