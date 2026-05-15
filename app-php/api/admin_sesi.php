<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();
require_admin();

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
preg_match('#/api/admin/sesi/([^/]+)#', $uri, $m);
$nik = urldecode($m[1] ?? '');
if (!$nik) json_out(['ok' => false, 'msg' => 'NIK diperlukan'], 400);

$db      = get_db();
$n       = $db->escapeString($nik);
$ses_res = $db->query("SELECT * FROM sesi_ujian WHERE nik='$n' ORDER BY kesempatan");
$result  = [];
while ($s = $ses_res->fetchArray(SQLITE3_ASSOC)) {
    $sid     = $s['id'];
    $jaw_res = $db->query("SELECT * FROM jawaban WHERE sesi_id=$sid ORDER BY nomor_soal");
    $jawaban = [];
    while ($j = $jaw_res->fetchArray(SQLITE3_ASSOC)) $jawaban[] = $j;

    $paket = json_decode($s['paket_soal'], true) ?: [];
    $info  = [];
    foreach ($paket as $i => $q) {
        $info[] = ['no' => $i+1, 'kategori' => $q['kategori'], 'pertanyaan' => $q['pertanyaan']];
    }
    unset($s['paket_soal']);
    $s['jawaban']         = $jawaban;
    $s['paket_soal_info'] = $info;
    $result[]             = $s;
}
json_out($result);
