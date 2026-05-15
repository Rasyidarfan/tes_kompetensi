<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
init_db();

$logged_in = false;
$error     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = $_POST['password'] ?? '';
    if (hash('sha256', $pw) === ADMIN_PASSWORD_HASH) {
        $logged_in = true;
    } else {
        $error = 'Password salah';
    }
}

// Jika sudah login, ambil semua data untuk render awal
$peserta_list = [];
$buckets      = array_fill(0, 11, 0);
$lulus        = 0;
$tidak_lulus  = 0;
$rata_waktu   = [];
$log_list     = [];

if ($logged_in) {
    $db = get_db();

    // Peserta + status sesi
    $res = $db->query("
        SELECT p.nik, p.nama_lengkap, p.no_telp, p.email, p.pekerjaan,
            (SELECT COUNT(*) FROM sesi_ujian s WHERE s.nik=p.nik) AS jml_sesi,
            (SELECT MAX(skor) FROM sesi_ujian s WHERE s.nik=p.nik AND s.status='selesai') AS skor_tertinggi,
            (SELECT status FROM sesi_ujian s WHERE s.nik=p.nik ORDER BY kesempatan DESC LIMIT 1) AS status_sesi
        FROM peserta p ORDER BY p.nama_lengkap
    ");
    while ($r = $res->fetchArray(SQLITE3_ASSOC)) $peserta_list[] = $r;

    // Distribusi nilai
    $skor_res = $db->query("SELECT skor FROM sesi_ujian WHERE status='selesai' AND skor IS NOT NULL");
    $skor_rows = [];
    while ($r = $skor_res->fetchArray(SQLITE3_NUM)) $skor_rows[] = $r[0];
    foreach ($skor_rows as $s) { $buckets[min((int)($s/10), 10)]++; }
    $lulus       = count(array_filter($skor_rows, fn($s) => $s >= 60));
    $tidak_lulus = count($skor_rows) - $lulus;

    // Rata-rata waktu per kategori
    $ses_res = $db->query("SELECT id, paket_soal FROM sesi_ujian WHERE status='selesai'");
    $soal_map = [];
    while ($row = $ses_res->fetchArray(SQLITE3_ASSOC)) {
        $paket = json_decode($row['paket_soal'], true) ?: [];
        foreach ($paket as $i => $q) $soal_map[$row['id'] . '_' . ($i+1)] = $q['kategori'] ?? 'Lainnya';
    }
    $jaw_res = $db->query("SELECT j.sesi_id, j.nomor_soal, j.waktu_jawab_detik FROM jawaban j JOIN sesi_ujian s ON j.sesi_id=s.id WHERE s.status='selesai' AND j.waktu_jawab_detik IS NOT NULL");
    $kat_waktu = [];
    while ($j = $jaw_res->fetchArray(SQLITE3_ASSOC)) {
        $kat = $soal_map[$j['sesi_id'] . '_' . $j['nomor_soal']] ?? 'Lainnya';
        $kat_waktu[$kat][] = (float)$j['waktu_jawab_detik'];
    }
    foreach ($kat_waktu as $k => $v) $rata_waktu[$k] = round(array_sum($v)/count($v), 1);

    // Log perubahan
    $log_res = $db->query("SELECT l.*, p.nama_lengkap FROM log_perubahan l LEFT JOIN peserta p ON l.nik=p.nik ORDER BY l.diubah_at DESC LIMIT 100");
    while ($r = $log_res->fetchArray(SQLITE3_ASSOC)) $log_list[] = $r;
}

function he(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Tes Kompetensi</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,Arial,sans-serif;background:#f1f5f9;color:#1e293b;font-size:14px}
a{color:inherit;text-decoration:none}
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-box{background:#fff;border-radius:12px;padding:36px 32px;width:100%;max-width:360px;box-shadow:0 4px 24px #0001}
.login-box h2{font-size:18px;font-weight:700;margin-bottom:4px;color:#1B2B6B}
.login-box p{color:#64748b;font-size:13px;margin-bottom:24px}
.login-box label{display:block;font-size:12px;font-weight:600;margin-bottom:6px;color:#475569}
.login-box input{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;outline:none}
.login-box input:focus{border-color:#1B2B6B}
.login-box button{margin-top:14px;width:100%;padding:11px;background:#1B2B6B;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
.login-box button:hover{background:#243485}
.err{color:#dc2626;font-size:12px;margin-top:10px}
.shell{display:flex;min-height:100vh}
.sidebar{width:200px;background:#1B2B6B;color:#fff;flex-shrink:0;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.sidebar .logo{padding:20px 16px 12px;font-weight:700;font-size:15px;border-bottom:1px solid #ffffff1a}
.sidebar .logo span{display:block;font-size:11px;font-weight:400;opacity:.6;margin-top:2px}
.nav-item{padding:10px 16px;cursor:pointer;border-left:3px solid transparent;font-size:13px;opacity:.75;transition:.15s}
.nav-item:hover{background:#ffffff12;opacity:1}
.nav-item.active{background:#ffffff18;border-left-color:#F5C518;opacity:1;font-weight:600}
.sidebar-foot{margin-top:auto;padding:12px 16px;border-top:1px solid #ffffff1a}
.sidebar-foot a{font-size:12px;opacity:.6;cursor:pointer}
.sidebar-foot a:hover{opacity:1}
.main{flex:1;overflow:auto}
.topbar{background:#fff;border-bottom:1px solid #e2e8f0;padding:14px 24px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:10}
.topbar h1{font-size:16px;font-weight:700;flex:1}
.topbar .badge{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:4px 10px;font-size:12px;color:#64748b}
.content{padding:20px 24px}
.panel{display:none}.panel.active{display:block}
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:#fff;border-radius:10px;padding:16px;border:1px solid #e2e8f0}
.stat-card .val{font-size:28px;font-weight:800;color:#1B2B6B}
.stat-card .lbl{font-size:11px;color:#64748b;margin-top:2px}
.stat-card.green .val{color:#16a34a}
.stat-card.amber .val{color:#d97706}
.stat-card.red .val{color:#dc2626}
.card{background:#fff;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:16px}
.card-head{padding:14px 16px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
th{background:#f8fafc;font-size:11px;font-weight:600;color:#64748b;padding:8px 12px;text-align:left;border-bottom:1px solid #e2e8f0;white-space:nowrap}
td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top}
tr:last-child td{border-bottom:none}
tr:hover td{background:#f8fafc}
.badge-status{display:inline-block;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600}
.s-selesai{background:#dcfce7;color:#16a34a}
.s-berlangsung{background:#fef3c7;color:#d97706}
.s-belum{background:#f1f5f9;color:#64748b}
.s-lulus{background:#dcfce7;color:#16a34a}
.s-gagal{background:#fee2e2;color:#dc2626}
.btn{padding:5px 12px;border-radius:6px;border:1px solid #cbd5e1;background:#fff;font-size:12px;cursor:pointer;font-weight:500}
.btn:hover{background:#f8fafc}
.btn-danger{border-color:#fca5a5;color:#dc2626}
.btn-danger:hover{background:#fee2e2}
.btn-primary{background:#1B2B6B;color:#fff;border-color:#1B2B6B}
.btn-primary:hover{background:#243485}
.search-wrap{padding:12px 16px;border-bottom:1px solid #f1f5f9}
.search-wrap input{width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none}
.search-wrap input:focus{border-color:#1B2B6B}
.charts-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.chart-full{grid-column:1/-1}
.chart-card{background:#fff;border-radius:10px;border:1px solid #e2e8f0;padding:16px}
.chart-card h3{font-size:12px;font-weight:700;color:#64748b;margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px}
.chart-wrap{position:relative;height:200px}
.chart-wrap-sm{position:relative;height:180px;max-width:200px;margin:0 auto}
.form-row{padding:16px;display:flex;flex-direction:column;gap:10px}
.form-row label{font-size:12px;font-weight:600;color:#475569}
.form-row input,.form-row select{padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none}
.form-row input:focus{border-color:#1B2B6B}
.msg{padding:10px 14px;border-radius:8px;font-size:13px;display:none}
.msg.ok{background:#dcfce7;color:#16a34a;display:block}
.msg.err{background:#fee2e2;color:#dc2626;display:block}
.modal-bg{display:none;position:fixed;inset:0;background:#0006;z-index:100;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal{background:#fff;border-radius:12px;padding:24px;width:100%;max-width:520px;max-height:85vh;overflow-y:auto}
.modal h3{font-size:15px;font-weight:700;margin-bottom:12px}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
.detail-table td:first-child{color:#64748b;font-size:12px;width:120px}
.rekap-toolbar{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap}
.rekap-toolbar input,.rekap-toolbar select{padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;background:#fff}
.rekap-count{font-size:12px;color:#64748b;margin-left:auto}
.rekap-grid{display:flex;flex-direction:column;gap:0}
.rekap-row{border-bottom:1px solid #f1f5f9}
.rekap-row:last-child{border-bottom:none}
.rekap-head{display:grid;grid-template-columns:1fr auto;align-items:center;gap:12px;padding:12px 16px;cursor:pointer;transition:.12s}
.rekap-head:hover{background:#f8fafc}
.rekap-head-left{display:flex;align-items:center;gap:10px;min-width:0}
.rekap-nama{font-weight:700;font-size:13px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rekap-nik{font-size:11px;color:#94a3b8;font-family:monospace;white-space:nowrap}
.rekap-ke{font-size:11px;background:#f1f5f9;color:#64748b;padding:2px 7px;border-radius:99px;white-space:nowrap}
.rekap-skor-big{font-family:system-ui;font-weight:800;font-size:22px;color:#1B2B6B;white-space:nowrap}
.rekap-skor-big.lulus{color:#16a34a}.rekap-skor-big.gagal{color:#dc2626}
.rekap-meta{font-size:11px;color:#94a3b8;display:flex;gap:8px;flex-wrap:wrap;margin-top:2px}
.rekap-chevron{font-size:13px;color:#94a3b8;transition:transform .2s;margin-left:8px;flex-shrink:0}
.rekap-row.open .rekap-chevron{transform:rotate(90deg)}
.rekap-detail{display:none;padding:0 16px 14px}
.rekap-row.open .rekap-detail{display:block}
.kat-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:4px}
.kat-table th{background:#f8fafc;font-size:11px;font-weight:600;color:#64748b;padding:6px 10px;text-align:left;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.kat-table td{padding:7px 10px;border-bottom:1px solid #f8fafc;vertical-align:middle}
.kat-table tr:last-child td{border-bottom:none}
.pct-bar-wrap{width:90px;height:7px;background:#e2e8f0;border-radius:99px;overflow:hidden;display:inline-block;vertical-align:middle;margin-right:6px}
.pct-bar{height:100%;border-radius:99px;background:#1B2B6B}
.pct-bar.good{background:#16a34a}.pct-bar.mid{background:#d97706}.pct-bar.bad{background:#dc2626}
.waktu-chip{display:inline-block;background:#f1f5f9;color:#475569;padding:2px 7px;border-radius:99px;font-size:11px;font-weight:600}
.export-btn{display:flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;font-size:12px;font-weight:600;cursor:pointer;color:#1B2B6B;transition:.12s}
.export-btn:hover{background:#eff6ff;border-color:#1B2B6B}
.conflict-item{border:1px solid #fde68a;border-radius:8px;background:#fffbeb;padding:12px 14px;font-size:12px}
.conflict-item .ci-kat{font-size:11px;font-weight:700;color:#d97706;margin-bottom:4px}
.conflict-item .ci-q{font-weight:600;color:#1e293b;margin-bottom:8px;line-height:1.4}
.conflict-cols{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px}
.conflict-col{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:8px 10px}
.conflict-col .cc-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:4px}
.conflict-col .cc-val{font-size:12px;color:#475569;line-height:1.4}
.conflict-col .cc-time{font-size:11px;color:#94a3b8;margin-top:4px}
.conflict-radios{display:flex;gap:12px}
.conflict-radios label{font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px;cursor:pointer}
.conflict-radios input[type=radio]{width:auto}
.soal-toolbar{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid #f1f5f9;flex-wrap:wrap}
.soal-toolbar select{padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;background:#fff}
.soal-toolbar select:focus{border-color:#1B2B6B}
.soal-count{font-size:12px;color:#64748b;margin-left:auto}
.soal-item{border-bottom:1px solid #f1f5f9;padding:14px 16px;transition:.12s}
.soal-item:last-child{border-bottom:none}
.soal-item:hover{background:#fafafa}
.soal-meta{display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap}
.soal-num{font-size:11px;font-weight:700;color:#94a3b8;min-width:28px}
.soal-kat{font-size:11px;font-weight:600;background:#eff6ff;color:#1d4ed8;padding:2px 8px;border-radius:99px}
.soal-pertanyaan{font-size:13px;font-weight:600;color:#1e293b;margin-bottom:8px;line-height:1.5}
.soal-pilihan{display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;margin-bottom:6px}
.soal-opt{font-size:12px;color:#475569;display:flex;gap:5px;align-items:baseline}
.soal-opt .huruf{font-weight:700;color:#94a3b8;min-width:14px}
.soal-opt.kunci .huruf{color:#16a34a}
.soal-opt.kunci{color:#15803d;font-weight:600}
.soal-actions{display:flex;gap:6px;margin-top:8px}
.soal-edit-form{display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-top:10px}
.soal-edit-form.open{display:block}
.ef-group{margin-bottom:10px}
.ef-group label{display:block;font-size:11px;font-weight:600;color:#475569;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px}
.ef-group input,.ef-group textarea{width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;font-family:inherit}
.ef-group textarea{resize:vertical;min-height:64px;line-height:1.5}
.ef-group input:focus,.ef-group textarea:focus{border-color:#1B2B6B}
.ef-pilihan{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.ef-opt{display:flex;align-items:center;gap:6px}
.ef-opt span{font-size:12px;font-weight:700;color:#64748b;min-width:16px}
.ef-kunci-row{display:flex;gap:6px;align-items:center}
.ef-kunci-row label{font-size:12px;margin:0;text-transform:none;letter-spacing:0;display:flex;align-items:center;gap:4px;cursor:pointer}
.ef-kunci-row input[type=radio]{width:auto}
.ef-foot{display:flex;gap:8px;margin-top:10px}
.tambah-soal-btn{display:flex;align-items:center;gap:6px;padding:12px 16px;font-size:13px;font-weight:600;color:#1B2B6B;cursor:pointer;border-top:2px dashed #e2e8f0;background:#f8fafc;border-radius:0 0 10px 10px;transition:.12s}
.tambah-soal-btn:hover{background:#eff6ff}
.tambah-form{display:none;padding:14px 16px;background:#f8fafc;border-top:1px solid #e2e8f0}
.tambah-form.open{display:block}
/* Sedang Ujian */
.aktif-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;padding:16px}
.aktif-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px}
.aktif-card.urgent{border-color:#fca5a5;background:#fff5f5}
.aktif-nama{font-weight:700;font-size:14px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.aktif-nik{font-size:11px;color:#94a3b8;font-family:monospace;margin-bottom:10px}
.aktif-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.aktif-timer{font-size:22px;font-weight:800;color:#1B2B6B;letter-spacing:.5px}
.aktif-timer.urgent{color:#dc2626}
.aktif-progress-wrap{background:#f1f5f9;border-radius:99px;height:6px;margin-bottom:10px;overflow:hidden}
.aktif-progress{height:6px;border-radius:99px;background:#1B2B6B;transition:width .4s}
.aktif-soal-info{font-size:11px;color:#64748b;margin-bottom:10px}
.aktif-actions{display:flex;flex-wrap:wrap;gap:5px}
.aktif-actions .btn{font-size:11px;padding:4px 9px}
.bulk-bar{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.bulk-bar span{font-size:12px;color:#64748b;font-weight:600;margin-right:4px}
#nav-aktif-badge{background:#F5C518;color:#1B2B6B;border-radius:99px;font-size:10px;font-weight:800;padding:1px 6px;margin-left:4px;display:none}
@media(max-width:768px){.shell{flex-direction:column}.sidebar{width:100%;height:auto;position:static;flex-direction:row;flex-wrap:wrap;padding:8px}.sidebar .logo{display:none}.nav-item{padding:8px 12px;border-left:none;border-bottom:3px solid transparent;font-size:12px}.nav-item.active{border-bottom-color:#F5C518;border-left-color:transparent}.sidebar-foot{display:none}.content{padding:12px}.stat-row{grid-template-columns:1fr 1fr}.charts-grid{grid-template-columns:1fr}.chart-full{grid-column:1}.topbar{padding:10px 14px}}
@media(max-width:480px){.stat-row{grid-template-columns:1fr 1fr}.tbl-wrap{font-size:12px}}
</style>
</head>
<body>

<?php if (!$logged_in): ?>
<div class="login-wrap">
  <div class="login-box">
    <h2>Admin Panel</h2>
    <p>Tes Kompetensi Mitra BPS</p>
    <form method="POST" action="/admin">
      <label>Password</label>
      <input type="password" name="password" autofocus placeholder="Masukkan password">
      <button type="submit">Masuk</button>
      <?php if ($error): ?><div class="err"><?= he($error) ?></div><?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>
<div class="shell">
  <div class="sidebar">
    <div class="logo">Admin Panel <span>Tes Kompetensi BPS</span></div>
    <div class="nav-item active" onclick="tab('dashboard',this)">Dashboard</div>
    <div class="nav-item" onclick="tab('aktif',this)">Sedang Ujian <span id="nav-aktif-badge"></span></div>
    <div class="nav-item" onclick="tab('peserta',this)">Data Peserta</div>
    <div class="nav-item" onclick="tab('log',this)">Log Perubahan</div>
    <div class="nav-item" onclick="tab('rekap',this)">Rekap Nilai</div>
    <div class="nav-item" onclick="tab('soal',this)">Bank Soal</div>
    <div class="nav-item" onclick="tab('sinkron',this)">Sinkronisasi</div>
    <div class="sidebar-foot"><a href="/admin">Keluar</a></div>
  </div>

  <div class="main">
    <div class="topbar">
      <h1 id="topbar-title">Dashboard</h1>
      <span class="badge"><?= count($peserta_list) ?> peserta</span>
      <a href="/admin" class="btn">Keluar</a>
    </div>
    <div class="content">

      <!-- DASHBOARD -->
      <div class="panel active" id="tab-dashboard">
        <?php
          $total   = count($peserta_list);
          $selesai = count(array_filter($peserta_list, fn($p) => $p['status_sesi'] === 'selesai'));
          $aktif   = count(array_filter($peserta_list, fn($p) => in_array($p['status_sesi'], ['berlangsung','mulai'])));
          $belum   = $total - $selesai - $aktif;
        ?>
        <div class="stat-row">
          <div class="stat-card"><div class="val"><?= $total ?></div><div class="lbl">Total Peserta</div></div>
          <div class="stat-card green"><div class="val"><?= $selesai ?></div><div class="lbl">Selesai</div></div>
          <div class="stat-card amber"><div class="val"><?= $aktif ?></div><div class="lbl">Sedang Ujian</div></div>
          <div class="stat-card"><div class="val"><?= $belum ?></div><div class="lbl">Belum Mulai</div></div>
        </div>
        <div class="charts-grid">
          <div class="chart-card chart-full"><h3>Distribusi Nilai</h3><div class="chart-wrap"><canvas id="c-dist"></canvas></div></div>
          <div class="chart-card"><h3>Kelulusan (nilai ≥ 60)</h3><div class="chart-wrap-sm"><canvas id="c-pie"></canvas></div></div>
          <div class="chart-card"><h3>Rata-rata Waktu per Kategori (detik)</h3><div class="chart-wrap"><canvas id="c-waktu"></canvas></div></div>
        </div>
        <div class="card">
          <div class="card-head">Peserta Sedang Ujian</div>
          <div class="tbl-wrap"><table>
            <thead><tr><th>Nama</th><th>NIK</th><th>Ke-</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php $aktif_list = array_filter($peserta_list, fn($p) => in_array($p['status_sesi'], ['berlangsung','mulai'])); ?>
            <?php if ($aktif_list): ?>
              <?php foreach ($aktif_list as $p): ?>
              <tr>
                <td><strong><?= he($p['nama_lengkap'] ?? '-') ?></strong></td>
                <td style="font-family:monospace;font-size:12px"><?= he($p['nik']) ?></td>
                <td><?= (int)$p['jml_sesi'] ?></td>
                <td><button class="btn btn-danger" onclick="resetSesi('<?= he($p['nik']) ?>','<?= he(addslashes($p['nama_lengkap'] ?? '')) ?>')">Reset</button></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="4" style="color:#94a3b8;text-align:center;padding:20px">Tidak ada</td></tr>
            <?php endif; ?>
            </tbody>
          </table></div>
        </div>
      </div>

      <!-- SEDANG UJIAN -->
      <div class="panel" id="tab-aktif">
        <div class="card">
          <div class="card-head">Peserta Sedang Ujian <span id="aktif-count-badge" style="font-size:11px;font-weight:500;color:#64748b;margin-left:4px"></span>
            <button class="btn" style="margin-left:auto;font-size:11px" onclick="loadAktif()">↺ Refresh</button>
          </div>
          <div class="bulk-bar">
            <span>Semua:</span>
            <button class="btn" onclick="sesiCtrl('plus5_all')">+5 menit</button>
            <button class="btn" onclick="sesiCtrl('minus5_all')">−5 menit</button>
            <button class="btn" onclick="sesiCtrl('selesai5_all')">Selesai dalam 5 menit</button>
            <button class="btn btn-danger" onclick="sesiCtrl('reset_all')">Reset Semua</button>
          </div>
          <div id="aktif-grid" class="aktif-grid"><div style="padding:32px;text-align:center;color:#94a3b8;font-size:13px;grid-column:1/-1">Memuat…</div></div>
        </div>
      </div>

      <!-- PESERTA -->
      <div class="panel" id="tab-peserta">
        <div class="card" style="margin-bottom:12px">
          <div class="card-head">Tambah Peserta</div>
          <div style="padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <div><label style="display:block;font-size:11px;font-weight:600;color:#475569;margin-bottom:4px">NIK / ID</label>
              <input type="text" id="tambah-nik" placeholder="NIK atau Sobat ID" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;width:200px" onkeydown="if(event.key==='Enter')tambahPeserta()"></div>
            <div><label style="display:block;font-size:11px;font-weight:600;color:#475569;margin-bottom:4px">Nama Lengkap</label>
              <input type="text" id="tambah-nama" placeholder="Nama peserta" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;width:240px" onkeydown="if(event.key==='Enter')tambahPeserta()"></div>
            <button class="btn btn-primary" onclick="tambahPeserta()">+ Tambah</button>
            <span id="tambah-msg" style="font-size:12px;display:none"></span>
          </div>
        </div>
        <div class="card">
          <div class="card-head">Data Peserta (<span id="peserta-count"><?= count($peserta_list) ?></span>)</div>
          <div class="search-wrap"><input type="text" id="cari" placeholder="Cari nama atau NIK…" oninput="cariPeserta()"></div>
          <div class="tbl-wrap"><table id="tbl-peserta">
            <thead><tr><th>Nama</th><th>NIK</th><th>Sesi</th><th>Nilai</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($peserta_list as $p): ?>
            <tr data-nama="<?= he(strtolower($p['nama_lengkap'] ?? '')) ?>" data-nik="<?= he($p['nik']) ?>">
              <td><strong><?= he($p['nama_lengkap'] ?? '-') ?></strong><br><span style="font-size:11px;color:#94a3b8"><?= he($p['no_telp'] ?? '') ?></span></td>
              <td style="font-family:monospace;font-size:12px"><?= he($p['nik']) ?></td>
              <td><?= (int)$p['jml_sesi'] ?> / 2</td>
              <td>
                <?php if ($p['skor_tertinggi'] !== null): ?>
                  <strong><?= $p['skor_tertinggi'] ?></strong>
                  <span class="badge-status <?= $p['skor_tertinggi'] >= 60 ? 's-lulus' : 's-gagal' ?>"><?= $p['skor_tertinggi'] >= 60 ? 'Lulus' : 'Gagal' ?></span>
                <?php else: ?>-<?php endif; ?>
              </td>
              <td>
                <?php if (in_array($p['status_sesi'], ['berlangsung','mulai'])): ?>
                  <span class="badge-status s-berlangsung">Ujian</span>
                <?php elseif ($p['status_sesi'] === 'selesai'): ?>
                  <span class="badge-status s-selesai">Selesai</span>
                <?php else: ?>
                  <span class="badge-status s-belum">Belum</span>
                <?php endif; ?>
              </td>
              <td>
                <button class="btn" onclick="lihatDetail('<?= he($p['nik']) ?>')">Detail</button>
                <button class="btn btn-danger" onclick="resetSesi('<?= he($p['nik']) ?>','<?= he(addslashes($p['nama_lengkap'] ?? '')) ?>')">Reset</button>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
        </div>
      </div>

      <!-- LOG -->
      <div class="panel" id="tab-log">
        <div class="card">
          <div class="card-head">Log Perubahan Data (<?= count($log_list) ?> terakhir)</div>
          <div class="tbl-wrap"><table>
            <thead><tr><th>Waktu</th><th>Nama</th><th>Field</th><th>Lama</th><th>Baru</th></tr></thead>
            <tbody>
            <?php if ($log_list): ?>
              <?php foreach ($log_list as $l): ?>
              <tr>
                <td style="white-space:nowrap;font-size:11px;color:#94a3b8"><?= he($l['diubah_at']) ?></td>
                <td><?= he($l['nama_lengkap'] ?? $l['nik']) ?></td>
                <td><code><?= he($l['field']) ?></code></td>
                <td style="color:#94a3b8"><?= he($l['nilai_lama'] ?? '-') ?></td>
                <td><?= he($l['nilai_baru'] ?? '-') ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr><td colspan="5" style="color:#94a3b8;text-align:center;padding:20px">Belum ada perubahan</td></tr>
            <?php endif; ?>
            </tbody>
          </table></div>
        </div>
      </div>

      <!-- REKAP -->
      <div class="panel" id="tab-rekap">
        <div class="card">
          <div class="card-head">Rekap Nilai per Peserta <span id="rekap-total-badge" style="font-size:11px;font-weight:500;color:#64748b;margin-left:4px"></span>
            <button class="export-btn" style="margin-left:auto" onclick="eksporCSV()">⬇ Export CSV</button>
          </div>
          <div class="rekap-toolbar">
            <input type="text" id="rekap-search" placeholder="Cari nama atau NIK…" oninput="filterRekap()" style="min-width:180px">
            <select id="rekap-filter-lulus" onchange="filterRekap()"><option value="">Semua</option><option value="lulus">Lulus (≥60)</option><option value="gagal">Tidak Lulus</option></select>
            <select id="rekap-sort" onchange="filterRekap()"><option value="nama">Urut: Nama</option><option value="skor_desc">Urut: Nilai ↓</option><option value="skor_asc">Urut: Nilai ↑</option></select>
            <span class="rekap-count" id="rekap-count"></span>
          </div>
          <div id="rekap-grid" class="rekap-grid"><div style="padding:32px;text-align:center;color:#94a3b8;font-size:13px">Memuat data…</div></div>
        </div>
      </div>

      <!-- BANK SOAL -->
      <div class="panel" id="tab-soal">
        <div class="card">
          <div class="card-head">Bank Soal <span id="soal-total-badge" style="font-size:11px;font-weight:500;color:#64748b;margin-left:4px"></span>
            <div style="margin-left:auto;display:flex;gap:6px">
              <button class="export-btn" onclick="eksporSoal()">⬇ Export JSON</button>
              <label class="export-btn" style="cursor:pointer">⬆ Import JSON<input type="file" accept=".json" style="display:none" onchange="imporSoal(this)"></label>
            </div>
          </div>
          <div class="soal-toolbar">
            <select id="soal-filter-kat" onchange="filterSoal()"><option value="">Semua Kategori</option></select>
            <input type="text" id="soal-search" placeholder="Cari teks soal…" oninput="filterSoal()" style="padding:7px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;min-width:200px">
            <span class="soal-count" id="soal-count"></span>
          </div>
          <div id="soal-list-wrap"><div style="padding:32px;text-align:center;color:#94a3b8;font-size:13px">Memuat soal…</div></div>
          <div id="tambah-soal-wrap"></div>
        </div>
      </div>

      <!-- SINKRON -->
      <div class="panel" id="tab-sinkron">
        <div class="card">
          <div class="card-head">Sinkronisasi Data dari API BPS</div>
          <div class="form-row">
            <label>Bearer Token API BPS</label>
            <input type="text" id="syn-token" placeholder="eyJ...">
            <label>Filter Pegawai</label>
            <select id="syn-pegawai"><option value="1">Pegawai Aktif</option><option value="0">Non-Pegawai</option></select>
            <button class="btn btn-primary" onclick="doSync()">Sinkronkan Sekarang</button>
            <div id="syn-msg" class="msg"></div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Modal konflik import -->
<div class="modal-bg" id="conflict-modal-bg">
  <div class="modal" style="max-width:680px">
    <h3>Konflik Saat Import</h3>
    <p style="font-size:13px;color:#64748b;margin-bottom:14px">Soal berikut memiliki <strong>updated_at</strong> berbeda. Pilih tindakan untuk setiap soal.</p>
    <div id="conflict-list" style="display:flex;flex-direction:column;gap:10px;max-height:50vh;overflow-y:auto"></div>
    <div style="display:flex;gap:8px;margin-top:6px;padding-top:10px;border-top:1px solid #f1f5f9">
      <button class="btn" onclick="piliSemua('keep')">Pertahankan Semua DB</button>
      <button class="btn" onclick="piliSemua('replace')">Ganti Semua dengan File</button>
    </div>
    <div class="modal-foot">
      <button class="btn" onclick="tutupKonflik()">Batal</button>
      <button class="btn btn-primary" onclick="selesaikanImport()">Terapkan</button>
    </div>
  </div>
</div>

<!-- Modal detail -->
<div class="modal-bg" id="modal-bg" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <h3 id="modal-title">Detail Peserta</h3>
    <div id="modal-body"></div>
    <div class="modal-foot"><button class="btn" onclick="closeModal()">Tutup</button></div>
  </div>
</div>

<script src="/static/chart.umd.min.js"></script>
<script>
const ADMIN_TOKEN = <?= json_encode(ADMIN_TOKEN) ?>;
const DIST_BUCKETS = <?= json_encode($buckets) ?>;
const DIST_LABELS  = ["0-9","10-19","20-29","30-39","40-49","50-59","60-69","70-79","80-89","90-99","100"];
const LULUS        = <?= $lulus ?>;
const TDK_LULUS    = <?= $tidak_lulus ?>;
const RATA_WAKTU   = <?= json_encode($rata_waktu) ?>;

function tab(name, el) {
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('topbar-title').textContent = el.textContent.trim();
  if (name === 'soal'  && !_soalLoaded)  loadSoal();
  if (name === 'rekap' && !_rekapLoaded) loadRekap();
  if (name === 'aktif') {
    _tabAktif = true;
    loadAktif();
    if (!_tickInterval) _tickInterval = setInterval(_tick, 1000);
    if (!_syncInterval) _syncInterval = setInterval(_syncDanTutup, 300000);
  } else {
    _tabAktif = false;
    clearInterval(_tickInterval); _tickInterval = null;
    clearInterval(_syncInterval); _syncInterval = null;
  }
}

function cariPeserta() {
  const q = document.getElementById('cari').value.toLowerCase();
  document.querySelectorAll('#tbl-peserta tbody tr').forEach(tr => {
    tr.style.display = (tr.dataset.nama.includes(q) || tr.dataset.nik.includes(q)) ? '' : 'none';
  });
}

function lihatDetail(nik) {
  fetch('/api/admin/sesi/' + nik, { headers: { 'X-Admin-Token': ADMIN_TOKEN } })
    .then(r => r.json()).then(data => {
      document.getElementById('modal-title').textContent = 'Detail: ' + nik;
      if (!data.length) {
        document.getElementById('modal-body').innerHTML = '<p style="color:#94a3b8">Belum ada sesi ujian.</p>';
      } else {
        document.getElementById('modal-body').innerHTML = data.map(s => `
          <div style="margin-bottom:14px">
            <strong>Sesi ${s.kesempatan}</strong>
            <span class="badge-status ${s.status==='selesai'?'s-selesai':'s-berlangsung'}" style="margin-left:6px">${s.status}</span>
            <table class="detail-table" style="margin-top:8px;width:100%">
              <tr><td>Mulai</td><td>${s.mulai_at||'-'}</td></tr>
              <tr><td>Selesai</td><td>${s.selesai_at||'-'}</td></tr>
              <tr><td>Nilai</td><td><strong>${s.skor??'-'}</strong></td></tr>
              <tr><td>Dijawab</td><td>${s.jawaban.filter(j=>j.jawaban).length} / ${s.jawaban.length} soal</td></tr>
            </table>
          </div>`).join('<hr style="margin:12px 0;border:none;border-top:1px solid #f1f5f9">');
      }
      document.getElementById('modal-bg').classList.add('open');
    });
}
function closeModal() { document.getElementById('modal-bg').classList.remove('open'); }

function resetSesi(nik, nama) {
  if (!confirm('Reset sesi ' + nama + '?')) return;
  fetch('/api/admin/reset_sesi', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Admin-Token': ADMIN_TOKEN },
    body: JSON.stringify({ nik })
  }).then(r => r.json()).then(d => {
    if (d.ok) { alert('Reset berhasil.'); location.reload(); }
    else alert('Gagal: ' + d.msg);
  });
}

function doSync() {
  const token = document.getElementById('syn-token').value.trim();
  const pegawai = document.getElementById('syn-pegawai').value;
  const msg = document.getElementById('syn-msg');
  msg.className = 'msg'; msg.textContent = 'Menyinkronkan...'; msg.style.display = 'block';
  fetch('/api/admin/sync', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Admin-Token': ADMIN_TOKEN },
    body: JSON.stringify({ token, pegawai })
  }).then(r => r.json()).then(d => {
    if (d.ok) { msg.className = 'msg ok'; msg.textContent = `Berhasil: ${d.baru} peserta baru dari ${d.total} data.`; }
    else { msg.className = 'msg err'; msg.textContent = 'Gagal: ' + d.msg; }
  }).catch(() => { msg.className = 'msg err'; msg.textContent = 'Koneksi gagal.'; });
}

async function tambahPeserta() {
  const nik = document.getElementById('tambah-nik').value.trim();
  const nama = document.getElementById('tambah-nama').value.trim();
  const msg = document.getElementById('tambah-msg');
  if (!nik || !nama) { msg.style.display='inline';msg.style.color='#dc2626';msg.textContent='NIK dan nama wajib diisi.';return; }
  const resp = await fetch('/api/admin/peserta', { method:'POST', headers:{'Content-Type':'application/json','X-Admin-Token':ADMIN_TOKEN}, body:JSON.stringify({nik,nama}) });
  const d = await resp.json();
  if (d.ok) {
    const tbody = document.querySelector('#tbl-peserta tbody');
    const tr = document.createElement('tr');
    tr.dataset.nama = nama.toLowerCase(); tr.dataset.nik = nik;
    tr.innerHTML = `<td><strong>${nama}</strong></td><td style="font-family:monospace;font-size:12px">${nik}</td><td>0 / 2</td><td>-</td><td><span class="badge-status s-belum">Belum</span></td><td><button class="btn" onclick="lihatDetail('${nik}')">Detail</button><button class="btn btn-danger" onclick="resetSesi('${nik}','${nama.replace(/'/g,'')}')">Reset</button></td>`;
    tbody.insertBefore(tr, tbody.firstChild);
    const el = document.getElementById('peserta-count');
    if (el) el.textContent = parseInt(el.textContent) + 1;
    document.getElementById('tambah-nik').value = '';
    document.getElementById('tambah-nama').value = '';
    msg.style.display='inline';msg.style.color='#16a34a';msg.textContent=`✓ ${nama} berhasil ditambahkan.`;
    setTimeout(()=>{msg.style.display='none'},3000);
  } else { msg.style.display='inline';msg.style.color='#dc2626';msg.textContent=d.msg||'Gagal.'; }
}

// ── Rekap ──
let _rekapData=[], _rekapLoaded=false;
const KAT_SHORT={'Aritmatika Dasar':'Aritmatika','Analogi Verbal':'Analogi','Deret dan Pola Angka':'Deret','Aritmatika dan Numerik Lanjutan':'Aritmatika Lanjutan','Analogi Verbal Lanjutan':'Analogi Lanjutan','Matematika Terapan dan Soal Cerita':'Mat. Terapan','Penalaran Logis':'Penalaran','Pengetahuan BPS dan Statistik':'BPS & Statistik'};
async function loadRekap(){const resp=await fetch('/api/admin/rekap',{headers:{'X-Admin-Token':ADMIN_TOKEN}});_rekapData=await resp.json();_rekapLoaded=true;document.getElementById('rekap-total-badge').textContent=`(${_rekapData.length} sesi dari ${new Set(_rekapData.map(r=>r.nik)).size} peserta)`;filterRekap()}
function filterRekap(){const q=document.getElementById('rekap-search').value.toLowerCase();const lulus=document.getElementById('rekap-filter-lulus').value;const sort=document.getElementById('rekap-sort').value;let data=_rekapData.filter(r=>{const mQ=!q||r.nama.toLowerCase().includes(q)||r.nik.includes(q);const mL=!lulus||(lulus==='lulus'&&r.skor>=60)||(lulus==='gagal'&&r.skor<60);return mQ&&mL});if(sort==='skor_desc')data.sort((a,b)=>b.skor-a.skor);else if(sort==='skor_asc')data.sort((a,b)=>a.skor-b.skor);else data.sort((a,b)=>a.nama.localeCompare(b.nama));document.getElementById('rekap-count').textContent=`Menampilkan ${data.length} sesi`;renderRekap(data)}
function renderRekap(data){const g=document.getElementById('rekap-grid');if(!data.length){g.innerHTML='<div style="padding:32px;text-align:center;color:#94a3b8;font-size:13px">Tidak ada data.</div>';return}g.innerHTML=data.map(r=>buildRekapRow(r)).join('')}
function fmtDurasi(det){if(!det)return'-';const m=Math.floor(det/60),s=det%60;return`${m}m ${s}s`}
function buildRekapRow(r){const isLulus=r.skor>=60;const sc=isLulus?'lulus':'gagal';const ll=isLulus?'<span class="badge-status s-lulus">Lulus</span>':'<span class="badge-status s-gagal">Tidak Lulus</span>';const katRows=r.kategori.map(k=>{const bc=k.pct>=70?'good':k.pct>=40?'mid':'bad';return`<tr><td>${KAT_SHORT[k.kategori]||k.kategori}</td><td style="white-space:nowrap"><div class="pct-bar-wrap"><div class="pct-bar ${bc}" style="width:${k.pct}%"></div></div><strong>${k.benar}</strong>/<span style="color:#94a3b8">${k.total}</span></td><td style="text-align:right;color:${k.pct>=70?'#16a34a':k.pct>=40?'#d97706':'#dc2626'};font-weight:700">${k.pct}%</td><td style="text-align:right"><span class="waktu-chip">${k.rata_waktu!=null?k.rata_waktu+'s':'-'}</span></td></tr>`}).join('');const rowId=`rekap-${r.sesi_id}`;return`<div class="rekap-row" id="${rowId}"><div class="rekap-head" onclick="toggleRekap('${rowId}')"><div class="rekap-head-left"><div><div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap"><span class="rekap-nama">${r.nama}</span><span class="rekap-ke">Ke-${r.kesempatan}</span>${ll}</div><div class="rekap-meta"><span class="rekap-nik">${r.nik}</span>${r.mulai_at?`<span>${r.mulai_at.slice(0,16)}</span>`:''} ${r.durasi_detik?`<span>⏱ ${fmtDurasi(r.durasi_detik)}</span>`:''}</div></div></div><div style="display:flex;align-items:center;gap:4px"><div><div class="rekap-skor-big ${sc}">${r.skor??'-'}</div><div style="font-size:10px;color:#94a3b8;text-align:right">/ 100</div></div><span class="rekap-chevron">›</span></div></div><div class="rekap-detail"><table class="kat-table"><thead><tr><th>Kategori</th><th>Benar / Total</th><th style="text-align:right">%</th><th style="text-align:right">Rata Waktu</th></tr></thead><tbody>${katRows}</tbody></table></div></div>`}
function toggleRekap(rowId){document.getElementById(rowId).classList.toggle('open')}
function eksporCSV(){if(!_rekapData.length)return;const allKat=[...new Set(_rekapData.flatMap(r=>r.kategori.map(k=>k.kategori)))];const header=['Nama','NIK','Ke-','Skor','Lulus','Durasi(s)',...allKat.flatMap(k=>[`${k} Benar`,`${k} Total`,`${k} %`,`${k} WaktuRata`])];const rows=_rekapData.map(r=>{const km=Object.fromEntries(r.kategori.map(k=>[k.kategori,k]));return[r.nama,r.nik,r.kesempatan,r.skor,r.skor>=60?'Ya':'Tidak',r.durasi_detik??'',...allKat.flatMap(k=>{const s=km[k];return s?[s.benar,s.total,s.pct,s.rata_waktu??'']:['','','','']})]});const csv=[header,...rows].map(r=>r.map(v=>`"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob(['﻿'+csv],{type:'text/csv;charset=utf-8'}));a.download=`rekap_nilai_${new Date().toISOString().slice(0,10)}.csv`;a.click()}

// ── Bank Soal ──
let _soalData=[], _soalLoaded=false;
const HURUF=['a','b','c','d','e'];
async function loadSoal(){const resp=await fetch('/api/admin/soal',{headers:{'X-Admin-Token':ADMIN_TOKEN}});_soalData=await resp.json();_soalLoaded=true;const kat=[...new Set(_soalData.map(s=>s.kategori))];const sel=document.getElementById('soal-filter-kat');kat.forEach(k=>{const o=document.createElement('option');o.value=k;o.textContent=k;sel.appendChild(o)});renderSoalTambahBtn(kat);renderSoal()}
function filterSoal(){renderSoal()}
function renderSoal(){const kf=document.getElementById('soal-filter-kat').value;const q=document.getElementById('soal-search').value.toLowerCase();const filtered=_soalData.filter(s=>(!kf||s.kategori===kf)&&(!q||s.pertanyaan.toLowerCase().includes(q)||Object.values(s.pilihan).some(v=>v.toLowerCase().includes(q))));document.getElementById('soal-total-badge').textContent=`(${_soalData.length} soal total)`;document.getElementById('soal-count').textContent=`Menampilkan ${filtered.length} soal`;const wrap=document.getElementById('soal-list-wrap');if(!filtered.length){wrap.innerHTML='<div style="padding:32px;text-align:center;color:#94a3b8;font-size:13px">Tidak ada soal ditemukan.</div>';return}wrap.innerHTML=filtered.map((s,i)=>buildSoalItem(s,i)).join('')}
function buildSoalItem(s,di){const pH=HURUF.filter(h=>s.pilihan[h]).map(h=>{const ik=h===s.kunci_jawaban.toLowerCase();return`<div class="soal-opt${ik?' kunci':''}"><span class="huruf">${h.toUpperCase()}${ik?' ✓':''}.</span><span>${esc(s.pilihan[h])}</span></div>`}).join('');const ePI=HURUF.map(h=>`<div class="ef-opt"><span>${h.toUpperCase()}.</span><input type="text" id="ep-${s.id}-${h}" value="${esc(s.pilihan[h]||'')}"></div>`).join('');const kR=HURUF.map(h=>`<label><input type="radio" name="ep-kunci-${s.id}" value="${h.toUpperCase()}" ${s.kunci_jawaban.toLowerCase()===h?'checked':''}>${h.toUpperCase()}</label>`).join('');return`<div class="soal-item" id="soal-item-${s.id.replace(/:/g,'_')}"><div class="soal-meta"><span class="soal-num">${di+1}</span><span class="soal-kat">${esc(s.kategori)}</span>${s.paket?`<span style="font-size:11px;color:#94a3b8">Paket ${esc(s.paket)}</span>`:''}</div><div class="soal-pertanyaan">${esc(s.pertanyaan)}</div><div class="soal-pilihan">${pH}</div><div class="soal-actions"><button class="btn" onclick="toggleEditSoal('${s.id}')">✏ Edit</button><button class="btn btn-danger" onclick="hapusSoal('${s.id}','${esc(s.kategori)}')">Hapus</button></div><div class="soal-edit-form" id="ef-${s.id.replace(/:/g,'_')}"><div class="ef-group"><label>Pertanyaan</label><textarea id="ep-${s.id}-pertanyaan" rows="3">${esc(s.pertanyaan)}</textarea></div><div class="ef-group"><label>Pilihan Jawaban</label><div class="ef-pilihan">${ePI}</div></div><div class="ef-group"><label>Kunci Jawaban</label><div class="ef-kunci-row">${kR}</div></div><div class="ef-foot"><button class="btn btn-primary" onclick="simpanSoal('${s.id}')">Simpan</button><button class="btn" onclick="toggleEditSoal('${s.id}')">Batal</button></div></div></div>`}
function toggleEditSoal(id){document.getElementById('ef-'+id.replace(/:/g,'_')).classList.toggle('open')}
async function simpanSoal(id){const pertanyaan=document.getElementById(`ep-${id}-pertanyaan`).value.trim();const pilihan={};HURUF.forEach(h=>{const el=document.getElementById(`ep-${id}-${h}`);if(el)pilihan[h]=el.value.trim()});const kunci=document.querySelector(`input[name="ep-kunci-${id}"]:checked`)?.value||'A';const resp=await fetch(`/api/admin/soal/${encodeURIComponent(id)}`,{method:'PUT',headers:{'Content-Type':'application/json','X-Admin-Token':ADMIN_TOKEN},body:JSON.stringify({pertanyaan,pilihan,kunci_jawaban:kunci})});const d=await resp.json();if(d.ok){const idx=_soalData.findIndex(s=>s.id===id);if(idx>=0){_soalData[idx].pertanyaan=pertanyaan;_soalData[idx].pilihan=pilihan;_soalData[idx].kunci_jawaban=kunci;if(d.updated_at)_soalData[idx].updated_at=d.updated_at}renderSoal()}else alert('Gagal menyimpan: '+d.msg)}
async function hapusSoal(id,kat){if(!confirm(`Hapus soal dari "${kat}"?`))return;const resp=await fetch(`/api/admin/soal/${encodeURIComponent(id)}`,{method:'DELETE',headers:{'X-Admin-Token':ADMIN_TOKEN}});const d=await resp.json();if(d.ok){_soalLoaded=false;await loadSoal()}else alert('Gagal: '+d.msg)}
function renderSoalTambahBtn(kat){const wrap=document.getElementById('tambah-soal-wrap');const pI=HURUF.map(h=>`<div class="ef-opt"><span>${h.toUpperCase()}.</span><input type="text" id="ns-pil-${h}" placeholder="Pilihan ${h.toUpperCase()}"></div>`).join('');const kR=HURUF.map(h=>`<label><input type="radio" name="ns-kunci" value="${h.toUpperCase()}" ${h==='a'?'checked':''}>${h.toUpperCase()}</label>`).join('');const kO=kat.map(k=>`<option value="${esc(k)}">${esc(k)}</option>`).join('');wrap.innerHTML=`<div class="tambah-soal-btn" onclick="toggleTambahForm()">+ Tambah Soal Baru</div><div class="tambah-form" id="tambah-form"><div class="ef-group"><label>Kategori</label><select id="ns-kat" style="padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;width:100%">${kO}</select></div><div class="ef-group"><label>Pertanyaan</label><textarea id="ns-pertanyaan" rows="3" placeholder="Tulis pertanyaan…"></textarea></div><div class="ef-group"><label>Pilihan Jawaban</label><div class="ef-pilihan">${pI}</div></div><div class="ef-group"><label>Kunci Jawaban</label><div class="ef-kunci-row">${kR}</div></div><div class="ef-foot"><button class="btn btn-primary" onclick="tambahSoal()">Tambah</button><button class="btn" onclick="toggleTambahForm()">Batal</button></div><div id="ns-msg" class="msg" style="margin-top:10px"></div></div>`}
function toggleTambahForm(){document.getElementById('tambah-form').classList.toggle('open');document.getElementById('ns-msg').className='msg'}
async function tambahSoal(){const kat=document.getElementById('ns-kat').value;const pertanyaan=document.getElementById('ns-pertanyaan').value.trim();const pilihan={};HURUF.forEach(h=>{pilihan[h]=document.getElementById(`ns-pil-${h}`).value.trim()});const kunci=document.querySelector('input[name="ns-kunci"]:checked')?.value||'A';const msg=document.getElementById('ns-msg');if(!pertanyaan){msg.className='msg err';msg.textContent='Pertanyaan tidak boleh kosong.';return}const resp=await fetch(`/api/admin/soal/kategori/${encodeURIComponent(kat)}`,{method:'POST',headers:{'Content-Type':'application/json','X-Admin-Token':ADMIN_TOKEN},body:JSON.stringify({pertanyaan,pilihan,kunci_jawaban:kunci})});const d=await resp.json();if(d.ok){msg.className='msg ok';msg.textContent='Soal berhasil ditambahkan.';document.getElementById('ns-pertanyaan').value='';HURUF.forEach(h=>{document.getElementById(`ns-pil-${h}`).value=''});document.querySelector('input[name="ns-kunci"][value="A"]').checked=true;_soalLoaded=false;await loadSoal();document.getElementById('tambah-form').classList.add('open')}else{msg.className='msg err';msg.textContent='Gagal: '+d.msg}}
async function eksporSoal(){const resp=await fetch('/api/admin/soal/export',{headers:{'X-Admin-Token':ADMIN_TOKEN}});const blob=await resp.blob();const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download=`bank_soal_${new Date().toISOString().slice(0,10)}.json`;a.click()}
let _pendingImportData=null, _pendingConflicts=[];
async function imporSoal(inputEl){const file=inputEl.files[0];if(!file)return;inputEl.value='';let parsed;try{parsed=JSON.parse(await file.text())}catch{alert('File JSON tidak valid.');return}const resp=await fetch('/api/admin/soal/import',{method:'POST',headers:{'Content-Type':'application/json','X-Admin-Token':ADMIN_TOKEN},body:JSON.stringify({soal:parsed.soal??parsed,resolve:{}})});const d=await resp.json();if(d.conflicts&&d.conflicts.length>0){_pendingImportData=parsed;_pendingConflicts=d.conflicts;buildConflictModal(d.conflicts);document.getElementById('conflict-modal-bg').style.display='flex';return}if(d.ok){_soalLoaded=false;await loadSoal();alert(`Import selesai: ${(d.inserted||0)+(d.updated||0)} soal diproses.`)}else alert('Gagal import: '+(d.msg||'error'))}
function buildConflictModal(conflicts){document.getElementById('conflict-list').innerHTML=conflicts.map(c=>`<div class="conflict-item" id="ci-${c.id}"><div class="ci-kat">${esc(c.kategori)}</div><div class="conflict-cols"><div class="conflict-col"><div class="cc-label">Database</div><div class="cc-val">${esc(c.pertanyaan_db)}</div><div class="cc-time">${esc(c.updated_at_db)}</div></div><div class="conflict-col"><div class="cc-label">File Import</div><div class="cc-val">${esc(c.pertanyaan_import)}</div><div class="cc-time">${esc(c.updated_at_import)}</div></div></div><div class="conflict-radios"><label><input type="radio" name="cr-${c.id}" value="keep" checked> Pertahankan DB</label><label><input type="radio" name="cr-${c.id}" value="replace"> Ganti dengan File</label></div></div>`).join('')}
function piliSemua(action){_pendingConflicts.forEach(c=>{const el=document.querySelector(`input[name="cr-${c.id}"][value="${action}"]`);if(el)el.checked=true})}
async function selesaikanImport(){const resolve={};_pendingConflicts.forEach(c=>{const el=document.querySelector(`input[name="cr-${c.id}"]:checked`);resolve[c.id]=el?el.value:'keep'});const resp=await fetch('/api/admin/soal/import',{method:'POST',headers:{'Content-Type':'application/json','X-Admin-Token':ADMIN_TOKEN},body:JSON.stringify({soal:_pendingImportData.soal??_pendingImportData,resolve})});const d=await resp.json();tutupKonflik();if(d.ok){_soalLoaded=false;await loadSoal();alert(`Import selesai: ${(d.inserted||0)+(d.updated||0)} soal diproses.`)}else alert('Gagal: '+(d.msg||'error'))}
function tutupKonflik(){document.getElementById('conflict-modal-bg').style.display='none';_pendingImportData=null;_pendingConflicts=[]}

// ── Sedang Ujian ──
let _aktifState={}, _tickInterval=null, _syncInterval=null, _tabAktif=false;
function fmtTimer(det){if(det==null||det<0)det=0;const m=Math.floor(det/60).toString().padStart(2,'0');const s=(det%60).toString().padStart(2,'0');return`${m}:${s}`}
function _cardId(nik){return'aktif-card-'+nik.replace(/\W/g,'_')}
function _tick(){let adaHabis=false;for(const nik of Object.keys(_aktifState)){const p=_aktifState[nik];if(p.status==='berlangsung'&&p.sisa_detik>0)p.sisa_detik--;if(p.status==='berlangsung'&&p.sisa_detik<=0)adaHabis=true;_updateCard(nik)}if(adaHabis)_tutupHabisDanRefresh()}
function _updateCard(nik){const p=_aktifState[nik];if(!p)return;const urgent=p.sisa_detik<=300;const card=document.getElementById(_cardId(nik));if(!card)return;const tEl=card.querySelector('.aktif-timer');if(tEl){tEl.textContent=fmtTimer(p.sisa_detik);tEl.className='aktif-timer'+(urgent?' urgent':'')}card.className='aktif-card'+(urgent?' urgent':'');const btn5=card.querySelector('.btn-selesai5');if(btn5){btn5.disabled=p.sisa_detik<=300;btn5.style.opacity=p.sisa_detik<=300?'0.4':'';btn5.style.cursor=p.sisa_detik<=300?'not-allowed':'';btn5.title=p.sisa_detik<=300?'Sisa waktu sudah ≤ 5 menit':''}}
async function _tutupHabisDanRefresh(){await fetch('/api/admin/sesi_ctrl',{method:'POST',headers:{'Content-Type':'application/json','X-Admin-Token':ADMIN_TOKEN},body:JSON.stringify({aksi:'tutup_habis'})});loadAktif()}
async function _syncDanTutup(){await fetch('/api/admin/sesi_ctrl',{method:'POST',headers:{'Content-Type':'application/json','X-Admin-Token':ADMIN_TOKEN},body:JSON.stringify({aksi:'tutup_habis'})});loadAktif()}
async function loadAktif(){const resp=await fetch('/api/admin/aktif',{headers:{'X-Admin-Token':ADMIN_TOKEN}});const data=await resp.json();const badge=document.getElementById('nav-aktif-badge');if(data.length>0){badge.textContent=data.length;badge.style.display='inline'}else badge.style.display='none';document.getElementById('aktif-count-badge').textContent=data.length?`(${data.length} peserta)`:'';const grid=document.getElementById('aktif-grid');if(!data.length){_aktifState={};grid.innerHTML='<div style="padding:32px;text-align:center;color:#94a3b8;font-size:13px;grid-column:1/-1">Tidak ada peserta yang sedang ujian.</div>';return}const newNiks=new Set(data.map(p=>p.nik));for(const nik of Object.keys(_aktifState)){if(!newNiks.has(nik))delete _aktifState[nik]}for(const p of data){const ex=_aktifState[p.nik];if(!ex||p.sisa_detik<ex.sisa_detik||Math.abs(ex.sisa_detik-p.sisa_detik)>10){_aktifState[p.nik]={...p}}else _aktifState[p.nik]={...p,sisa_detik:ex.sisa_detik}}grid.innerHTML=data.map(p=>buildAktifCard(_aktifState[p.nik])).join('')}
function buildAktifCard(p){const urgent=p.sisa_detik<=300;const pct=Math.round(p.dijawab/30*100);const b5d=p.sisa_detik<=300;return`<div class="aktif-card${urgent?' urgent':''}" id="${_cardId(p.nik)}"><div class="aktif-nama">${esc(p.nama)}</div><div class="aktif-nik">${esc(p.nik)}</div><div class="aktif-row"><div><div style="font-size:10px;color:#94a3b8;margin-bottom:2px">SISA WAKTU</div><div class="aktif-timer${urgent?' urgent':''}">${fmtTimer(p.sisa_detik)}</div></div><div style="text-align:right"><div style="font-size:10px;color:#94a3b8;margin-bottom:2px">DIJAWAB</div><div style="font-weight:700;font-size:18px;color:#1B2B6B">${p.dijawab}<span style="font-size:12px;font-weight:400;color:#94a3b8">/30</span></div></div></div><div class="aktif-progress-wrap"><div class="aktif-progress" style="width:${pct}%;${urgent?'background:#dc2626':''}"></div></div><div class="aktif-soal-info">Ke-${p.kesempatan} · ${p.status==='berlangsung'?'Berlangsung':'Memuat soal…'}</div><div class="aktif-actions"><button class="btn" onclick="sesiCtrl('plus5','${esc(p.nik)}')">+5 min</button><button class="btn" onclick="sesiCtrl('minus5','${esc(p.nik)}')">−5 min</button><button class="btn btn-selesai5" onclick="sesiCtrl('selesai5','${esc(p.nik)}')" ${b5d?'disabled style="opacity:0.4;cursor:not-allowed"':''} title="${b5d?'Sisa waktu sudah ≤ 5 menit':''}">5 menit lagi</button><button class="btn btn-danger" onclick="sesiCtrl('reset','${esc(p.nik)}','${esc(p.nama)}')">Reset</button></div></div>`}
async function sesiCtrl(aksi,nik,nama){if(aksi==='reset'&&!confirm(`Reset sesi ${nama||nik}?`))return;if(aksi==='reset_all'&&!confirm('Reset SEMUA sesi yang sedang berlangsung?'))return;if(aksi==='selesai5_all'){const ada=Object.values(_aktifState).some(p=>p.sisa_detik>300);if(!ada){alert('Semua peserta sudah memiliki sisa waktu ≤ 5 menit.');return}}await fetch('/api/admin/sesi_ctrl',{method:'POST',headers:{'Content-Type':'application/json','X-Admin-Token':ADMIN_TOKEN},body:JSON.stringify({aksi,nik})});await loadAktif()}

function esc(str){return String(str??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;')}

// ── Charts ──
const navy='#1B2B6B',gold='#F5C518',green='#16a34a',red='#dc2626';
new Chart(document.getElementById('c-dist').getContext('2d'),{type:'bar',data:{labels:DIST_LABELS,datasets:[{label:'Peserta',data:DIST_BUCKETS,borderRadius:5,borderSkipped:false,backgroundColor:DIST_LABELS.map((_,i)=>i>=6?green:i>=4?gold:red)}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{precision:0},grid:{color:'#f1f5f9'}}}}});
new Chart(document.getElementById('c-pie').getContext('2d'),{type:'doughnut',data:{labels:['Lulus','Tidak Lulus'],datasets:[{data:[LULUS,TDK_LULUS],backgroundColor:[green,red],borderWidth:2,borderColor:'#fff',hoverOffset:4}]},options:{responsive:true,maintainAspectRatio:false,cutout:'60%',plugins:{legend:{position:'bottom',labels:{font:{size:11},padding:10}},tooltip:{callbacks:{label:ctx=>{const tot=LULUS+TDK_LULUS;return` ${ctx.parsed} (${tot?Math.round(ctx.parsed/tot*100):0}%)`}}}}}});
const wKat=Object.keys(RATA_WAKTU),wVal=Object.values(RATA_WAKTU),shorten=s=>s.length>22?s.slice(0,20)+'…':s;
new Chart(document.getElementById('c-waktu').getContext('2d'),{type:'bar',data:{labels:wKat.map(shorten),datasets:[{label:'Detik',data:wVal,backgroundColor:navy,borderRadius:4,borderSkipped:false}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{title:ctx=>wKat[ctx[0].dataIndex],label:ctx=>` ${ctx.parsed.x} detik`}}},scales:{x:{beginAtZero:true,grid:{color:'#f1f5f9'}},y:{grid:{display:false}}}}});
</script>
<?php endif; ?>
</body>
</html>
