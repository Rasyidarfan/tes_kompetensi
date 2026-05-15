<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
init_db();

header('Content-Type: text/html; charset=utf-8');
// Semua route diarahkan dari .htaccess ke sini
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ── API routes ────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($method === 'OPTIONS') { http_response_code(204); exit; }

if ($uri === '/api/login' && $method === 'POST') { require __DIR__ . '/api/login.php'; exit; }
if ($uri === '/api/ujian/mulai'  && $method === 'POST') { require __DIR__ . '/api/ujian_mulai.php';  exit; }
if ($uri === '/api/ujian/loaded' && $method === 'POST') { require __DIR__ . '/api/ujian_loaded.php'; exit; }
if ($uri === '/api/ujian/jawab'  && $method === 'POST') { require __DIR__ . '/api/ujian_jawab.php';  exit; }
if ($uri === '/api/ujian/selesai'&& $method === 'POST') { require __DIR__ . '/api/ujian_selesai.php';exit; }

if (preg_match('#^/api/peserta/[^/]+/edit$#', $uri) && $method === 'POST') {
    require __DIR__ . '/api/peserta_edit.php'; exit;
}

if ($uri === '/api/admin/peserta') {
    require __DIR__ . '/api/admin_peserta.php'; exit;
}
if ($uri === '/api/admin/aktif')    { require __DIR__ . '/api/admin_aktif.php';    exit; }
if ($uri === '/api/admin/log')      { require __DIR__ . '/api/admin_log.php';      exit; }
if ($uri === '/api/admin/sync' && $method === 'POST') { require __DIR__ . '/api/admin_sync.php'; exit; }
if ($uri === '/api/admin/reset_sesi' && $method === 'POST') { require __DIR__ . '/api/admin_reset_sesi.php'; exit; }
if ($uri === '/api/admin/sesi_ctrl' && $method === 'POST')  { require __DIR__ . '/api/admin_sesi_ctrl.php';  exit; }
if ($uri === '/api/admin/rekap')    { require __DIR__ . '/api/admin_rekap.php'; exit; }

// soal routes
if (preg_match('#^/api/admin/soal#', $uri)) {
    require __DIR__ . '/api/admin_soal.php'; exit;
}
if (preg_match('#^/api/admin/sesi/#', $uri)) {
    require __DIR__ . '/api/admin_sesi.php'; exit;
}

// ── Halaman Admin ─────────────────────────────────────────────────────────────
if ($uri === '/admin' || $uri === '/admin/') {
    require __DIR__ . '/admin.php'; exit;
}

// ── Halaman Peserta (root) ────────────────────────────────────────────────────
require __DIR__ . '/halaman_peserta.php';
