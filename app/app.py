import json
import os
import sys
import time
import hashlib
import requests
from datetime import datetime
from flask import Flask, request, jsonify, render_template, session
from flask_cors import CORS
from database import (
    init_db, get_db, buat_paket_soal, get_sesi_aktif,
    get_jumlah_kesempatan, get_skor_tertinggi, hitung_skor,
    DURASI_DETIK, MAX_KESEMPATAN, SOAL_PER_UJIAN, _app_dir
)


def _resource_dir():
    """Folder templates/static — di dalam _MEIPASS saat frozen, app dir saat dev."""
    if getattr(sys, "frozen", False):
        return sys._MEIPASS
    return os.path.dirname(os.path.abspath(__file__))


app = Flask(
    __name__,
    template_folder=os.path.join(_resource_dir(), "templates"),
    static_folder=os.path.join(_resource_dir(), "static"),
)

# Secret key persisten — di folder yg sama dengan exe/app.py
_KEY_FILE = os.path.join(_app_dir(), ".secret_key")
if os.path.exists(_KEY_FILE):
    with open(_KEY_FILE, "rb") as _f:
        app.secret_key = _f.read()
else:
    app.secret_key = os.urandom(32)
    with open(_KEY_FILE, "wb") as _f:
        _f.write(app.secret_key)

CORS(app, supports_credentials=True)

# SHA-256 dari "1qazxsw2"
ADMIN_PASSWORD_HASH = "b73846dd535927acb39ffca85c45f41197d0380b1477e3448a041b9b94a222b1"

# Token statis — dikirim via header X-Admin-Token, disimpan di localStorage browser
ADMIN_TOKEN = hashlib.sha256(b"admin-token-" + app.secret_key).hexdigest()

def require_admin():
    token = request.headers.get("X-Admin-Token", "")
    if token != ADMIN_TOKEN:
        return jsonify({"ok": False, "msg": "Unauthorized"}), 401
    return None

# --- Konfigurasi API BPS ---
BASE_URL_LIST = "https://mitra-api.bps.go.id/api/mitra/listv4"
KD_SURVEI   = "REKRUTMEN26"
ID_KEGIATAN = "96"
KD_PROV     = "97"
KD_KAB      = "02"

ADMIN_TOKEN_FILE = os.path.join(_app_dir(), "admin_token.txt")


def get_admin_token():
    if os.path.exists(ADMIN_TOKEN_FILE):
        with open(ADMIN_TOKEN_FILE) as f:
            return f.read().strip()
    return ""


def save_admin_token(token):
    with open(ADMIN_TOKEN_FILE, "w") as f:
        f.write(token)


def fetch_peserta_dari_api(token, pegawai="1"):
    url = f"{BASE_URL_LIST}/{KD_SURVEI}/{ID_KEGIATAN}/{KD_PROV}/{KD_KAB}"
    headers = {"Authorization": f"Bearer {token}"}
    params = {"pegawai": pegawai}
    resp = requests.get(url, headers=headers, params=params, timeout=30)
    resp.raise_for_status()
    data = resp.json()
    return data


def simpan_peserta_ke_db(data):
    conn = get_db()
    jumlah_baru = 0
    for item in data:
        detail = item.get("mitra_detail", {}) or {}
        nik = detail.get("nik")
        if not nik:
            continue
        existing = conn.execute("SELECT nik FROM peserta WHERE nik=?", (nik,)).fetchone()
        if not existing:
            conn.execute("""
                INSERT OR IGNORE INTO peserta
                (nik, nama_lengkap, sobat_id, nama_pos, pendidikan, no_telp, email,
                 jns_kelamin, tgl_lahir, pekerjaan, is_motor, is_naik_motor, ket_status, skor_api)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            """, (
                nik,
                detail.get("nama_lengkap"),
                item.get("sobat_id"),
                item.get("nama_pos"),
                detail.get("pendidikan"),
                detail.get("no_telp"),
                detail.get("email"),
                detail.get("jns_kelamin"),
                detail.get("tgl_lahir"),
                detail.get("pekerjaan"),
                1 if item.get("is_motor") == "1" else 0,
                1 if item.get("is_naik_motor") == "1" else 0,
                item.get("ket_status"),
                item.get("skor"),
            ))
            jumlah_baru += 1
    conn.commit()
    conn.close()
    return jumlah_baru


# ─── Routes ──────────────────────────────────────────────────────────────────

@app.route("/")
def index():
    return render_template("index.html")


