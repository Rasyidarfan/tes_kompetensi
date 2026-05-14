#!/bin/bash
# Jalankan server Tes Kompetensi Mitra BPS
# Port default 8080 (hindari konflik AirPlay di macOS port 5000)
cd "$(dirname "$0")"

# Install dependensi jika belum
pip3 install -q -r requirements.txt

echo ""
echo "======================================"
echo "  TES KOMPETENSI MITRA BPS"
echo "  Jayawijaya — 2026"
echo "======================================"
echo ""
echo "  Peserta : http://<IP-SERVER>:8080/"
echo "  Admin   : http://<IP-SERVER>:8080/admin"
echo ""
echo "  Jaringan 1: 192.168.1.x:8080"
echo "  Jaringan 2: 192.168.3.x:8080"
echo ""
echo "  Tekan Ctrl+C untuk menghentikan"
echo "======================================"
echo ""

PORT=8080 python3 app.py
