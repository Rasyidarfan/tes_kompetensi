# Tes Kompetensi Mitra BPS

Aplikasi ujian kompetensi berbasis web untuk mitra survei BPS. Dijalankan sebagai server lokal dan diakses peserta melalui browser di jaringan yang sama.

Tersedia dalam dua versi: **Python/Flask** (`app/`) dan **PHP** (`app-php/`), dengan fitur dan tampilan yang identik.

---

## Fitur

- **Peserta** — daftar dengan NIK, kerjakan ujian 30 soal dengan timer 45 menit, maksimal 2 kesempatan
- **Admin** — dashboard nilai, data peserta, rekap per-kategori, kelola bank soal (tambah/edit/hapus/export/import)
- **Sedang Ujian** — pantau sisa waktu peserta aktif secara real-time, tambah/kurangi waktu, percepat selesai
- **Bank soal** — disimpan di SQLite, diambil acak 1/3 per-kategori setiap sesi
- **Sinkronisasi** — impor data peserta dari API BPS (mitra-api.bps.go.id)
- **QR Code** — halaman full-screen `/qr` untuk menampilkan URL server ke peserta *(Flask only)*
- **Auto port** — scan otomatis port 8000–8080 saat startup *(Flask only)*
- **Pendaftaran calon mitra** — form `/daftar`: biodata, alamat + tagging GPS, pasfoto/KTP/ijazah, rekening bank, perangkat (HP/motor/laptop), 2 pertanyaan esai, dan pilihan lokasi tugas per distrik (Jayawijaya, Mamberamo Tengah, Yalimo) beserta alasannya. Pendaftar otomatis masuk daftar peserta tes. Admin: menu *Pendaftar Online* (detail, berkas, hapus, unduh CSV) *(Flask only)*

---

## Struktur File

```
tes-kompetensi/
├── bank_soal.json          # Sumber soal awal (diimpor ke DB saat pertama jalan)
├── README.md
│
├── app/                    # Versi Python/Flask
│   ├── app.py              # Aplikasi Flask utama (route + logika)
│   ├── database.py         # Skema SQLite, logika soal & sesi
│   ├── requirements.txt
│   ├── app.spec            # Konfigurasi PyInstaller
│   ├── build_windows.bat   # Script build Windows .exe
│   ├── static/             # Asset statis (Chart.js, font, logo)
│   └── templates/          # HTML (index, admin, qr)
│
└── app-php/                # Versi PHP
    ├── index.php           # Router utama (semua request masuk ke sini)
    ├── db.php              # Database layer (SQLite3)
    ├── auth.php            # Token admin & helper
    ├── .htaccess           # Routing Apache (mod_rewrite)
    ├── router.php          # Router untuk php -S (built-in server)
    ├── halaman_peserta.php # Frontend peserta
    ├── admin.php           # Panel admin
    ├── api/                # Endpoint API (15 file)
    └── static/             # Symlink ke app/static/
```

File yang dibuat otomatis saat pertama kali dijalankan:

| File | Keterangan |
|---|---|
| `tes_kompetensi.db` | Database SQLite (peserta, sesi, jawaban, soal) |
| `.secret_key` | Kunci token admin (dibuat acak sekali) |
| `admin_token.txt` | Token BPS API terakhir dipakai (opsional) |

---

## Versi Python/Flask

### Menjalankan (Development)

```bash
cd app
pip install -r requirements.txt
pip install waitress qrcode pillow
python3 app.py
```

Server otomatis memilih port tersedia di rentang 8000–8080. URL ditampilkan di terminal.

### Build Windows .exe

Jalankan di mesin Windows:

```bat
cd app
build_windows.bat
```

Output: `app/dist/TesKompetensi.exe`

Saat pertama kali dijalankan di komputer baru, letakkan `bank_soal.json` di folder yang sama dengan `.exe` agar soal otomatis diimpor ke database.

---

## Versi PHP

### Kebutuhan

- PHP 8.1+ dengan ekstensi `sqlite3`
- Apache dengan `mod_rewrite` **atau** PHP built-in server

### Menjalankan dengan PHP built-in server

```bash
cd app-php
php -S 0.0.0.0:8080 router.php
```

### Menjalankan dengan Apache

Letakkan folder `app-php/` di dalam `DocumentRoot` (misalnya `/var/www/html/`), pastikan `mod_rewrite` aktif dan `AllowOverride All` diset untuk direktori tersebut. File `.htaccess` menangani routing secara otomatis.

### Membuat symlink static assets (jika belum ada)

```bash
cd app-php
ln -s ../app/static static
```

---

## Login Admin

1. Buka `http://<ip-server>:<port>/admin`
2. Masukkan password (default: `1qazxsw2`)

Token admin di-*inject* langsung ke halaman setelah login berhasil — tidak perlu melihat file `admin_token.txt`.

---

## Konfigurasi

Edit konstanta berikut untuk mengubah parameter ujian:

**Python** — `database.py`:
```python
DURASI_DETIK   = 45 * 60   # Durasi ujian (detik)
MAX_KESEMPATAN = 2          # Maksimal percobaan per peserta
SOAL_PER_UJIAN = 30         # Jumlah soal per sesi (penyebut skor)
```

**PHP** — `app-php/db.php`:
```php
define('DURASI_DETIK',   45 * 60);
define('MAX_KESEMPATAN', 2);
define('SOAL_PER_UJIAN', 30);
```

**Pendaftaran** — `pendaftaran.py`:
- Daftar kabupaten/distrik pilihan lokasi tugas ada di `static/wilayah.json` (kode Kemendagri; sumber wilayah.id).
- Berkas pendaftar disimpan di folder `berkas_pendaftar/<NIK>/` di samping database.
- Env `PUBLIK_DAFTAR_HOSTS` (opsional, dipisah koma): hostname publik yang **hanya** boleh mengakses `/daftar`
  (admin & tes jadi 404 di hostname itu). Contoh: `PUBLIK_DAFTAR_HOSTS=rekrutmen.bps9702.com`.
- Tombol GPS butuh halaman https (atau localhost); lewat http biasa peserta mengisi koordinat manual.
- Opsional: `pip install pillow-heif` agar foto HEIC dari iPhone bisa dibaca.

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
