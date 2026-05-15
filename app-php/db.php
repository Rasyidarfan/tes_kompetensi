<?php
define('DB_PATH',        __DIR__ . '/tes_kompetensi.db');
define('BANK_SOAL_PATH', __DIR__ . '/../bank_soal.json');
define('DURASI_DETIK',   45 * 60);
define('MAX_KESEMPATAN', 2);
define('SOAL_PER_UJIAN', 30);

const KATEGORI_URUTAN = [
    'Aritmatika Dasar',
    'Analogi Verbal',
    'Deret dan Pola Angka',
    'Aritmatika dan Numerik Lanjutan',
    'Analogi Verbal Lanjutan',
    'Matematika Terapan dan Soal Cerita',
    'Penalaran Logis',
    'Pengetahuan BPS dan Statistik',
];

function get_db(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(DB_PATH);
        $db->busyTimeout(5000);
        $db->exec("PRAGMA journal_mode=WAL");
        $db->exec("PRAGMA foreign_keys=ON");
    }
    return $db;
}

function init_db(): void {
    $db = get_db();
    $db->exec("
        CREATE TABLE IF NOT EXISTS peserta (
            nik TEXT PRIMARY KEY,
            nama_lengkap TEXT,
            sobat_id TEXT,
            nama_pos TEXT,
            pendidikan TEXT,
            no_telp TEXT,
            email TEXT,
            jns_kelamin TEXT,
            tgl_lahir TEXT,
            pekerjaan TEXT,
            is_motor INTEGER DEFAULT 0,
            is_naik_motor INTEGER DEFAULT 0,
            ket_status TEXT,
            skor_api REAL,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime')),
            updated_by TEXT
        );
        CREATE TABLE IF NOT EXISTS sesi_ujian (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nik TEXT NOT NULL,
            kesempatan INTEGER NOT NULL DEFAULT 1,
            paket_soal TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'mulai',
            mulai_at TEXT,
            selesai_at TEXT,
            sisa_detik INTEGER DEFAULT NULL,
            skor REAL DEFAULT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (nik) REFERENCES peserta(nik),
            UNIQUE(nik, kesempatan)
        );
        CREATE TABLE IF NOT EXISTS jawaban (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sesi_id INTEGER NOT NULL,
            nomor_soal INTEGER NOT NULL,
            kunci_soal TEXT NOT NULL,
            jawaban TEXT,
            benar INTEGER,
            waktu_jawab_detik REAL,
            dijawab_at TEXT DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (sesi_id) REFERENCES sesi_ujian(id),
            UNIQUE(sesi_id, nomor_soal)
        );
        CREATE TABLE IF NOT EXISTS log_perubahan (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nik TEXT NOT NULL,
            field TEXT NOT NULL,
            nilai_lama TEXT,
            nilai_baru TEXT,
            diubah_oleh TEXT,
            diubah_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS bank_soal (
            id TEXT PRIMARY KEY,
            kategori TEXT NOT NULL,
            pertanyaan TEXT NOT NULL,
            pilihan_a TEXT NOT NULL DEFAULT '',
            pilihan_b TEXT NOT NULL DEFAULT '',
            pilihan_c TEXT DEFAULT '',
            pilihan_d TEXT DEFAULT '',
            pilihan_e TEXT DEFAULT '',
            kunci_jawaban TEXT NOT NULL,
            paket TEXT DEFAULT '',
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    ");

    $count = $db->querySingle("SELECT COUNT(*) FROM bank_soal");
    if ($count == 0 && file_exists(BANK_SOAL_PATH)) {
        _import_json_ke_db($db);
    }
}

function _import_json_ke_db(SQLite3 $db): void {
    $bank = json_decode(file_get_contents(BANK_SOAL_PATH), true);
    $now  = date('Y-m-d H:i:s');
    $stmt = $db->prepare("
        INSERT OR IGNORE INTO bank_soal
        (id,kategori,pertanyaan,pilihan_a,pilihan_b,pilihan_c,pilihan_d,pilihan_e,kunci_jawaban,paket,updated_at)
        VALUES (:id,:kat,:p,:a,:b,:c,:d,:e,:k,:pak,:now)
    ");
    foreach ($bank['meta']['kategori'] as $kat) {
        foreach (($bank['bank_soal'][$kat]['soal'] ?? []) as $soal) {
            $pil = $soal['pilihan'] ?? [];
            $stmt->bindValue(':id',  bin2hex(random_bytes(16)));
            $stmt->bindValue(':kat', $kat);
            $stmt->bindValue(':p',   $soal['pertanyaan']);
            $stmt->bindValue(':a',   $pil['a'] ?? '');
            $stmt->bindValue(':b',   $pil['b'] ?? '');
            $stmt->bindValue(':c',   $pil['c'] ?? '');
            $stmt->bindValue(':d',   $pil['d'] ?? '');
            $stmt->bindValue(':e',   $pil['e'] ?? '');
            $stmt->bindValue(':k',   strtoupper($soal['kunci_jawaban']));
            $stmt->bindValue(':pak', $soal['paket'] ?? '');
            $stmt->bindValue(':now', $now);
            $stmt->execute();
        }
    }
}

function buat_paket_soal(): array {
    $db    = get_db();
    $paket = [];
    foreach (KATEGORI_URUTAN as $kat) {
        $k    = $db->escapeString($kat);
        $res  = $db->query("SELECT * FROM bank_soal WHERE kategori='$k'");
        $rows = [];
        while ($r = $res->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;
        if (!$rows) continue;
        $ambil = max(1, intdiv(count($rows), 3));
        shuffle($rows);
        foreach (array_slice($rows, 0, $ambil) as $s) {
            $pilihan = [];
            foreach (['a','b','c','d','e'] as $h) {
                if (!empty($s["pilihan_$h"])) $pilihan[$h] = $s["pilihan_$h"];
            }
            $paket[] = [
                'kategori'      => $kat,
                'pertanyaan'    => $s['pertanyaan'],
                'pilihan'       => $pilihan,
                'kunci_jawaban' => $s['kunci_jawaban'],
                'paket_asal'    => $s['paket'] ?? '',
            ];
        }
    }
    return $paket;
}

function hitung_skor(int $sesi_id): float {
    $db    = get_db();
    $benar = (int)$db->querySingle("SELECT COUNT(*) FROM jawaban WHERE sesi_id=$sesi_id AND benar=1");
    return round(($benar / SOAL_PER_UJIAN) * 100, 2);
}

function get_sesi_aktif(string $nik): ?array {
    $db  = get_db();
    $nik = $db->escapeString($nik);
    $res = $db->query("SELECT * FROM sesi_ujian WHERE nik='$nik' AND status IN ('mulai','berlangsung') ORDER BY kesempatan DESC LIMIT 1");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function get_jumlah_kesempatan(string $nik): int {
    $db  = get_db();
    $nik = $db->escapeString($nik);
    return (int)$db->querySingle("SELECT COUNT(*) FROM sesi_ujian WHERE nik='$nik'");
}

function get_skor_tertinggi(string $nik): ?float {
    $db  = get_db();
    $nik = $db->escapeString($nik);
    $val = $db->querySingle("SELECT MAX(skor) FROM sesi_ujian WHERE nik='$nik' AND status='selesai'");
    return $val !== null ? (float)$val : null;
}

function now_str(): string {
    return date('Y-m-d H:i:s');
}

function sisa_detik_sesi(array $sesi): int {
    if ($sesi['status'] !== 'berlangsung' || !$sesi['mulai_at']) return DURASI_DETIK;
    $elapsed = time() - strtotime($sesi['mulai_at']);
    return max(0, DURASI_DETIK - $elapsed);
}