@app.route("/admin", methods=["GET", "POST"])
def admin():
    error = None
    if request.method == "POST":
        pw = request.form.get("password", "")
        if hashlib.sha256(pw.encode()).hexdigest() == ADMIN_PASSWORD_HASH:
            # Login berhasil — ambil semua data dan render langsung
            conn = get_db()
            peserta_rows = conn.execute("""
                SELECT p.nik, p.nama_lengkap, p.no_telp, p.email, p.pekerjaan,
                    (SELECT COUNT(*) FROM sesi_ujian s WHERE s.nik=p.nik) AS jml_sesi,
                    (SELECT MAX(skor) FROM sesi_ujian s WHERE s.nik=p.nik AND s.status='selesai') AS skor_tertinggi,
                    (SELECT status FROM sesi_ujian s WHERE s.nik=p.nik ORDER BY kesempatan DESC LIMIT 1) AS status_sesi
                FROM peserta p ORDER BY p.nama_lengkap
            """).fetchall()

            skor_rows = conn.execute(
                "SELECT skor FROM sesi_ujian WHERE status='selesai' AND skor IS NOT NULL"
            ).fetchall()
            buckets = [0] * 11
            for r in skor_rows:
                buckets[min(int(r[0] // 10), 10)] += 1
            lulus = sum(1 for r in skor_rows if r[0] >= 60)

            # Rata-rata waktu per kategori
            sesi_rows = conn.execute("SELECT id, paket_soal FROM sesi_ujian WHERE status='selesai'").fetchall()
            soal_map = {}
            for sid, paket_json in sesi_rows:
                try:
                    for i, s in enumerate(json.loads(paket_json)):
                        soal_map[(sid, i+1)] = s.get("kategori", "Lainnya")
                except Exception:
                    pass
            jawaban_rows = conn.execute("""
                SELECT j.sesi_id, j.nomor_soal, j.waktu_jawab_detik FROM jawaban j
                JOIN sesi_ujian s ON j.sesi_id=s.id
                WHERE s.status='selesai' AND j.waktu_jawab_detik IS NOT NULL
            """).fetchall()
            kat_waktu = {}
            for sid, nomor, waktu in jawaban_rows:
                kat = soal_map.get((sid, nomor), "Lainnya")
                kat_waktu.setdefault(kat, []).append(waktu)
            rata_waktu = {k: round(sum(v)/len(v), 1) for k, v in kat_waktu.items()}

            log_rows = conn.execute("""
                SELECT l.nik, p.nama_lengkap, l.field, l.nilai_lama, l.nilai_baru, l.diubah_at
                FROM log_perubahan l LEFT JOIN peserta p ON l.nik=p.nik
                ORDER BY l.diubah_at DESC LIMIT 100
            """).fetchall()
            conn.close()

            return render_template("admin.html",
                logged_in=True,
                token=ADMIN_TOKEN,
                peserta=[dict(r) for r in peserta_rows],
                buckets=buckets,
                lulus=lulus,
                tidak_lulus=len(skor_rows)-lulus,
                total_selesai=len(skor_rows),
                rata_waktu=rata_waktu,
                log=[dict(r) for r in log_rows],
            )
        else:
            error = "Password salah"
    return render_template("admin.html", logged_in=False, error=error)


@app.route("/api/admin/login", methods=["POST"])
def admin_login():
    body = request.get_json(force=True)
    pw = str(body.get("password", ""))
    pw_hash = hashlib.sha256(pw.encode()).hexdigest()
    if pw_hash == ADMIN_PASSWORD_HASH:
        return jsonify({"ok": True, "token": ADMIN_TOKEN})
    return jsonify({"ok": False, "msg": "Password salah"}), 401


@app.route("/api/admin/logout", methods=["POST"])
def admin_logout():
    return jsonify({"ok": True})


# --- Admin: ambil data dari API BPS ---
@app.route("/api/admin/sync", methods=["POST"])
def admin_sync():
    err = require_admin()
    if err: return err
    body = request.get_json(force=True)
    token = body.get("token", "").strip()
    pegawai = body.get("pegawai", "1")
    if not token:
        return jsonify({"ok": False, "msg": "Token diperlukan"}), 400
    try:
        data = fetch_peserta_dari_api(token, pegawai)
        jumlah_baru = simpan_peserta_ke_db(data)
        save_admin_token(token)
        return jsonify({"ok": True, "total": len(data), "baru": jumlah_baru})
    except requests.exceptions.HTTPError as e:
        return jsonify({"ok": False, "msg": f"HTTP {e.response.status_code}"}), 502
    except Exception as e:
        return jsonify({"ok": False, "msg": str(e)}), 500


# --- Admin: daftar semua peserta + status ujian ---
@app.route("/api/admin/peserta")
def admin_peserta():
    err = require_admin()
    if err: return err
    conn = get_db()
    rows = conn.execute("""
        SELECT p.*,
            (SELECT COUNT(*) FROM sesi_ujian s WHERE s.nik=p.nik) AS jml_sesi,
            (SELECT MAX(skor) FROM sesi_ujian s WHERE s.nik=p.nik AND s.status='selesai') AS skor_tertinggi,
            (SELECT status FROM sesi_ujian s WHERE s.nik=p.nik ORDER BY kesempatan DESC LIMIT 1) AS status_sesi
        FROM peserta p
        ORDER BY p.nama_lengkap
    """).fetchall()
    conn.close()
    return jsonify([dict(r) for r in rows])


# --- Admin: log perubahan data peserta ---
@app.route("/api/admin/log")
def admin_log():
    err = require_admin()
    if err: return err
    conn = get_db()
    rows = conn.execute("""
        SELECT l.*, p.nama_lengkap FROM log_perubahan l
        LEFT JOIN peserta p ON l.nik=p.nik
        ORDER BY l.diubah_at DESC LIMIT 200
    """).fetchall()
    conn.close()
    return jsonify([dict(r) for r in rows])


# --- Admin: detail sesi + jawaban peserta ---
@app.route("/api/admin/sesi/<nik>")
def admin_sesi(nik):
    err = require_admin()
    if err: return err
    conn = get_db()
    sesi_list = conn.execute(
        "SELECT * FROM sesi_ujian WHERE nik=? ORDER BY kesempatan", (nik,)
    ).fetchall()
    result = []
    for s in sesi_list:
        s = dict(s)
        jawaban = conn.execute(
            "SELECT * FROM jawaban WHERE sesi_id=? ORDER BY nomor_soal", (s["id"],)
        ).fetchall()
        s["jawaban"] = [dict(j) for j in jawaban]
        paket = json.loads(s["paket_soal"]) if s.get("paket_soal") else []
        s["paket_soal_info"] = [{"no": i+1, "kategori": q["kategori"], "pertanyaan": q["pertanyaan"]} for i, q in enumerate(paket)]
        del s["paket_soal"]
        result.append(s)
    conn.close()
    return jsonify(result)


# --- Admin: tambah peserta manual ---
@app.route("/api/admin/peserta", methods=["POST"])
def admin_tambah_peserta():
    err = require_admin()
    if err: return err
    body = request.get_json(force=True)
    nik = str(body.get("nik", "")).strip()
    nama = str(body.get("nama", "")).strip()
    if not nik:
        return jsonify({"ok": False, "msg": "NIK tidak boleh kosong"}), 400
    if not nama:
        return jsonify({"ok": False, "msg": "Nama tidak boleh kosong"}), 400
    conn = get_db()
    existing = conn.execute("SELECT nik FROM peserta WHERE nik=?", (nik,)).fetchone()
    if existing:
        conn.close()
        return jsonify({"ok": False, "msg": "NIK sudah terdaftar"}), 409
    conn.execute("INSERT INTO peserta (nik, nama_lengkap) VALUES (?, ?)", (nik, nama))
    conn.commit()
    conn.close()
    return jsonify({"ok": True})


# --- Peserta: login dengan NIK ---
@app.route("/api/login", methods=["POST"])
def login():
    body = request.get_json(force=True)
    nik = str(body.get("nik", "")).strip()
    if not nik:
        return jsonify({"ok": False, "msg": "NIK tidak boleh kosong"}), 400

    conn = get_db()
    peserta = conn.execute("SELECT * FROM peserta WHERE nik=?", (nik,)).fetchone()
    conn.close()

    if not peserta:
        return jsonify({"ok": False, "msg": "NIK tidak terdaftar sebagai peserta"}), 404

    peserta = dict(peserta)
    sesi_aktif = get_sesi_aktif(nik)
    jml_kesempatan = get_jumlah_kesempatan(nik)
    skor_tertinggi = get_skor_tertinggi(nik)

    return jsonify({
        "ok": True,
        "peserta": peserta,
        "sesi_aktif": sesi_aktif is not None,
        "jml_kesempatan": jml_kesempatan,
        "skor_tertinggi": skor_tertinggi,
        "max_kesempatan": MAX_KESEMPATAN,
    })


# --- Peserta: edit data diri ---
@app.route("/api/peserta/<nik>/edit", methods=["POST"])
def edit_peserta(nik):
    body = request.get_json(force=True)
    diubah_oleh = body.get("diubah_oleh", "peserta")

    allowed_fields = ["no_telp", "email", "pekerjaan", "is_motor", "is_naik_motor"]
    conn = get_db()
    existing = conn.execute("SELECT * FROM peserta WHERE nik=?", (nik,)).fetchone()
    if not existing:
        conn.close()
        return jsonify({"ok": False, "msg": "NIK tidak ditemukan"}), 404

    existing = dict(existing)
    logs = []
    updates = {}
    for field in allowed_fields:
        if field in body:
            nilai_baru = body[field]
            nilai_lama = existing.get(field)
            if str(nilai_lama) != str(nilai_baru):
                updates[field] = nilai_baru
                logs.append((nik, field, str(nilai_lama), str(nilai_baru), diubah_oleh))

    if updates:
        set_clause = ", ".join(f"{k}=?" for k in updates)
        vals = list(updates.values()) + [datetime.now().strftime("%Y-%m-%d %H:%M:%S"), diubah_oleh, nik]
        conn.execute(
            f"UPDATE peserta SET {set_clause}, updated_at=?, updated_by=? WHERE nik=?", vals
        )
        for log in logs:
            conn.execute(
                "INSERT INTO log_perubahan (nik,field,nilai_lama,nilai_baru,diubah_oleh) VALUES (?,?,?,?,?)", log
            )
        conn.commit()

    conn.close()
    return jsonify({"ok": True, "updated": list(updates.keys())})


# --- Peserta: mulai atau lanjut ujian ---
@app.route("/api/ujian/mulai", methods=["POST"])
def ujian_mulai():
    body = request.get_json(force=True)
    nik = str(body.get("nik", "")).strip()
    if not nik:
        return jsonify({"ok": False, "msg": "NIK diperlukan"}), 400

    conn = get_db()
    peserta = conn.execute("SELECT nik FROM peserta WHERE nik=?", (nik,)).fetchone()
    if not peserta:
        conn.close()
        return jsonify({"ok": False, "msg": "NIK tidak ditemukan"}), 404

    # Cek sesi aktif (berlangsung)
    sesi = conn.execute(
        "SELECT * FROM sesi_ujian WHERE nik=? AND status IN ('mulai','berlangsung') ORDER BY kesempatan DESC LIMIT 1",
        (nik,)
    ).fetchone()

    if sesi:
        sesi = dict(sesi)
        paket = json.loads(sesi["paket_soal"])
        # Ambil jawaban yang sudah ada
        jawaban_ada = conn.execute(
            "SELECT nomor_soal, jawaban, benar FROM jawaban WHERE sesi_id=?", (sesi["id"],)
        ).fetchall()
        jawaban_map = {j["nomor_soal"]: dict(j) for j in jawaban_ada}
        conn.close()

        # Hitung sisa waktu dari server
        sisa_detik = DURASI_DETIK
        if sesi["status"] == "berlangsung" and sesi["mulai_at"]:
            elapsed = (datetime.now() - datetime.fromisoformat(sesi["mulai_at"])).total_seconds()
            sisa_detik = max(0, DURASI_DETIK - int(elapsed))

        return jsonify({
            "ok": True,
            "mode": "lanjut",
            "sesi_id": sesi["id"],
            "kesempatan": sesi["kesempatan"],
            "soal": paket,
            "jawaban_ada": jawaban_map,
            "sisa_detik": sisa_detik,
            "status": sesi["status"],
        })

    # Cek batas kesempatan
    jml = conn.execute(
        "SELECT COUNT(*) as c FROM sesi_ujian WHERE nik=?", (nik,)
    ).fetchone()["c"]

    if jml >= MAX_KESEMPATAN:
        conn.close()
        return jsonify({"ok": False, "msg": f"Batas {MAX_KESEMPATAN} kesempatan telah habis"}), 403

    # Buat sesi baru
    paket = buat_paket_soal()
    kesempatan = jml + 1
    conn.execute("""
        INSERT INTO sesi_ujian (nik, kesempatan, paket_soal, status)
        VALUES (?,?,?,?)
    """, (nik, kesempatan, json.dumps(paket), "mulai"))
    conn.commit()
    sesi_id = conn.execute(
        "SELECT id FROM sesi_ujian WHERE nik=? AND kesempatan=?", (nik, kesempatan)
    ).fetchone()["id"]
    conn.close()

    return jsonify({
        "ok": True,
        "mode": "baru",
        "sesi_id": sesi_id,
        "kesempatan": kesempatan,
        "soal": paket,
        "jawaban_ada": {},
        "sisa_detik": DURASI_DETIK,
        "status": "mulai",
    })


# --- Peserta: konfirmasi halaman soal sudah dimuat, mulai timer ---
@app.route("/api/ujian/loaded", methods=["POST"])
def ujian_loaded():
    body = request.get_json(force=True)
    sesi_id = body.get("sesi_id")
    nik = str(body.get("nik", "")).strip()

    conn = get_db()
    sesi = conn.execute(
        "SELECT * FROM sesi_ujian WHERE id=? AND nik=?", (sesi_id, nik)
    ).fetchone()
    if not sesi:
        conn.close()
        return jsonify({"ok": False, "msg": "Sesi tidak ditemukan"}), 404

    sesi = dict(sesi)
    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

    if sesi["status"] == "mulai":
        conn.execute(
            "UPDATE sesi_ujian SET status='berlangsung', mulai_at=? WHERE id=?",
            (now_str, sesi_id)
        )
        conn.commit()
        sisa_detik = DURASI_DETIK
    else:
        elapsed = (datetime.now() - datetime.fromisoformat(sesi["mulai_at"])).total_seconds()
        sisa_detik = max(0, DURASI_DETIK - int(elapsed))

    conn.close()
    return jsonify({"ok": True, "sisa_detik": sisa_detik})


# --- Peserta: kirim jawaban satu soal ---
@app.route("/api/ujian/jawab", methods=["POST"])
def ujian_jawab():
    body = request.get_json(force=True)
    sesi_id = body.get("sesi_id")
    nik = str(body.get("nik", "")).strip()
    nomor_soal = body.get("nomor_soal")  # 1-based
    jawaban = str(body.get("jawaban", "")).upper().strip()
    waktu_jawab = body.get("waktu_jawab_detik", 0)  # selisih waktu dari soal ditampilkan

    conn = get_db()
    sesi = conn.execute(
        "SELECT * FROM sesi_ujian WHERE id=? AND nik=? AND status='berlangsung'",
        (sesi_id, nik)
    ).fetchone()

    if not sesi:
        conn.close()
        return jsonify({"ok": False, "msg": "Sesi tidak aktif"}), 400

    sesi = dict(sesi)

    # Hitung sisa waktu server
    elapsed = (datetime.now() - datetime.fromisoformat(sesi["mulai_at"])).total_seconds()
    sisa_detik = max(0, DURASI_DETIK - int(elapsed))

    if sisa_detik == 0:
        # Waktu habis - otomatis selesaikan
        skor = hitung_skor(sesi_id)
        conn.execute(
            "UPDATE sesi_ujian SET status='selesai', selesai_at=?, skor=? WHERE id=?",
            (datetime.now().strftime("%Y-%m-%d %H:%M:%S"), skor, sesi_id)
        )
        conn.commit()
        conn.close()
        return jsonify({"ok": False, "msg": "Waktu habis", "skor": skor}), 400

    # Ambil kunci jawaban dari paket soal
    paket = json.loads(sesi["paket_soal"])
    idx = nomor_soal - 1
    if idx < 0 or idx >= len(paket):
        conn.close()
        return jsonify({"ok": False, "msg": "Nomor soal tidak valid"}), 400

    soal = paket[idx]
    kunci = soal["kunci_jawaban"].upper()
    benar = 1 if jawaban == kunci else 0

    conn.execute("""
        INSERT INTO jawaban (sesi_id, nomor_soal, kunci_soal, jawaban, benar, waktu_jawab_detik)
        VALUES (?,?,?,?,?,?)
        ON CONFLICT(sesi_id, nomor_soal) DO UPDATE SET
            jawaban=excluded.jawaban,
            benar=excluded.benar,
            waktu_jawab_detik=excluded.waktu_jawab_detik,
            dijawab_at=datetime('now','localtime')
    """, (sesi_id, nomor_soal, kunci, jawaban, benar, waktu_jawab))
    conn.commit()
    conn.close()

    return jsonify({"ok": True, "sisa_detik": sisa_detik, "benar": benar})


# --- Peserta: selesaikan ujian ---
@app.route("/api/ujian/selesai", methods=["POST"])
def ujian_selesai():
    body = request.get_json(force=True)
    sesi_id = body.get("sesi_id")
    nik = str(body.get("nik", "")).strip()

    conn = get_db()
    sesi = conn.execute(
        "SELECT * FROM sesi_ujian WHERE id=? AND nik=? AND status='berlangsung'",
        (sesi_id, nik)
    ).fetchone()

    if not sesi:
        conn.close()
        return jsonify({"ok": False, "msg": "Sesi tidak ditemukan atau sudah selesai"}), 400

    skor = hitung_skor(sesi_id)
    now_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    conn.execute(
        "UPDATE sesi_ujian SET status='selesai', selesai_at=?, skor=? WHERE id=?",
        (now_str, skor, sesi_id)
    )
    conn.commit()
    skor_tertinggi = conn.execute(
        "SELECT MAX(skor) as s FROM sesi_ujian WHERE nik=? AND status='selesai'", (nik,)
    ).fetchone()["s"]
    jml_sesi = conn.execute(
        "SELECT COUNT(*) as c FROM sesi_ujian WHERE nik=?", (nik,)
    ).fetchone()["c"]
    conn.close()

    return jsonify({
        "ok": True,
        "skor": skor,
        "skor_tertinggi": skor_tertinggi,
        "jml_sesi": jml_sesi,
        "max_kesempatan": MAX_KESEMPATAN,
    })


# --- Admin: reset sesi untuk testing (bisa dihapus di produksi) ---
@app.route("/api/admin/reset_sesi", methods=["POST"])
def admin_reset_sesi():
    err = require_admin()
    if err: return err
    body = request.get_json(force=True)
    nik = body.get("nik")
    conn = get_db()
    if nik:
        jawaban_ids = [r[0] for r in conn.execute(
            "SELECT id FROM sesi_ujian WHERE nik=?", (nik,)
        ).fetchall()]
        for sid in jawaban_ids:
            conn.execute("DELETE FROM jawaban WHERE sesi_id=?", (sid,))
        conn.execute("DELETE FROM sesi_ujian WHERE nik=?", (nik,))
    conn.commit()
    conn.close()
    return jsonify({"ok": True})


@app.route("/api/admin/statistik")
def admin_statistik():
    err = require_admin()
    if err: return err
    conn = get_db()

    # 1. Distribusi nilai (bucket 10-an: 0-9, 10-19, ..., 90-100)
    skor_rows = conn.execute(
        "SELECT skor FROM sesi_ujian WHERE status='selesai' AND skor IS NOT NULL"
    ).fetchall()
    buckets = [0] * 11  # index 0=0-9, ..., 9=90-99, 10=100
    for r in skor_rows:
        s = r[0]
        idx = min(int(s // 10), 10)
        buckets[idx] += 1
    distribusi = {
        "labels": ["0-9","10-19","20-29","30-39","40-49","50-59","60-69","70-79","80-89","90-99","100"],
        "data": buckets
    }

    # 2. Lulus / tidak lulus (nilai >= 60)
    lulus = sum(1 for r in skor_rows if r[0] >= 60)
    tidak_lulus = len(skor_rows) - lulus
    kelulusan = {"lulus": lulus, "tidak_lulus": tidak_lulus}

    # 3. Rata-rata waktu jawab per kategori
    sesi_rows = conn.execute(
        "SELECT id, paket_soal FROM sesi_ujian WHERE status='selesai'"
    ).fetchall()
    # Bangun map: (sesi_id, nomor_soal) -> kategori
    soal_map = {}
    for sid, paket_json in sesi_rows:
        try:
            paket = json.loads(paket_json)
            for i, soal in enumerate(paket):
                soal_map[(sid, i + 1)] = soal.get("kategori", "Lainnya")
        except Exception:
            pass

    jawaban_rows = conn.execute(
        """SELECT j.sesi_id, j.nomor_soal, j.waktu_jawab_detik
           FROM jawaban j
           JOIN sesi_ujian s ON j.sesi_id = s.id
           WHERE s.status='selesai' AND j.waktu_jawab_detik IS NOT NULL"""
    ).fetchall()

    kat_waktu = {}
    for sid, nomor, waktu in jawaban_rows:
        kat = soal_map.get((sid, nomor), "Lainnya")
        if kat not in kat_waktu:
            kat_waktu[kat] = []
        kat_waktu[kat].append(waktu)

    rata_waktu = {
        "labels": [],
        "data": []
    }
    for kat, waktus in kat_waktu.items():
        rata_waktu["labels"].append(kat)
        rata_waktu["data"].append(round(sum(waktus) / len(waktus), 1))

    conn.close()
    return jsonify({
        "ok": True,
        "distribusi": distribusi,
        "kelulusan": kelulusan,
        "rata_waktu": rata_waktu,
        "total_peserta": len(skor_rows)
    })


@app.route("/api/admin/rekap")
def admin_rekap():
    err = require_admin()
    if err: return err

    conn = get_db()
    sesi_rows = conn.execute("""
        SELECT s.id, s.nik, s.kesempatan, s.skor, s.mulai_at, s.selesai_at, s.paket_soal,
               p.nama_lengkap
        FROM sesi_ujian s JOIN peserta p ON s.nik = p.nik
        WHERE s.status = 'selesai'
        ORDER BY p.nama_lengkap, s.kesempatan
    """).fetchall()

    # Ambil semua jawaban sekaligus
    sesi_ids = [r["id"] for r in sesi_rows]
    if not sesi_ids:
        conn.close()
        return jsonify([])

    placeholders = ",".join("?" * len(sesi_ids))
    jaw_rows = conn.execute(
        f"SELECT * FROM jawaban WHERE sesi_id IN ({placeholders})", sesi_ids
    ).fetchall()
    conn.close()

    # Index jawaban per sesi
    jaw_map = {}
    for j in jaw_rows:
        jaw_map.setdefault(j["sesi_id"], []).append(dict(j))

    result = []
    for s in sesi_rows:
        s = dict(s)
        paket = json.loads(s["paket_soal"])
        jawaban = jaw_map.get(s["id"], [])
        jaw_by_nomor = {j["nomor_soal"]: j for j in jawaban}

        # Hitung per kategori
        kat_stats = {}
        for i, soal in enumerate(paket):
            nomor = i + 1
            kat = soal["kategori"]
            if kat not in kat_stats:
                kat_stats[kat] = {"benar": 0, "total": 0, "waktu": []}
            j = jaw_by_nomor.get(nomor)
            kat_stats[kat]["total"] += 1
            if j:
                if j["benar"]:
                    kat_stats[kat]["benar"] += 1
                if j["waktu_jawab_detik"] is not None:
                    kat_stats[kat]["waktu"].append(j["waktu_jawab_detik"])

        kategori = []
        for kat, st in kat_stats.items():
            waktu_list = st["waktu"]
            kategori.append({
                "kategori": kat,
                "benar": st["benar"],
                "total": st["total"],
                "dijawab": len(waktu_list),
                "pct": round(st["benar"] / st["total"] * 100) if st["total"] else 0,
                "rata_waktu": round(sum(waktu_list) / len(waktu_list), 1) if waktu_list else None,
            })

        durasi = None
        if s["mulai_at"] and s["selesai_at"]:
            from datetime import datetime
            try:
                d = (datetime.fromisoformat(s["selesai_at"]) -
                     datetime.fromisoformat(s["mulai_at"])).total_seconds()
                durasi = int(d)
            except Exception:
                pass

        result.append({
            "sesi_id": s["id"],
            "nik": s["nik"],
            "nama": s["nama_lengkap"],
            "kesempatan": s["kesempatan"],
            "skor": s["skor"],
            "mulai_at": s["mulai_at"],
            "selesai_at": s["selesai_at"],
            "durasi_detik": durasi,
            "kategori": kategori,
        })

    return jsonify(result)


@app.route("/qr")
def qr_page():
    return render_template("qr.html")


@app.route("/api/qr.png")
def qr_image():
    import qrcode
    import io
    url = request.args.get("url", "").strip()
    if not url:
        # Bangun URL dari request host
        url = request.url_root.rstrip("/")
    img = qrcode.make(url, box_size=12, border=2)
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    buf.seek(0)
    from flask import send_file
    return send_file(buf, mimetype="image/png")


def _soal_row_to_dict(s):
    return {
        "id": s["id"],
        "kategori": s["kategori"],
        "pertanyaan": s["pertanyaan"],
        "pilihan": {h: s[f"pilihan_{h}"] for h in ["a","b","c","d","e"] if s[f"pilihan_{h}"]},
        "kunci_jawaban": s["kunci_jawaban"],
        "paket": s["paket"] or "",
        "updated_at": s["updated_at"],
    }


@app.route("/api/admin/soal")
def admin_soal_list():
    err = require_admin()
    if err: return err
    from database import KATEGORI_URUTAN
    conn = get_db()
    result = []
    for kat in KATEGORI_URUTAN:
        rows = conn.execute(
            "SELECT * FROM bank_soal WHERE kategori=? ORDER BY rowid", (kat,)
        ).fetchall()
        for s in rows:
            result.append(_soal_row_to_dict(s))
    conn.close()
    return jsonify(result)


@app.route("/api/admin/soal/<soal_id>", methods=["PUT"])
def admin_soal_update(soal_id):
    err = require_admin()
    if err: return err
    body = request.get_json(force=True)
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    conn = get_db()
    s = conn.execute("SELECT * FROM bank_soal WHERE id=?", (soal_id,)).fetchone()
    if not s:
        conn.close()
        return jsonify({"ok": False, "msg": "Soal tidak ditemukan"}), 404
    pilihan = body.get("pilihan", {})
    conn.execute("""
        UPDATE bank_soal SET
            pertanyaan=?, pilihan_a=?, pilihan_b=?, pilihan_c=?,
            pilihan_d=?, pilihan_e=?, kunci_jawaban=?, updated_at=?
        WHERE id=?
    """, (
        body.get("pertanyaan", s["pertanyaan"]).strip(),
        pilihan.get("a", s["pilihan_a"]),
        pilihan.get("b", s["pilihan_b"]),
        pilihan.get("c", s["pilihan_c"] or ""),
        pilihan.get("d", s["pilihan_d"] or ""),
        pilihan.get("e", s["pilihan_e"] or ""),
        body.get("kunci_jawaban", s["kunci_jawaban"]).upper().strip(),
        now,
        soal_id,
    ))
    conn.commit()
    conn.close()
    return jsonify({"ok": True, "updated_at": now})


@app.route("/api/admin/soal/kategori/<path:kat_id>", methods=["POST"])
def admin_soal_tambah(kat_id):
    err = require_admin()
    if err: return err
    body = request.get_json(force=True)
    import uuid as _uuid
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    new_id = str(_uuid.uuid4())
    p = body.get("pilihan", {})
    conn = get_db()
    conn.execute("""
        INSERT INTO bank_soal
        (id, kategori, pertanyaan, pilihan_a, pilihan_b, pilihan_c,
         pilihan_d, pilihan_e, kunci_jawaban, paket, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    """, (
        new_id, kat_id,
        body.get("pertanyaan", "").strip(),
        p.get("a",""), p.get("b",""), p.get("c",""),
        p.get("d",""), p.get("e",""),
        body.get("kunci_jawaban","A").upper().strip(),
        body.get("paket",""),
        now,
    ))
    conn.commit()
    conn.close()
    return jsonify({"ok": True, "id": new_id, "updated_at": now})


@app.route("/api/admin/soal/<soal_id>", methods=["DELETE"])
def admin_soal_hapus(soal_id):
    err = require_admin()
    if err: return err
    conn = get_db()
    conn.execute("DELETE FROM bank_soal WHERE id=?", (soal_id,))
    conn.commit()
    conn.close()
    return jsonify({"ok": True})


@app.route("/api/admin/soal/export")
def admin_soal_export():
    err = require_admin()
    if err: return err
    from database import KATEGORI_URUTAN
    conn = get_db()
    rows = conn.execute("SELECT * FROM bank_soal ORDER BY kategori, rowid").fetchall()
    conn.close()
    data = [_soal_row_to_dict(s) for s in rows]
    resp = jsonify(data)
    resp.headers["Content-Disposition"] = \
        f"attachment; filename=bank_soal_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
    return resp


@app.route("/api/admin/soal/import", methods=["POST"])
def admin_soal_import():
    """
    Body: JSON array soal (sama dengan format export).
    Kembalikan: {inserted, updated, conflicts}
    conflicts = soal yang updated_at berbeda antara file dan DB.
    Kalau body punya field resolve={id: 'keep'|'replace'}, terapkan resolusi itu dulu.
    """
    err = require_admin()
    if err: return err
    body = request.get_json(force=True)
    soal_list = body.get("soal", body) if isinstance(body, dict) else body
    resolusi  = body.get("resolve", {}) if isinstance(body, dict) else {}

    conn = get_db()
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    inserted = updated = 0
    conflicts = []

    for s in soal_list:
        sid = s.get("id")
        if not sid:
            continue
        existing = conn.execute("SELECT * FROM bank_soal WHERE id=?", (sid,)).fetchone()

        if existing:
            if existing["updated_at"] != s.get("updated_at", ""):
                # Ada konflik — cek apakah user sudah menentukan resolusi
                if sid in resolusi:
                    if resolusi[sid] == "replace":
                        p = s.get("pilihan", {})
                        conn.execute("""
                            UPDATE bank_soal SET
                                kategori=?, pertanyaan=?, pilihan_a=?, pilihan_b=?,
                                pilihan_c=?, pilihan_d=?, pilihan_e=?,
                                kunci_jawaban=?, paket=?, updated_at=?
                            WHERE id=?
                        """, (
                            s.get("kategori", existing["kategori"]),
                            s.get("pertanyaan", existing["pertanyaan"]),
                            p.get("a", existing["pilihan_a"]),
                            p.get("b", existing["pilihan_b"]),
                            p.get("c", existing["pilihan_c"] or ""),
                            p.get("d", existing["pilihan_d"] or ""),
                            p.get("e", existing["pilihan_e"] or ""),
                            s.get("kunci_jawaban", existing["kunci_jawaban"]).upper(),
                            s.get("paket", existing["paket"] or ""),
                            s.get("updated_at", now),
                            sid,
                        ))
                        updated += 1
                    # 'keep' → tidak lakukan apa-apa
                else:
                    # Belum ada resolusi — tandai sebagai konflik
                    conflicts.append({
                        "id": sid,
                        "kategori": s.get("kategori"),
                        "pertanyaan_import": s.get("pertanyaan"),
                        "pertanyaan_db": existing["pertanyaan"],
                        "updated_at_import": s.get("updated_at"),
                        "updated_at_db": existing["updated_at"],
                    })
        else:
            # Soal baru, langsung insert
            p = s.get("pilihan", {})
            conn.execute("""
                INSERT INTO bank_soal
                (id, kategori, pertanyaan, pilihan_a, pilihan_b, pilihan_c,
                 pilihan_d, pilihan_e, kunci_jawaban, paket, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            """, (
                sid,
                s.get("kategori", ""),
                s.get("pertanyaan", ""),
                p.get("a",""), p.get("b",""), p.get("c",""),
                p.get("d",""), p.get("e",""),
                s.get("kunci_jawaban","A").upper(),
                s.get("paket",""),
                s.get("updated_at", now),
            ))
            inserted += 1

    conn.commit()
    conn.close()
    return jsonify({"ok": True, "inserted": inserted, "updated": updated, "conflicts": conflicts})


if __name__ == "__main__":
    import socket
    init_db()

    # Scan port 8000-8080, pakai yang pertama kosong
    def cari_port(start=8000, end=8080):
        for p in range(start, end + 1):
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
                s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                try:
                    s.bind(("0.0.0.0", p))
                    return p
                except OSError:
                    continue
        raise RuntimeError(f"Tidak ada port kosong di {start}-{end}")

    port = int(os.environ.get("PORT", 0)) or cari_port()

    # Tampilkan semua IP aktif
    ips = []
    for iface_addrs in socket.getaddrinfo(socket.gethostname(), None):
        ip = iface_addrs[4][0]
        if ip.startswith("192.168.") or ip.startswith("10."):
            if ip not in ips:
                ips.append(ip)

    print(f"\n=== Tes Kompetensi Mitra BPS ===")
    for ip in ips:
        print(f"  http://{ip}:{port}/")
    print(f"  Admin: http://{ips[0] if ips else '127.0.0.1'}:{port}/admin")
    print()

    try:
        from waitress import serve
        print(f"Server siap (waitress) di port {port}\n")
        serve(app, host="0.0.0.0", port=port, threads=8)
    except ImportError:
        print(f"Server siap (flask dev) di port {port}\n")
        app.run(host="0.0.0.0", port=port, debug=False, threaded=True)
