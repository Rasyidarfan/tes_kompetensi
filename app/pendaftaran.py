"""Pendaftaran (registrasi) calon mitra: biodata, alamat + GPS, berkas,
bank, perangkat, esai, dan pilihan lokasi tugas (kabupaten → distrik).

Pendaftar otomatis dimasukkan ke tabel `peserta` sehingga bisa langsung
login dengan NIK untuk ikut tes kompetensi.
"""
import csv
import hmac
import io
import json
import os
import re
import shutil
import sqlite3
from datetime import datetime

from flask import Blueprint, abort, current_app, jsonify, redirect, render_template, request, send_file
from PIL import Image, ImageOps

try:  # foto HEIC dari iPhone
    from pillow_heif import register_heif_opener
    register_heif_opener()
except Exception:  # pragma: no cover
    pass

from database import get_db, _app_dir

bp = Blueprint("pendaftaran", __name__)

BERKAS_DIR = os.path.join(_app_dir(), "berkas_pendaftar")
WILAYAH_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "static", "wilayah.json")

MAKS_BERKAS = 15 * 1024 * 1024          # per berkas (sebelum dikompres)
MAKS_REQUEST = 50 * 1024 * 1024
BERKAS = {                               # jenis: (label, sisi terpanjang px, boleh PDF)
    "pasfoto":     ("Pasfoto", 900, False),
    "foto_ktp":    ("Foto KTP", 1800, False),
    "foto_ijazah": ("Foto ijazah", 2200, True),
}

AGAMA = ["Islam", "Kristen Protestan", "Katolik", "Hindu", "Buddha", "Konghucu"]
STATUS_KAWIN = ["Belum kawin", "Kawin", "Cerai hidup", "Cerai mati"]
PENDIDIKAN = ["SD/sederajat", "SMP/sederajat", "SMA/SMK/sederajat", "D1/D2/D3",
              "D4/S1", "S2/S3"]
JK = ["Laki-laki", "Perempuan"]
ALASAN = ["Domisili", "Ada keluarga/kenalan", "Pernah tinggal/bertugas",
          "Menguasai bahasa setempat", "Akses transportasi mudah"]

_wilayah_cache = None


def wilayah():
    global _wilayah_cache
    if _wilayah_cache is None:
        with open(WILAYAH_PATH, encoding="utf-8") as f:
            data = json.load(f)
        _wilayah_cache = {
            d["kode"]: (k["nama"], d["nama"]) for k in data for d in k["distrik"]
        }
    return _wilayah_cache


def init_pendaftaran():
    conn = get_db()
    conn.execute("""
        CREATE TABLE IF NOT EXISTS pendaftaran (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nik TEXT NOT NULL UNIQUE,
            nama TEXT NOT NULL,
            tempat_lahir TEXT, tgl_lahir TEXT, jns_kelamin TEXT, agama TEXT,
            status_kawin TEXT, pendidikan TEXT, jurusan TEXT, pekerjaan TEXT,
            no_telp TEXT, email TEXT,
            alamat TEXT, latitude REAL, longitude REAL, akurasi_m REAL, sumber_lokasi TEXT,
            pasfoto TEXT, foto_ktp TEXT, foto_ijazah TEXT,
            bank TEXT, no_rekening TEXT, nama_rekening TEXT,
            hp_android INTEGER, hp_merk TEXT, hp_tipe TEXT, hp_ram REAL,
            punya_motor INTEGER, bisa_motor INTEGER, punya_laptop INTEGER, bisa_komputer INTEGER,
            esai_bps TEXT, esai_kegiatan TEXT,
            penugasan TEXT,
            dibuat TEXT DEFAULT (datetime('now','localtime')),
            ip TEXT
        )
    """)
    conn.commit()
    conn.close()
    os.makedirs(BERKAS_DIR, exist_ok=True)


# ─── validasi ────────────────────────────────────────────────────────────────

class Gagal(Exception):
    pass


def _teks(f, nama, label, wajib=True, maks=200):
    v = (f.get(nama) or "").strip()
    if wajib and not v:
        raise Gagal(f"{label} wajib diisi")
    if len(v) > maks:
        raise Gagal(f"{label} terlalu panjang (maks {maks} karakter)")
    return v or None


