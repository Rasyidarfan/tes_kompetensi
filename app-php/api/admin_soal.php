<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
init_db();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Helpers
function soal_to_arr(array $s): array {
    $pilihan = [];
    foreach (['a','b','c','d','e'] as $h) {
        if (!empty($s["pilihan_$h"])) $pilihan[$h] = $s["pilihan_$h"];
    }
    return [
        'id'            => $s['id'],
        'kategori'      => $s['kategori'],
        'pertanyaan'    => $s['pertanyaan'],
        'pilihan'       => $pilihan,
        'kunci_jawaban' => $s['kunci_jawaban'],
        'paket'         => $s['paket'] ?? '',
        'updated_at'    => $s['updated_at'],
    ];
}

// GET /api/admin/soal/export
if ($method === 'GET' && str_ends_with($uri, '/export')) {
    $db   = get_db();
    $res  = $db->query("SELECT * FROM bank_soal ORDER BY kategori, rowid");
    $data = [];
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $data[] = soal_to_arr($r);
    $fname = 'bank_soal_' . date('Ymd_His') . '.json';
    header("Content-Disposition: attachment; filename=$fname");
    json_out($data);
}

// POST /api/admin/soal/import
if ($method === 'POST' && str_ends_with($uri, '/import')) {
    $body     = get_json_body();
    $soal_list = $body['soal'] ?? (is_array($body) ? $body : []);
    $resolusi  = $body['resolve'] ?? [];
    $db        = get_db();
    $now       = now_str();
    $inserted  = $updated = 0;
    $conflicts = [];

    foreach ($soal_list as $s) {
        $sid = $s['id'] ?? null;
        if (!$sid) continue;
        $sid_esc = $db->escapeString($sid);
        $exist   = $db->querySingle("SELECT * FROM bank_soal WHERE id='$sid_esc'", true);

        if ($exist) {
            if ($exist['updated_at'] !== ($s['updated_at'] ?? '')) {
                if (isset($resolusi[$sid])) {
                    if ($resolusi[$sid] === 'replace') {
                        $p = $s['pilihan'] ?? [];
                        $db->exec("UPDATE bank_soal SET
                            kategori=" . $db->escapeString("'" . SQLite3::escapeString($s['kategori'] ?? $exist['kategori']) . "'") . ",
                            pertanyaan=" . $db->escapeString("'" . SQLite3::escapeString($s['pertanyaan'] ?? '') . "'") . ",
                            pilihan_a=" . $db->escapeString("'" . SQLite3::escapeString($p['a'] ?? $exist['pilihan_a']) . "'") . ",
                            pilihan_b=" . $db->escapeString("'" . SQLite3::escapeString($p['b'] ?? $exist['pilihan_b']) . "'") . ",
                            pilihan_c=" . $db->escapeString("'" . SQLite3::escapeString($p['c'] ?? '') . "'") . ",
                            pilihan_d=" . $db->escapeString("'" . SQLite3::escapeString($p['d'] ?? '') . "'") . ",
                            pilihan_e=" . $db->escapeString("'" . SQLite3::escapeString($p['e'] ?? '') . "'") . ",
                            kunci_jawaban='" . SQLite3::escapeString(strtoupper($s['kunci_jawaban'] ?? $exist['kunci_jawaban'])) . "',
                            updated_at='" . SQLite3::escapeString($s['updated_at'] ?? $now) . "'
                            WHERE id='$sid_esc'");
                        $updated++;
                    }
                } else {
                    $conflicts[] = [
                        'id'               => $sid,
                        'kategori'         => $s['kategori'] ?? '',
                        'pertanyaan_import'=> $s['pertanyaan'] ?? '',
                        'pertanyaan_db'    => $exist['pertanyaan'],
                        'updated_at_import'=> $s['updated_at'] ?? '',
                        'updated_at_db'    => $exist['updated_at'],
                    ];
                }
            }
        } else {
            $p = $s['pilihan'] ?? [];
            $new_id = bin2hex(random_bytes(16));
            $db->exec("INSERT INTO bank_soal (id,kategori,pertanyaan,pilihan_a,pilihan_b,pilihan_c,pilihan_d,pilihan_e,kunci_jawaban,paket,updated_at)
                VALUES (
                    '$new_id',
                    '" . SQLite3::escapeString($s['kategori'] ?? '') . "',
                    '" . SQLite3::escapeString($s['pertanyaan'] ?? '') . "',
                    '" . SQLite3::escapeString($p['a'] ?? '') . "',
                    '" . SQLite3::escapeString($p['b'] ?? '') . "',
                    '" . SQLite3::escapeString($p['c'] ?? '') . "',
                    '" . SQLite3::escapeString($p['d'] ?? '') . "',
                    '" . SQLite3::escapeString($p['e'] ?? '') . "',
                    '" . SQLite3::escapeString(strtoupper($s['kunci_jawaban'] ?? 'A')) . "',
                    '" . SQLite3::escapeString($s['paket'] ?? '') . "',
                    '" . SQLite3::escapeString($s['updated_at'] ?? $now) . "'
                )");
            $inserted++;
        }
    }
    json_out(['ok' => true, 'inserted' => $inserted, 'updated' => $updated, 'conflicts' => $conflicts]);
}

// POST /api/admin/soal/kategori/{kat}
if ($method === 'POST' && preg_match('#/api/admin/soal/kategori/(.+)$#', $uri, $m)) {
    $kat  = urldecode($m[1]);
    $body = get_json_body();
    $p    = $body['pilihan'] ?? [];
    $now  = now_str();
    $id   = bin2hex(random_bytes(16));
    $db   = get_db();
    $db->exec("INSERT INTO bank_soal (id,kategori,pertanyaan,pilihan_a,pilihan_b,pilihan_c,pilihan_d,pilihan_e,kunci_jawaban,paket,updated_at)
        VALUES (
            '$id',
            '" . SQLite3::escapeString($kat) . "',
            '" . SQLite3::escapeString(trim($body['pertanyaan'] ?? '')) . "',
            '" . SQLite3::escapeString($p['a'] ?? '') . "',
            '" . SQLite3::escapeString($p['b'] ?? '') . "',
            '" . SQLite3::escapeString($p['c'] ?? '') . "',
            '" . SQLite3::escapeString($p['d'] ?? '') . "',
            '" . SQLite3::escapeString($p['e'] ?? '') . "',
            '" . SQLite3::escapeString(strtoupper($body['kunci_jawaban'] ?? 'A')) . "',
            '" . SQLite3::escapeString($body['paket'] ?? '') . "',
            '" . SQLite3::escapeString($now) . "'
        )");
    json_out(['ok' => true, 'id' => $id, 'updated_at' => $now]);
}

// PUT /api/admin/soal/{id}
if ($method === 'PUT' && preg_match('#/api/admin/soal/([^/]+)$#', $uri, $m)) {
    $soal_id  = urldecode($m[1]);
    $body     = get_json_body();
    $p        = $body['pilihan'] ?? [];
    $now      = now_str();
    $db       = get_db();
    $sid_esc  = $db->escapeString($soal_id);
    $exist    = $db->querySingle("SELECT * FROM bank_soal WHERE id='$sid_esc'", true);
    if (!$exist) json_out(['ok' => false, 'msg' => 'Soal tidak ditemukan'], 404);

    $db->exec("UPDATE bank_soal SET
        pertanyaan='" . SQLite3::escapeString(trim($body['pertanyaan'] ?? $exist['pertanyaan'])) . "',
        pilihan_a='"  . SQLite3::escapeString($p['a'] ?? $exist['pilihan_a']) . "',
        pilihan_b='"  . SQLite3::escapeString($p['b'] ?? $exist['pilihan_b']) . "',
        pilihan_c='"  . SQLite3::escapeString($p['c'] ?? '') . "',
        pilihan_d='"  . SQLite3::escapeString($p['d'] ?? '') . "',
        pilihan_e='"  . SQLite3::escapeString($p['e'] ?? '') . "',
        kunci_jawaban='" . SQLite3::escapeString(strtoupper($body['kunci_jawaban'] ?? $exist['kunci_jawaban'])) . "',
        updated_at='" . SQLite3::escapeString($now) . "'
        WHERE id='$sid_esc'");
    json_out(['ok' => true, 'updated_at' => $now]);
}

// DELETE /api/admin/soal/{id}
if ($method === 'DELETE' && preg_match('#/api/admin/soal/([^/]+)$#', $uri, $m)) {
    $soal_id = urldecode($m[1]);
    $db      = get_db();
    $sid_esc = $db->escapeString($soal_id);
    $db->exec("DELETE FROM bank_soal WHERE id='$sid_esc'");
    json_out(['ok' => true]);
}

// GET /api/admin/soal — daftar semua soal
$db     = get_db();
$result = [];
foreach (KATEGORI_URUTAN as $kat) {
    $k   = $db->escapeString($kat);
    $res = $db->query("SELECT * FROM bank_soal WHERE kategori='$k' ORDER BY rowid");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $result[] = soal_to_arr($r);
}
json_out($result);
