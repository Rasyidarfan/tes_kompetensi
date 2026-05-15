<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();

// URL: /api/peserta/{nik}/edit
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
preg_match('#/api/peserta/([^/]+)/edit#', $uri, $m);
$nik  = urldecode($m[1] ?? '');
if (!$nik) json_out(['ok' => false, 'msg' => 'NIK tidak valid'], 400);

$body        = get_json_body();
$diubah_oleh = $body['diubah_oleh'] ?? 'peserta';
$allowed     = ['no_telp','email','pekerjaan','is_motor','is_naik_motor'];

$db  = get_db();
$row = $db->querySingle("SELECT * FROM peserta WHERE nik=" . $db->escapeString("'$nik'"), true);
if (!$row) json_out(['ok' => false, 'msg' => 'NIK tidak ditemukan'], 404);

$sets = []; $logs = []; $updated_fields = [];
foreach ($allowed as $f) {
    if (!array_key_exists($f, $body)) continue;
    $nilai_baru = $body[$f];
    $nilai_lama = $row[$f];
    if ((string)$nilai_lama === (string)$nilai_baru) continue;
    $sets[]          = "$f=" . $db->escapeString("'" . SQLite3::escapeString((string)$nilai_baru) . "'");
    $logs[]          = [$nik, $f, (string)$nilai_lama, (string)$nilai_baru, $diubah_oleh];
    $updated_fields[] = $f;
}

if ($sets) {
    $now = now_str();
    $sets[] = "updated_at=" . $db->escapeString("'$now'");
    $sets[] = "updated_by=" . $db->escapeString("'$diubah_oleh'");
    $db->exec("UPDATE peserta SET " . implode(',', $sets) . " WHERE nik=" . $db->escapeString("'$nik'"));
    $stmt = $db->prepare("INSERT INTO log_perubahan (nik,field,nilai_lama,nilai_baru,diubah_oleh) VALUES (?,?,?,?,?)");
    foreach ($logs as $l) {
        $stmt->bindValue(1, $l[0]); $stmt->bindValue(2, $l[1]);
        $stmt->bindValue(3, $l[2]); $stmt->bindValue(4, $l[3]); $stmt->bindValue(5, $l[4]);
        $stmt->execute(); $stmt->reset();
    }
}

json_out(['ok' => true, 'updated' => $updated_fields]);
