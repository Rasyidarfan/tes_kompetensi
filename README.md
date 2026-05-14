# Tes Kompetensi Mitra BPS

Aplikasi ujian kompetensi berbasis web untuk mitra survei BPS. Dijalankan sebagai server lokal (Flask + Waitress) dan diakses peserta melalui browser di jaringan yang sama.

---

## Fitur

- **Peserta** — daftar dengan NIK, kerjakan ujian 30 soal dengan timer 45 menit, maksimal 2 kesempatan
- **Admin** — dashboard nilai, data peserta, rekap per-kategori, kelola bank soal (tambah/edit/hapus/export/import)
- **Bank soal** — disimpan di SQLite, diambil acak 1/3 per-kategori setiap sesi
- **QR Code** — halaman full-screen `/qr` untuk menampilkan URL server ke peserta
- **Auto port** — scan otomatis port 8000–8080 saat startup

---

## Struktur File

```
tes-kompetensi/
├── bank_soal.json          # Sumber soal awal (diimpor ke DB saat pertama jalan)
└── app/
    ├── app.py              # Aplikasi Flask utama
    ├── database.py         # Skema SQLite, logika soal & sesi
    ├── requirements.txt
    ├── app.spec            # Konfigurasi PyInstaller
    ├── build_windows.bat   # Script build Windows .exe
    ├── static/             # Asset statis (Chart.js, font, logo)
    └── templates/          # HTML (index, admin, qr)
```

File yang dibuat otomatis saat pertama kali dijalankan (di folder yang sama dengan `app.py` / `.exe`):

| File | Keterangan |
|---|---|
| `tes_kompetensi.db` | Database SQLite (peserta, sesi, jawaban, soal) |
| `.secret_key` | Kunci sesi Flask (dibuat acak sekali) |
| `admin_token.txt` | Token login admin |

---

## Menjalankan (Mode Development)

```bash
cd app
pip install -r requirements.txt
pip install waitress qrcode pillow
python3 app.py
```

Server otomatis memilih port tersedia di rentang 8000–8080. URL ditampilkan di terminal.

---

## Build Windows .exe

Jalankan di mesin Windows:

```bat
cd app
build_windows.bat
```

Output: `app/dist/TesKompetensi.exe`

Saat pertama kali dijalankan di komputer baru, letakkan `bank_soal.json` di folder yang sama dengan `.exe` agar soal otomatis diimpor ke database.

---

## Login Admin

1. Buka `http://<ip-server>:<port>/admin`
2. Token tersedia di file `admin_token.txt` di folder yang sama dengan `app.py` / `.exe`

---

## Konfigurasi

Edit `database.py` untuk mengubah parameter ujian:

```python
DURASI_DETIK  = 45 * 60   # Durasi ujian (detik)
MAX_KESEMPATAN = 2         # Maksimal percobaan per peserta
SOAL_PER_UJIAN = 30        # Jumlah soal per sesi (penyebut skor)
```

Urutan dan nama kategori soal ada di `KATEGORI_URUTAN`.

---

## Kategori Soal

1. Aritmatika Dasar
2. Analogi Verbal
3. Deret dan Pola Angka
4. Aritmatika dan Numerik Lanjutan
5. Analogi Verbal Lanjutan
6. Matematika Terapan dan Soal Cerita
7. Penalaran Logis
8. Pengetahuan BPS dan Statistik

---

## Import / Export Bank Soal

Di panel **Bank Soal** halaman admin:

- **Export JSON** — unduh semua soal sebagai file JSON
- **Import JSON** — unggah file JSON; jika ada soal dengan `id` yang sama tapi `updated_at` berbeda, muncul dialog konflik untuk memilih versi mana yang dipakai (per-soal atau sekaligus)