def _pilih(f, nama, label, opsi):
    v = (f.get(nama) or "").strip()
    if v not in opsi:
        raise Gagal(f"{label} wajib dipilih")
    return v


def _yatidak(f, nama, label):
    v = f.get(nama)
    if v not in ("1", "0"):
        raise Gagal(f"{label} wajib dipilih")
    return int(v)


def _simpan_berkas(fs, nik, jenis):
    label, sisi, boleh_pdf = BERKAS[jenis]
    if not fs or not fs.filename:
        raise Gagal(f"{label} wajib diunggah")
    data = fs.read(MAKS_BERKAS + 1)
    if len(data) > MAKS_BERKAS:
        raise Gagal(f"{label} terlalu besar (maks 15 MB)")
    folder = os.path.join(BERKAS_DIR, nik)
    os.makedirs(folder, exist_ok=True)
    if data[:5] == b"%PDF-":
        if not boleh_pdf:
            raise Gagal(f"{label} harus berupa foto (JPG/PNG), bukan PDF")
        nama = f"{jenis}.pdf"
        with open(os.path.join(folder, nama), "wb") as out:
            out.write(data)
        return nama
    try:
        img = Image.open(io.BytesIO(data))
        img.load()
    except Exception:
        raise Gagal(f"{label}: berkas bukan gambar yang bisa dibaca (gunakan JPG/PNG"
                    + ("/PDF" if boleh_pdf else "") + ")")
    img = ImageOps.exif_transpose(img).convert("RGB")
    img.thumbnail((sisi, sisi))
    nama = f"{jenis}.jpg"
    img.save(os.path.join(folder, nama), "JPEG", quality=85, optimize=True)
    return nama


def _penugasan(raw):
    try:
        daftar = json.loads(raw or "[]")
    except ValueError:
        raise Gagal("Data lokasi tugas tidak valid")
    if not isinstance(daftar, list) or not daftar:
        raise Gagal("Pilih minimal satu distrik lokasi tugas")
    w = wilayah()
    hasil, sudah = [], set()
    for item in daftar[:300]:
        kode = str(item.get("kode", ""))
        if kode not in w or kode in sudah:
            continue
        sudah.add(kode)
        alasan = [a for a in item.get("alasan", []) if a in ALASAN]
        lain = str(item.get("lainnya") or "").strip()[:200]
        kab, dis = w[kode]
        if not alasan and not lain:
            raise Gagal(f"Isi alasan untuk distrik {dis} ({kab})")
        hasil.append({"kode": kode, "kabupaten": kab, "distrik": dis,
                      "alasan": alasan, "lainnya": lain})
    if not hasil:
        raise Gagal("Pilih minimal satu distrik lokasi tugas")
    return hasil


def ada_pendaftar(nik):
    conn = get_db()
    try:
        return conn.execute("SELECT 1 FROM pendaftaran WHERE nik=?", (nik,)).fetchone() is not None
    finally:
        conn.close()


# Hostname publik (Cloudflare) hanya boleh mengakses halaman pendaftaran;
# admin & tes tetap LAN-only.
PUBLIK_HOSTS = {h.strip().lower() for h in os.environ.get("PUBLIK_DAFTAR_HOSTS", "").split(",") if h.strip()}
PUBLIK_PATH = ("/daftar", "/api/daftar", "/static/")


def host_publik():
    return request.host.split(":")[0].lower() in PUBLIK_HOSTS


@bp.before_app_request
def batasi_host_publik():
    if not host_publik():
        return None
    if request.path == "/":
        return redirect("/daftar")
    if request.path == "/daftar" or request.path == "/api/daftar" or (
            request.path.startswith("/static/") and not request.path.endswith(".map")):
        return None
    abort(404)


# ─── routes publik ───────────────────────────────────────────────────────────

@bp.route("/daftar")
def halaman_daftar():
    return render_template("daftar.html", publik=host_publik(), agama=AGAMA, status_kawin=STATUS_KAWIN,
                           pendidikan=PENDIDIKAN, jk=JK, alasan=ALASAN)


