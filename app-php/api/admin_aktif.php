<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();
require_admin();

$db  = get_db();
$res = $db->query("
    SELECT s.id, s.nik, s.kesempatan, s.status, s.mulai_at,
           p.nama_lengkap
    FROM sesi_ujian s
    JOIN peserta p ON p.nik = s.nik
    WHERE s.status IN ('mulai','berlangsung')
    ORDER BY s.mulai_at
");

$result = [];
while ($r = $res->fetchArray(SQLITE3_ASSOC)) {
    $sisa    = sisa_detik_sesi($r);
    $dijawab = (int)$db->querySingle("SELECT COUNT(*) FROM jawaban WHERE sesi_id={$r['id']}");
    $result[] = [
        'sesi_id'    => $r['id'],
        'nik'        => $r['nik'],
        'nama'       => $r['nama_lengkap'] ?: $r['nik'],
        'kesempatan' => $r['kesempatan'],
        'status'     => $r['status'],
        'dijawab'    => $dijawab,
        'sisa_detik' => $sisa,
    ];
}
json_out($result);
