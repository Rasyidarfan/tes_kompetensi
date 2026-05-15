<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();
require_admin();

$body = get_json_body();
$aksi = $body['aksi'] ?? '';
$nik  = $body['nik']  ?? null;
$db   = get_db();
$now  = time();

function geser_waktu(int $sesi_id, int $delta): void {
    global $db, $now;
    $res = $db->query("SELECT mulai_at, status FROM sesi_ujian WHERE id=$sesi_id");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row || !in_array($row['status'], ['mulai','berlangsung'])) return;
    if (!$row['mulai_at']) return;
    $mulai     = strtotime($row['mulai_at']);
    $mulai_baru = $mulai + $delta;  // delta negatif = mundur = tambah waktu
    $min_mulai  = $now - DURASI_DETIK;
    if ($mulai_baru < $min_mulai) $mulai_baru = $min_mulai;
    $t = SQLite3::escapeString(date('Y-m-d H:i:s', $mulai_baru));
    $db->exec("UPDATE sesi_ujian SET mulai_at='$t' WHERE id=$sesi_id");
}

function selesai_5_menit(int $sesi_id): void {
    global $db, $now;
    $t = SQLite3::escapeString(date('Y-m-d H:i:s', $now - (DURASI_DETIK - 300)));
    $db->exec("UPDATE sesi_ujian SET mulai_at='$t' WHERE id=$sesi_id");
}

function reset_satu(string $nik_val): void {
    global $db;
    $n    = $db->escapeString($nik_val);
    $res  = $db->query("SELECT id FROM sesi_ujian WHERE nik='$n'");
    while ($r = $res->fetchArray(SQLITE3_NUM)) {
        $db->exec("DELETE FROM jawaban WHERE sesi_id={$r[0]}");
    }
    $db->exec("DELETE FROM sesi_ujian WHERE nik='$n'");
}

function aktif_ids(): array {
    global $db;
    $res = $db->query("SELECT id FROM sesi_ujian WHERE status IN ('mulai','berlangsung')");
    $ids = [];
    while ($r = $res->fetchArray(SQLITE3_NUM)) $ids[] = $r[0];
    return $ids;
}

function aktif_niks(): array {
    global $db;
    $res  = $db->query("SELECT nik FROM sesi_ujian WHERE status IN ('mulai','berlangsung')");
    $niks = [];
    while ($r = $res->fetchArray(SQLITE3_NUM)) $niks[] = $r[0];
    return $niks;
}

switch ($aksi) {
    case 'reset':
        if ($nik) reset_satu($nik);
        break;
    case 'reset_all':
        foreach (aktif_niks() as $n) reset_satu($n);
        break;
    case 'plus5':
        if ($nik) {
            $n   = $db->escapeString($nik);
            $res = $db->query("SELECT id FROM sesi_ujian WHERE nik='$n' AND status IN ('mulai','berlangsung')");
            $r   = $res->fetchArray(SQLITE3_ASSOC);
            if ($r) geser_waktu($r['id'], -300);
        }
        break;
    case 'minus5':
        if ($nik) {
            $n   = $db->escapeString($nik);
            $res = $db->query("SELECT id FROM sesi_ujian WHERE nik='$n' AND status IN ('mulai','berlangsung')");
            $r   = $res->fetchArray(SQLITE3_ASSOC);
            if ($r) geser_waktu($r['id'], 300);
        }
        break;
    case 'selesai5':
        if ($nik) {
            $n   = $db->escapeString($nik);
            $res = $db->query("SELECT id FROM sesi_ujian WHERE nik='$n' AND status IN ('mulai','berlangsung')");
            $r   = $res->fetchArray(SQLITE3_ASSOC);
            if ($r) selesai_5_menit($r['id']);
        }
        break;
    case 'plus5_all':
        foreach (aktif_ids() as $id) geser_waktu($id, -300);
        break;
    case 'minus5_all':
        foreach (aktif_ids() as $id) geser_waktu($id, 300);
        break;
    case 'selesai5_all':
        foreach (aktif_ids() as $id) {
            $res = $db->query("SELECT mulai_at FROM sesi_ujian WHERE id=$id");
            $row = $res->fetchArray(SQLITE3_ASSOC);
            if ($row && $row['mulai_at']) {
                $sisa = max(0, DURASI_DETIK - ($now - strtotime($row['mulai_at'])));
                if ($sisa > 300) selesai_5_menit($id);
            }
        }
        break;
    case 'tutup_habis':
        $res = $db->query("SELECT id, mulai_at FROM sesi_ujian WHERE status='berlangsung' AND mulai_at IS NOT NULL");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $elapsed = $now - strtotime($row['mulai_at']);
            if ($elapsed >= DURASI_DETIK) {
                $skor    = hitung_skor($row['id']);
                $now_esc = SQLite3::escapeString(date('Y-m-d H:i:s', $now));
                $db->exec("UPDATE sesi_ujian SET status='selesai', selesai_at='$now_esc', skor=$skor WHERE id={$row['id']}");
            }
        }
        break;
}

json_out(['ok' => true]);