@bp.route("/api/daftar", methods=["POST"])
def kirim_daftar():
    if (request.content_length or 0) > MAKS_REQUEST:
        return jsonify({"ok": False, "msg": "Total ukuran berkas terlalu besar"}), 413
    f = request.form
    if f.get("website"):  # honeypot anti-bot
        return jsonify({"ok": True})
    try:
        nik = re.sub(r"\D", "", f.get("nik") or "")
        if len(nik) != 16:
            raise Gagal("NIK harus 16 digit angka")
        v = {
            "nik": nik,
            "nama": _teks(f, "nama", "Nama lengkap", maks=120),
            "tempat_lahir": _teks(f, "tempat_lahir", "Tempat lahir", maks=80),
            "tgl_lahir": _teks(f, "tgl_lahir", "Tanggal lahir", maks=10),
            "jns_kelamin": _pilih(f, "jns_kelamin", "Jenis kelamin", JK),
            "agama": _pilih(f, "agama", "Agama", AGAMA),
            "status_kawin": _pilih(f, "status_kawin", "Status perkawinan", STATUS_KAWIN),
            "pendidikan": _pilih(f, "pendidikan", "Pendidikan terakhir", PENDIDIKAN),
            "jurusan": _teks(f, "jurusan", "Jurusan", wajib=False, maks=100),
            "pekerjaan": _teks(f, "pekerjaan", "Pekerjaan", maks=100),
            "no_telp": _teks(f, "no_telp", "Nomor HP/WA", maks=20),
            "email": _teks(f, "email", "Email", wajib=False, maks=120),
            "alamat": _teks(f, "alamat", "Alamat rumah", maks=500),
            "bank": _teks(f, "bank", "Nama bank", maks=60),
            "no_rekening": re.sub(r"[\s.-]", "", f.get("no_rekening") or "") or None,
            "nama_rekening": _teks(f, "nama_rekening", "Nama pemilik rekening", maks=120),
            "hp_android": _yatidak(f, "hp_android", "Kepemilikan HP Android"),
            "punya_motor": _yatidak(f, "punya_motor", "Kepemilikan kendaraan bermotor"),
            "bisa_motor": _yatidak(f, "bisa_motor", "Bisa mengendarai motor"),
            "punya_laptop": _yatidak(f, "punya_laptop", "Mempunyai laptop"),
            "bisa_komputer": _yatidak(f, "bisa_komputer", "Bisa mengoperasikan komputer"),
            "esai_bps": _teks(f, "esai_bps", "Jawaban esai 1", maks=4000),
            "esai_kegiatan": _teks(f, "esai_kegiatan", "Jawaban esai 2", maks=4000),
        }
        try:
            datetime.strptime(v["tgl_lahir"], "%Y-%m-%d")
        except ValueError:
            raise Gagal("Tanggal lahir tidak valid")
        if not re.fullmatch(r"\+?[0-9 -]{8,20}", v["no_telp"]):
            raise Gagal("Nomor HP/WA tidak valid")
        if v["email"] and not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", v["email"]):
            raise Gagal("Format email tidak valid")
        if not v["no_rekening"] or not v["no_rekening"].isdigit() or not 6 <= len(v["no_rekening"]) <= 20:
            raise Gagal("Nomor rekening harus 6–20 digit angka")
        if v["hp_android"]:
            v["hp_merk"] = _teks(f, "hp_merk", "Merk HP", maks=40)
            v["hp_tipe"] = _teks(f, "hp_tipe", "Tipe HP", maks=60)
            ram = (f.get("hp_ram") or "").strip().replace(",", ".")
            try:
                v["hp_ram"] = float(ram) if ram else None
            except ValueError:
                raise Gagal("RAM HP harus angka (GB)")
            if v["hp_ram"] is not None and not 0 < v["hp_ram"] <= 64:
                raise Gagal("RAM HP tidak wajar (isi dalam GB, misal 4)")
        else:
            v["hp_merk"] = v["hp_tipe"] = v["hp_ram"] = None

        try:
            lat, lon = float(f.get("latitude")), float(f.get("longitude"))
        except (TypeError, ValueError):
            raise Gagal("Tagging lokasi GPS rumah wajib diisi")
        if not (-90 <= lat <= 90 and -180 <= lon <= 180):
            raise Gagal("Koordinat GPS tidak valid")
        v["latitude"], v["longitude"] = lat, lon
        try:
            v["akurasi_m"] = round(float(f.get("akurasi")), 1) if f.get("akurasi") else None
        except ValueError:
            v["akurasi_m"] = None
        v["sumber_lokasi"] = "gps" if f.get("sumber_lokasi") == "gps" else "manual"

        tugas = _penugasan(f.get("penugasan"))
        v["penugasan"] = json.dumps(tugas, ensure_ascii=False)

        if ada_pendaftar(nik):
            raise Gagal("NIK ini sudah terdaftar. Hubungi panitia jika perlu mengubah data.")

        for jenis in BERKAS:
            v[jenis] = _simpan_berkas(request.files.get(jenis), nik, jenis)
    except Gagal as e:
        if "nik" in locals() and len(nik) == 16 and not ada_pendaftar(nik):
            shutil.rmtree(os.path.join(BERKAS_DIR, nik), ignore_errors=True)
        return jsonify({"ok": False, "msg": str(e)}), 400

    v["ip"] = request.headers.get("CF-Connecting-IP") or request.headers.get(
        "X-Forwarded-For", request.remote_addr or "").split(",")[0].strip()
    kolom = list(v)
    conn = get_db()
    try:
        conn.execute("BEGIN IMMEDIATE")
        conn.execute(
            f"INSERT INTO pendaftaran ({','.join(kolom)}) VALUES ({','.join('?' * len(kolom))})",
            [v[k] for k in kolom])
        # Masukkan ke daftar peserta tes (kalau belum ada dari sinkron SOBAT)
        conn.execute("""
            INSERT OR IGNORE INTO peserta
            (nik, nama_lengkap, pendidikan, no_telp, email, jns_kelamin, tgl_lahir,
             pekerjaan, is_motor, is_naik_motor, ket_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        """, (nik, v["nama"], v["pendidikan"], v["no_telp"], v["email"], v["jns_kelamin"],
              v["tgl_lahir"], v["pekerjaan"], v["punya_motor"], v["bisa_motor"],
              "Daftar online"))
        conn.commit()
    except sqlite3.IntegrityError:
        conn.rollback()
        return jsonify({"ok": False, "msg": "NIK ini sudah terdaftar."}), 409
    finally:
        conn.close()
    return jsonify({"ok": True, "nama": v["nama"], "jumlah_lokasi": len(tugas)})


