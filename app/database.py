import sqlite3
import json
import os
import random
import sys
import uuid
from datetime import datetime


def _app_dir():
    """Folder di samping .exe (saat build) atau folder app.py (saat dev)."""
    if getattr(sys, "frozen", False):
        return os.path.dirname(sys.executable)
    return os.path.dirname(os.path.abspath(__file__))


DB_PATH        = os.path.join(_app_dir(), "tes_kompetensi.db")
BANK_SOAL_PATH = os.path.join(_app_dir(), "bank_soal.json")

DURASI_DETIK = 45 * 60
MAX_KESEMPATAN = 2
SOAL_PER_UJIAN = 30

KATEGORI_URUTAN = [
    "Aritmatika Dasar",
    "Analogi Verbal",
    "Deret dan Pola Angka",
    "Aritmatika dan Numerik Lanjutan",
    "Analogi Verbal Lanjutan",
    "Matematika Terapan dan Soal Cerita",
    "Penalaran Logis",
    "Pengetahuan BPS dan Statistik",
]


def get_db():
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    return conn


def init_db():
    conn = get_db()
    conn.executescript("""
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
    """)
    conn.commit()

    # Migrasi: import dari bank_soal.json jika tabel masih kosong
    count = conn.execute("SELECT COUNT(*) FROM bank_soal").fetchone()[0]
    if count == 0 and os.path.exists(BANK_SOAL_PATH):
        _import_json_ke_db(conn, BANK_SOAL_PATH)

    conn.close()


def _import_json_ke_db(conn, path):
    """Baca bank_soal.json dan masukkan ke tabel bank_soal."""
    with open(path, encoding="utf-8") as f:
        bank = json.load(f)
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    for kat in bank["meta"].get("kategori", []):
        for soal in bank["bank_soal"].get(kat, {}).get("soal", []):
            p = soal.get("pilihan", {})
            conn.execute("""
                INSERT OR IGNORE INTO bank_soal
                (id, kategori, pertanyaan, pilihan_a, pilihan_b, pilihan_c,
                 pilihan_d, pilihan_e, kunci_jawaban, paket, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            """, (
                str(uuid.uuid4()),
                kat,
                soal["pertanyaan"],
                p.get("a", ""), p.get("b", ""), p.get("c", ""),
                p.get("d", ""), p.get("e", ""),
                soal["kunci_jawaban"].upper(),
                soal.get("paket", ""),
                now,
            ))
    conn.commit()


def buat_paket_soal():
    """Ambil 1/3 soal dari setiap kategori secara acak dari DB."""
    conn = get_db()
    paket = []
    for kat in KATEGORI_URUTAN:
        rows = conn.execute(
            "SELECT * FROM bank_soal WHERE kategori=?", (kat,)
        ).fetchall()
        if not rows:
            continue
        jumlah_ambil = max(1, len(rows) // 3)
        dipilih = random.sample(rows, min(jumlah_ambil, len(rows)))
        for s in dipilih:
            pilihan = {h: s[f"pilihan_{h}"] for h in ["a","b","c","d","e"] if s[f"pilihan_{h}"]}
            paket.append({
                "kategori": kat,
                "pertanyaan": s["pertanyaan"],
                "pilihan": pilihan,
                "kunci_jawaban": s["kunci_jawaban"],
                "paket_asal": s["paket"] or "",
            })
    conn.close()
    return paket


def get_sesi_aktif(nik):
    conn = get_db()
    sesi = conn.execute(
        "SELECT * FROM sesi_ujian WHERE nik=? AND status IN ('mulai','berlangsung') ORDER BY kesempatan DESC LIMIT 1",
        (nik,)
    ).fetchone()
    conn.close()
    return dict(sesi) if sesi else None


def get_jumlah_kesempatan(nik):
    conn = get_db()
    row = conn.execute("SELECT COUNT(*) as c FROM sesi_ujian WHERE nik=?", (nik,)).fetchone()
    conn.close()
    return row["c"]


def get_skor_tertinggi(nik):
    conn = get_db()
    row = conn.execute(
        "SELECT MAX(skor) as s FROM sesi_ujian WHERE nik=? AND status='selesai'", (nik,)
    ).fetchone()
    conn.close()
    return row["s"] if row and row["s"] is not None else None


def hitung_skor(sesi_id):
    conn = get_db()
    total = conn.execute("SELECT COUNT(*) as c FROM jawaban WHERE sesi_id=?", (sesi_id,)).fetchone()["c"]
    benar = conn.execute("SELECT COUNT(*) as c FROM jawaban WHERE sesi_id=? AND benar=1", (sesi_id,)).fetchone()["c"]
    conn.close()
    if total == 0:
        return 0.0
    return round((benar / SOAL_PER_UJIAN) * 100, 2)
