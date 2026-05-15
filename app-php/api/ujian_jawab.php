<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();

$body        = get_json_body();
$sesi_id     = (int)($body['sesi_id'] ?? 0);
$nik         = trim($body['nik'] ?? '');
$nomor_soal  = (int)($body['nomor_soal'] ?? 0);
$jawaban     = strtoupper(trim($body['jawaban'] ?? ''));
$waktu_jawab = (float)($body['waktu_jawab_detik'] ?? 0);

if (!$sesi_id || !$nik || !$nomor_soal || !$jawaban) {
    json_out(['ok' => false, 'msg' => 'Parameter tidak lengkap'], 400);
}

$db      = get_db();
$nik_esc = $db->escapeString($nik);
$res     = $db->query("SELECT * FROM sesi_ujian WHERE id=$sesi_id AND nik='$nik_esc' AND status='berlangsung'");
$sesi    = $res->fetchArray(SQLITE3_ASSOC);
if (!$sesi) json_out(['ok' => false, 'msg' => 'Sesi tidak aktif'], 400);

$sisa_detik = sisa_detik_sesi($sesi);

// Waktu habis — selesaikan otomatis
if ($sisa_detik <= 0) {
    $skor    = hitung_skor($sesi_id);
    $now_esc = SQLite3::escapeString(now_str());
    $db->exec("UPDATE sesi_ujian SET status='selesai', selesai_at='$now_esc', skor=$skor WHERE id=$sesi_id");
    json_out(['ok' => false, 'msg' => 'Waktu habis', 'skor' => $skor], 400);
}

$paket = json_decode($sesi['paket_soal'], true);
$idx   = $nomor_soal - 1;
if ($idx < 0 || $idx >= count($paket)) json_out(['ok' => false, 'msg' => 'Nomor soal tidak valid'], 400);

$kunci  = strtoupper($paket[$idx]['kunci_jawaban']);
$benar  = ($jawaban === $kunci) ? 1 : 0;
$j_esc  = SQLite3::escapeString($jawaban);
$k_esc  = SQLite3::escapeString($kunci);

$db->exec("
    INSERT INTO jawaban (sesi_id,nomor_soal,kunci_soal,jawaban,benar,waktu_jawab_detik)
    VALUES ($sesi_id,$nomor_soal,'$k_esc','$j_esc',$benar,$waktu_jawab)
    ON CONFLICT(sesi_id,nomor_soal) DO UPDATE SET
        jawaban=excluded.jawaban,
        benar=excluded.benar,
        waktu_jawab_detik=excluded.waktu_jawab_detik,
        dijawab_at=datetime('now','localtime')
");

json_out(['ok' => true, 'sisa_detik' => $sisa_detik, 'benar' => $benar]);