# ─── routes admin ────────────────────────────────────────────────────────────

def _cek_admin():
    tok = request.headers.get("X-Admin-Token", "")
    return bool(tok) and hmac.compare_digest(tok, current_app.config["ADMIN_TOKEN"])


@bp.route("/api/admin/pendaftar")
def admin_pendaftar():
    if not _cek_admin():
        return jsonify({"ok": False, "msg": "Unauthorized"}), 401
    conn = get_db()
    rows = conn.execute("""
        SELECT d.*,
          (SELECT MAX(skor) FROM sesi_ujian s WHERE s.nik=d.nik AND s.status='selesai') AS skor_tertinggi
        FROM pendaftaran d ORDER BY d.id
    """).fetchall()
    conn.close()
    out = []
    for r in rows:
        r = dict(r)
        r["penugasan"] = json.loads(r["penugasan"] or "[]")
        out.append(r)
    return jsonify(out)


@bp.route("/api/admin/pendaftar/<int:pid>/berkas/<jenis>")
def admin_berkas(pid, jenis):
    if not _cek_admin():
        return jsonify({"ok": False, "msg": "Unauthorized"}), 401
    if jenis not in BERKAS:
        return jsonify({"ok": False}), 404
    conn = get_db()
    r = conn.execute(f"SELECT nik, {jenis} FROM pendaftaran WHERE id=?", (pid,)).fetchone()
    conn.close()
    if not r or not r[jenis]:
        return jsonify({"ok": False}), 404
    path = os.path.join(BERKAS_DIR, r["nik"], os.path.basename(r[jenis]))
    if not os.path.exists(path):
        return jsonify({"ok": False}), 404
    return send_file(path, max_age=0)


