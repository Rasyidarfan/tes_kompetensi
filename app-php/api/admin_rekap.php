<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();
require_admin();

$db      = get_db();
$ses_res = $db->query("
    SELECT s.id, s.nik, s.kesempatan, s.skor, s.mulai_at, s.selesai_at, s.paket_soal,
           p.nama_lengkap
    FROM sesi_ujian s JOIN peserta p ON s.nik=p.nik
    WHERE s.status='selesai'
    ORDER BY p.nama_lengkap, s.kesempatan
");
$sesi_rows = [];
while ($r = $ses_res->fetchArray(SQLITE3_ASSOC)) $sesi_rows[] = $r;

if (!$sesi_rows) { json_out([]); }

$sesi_ids    = array_column($sesi_rows, 'id');
$placeholders = implode(',', $sesi_ids);
$jaw_res     = $db->query("SELECT * FROM jawaban WHERE sesi_id IN ($placeholders)");
$jaw_map     = [];
while ($j = $jaw_res->fetchArray(SQLITE3_ASSOC)) {
    $jaw_map[$j['sesi_id']][] = $j;
}

$result = [];
foreach ($sesi_rows as $s) {
    $paket   = json_decode($s['paket_soal'], true) ?: [];
    $jawaban = $jaw_map[$s['id']] ?? [];
    $jaw_by_nomor = [];
    foreach ($jawaban as $j) $jaw_by_nomor[$j['nomor_soal']] = $j;

    $kat_stats = [];
    foreach ($paket as $i => $soal) {
        $nomor = $i + 1;
        $kat   = $soal['kategori'];
        if (!isset($kat_stats[$kat])) $kat_stats[$kat] = ['benar'=>0,'total'=>0,'waktu'=>[]];
        $kat_stats[$kat]['total']++;
        $j = $jaw_by_nomor[$nomor] ?? null;
        if ($j) {
            if ($j['benar']) $kat_stats[$kat]['benar']++;
            if ($j['waktu_jawab_detik'] !== null) $kat_stats[$kat]['waktu'][] = (float)$j['waktu_jawab_detik'];
        }
    }

    $kategori = [];
    foreach ($kat_stats as $kat => $st) {
        $wl = $st['waktu'];
        $kategori[] = [
            'kategori'   => $kat,
            'benar'      => $st['benar'],
            'total'      => $st['total'],
            'pct'        => $st['total'] ? round($st['benar']/$st['total']*100) : 0,
            'rata_waktu' => $wl ? round(array_sum($wl)/count($wl), 1) : null,
        ];
    }

    $durasi = null;
    if ($s['mulai_at'] && $s['selesai_at']) {
        $durasi = (int)(strtotime($s['selesai_at']) - strtotime($s['mulai_at']));
    }

    $result[] = [
        'sesi_id'      => $s['id'],
        'nik'          => $s['nik'],
        'nama'         => $s['nama_lengkap'],
        'kesempatan'   => $s['kesempatan'],
        'skor'         => $s['skor'] !== null ? (float)$s['skor'] : null,
        'mulai_at'     => $s['mulai_at'],
        'selesai_at'   => $s['selesai_at'],
        'durasi_detik' => $durasi,
        'kategori'     => $kategori,
    ];
}
json_out($result);