@bp.route("/api/admin/pendaftar/<int:pid>", methods=["DELETE"])
def admin_hapus_pendaftar(pid):
    if not _cek_admin():
        return jsonify({"ok": False, "msg": "Unauthorized"}), 401
    conn = get_db()
    r = conn.execute("SELECT nik FROM pendaftaran WHERE id=?", (pid,)).fetchone()
    if not r:
        conn.close()
        return jsonify({"ok": False, "msg": "Tidak ditemukan"}), 404
    conn.execute("DELETE FROM pendaftaran WHERE id=?", (pid,))
    conn.commit()
    conn.close()
    folder = os.path.join(BERKAS_DIR, r["nik"])
    for jenis in BERKAS:
        for ext in (".jpg", ".pdf"):
            p = os.path.join(folder, jenis + ext)
            if os.path.exists(p):
                os.remove(p)
    try:
        os.rmdir(folder)
    except OSError:
        pass
    # Baris di tabel peserta sengaja tidak dihapus (bisa jadi sudah ikut tes).
    return jsonify({"ok": True})


@bp.route("/api/admin/pendaftar.csv")
def admin_pendaftar_csv():
    if not _cek_admin():
        return jsonify({"ok": False, "msg": "Unauthorized"}), 401
    conn = get_db()
    rows = conn.execute("""
        SELECT d.*,
          (SELECT MAX(skor) FROM sesi_ujian s WHERE s.nik=d.nik AND s.status='selesai') AS skor_tertinggi
        FROM pendaftaran d ORDER BY d.id
    """).fetchall()
    conn.close()
    yt = lambda x: "" if x is None else ("Ya" if x else "Tidak")
    buf = io.StringIO()
    w = csv.writer(buf)
    w.writerow(["No", "Waktu daftar", "NIK", "Nama", "Tempat lahir", "Tgl lahir", "JK", "Agama",
                "Status kawin", "Pendidikan", "Jurusan", "Pekerjaan", "No HP", "Email",
                "Alamat", "Latitude", "Longitude", "Akurasi (m)", "Sumber lokasi",
                "Bank", "No rekening", "Nama rekening",
                "HP Android", "Merk HP", "Tipe HP", "RAM (GB)", "Punya motor",
                "Bisa motor", "Punya laptop", "Bisa komputer",
                "Esai: tentang BPS & BPS Jayawijaya", "Esai: kegiatan BPS",
                "Lokasi tugas (alasan)", "Skor tes tertinggi"])
    for i, r in enumerate(rows, 1):
        tugas = json.loads(r["penugasan"] or "[]")
        lok = "; ".join(
            f"{t['kabupaten']} - {t['distrik']} ({', '.join(t['alasan'] + ([t['lainnya']] if t['lainnya'] else []))})"
            for t in tugas)
        w.writerow([i, r["dibuat"], "'" + r["nik"], r["nama"], r["tempat_lahir"], r["tgl_lahir"],
                    r["jns_kelamin"], r["agama"], r["status_kawin"], r["pendidikan"], r["jurusan"],
                    r["pekerjaan"], "'" + (r["no_telp"] or ""), r["email"], r["alamat"],
                    r["latitude"], r["longitude"], r["akurasi_m"], r["sumber_lokasi"],
                    r["bank"], "'" + (r["no_rekening"] or ""), r["nama_rekening"],
                    yt(r["hp_android"]), r["hp_merk"], r["hp_tipe"], r["hp_ram"],
                    yt(r["punya_motor"]), yt(r["bisa_motor"]), yt(r["punya_laptop"]),
                    yt(r["bisa_komputer"]), r["esai_bps"], r["esai_kegiatan"], lok,
                    r["skor_tertinggi"]])
    data = io.BytesIO(("﻿" + buf.getvalue()).encode("utf-8"))
    nama = f"pendaftar-{datetime.now():%Y%m%d-%H%M}.csv"
    return send_file(data, mimetype="text/csv", as_attachment=True, download_name=nama)
