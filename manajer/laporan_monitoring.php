<?php
// manajer/laporan_monitoring.php
// LAPORAN MONITORING: Fokus Murni pada ALUR VERIFIKASI & SLA (Menunggu, Diverifikasi, Disetujui, Ditolak, Backlog SLA).
require_once '../config/koneksi.php';
require_once '../includes/header.php';

// Validate role
if ($_SESSION['role'] !== 'manajer') {
    header("Location: ../index.php");
    exit;
}

$error = "";
$nama = $_SESSION['nama'] ?? 'Manajer';

// 1. Fetch Mandor & Karyawan for Filter Dropdowns
try {
    $stmt = $pdo->query("SELECT id_mandor, nama FROM mandor ORDER BY nama ASC");
    $foremen = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT id_karyawan, nama FROM karyawan ORDER BY nama ASC");
    $employees = $stmt->fetchAll();
} catch (\PDOException $e) {
    $error = "Error: " . $e->getMessage();
}

// 2. Capture filters
$start_date   = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date     = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$filter_mandor    = isset($_GET['id_mandor']) ? (int)$_GET['id_mandor'] : 0;
$filter_karyawan  = isset($_GET['id_karyawan']) ? (int)$_GET['id_karyawan'] : 0;
$filter_status    = isset($_GET['status']) ? trim($_GET['status']) : '';

$query_str = "
    SELECT r.id, r.status, r.catatan_mandor, r.catatan_manajer,
           r.tanggal_verifikasi_mandor, r.tanggal_verifikasi_manajer, r.jumlah_realisasi, r.potongan_penalti, r.bonus_diterima, r.catatan_karyawan, r.foto_bukti,
           a.tanggal, a.aktivitas, a.target_jumlah, a.unit,
           k.nama as nama_karyawan, m.nama as nama_mandor
    FROM work_reports r
    JOIN assignments a ON r.id_assignment = a.id
    JOIN karyawan k ON r.id_karyawan = k.id_karyawan
    JOIN mandor m ON a.id_mandor = m.id_mandor
    WHERE 1=1
";
$params = [];

if (!empty($start_date)) { $query_str .= " AND a.tanggal >= ?"; $params[] = $start_date; }
if (!empty($end_date))   { $query_str .= " AND a.tanggal <= ?"; $params[] = $end_date; }
if ($filter_mandor > 0)  { $query_str .= " AND a.id_mandor = ?"; $params[] = $filter_mandor; }
if ($filter_karyawan > 0){ $query_str .= " AND a.id_karyawan = ?"; $params[] = $filter_karyawan; }
if (!empty($filter_status)) { $query_str .= " AND r.status = ?"; $params[] = $filter_status; }

$query_str .= " ORDER BY a.tanggal DESC, r.created_at DESC";

$reports = [];
try {
    $stmt = $pdo->prepare($query_str);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
} catch (\PDOException $e) {
    $error = "Gagal memuat laporan: " . $e->getMessage();
}

// 3. Ringkasan jumlah laporan PER STATUS
$status_labels = [
    'pending_mandor'        => 'Menunggu Mandor',
    'verified_by_mandor'    => 'Terverifikasi Mandor',
    'approved'              => 'Disetujui',
    'rejected'              => 'Ditolak',
    'pending_manajer_tolak' => 'Tinjauan Sanksi',
];
$status_counts = array_fill_keys(array_keys($status_labels), 0);
foreach ($reports as $rep) {
    if (isset($status_counts[$rep['status']])) {
        $status_counts[$rep['status']]++;
    }
}
$total_reports = count($reports);

// 4. ANALISIS SLA & BACKLOG (Laporan Tertahan)
$sla_hari_mandor  = 2;
$sla_hari_manajer = 2;
$today = new DateTime();
$tindak_lanjut = [];
$total_tindak_lanjut = 0;

foreach ($reports as $rep) {
    $hari = 0;
    $tahap = '';
    $mulai = null;

    if ($rep['status'] === 'pending_mandor') {
        $mulai = $rep['tanggal'];
        $ambang = $sla_hari_mandor;
        $tahap = 'Menunggu verifikasi Mandor';
    } elseif ($rep['status'] === 'verified_by_mandor' && !empty($rep['tanggal_verifikasi_mandor'])) {
        $mulai = $rep['tanggal_verifikasi_mandor'];
        $ambang = $sla_hari_manajer;
        $tahap = 'Menunggu persetujuan Manajer';
    } elseif ($rep['status'] === 'pending_manajer_tolak') {
        $mulai = $rep['tanggal_verifikasi_mandor'] ?: $rep['tanggal'];
        $ambang = $sla_hari_manajer;
        $tahap = 'Menunggu tinjauan sanksi Manajer';
    }

    if ($mulai !== null) {
        $hari = (int)$today->diff(new DateTime($mulai))->days;
        if ($hari >= $ambang) {
            $rep['hari_tertahan'] = $hari;
            $rep['tahap_tertahan'] = $tahap;
            $mandor_key = $rep['nama_mandor'];
            if (!isset($tindak_lanjut[$mandor_key])) {
                $tindak_lanjut[$mandor_key] = [];
            }
            $tindak_lanjut[$mandor_key][] = $rep;
            $total_tindak_lanjut++;
        }
    }
}

// 5. QUICK FILTER "HARI INI"
$filter_today = isset($_GET['quick_today']) && $_GET['quick_today'] === '1';

function build_qs($overrides = [], $remove = []) {
    $qs = $_GET;
    foreach ($remove as $r) { unset($qs[$r]); }
    foreach ($overrides as $k => $v) { $qs[$k] = $v; }
    return htmlspecialchars('?' . http_build_query($qs));
}

// 6. DATA PENGAWASAN MANDOR
$sup_where = "1=1";
$sup_params = [];
if ($filter_today) {
    $sup_where .= " AND a.tanggal = CURDATE()";
} else {
    if (!empty($start_date)) { $sup_where .= " AND a.tanggal >= ?"; $sup_params[] = $start_date; }
    if (!empty($end_date))   { $sup_where .= " AND a.tanggal <= ?"; $sup_params[] = $end_date; }
}
if ($filter_mandor > 0) { $sup_where .= " AND a.id_mandor = ?"; $sup_params[] = $filter_mandor; }

$mandor_stats = [];
try {
    $sql_sup = "
        SELECT a.id, a.tanggal, a.aktivitas, a.target_jumlah, a.unit,
               a.id_mandor, a.id_karyawan, k.nama as nama_karyawan, m.nama as nama_mandor,
               r.id as report_id, r.status as report_status, r.jumlah_realisasi, r.foto_bukti,
               r.catatan_karyawan, r.catatan_mandor, r.tanggal_verifikasi_mandor,
               r.catatan_manajer, r.tanggal_verifikasi_manajer, r.bonus_diterima
        FROM assignments a
        JOIN karyawan k ON a.id_karyawan = k.id_karyawan
        JOIN mandor m ON a.id_mandor = m.id_mandor
        LEFT JOIN work_reports r ON r.id_assignment = a.id
        WHERE $sup_where
        ORDER BY a.tanggal DESC, m.nama ASC, k.nama ASC
    ";
    $stmt_sup = $pdo->prepare($sql_sup);
    $stmt_sup->execute($sup_params);
    $sup_rows = $stmt_sup->fetchAll();

    foreach ($sup_rows as $row) {
        $mid = $row['id_mandor'];
        if (!isset($mandor_stats[$mid])) {
            $mandor_stats[$mid] = [
                'nama' => $row['nama_mandor'],
                'karyawan_set' => [],
                'total_tugas' => 0,
                'belum_lapor' => 0,
                'per_aktivitas' => [],
                'detail' => [],
                'belum_lapor_list' => [],
            ];
        }
        $mandor_stats[$mid]['karyawan_set'][$row['id_karyawan']] = true;
        $mandor_stats[$mid]['total_tugas']++;
        if (!isset($mandor_stats[$mid]['per_aktivitas'][$row['aktivitas']])) {
            $mandor_stats[$mid]['per_aktivitas'][$row['aktivitas']] = 0;
        }
        $mandor_stats[$mid]['per_aktivitas'][$row['aktivitas']]++;

        $is_belum_lapor = $row['report_id'] === null;
        $status_key = $is_belum_lapor ? 'belum_lapor' : $row['report_status'];
        $status_label_disp = $is_belum_lapor ? 'Belum Lapor' : ($status_labels[$row['report_status']] ?? $row['report_status']);

        $detail_json = base64_encode(json_encode([
            'nama_karyawan'    => $row['nama_karyawan'],
            'nama_mandor'      => $row['nama_mandor'],
            'tanggal_kerja'    => date('d F Y', strtotime($row['tanggal'])),
            'aktivitas'        => $row['aktivitas'],
            'target_jumlah'    => (float)$row['target_jumlah'],
            'unit'             => $row['unit'],
            'jumlah_realisasi' => (float)($row['jumlah_realisasi'] ?? 0),
            'catatan_karyawan' => $row['catatan_karyawan'] ?? '',
            'foto_bukti'       => !empty($row['foto_bukti']) ? '../' . $row['foto_bukti'] : '',
            'status'           => $status_key,
            'status_label'     => $status_label_disp,
            'catatan_mandor'   => $row['catatan_mandor'] ?? '',
            'waktu_mandor'     => $row['tanggal_verifikasi_mandor'] ? date('d-m-Y H:i', strtotime($row['tanggal_verifikasi_mandor'])) : '',
            'catatan_manajer'  => $row['catatan_manajer'] ?? '',
            'waktu_manajer'    => $row['tanggal_verifikasi_manajer'] ? date('d-m-Y H:i', strtotime($row['tanggal_verifikasi_manajer'])) : '',
            'bonus_diterima'   => (float)($row['bonus_diterima'] ?? 0),
        ]));

        $detail_row = [
            'tanggal'      => $row['tanggal'],
            'nama_karyawan'=> $row['nama_karyawan'],
            'aktivitas'    => $row['aktivitas'],
            'status_key'   => $status_key,
            'status_label' => $status_label_disp,
            'has_report'   => !$is_belum_lapor,
            'detail_json'  => $detail_json,
        ];
        $mandor_stats[$mid]['detail'][] = $detail_row;

        if ($is_belum_lapor) {
            $mandor_stats[$mid]['belum_lapor']++;
            $mandor_stats[$mid]['belum_lapor_list'][] = $detail_row;
        }
    }
} catch (\PDOException $e) {
    $error = $error ?: ("Gagal memuat data pengawasan mandor: " . $e->getMessage());
}

$total_mandor_dipantau = count($mandor_stats);
$total_karyawan_unik_all = [];
$total_tugas_all = 0;
$total_belum_lapor_all = 0;
foreach ($mandor_stats as $ms) {
    foreach (array_keys($ms['karyawan_set']) as $kid) { $total_karyawan_unik_all[$kid] = true; }
    $total_tugas_all += $ms['total_tugas'];
    $total_belum_lapor_all += $ms['belum_lapor'];
}
?>

<div style="margin-bottom: 25px;" class="no-print">
    <a href="index.php" style="color: var(--text-muted); font-size: 0.9rem;"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
    <h2 style="margin-top: 10px;">Laporan Monitoring Alur Verifikasi &amp; SLA</h2>
    <p style="color: var(--text-muted);">Pemantauan alur verifikasi bertingkat, status laporan kerja, dan analisis laporan tertahan (SLA)</p>
</div>

<!-- Kop Surat Resmi (Cetak Only) -->
<div style="display:none;" class="print-only">
    <div style="display: flex; align-items: center; justify-content: center; gap: 20px; border-bottom: 3px double #000; padding-bottom: 12px; margin-bottom: 18px;">
        <img src="../assets/logo.png" alt="Logo PT" style="height: 55px; width: auto;">
        <div style="text-align: center;">
            <h2 style="font-family: 'Times New Roman', Times, serif; font-size: 1.5rem; font-weight: bold; margin: 0; color: #000; letter-spacing: 1px; white-space: nowrap;">PT AGROTAMEX SUMINDO ABADI</h2>
            <p style="font-family: 'Times New Roman', Times, serif; font-size: 0.85rem; margin: 4px 0 0 0; color: #000;">Desa Nyogan, Kecamatan Mestong, Kabupaten Muaro Jambi, Provinsi Jambi</p>
        </div>
    </div>
    <div style="text-align: center; margin-bottom: 20px;">
        <h3 style="font-family: 'Times New Roman', Times, serif; font-size: 1.25rem; font-weight: bold; text-decoration: underline; margin: 0 0 5px 0; color: #000;">
            LAPORAN MONITORING ALUR VERIFIKASI &amp; SLA
        </h3>
        <p style="font-family: 'Times New Roman', Times, serif; font-size: 0.85rem; color: #000; margin: 0;">
            Manajer: <strong><?php echo htmlspecialchars($nama); ?></strong> &nbsp;|&nbsp; Tanggal Cetak: <?php echo date('d-m-Y H:i'); ?> WIB 
            <?php if (!empty($start_date) || !empty($end_date)): ?>
                &nbsp;|&nbsp; Periode: <?php echo !empty($start_date) ? htmlspecialchars($start_date) : 'Awal Data'; ?> s/d <?php echo !empty($end_date) ? htmlspecialchars($end_date) : 'Sekarang'; ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger no-print"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Filter Panel Inline -->
<div class="card glass-panel no-print" style="margin-bottom: 20px; padding: 14px 18px;">
    <form method="GET" action="laporan_monitoring.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0;">
        <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-color); display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-filter" style="color: var(--primary-light);"></i> Filter:
        </span>
        
        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 140px; margin: 0;" title="Mulai Tanggal">
        <span style="font-size: 0.8rem; color: var(--text-muted);">s/d</span>
        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 140px; margin: 0;" title="Sampai Tanggal">
        
        <select name="status" class="form-control" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 155px; margin: 0;">
            <option value="">Semua Status</option>
            <?php foreach ($status_labels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $filter_status === $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
        
        <select name="id_mandor" class="form-control" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 150px; margin: 0;">
            <option value="0">Semua Mandor</option>
            <?php foreach ($foremen as $m): ?>
                <option value="<?php echo $m['id_mandor']; ?>" <?php echo $filter_mandor === (int)$m['id_mandor'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($m['nama']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <select name="id_karyawan" class="form-control" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 150px; margin: 0;">
            <option value="0">Semua Karyawan</option>
            <?php foreach ($employees as $k): ?>
                <option value="<?php echo $k['id_karyawan']; ?>" <?php echo $filter_karyawan === (int)$k['id_karyawan'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($k['nama']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <div style="display: flex; gap: 6px; margin-left: auto;">
            <a href="laporan_monitoring.php" class="btn btn-secondary" style="padding: 0 12px; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; height: 36px; border-radius: 6px;" title="Reset Filter"><i class="fa-solid fa-rotate-left"></i></a>
            <button type="submit" class="btn btn-primary" style="padding: 0 16px; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; gap: 6px; height: 36px; border-radius: 6px;"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
        </div>
    </form>
</div>

<!-- Navigation Tabs Rapi (Monitoring Alur & SLA) -->
<div class="report-tabs-header no-print" style="display: flex; gap: 6px; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
    <button class="tab-btn active" onclick="switchTab(event, 'tab-detail-verifikasi')" style="padding: 10px 18px; font-weight: 600; font-size: 0.85rem; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid var(--primary-light); color: var(--primary-light); display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-file-invoice"></i> 1. Detail Alur Verifikasi (<?php echo $total_reports; ?> Laporan)
    </button>
    <button class="tab-btn" onclick="switchTab(event, 'tab-laporan-tertahan')" style="padding: 10px 18px; font-weight: 600; font-size: 0.85rem; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid transparent; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-triangle-exclamation"></i> 2. Laporan Tertahan / SLA (<?php echo $total_tindak_lanjut; ?>)
    </button>
    <button class="tab-btn" onclick="switchTab(event, 'tab-pengawasan-mandor')" style="padding: 10px 18px; font-weight: 600; font-size: 0.85rem; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid transparent; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-user-tie"></i> 3. Pengawasan Mandor (<?php echo $total_mandor_dipantau; ?>)
    </button>
</div>

<!-- ================= TAB 1: DETAIL ALUR VERIFIKASI ================= -->
<div id="tab-detail-verifikasi" class="tab-content-panel" style="display: block;">
    <!-- Counters per Status -->
    <div class="grid-3 no-print" style="margin-bottom: 20px; display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px;">
        <?php
        $status_colors = [
            'pending_mandor'        => '#e0a800',
            'verified_by_mandor'    => '#0d6efd',
            'approved'              => '#2e7d32',
            'rejected'              => '#c62828',
            'pending_manajer_tolak' => '#e65100',
        ];
        foreach ($status_labels as $key => $label):
            $count = $status_counts[$key];
            $color = $status_colors[$key];
        ?>
            <div class="card glass-panel" style="padding: 12px; text-align:center; border-top: 3px solid <?php echo $color; ?>; margin:0;">
                <div style="font-size: 1.4rem; font-weight: 700; color: <?php echo $color; ?>;"><?php echo $count; ?></div>
                <div style="font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-top: 2px;"><?php echo $label; ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Grafik Distribusi Status Alur Verifikasi -->
    <?php if ($total_reports > 0): ?>
    <div class="card glass-panel no-print" style="margin-bottom: 20px; padding: 18px;">
        <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-color); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-chart-column" style="color: var(--primary-light);"></i> Grafik Distribusi Status Alur Verifikasi
        </h4>
        <div style="height: 200px; position: relative;">
            <canvas id="monitoringStatusChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <div class="card glass-panel" style="margin-bottom: 25px;">
        <div class="card-title no-print">
            <span>Detail Alur Verifikasi Laporan Kerja (<?php echo $total_reports; ?> Data)</span>
            <button onclick="window.print()" class="btn btn-gold btn-sm no-print"><i class="fa-solid fa-print"></i> Cetak Laporan</button>
        </div>

        <h3 class="print-only" style="font-family: 'Times New Roman', Times, serif; font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; display:none;">1. Rincian Detail Alur Verifikasi Laporan Kerja</h3>

        <?php if (empty($reports)): ?>
            <div style="text-align: center; padding: 30px 20px; color: var(--text-muted);">Tidak ada data yang cocok dengan kriteria filter.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" style="width: 100%; table-layout: fixed;">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Tanggal</th>
                            <th style="width: 14%;">Karyawan</th>
                            <th style="width: 14%;">Mandor</th>
                            <th style="width: 14%;">Aktivitas</th>
                            <th style="width: 14%;">Status Alur</th>
                            <th style="width: 12%;">Verifikasi Mandor</th>
                            <th style="width: 12%;">Verifikasi Manajer</th>
                            <th class="no-print" style="width: 10%; text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $rep): 
                            $detail_json = base64_encode(json_encode([
                                'nama_karyawan'    => $rep['nama_karyawan'],
                                'nama_mandor'      => $rep['nama_mandor'] ?? '-',
                                'tanggal_kerja'    => date('d F Y', strtotime($rep['tanggal'])),
                                'aktivitas'        => $rep['aktivitas'],
                                'target_jumlah'    => (float)$rep['target_jumlah'],
                                'unit'             => $rep['unit'],
                                'jumlah_realisasi' => (float)$rep['jumlah_realisasi'],
                                'catatan_karyawan' => $rep['catatan_karyawan'] ?? '',
                                'foto_bukti'       => !empty($rep['foto_bukti']) ? '../' . $rep['foto_bukti'] : '',
                                'status'           => $rep['status'],
                                'status_label'     => $status_labels[$rep['status']] ?? $rep['status'],
                                'catatan_mandor'   => $rep['catatan_mandor'] ?? '',
                                'waktu_mandor'     => $rep['tanggal_verifikasi_mandor'] ? date('d-m-Y H:i', strtotime($rep['tanggal_verifikasi_mandor'])) : '',
                                'catatan_manajer'  => $rep['catatan_manajer'] ?? '',
                                'waktu_manajer'    => $rep['tanggal_verifikasi_manajer'] ? date('d-m-Y H:i', strtotime($rep['tanggal_verifikasi_manajer'])) : '',
                                'bonus_diterima'   => (float)($rep['bonus_diterima'] ?? 0),
                            ]));
                        ?>
                            <tr>
                                <td style="white-space: nowrap; font-size: 0.82rem; text-align: center;"><?php echo date('d-m-Y', strtotime($rep['tanggal'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($rep['nama_karyawan']); ?></strong></td>
                                <td><?php echo htmlspecialchars($rep['nama_mandor']); ?></td>
                                <td><?php echo htmlspecialchars($rep['aktivitas']); ?></td>
                                <td style="text-align: center;">
                                    <span class="badge" style="background: <?php echo $status_colors[$rep['status']]; ?>1a; color: <?php echo $status_colors[$rep['status']]; ?>; border: 1px solid <?php echo $status_colors[$rep['status']]; ?>44;">
                                        <?php echo $status_labels[$rep['status']]; ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.78rem; white-space: nowrap; text-align: center; color: var(--text-muted);"><?php echo $rep['tanggal_verifikasi_mandor'] ? date('d-m-Y H:i', strtotime($rep['tanggal_verifikasi_mandor'])) : '-'; ?></td>
                                <td style="font-size: 0.78rem; white-space: nowrap; text-align: center; color: var(--text-muted);"><?php echo $rep['tanggal_verifikasi_manajer'] ? date('d-m-Y H:i', strtotime($rep['tanggal_verifikasi_manajer'])) : '-'; ?></td>
                                <td class="no-print" style="text-align: center;">
                                    <button type="button" class="btn btn-secondary btn-sm" data-detail="<?php echo $detail_json; ?>" onclick="openMonitoringDetailModal(this)" style="padding: 4px 12px; font-size: 0.78rem; font-weight: 600;">
                                        <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Detail
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ================= TAB 2: LAPORAN TERTAHAN / BACKLOG SLA ================= -->
<div id="tab-laporan-tertahan" class="tab-content-panel" style="display: none;">
    <div class="card glass-panel" style="margin-bottom: 25px; border-left: 5px solid #c62828;">
        <div class="card-title no-print">
            <span style="color: #c62828;"><i class="fa-solid fa-triangle-exclamation"></i> Laporan Tertahan / Butuh Tindak Lanjut</span>
            <span class="badge" style="background:#c628281a; color:#c62828; border:1px solid #c6282844;">
                <?php echo $total_tindak_lanjut; ?> laporan tertahan
            </span>
        </div>

        <h3 class="print-only" style="font-family: 'Times New Roman', Times, serif; font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; display:none;">2. Analisis Laporan Tertahan (Backlog SLA)</h3>

        <p style="color: var(--text-muted); font-size: 0.83rem; margin-top: -8px; margin-bottom: 15px;" class="no-print">
            Laporan yang tertahan &ge; <?php echo $sla_hari_mandor; ?> hari di suatu tahap alur verifikasi. Dikelompokkan per Mandor untuk evaluasi SLA.
        </p>

        <?php if ($total_tindak_lanjut === 0): ?>
            <div style="text-align: center; padding: 30px 20px; color: var(--text-muted);">
                <i class="fa-solid fa-circle-check" style="color:#2e7d32; margin-right:6px; font-size: 1.2rem;"></i>
                Seluruh alur verifikasi berjalan lancar. Tidak ada laporan yang tertahan melewati batas SLA.
            </div>
        <?php else: ?>
            <?php foreach ($tindak_lanjut as $mandor_name => $list): ?>
                <div style="margin-bottom: 18px;">
                    <div style="font-weight: 700; margin-bottom: 8px; font-size: 0.88rem;">
                        <i class="fa-solid fa-user-tie" style="color: var(--text-muted); margin-right: 4px;"></i>
                        Mandor: <?php echo htmlspecialchars($mandor_name); ?> (<?php echo count($list); ?> laporan tertahan)
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Pelaksana (Karyawan)</th>
                                    <th>Aktivitas</th>
                                    <th>Tanggal Kerja</th>
                                    <th>Tahap Tertahan</th>
                                    <th>Lama Tertahan</th>
                                    <th class="no-print" style="text-align: center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($list as $rep): 
                                    $detail_json = base64_encode(json_encode([
                                        'nama_karyawan'    => $rep['nama_karyawan'],
                                        'tanggal_kerja'    => date('d F Y', strtotime($rep['tanggal'])),
                                        'aktivitas'        => $rep['aktivitas'],
                                        'target_jumlah'    => (float)$rep['target_jumlah'],
                                        'unit'             => $rep['unit'],
                                        'jumlah_realisasi' => (float)$rep['jumlah_realisasi'],
                                        'catatan_karyawan' => $rep['catatan_karyawan'] ?? '',
                                        'foto_bukti'       => !empty($rep['foto_bukti']) ? '../' . $rep['foto_bukti'] : '',
                                        'status'           => $rep['status'],
                                        'status_label'     => $status_labels[$rep['status']] ?? $rep['status'],
                                        'catatan_mandor'   => $rep['catatan_mandor'] ?? '',
                                        'waktu_mandor'     => $rep['tanggal_verifikasi_mandor'] ? date('d-m-Y H:i', strtotime($rep['tanggal_verifikasi_mandor'])) : '',
                                        'catatan_manajer'  => $rep['catatan_manajer'] ?? '',
                                        'waktu_manajer'    => $rep['tanggal_verifikasi_manajer'] ? date('d-m-Y H:i', strtotime($rep['tanggal_verifikasi_manajer'])) : '',
                                        'bonus_diterima'   => (float)($rep['bonus_diterima'] ?? 0),
                                    ]));
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($rep['nama_karyawan']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($rep['aktivitas']); ?></td>
                                        <td><?php echo date('d-m-Y', strtotime($rep['tanggal'])); ?></td>
                                        <td><?php echo htmlspecialchars($rep['tahap_tertahan']); ?></td>
                                        <td>
                                            <span class="badge" style="background:#e651001a; color:#e65100; border:1px solid #e6510044; font-weight:700;">
                                                <?php echo $rep['hari_tertahan']; ?> hari
                                            </span>
                                        </td>
                                        <td class="no-print" style="text-align: center;">
                                            <button type="button" class="btn btn-secondary btn-sm" data-detail="<?php echo $detail_json; ?>" onclick="openMonitoringDetailModal(this)" style="padding: 4px 12px; font-size: 0.78rem; font-weight: 600;">
                                                <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Detail
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ================= TAB 3: PENGAWASAN MANDOR ================= -->
<div id="tab-pengawasan-mandor" class="tab-content-panel" style="display: none;">

    <!-- Pintasan Filter Hari Ini -->
    <div class="card glass-panel no-print" style="margin-bottom: 16px; padding: 12px 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-color); display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-bolt" style="color: var(--primary-light);"></i> Pintasan:
        </span>
        <a href="<?php echo build_qs(['quick_today' => '1']); ?>" class="btn btn-sm <?php echo $filter_today ? 'btn-primary' : 'btn-secondary'; ?>" style="padding: 5px 14px; border-radius: 6px; font-weight: 600;">
            <i class="fa-regular fa-calendar-check"></i> Hari Ini
        </a>
        <?php if ($filter_today): ?>
            <a href="<?php echo build_qs([], ['quick_today']); ?>" class="btn btn-sm btn-secondary" style="padding: 5px 14px; border-radius: 6px; font-weight: 600;">
                <i class="fa-solid fa-rotate-left"></i> Kembali ke Filter Rentang Tanggal
            </a>
        <?php endif; ?>
        <span style="font-size: 0.78rem; color: var(--text-muted); margin-left: auto;">
            <?php echo $filter_today ? ('Menampilkan tugas tanggal ' . date('d F Y') . ' saja') : 'Mengikuti filter rentang tanggal &amp; mandor di panel atas'; ?>
        </span>
    </div>

    <div class="alert alert-info no-print" style="background: rgba(46,125,50,0.03); border: 1.5px solid var(--primary-light); color: var(--text-color); padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.83rem; line-height: 1.5;">
        <div style="font-weight: 700; color: var(--primary); margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-circle-info"></i> Tentang Bagian Ini
        </div>
        Section ini murni untuk <strong>memantau</strong> apa saja yang berada di bawah pengawasan tiap mandor hari demi hari &mdash; bukan untuk menilai kinerja mandor. Ranking &amp; penilaian kualitas verifikasi mandor ada di menu <strong>Laporan Produktivitas</strong>.
    </div>

    <!-- Ringkasan Global -->
    <div class="grid-3 no-print" style="margin-bottom: 20px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;">
        <div class="card glass-panel" style="padding: 14px; text-align:center; border-top: 3px solid var(--primary-light); margin:0;">
            <div style="font-size: 1.5rem; font-weight: 700; color: var(--primary-light);"><?php echo $total_mandor_dipantau; ?></div>
            <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-top: 2px;">Mandor Dipantau</div>
        </div>
        <div class="card glass-panel" style="padding: 14px; text-align:center; border-top: 3px solid #0d6efd; margin:0;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #0d6efd;"><?php echo count($total_karyawan_unik_all); ?></div>
            <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-top: 2px;">Karyawan Unik Dipantau</div>
        </div>
        <div class="card glass-panel" style="padding: 14px; text-align:center; border-top: 3px solid #c62828; margin:0;">
            <div style="font-size: 1.5rem; font-weight: 700; color: #c62828;"><?php echo $total_belum_lapor_all; ?></div>
            <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; margin-top: 2px;">Tugas Belum Ada Laporan</div>
        </div>
    </div>

    <!-- Grafik Jumlah Tugas per Mandor -->
    <?php if (!empty($mandor_stats)): ?>
    <div class="card glass-panel no-print" style="margin-bottom: 20px; padding: 18px;">
        <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-color); margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-chart-column" style="color: var(--primary-light);"></i> Grafik Jumlah Tugas per Mandor
        </h4>
        <p style="font-size: 0.78rem; color: var(--text-muted); margin: 0 0 12px 0;">Sekadar perbandingan volume tugas yang dipantau, bukan ranking kinerja.</p>
        <div style="height: 200px; position: relative;">
            <canvas id="mandorTaskChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($mandor_stats)): ?>
        <div class="card glass-panel" style="text-align: center; padding: 30px 20px; color: var(--text-muted);">Tidak ada data penugasan yang cocok dengan filter.</div>
    <?php else: ?>

        <!-- 1. Master Table: Rekapitulasi Pengawasan Mandor -->
        <div class="card glass-panel" style="margin-bottom: 25px;">
            <div class="card-title no-print">
                <span><i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Ringkasan Rekapitulasi Pengawasan Mandor</span>
                <button onclick="window.print()" class="btn btn-gold btn-sm no-print"><i class="fa-solid fa-print"></i> Cetak Laporan</button>
            </div>

            <h3 class="print-only" style="font-family: 'Times New Roman', Times, serif; font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; display:none;">3. Rekapitulasi Pengawasan Mandor Lapangan</h3>

            <div class="table-responsive">
                <table class="table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="text-align: center; width: 5%;">No</th>
                            <th style="width: 22%;">Nama Mandor (Penanggung Jawab)</th>
                            <th style="text-align: center; width: 13%;">Karyawan Dipantau</th>
                            <th style="text-align: center; width: 11%;">Total Tugas</th>
                            <th style="text-align: center; width: 12%;">Sudah Dilaporkan</th>
                            <th style="text-align: center; width: 12%;">Belum Ada Laporan</th>
                            <th style="width: 25%;">Breakdown Jenis Pekerjaan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no_m = 1; foreach ($mandor_stats as $mid => $ms): 
                            $sudah_lapor = $ms['total_tugas'] - $ms['belum_lapor'];
                        ?>
                            <tr>
                                <td style="text-align: center; font-weight: 600;"><?php echo $no_m++; ?></td>
                                <td>
                                    <strong><i class="fa-solid fa-user-tie" style="color: var(--primary-light); margin-right: 4px;"></i> <?php echo htmlspecialchars($ms['nama']); ?></strong>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge" style="background:#0d6efd1a; color:#0d6efd; border:1px solid #0d6efd44;">
                                        <?php echo count($ms['karyawan_set']); ?> Karyawan
                                    </span>
                                </td>
                                <td style="text-align: center; font-weight: 700;"><?php echo $ms['total_tugas']; ?> Tugas</td>
                                <td style="text-align: center; color: var(--success); font-weight: 700;"><?php echo $sudah_lapor; ?> Laporan</td>
                                <td style="text-align: center;">
                                    <?php if ($ms['belum_lapor'] > 0): ?>
                                        <span class="badge" style="background:#ffebee; color:#c62828; border:1px solid #ef9a9a; font-weight:700;">
                                            <?php echo $ms['belum_lapor']; ?> Belum Lapor
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.82rem;">0 (Lengkap)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                                        <?php foreach ($ms['per_aktivitas'] as $akt => $jml): ?>
                                            <span class="badge" style="background:#f1f5f9; color:#334155; border:1px solid #e2e8f0; font-size: 0.72rem; font-weight: 600;">
                                                <?php echo htmlspecialchars($akt); ?>: <?php echo $jml; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. Rincian Detail Tugas Per Mandor -->
        <h4 class="no-print" style="margin-top: 25px; margin-bottom: 15px; font-weight: 700; color: var(--text-color); font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-folder-open" style="color: var(--primary-light);"></i> Rincian Detail Tugas di Bawah Pengawasan Mandor
        </h4>

        <?php foreach ($mandor_stats as $mid => $ms): ?>
            <div class="card glass-panel" style="margin-bottom: 18px; padding: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px;">
                    <div style="font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: 8px; color: var(--text-color);">
                        <i class="fa-solid fa-user-tie" style="color: var(--primary-light);"></i> Mandor: <strong><?php echo htmlspecialchars($ms['nama']); ?></strong>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <span class="badge" style="background:#0d6efd1a; color:#0d6efd; border:1px solid #0d6efd44;">
                            <i class="fa-solid fa-users"></i> <?php echo count($ms['karyawan_set']); ?> Karyawan
                        </span>
                        <span class="badge" style="background:#2e7d321a; color:#2e7d32; border:1px solid #2e7d3244;">
                            <i class="fa-solid fa-list-check"></i> <?php echo $ms['total_tugas']; ?> Total Tugas
                        </span>
                        <span class="badge" style="background:<?php echo $ms['belum_lapor'] > 0 ? '#c628281a' : '#f1f5f9'; ?>; color:<?php echo $ms['belum_lapor'] > 0 ? '#c62828' : '#64748b'; ?>; border:1px solid <?php echo $ms['belum_lapor'] > 0 ? '#c6282844' : '#e2e8f0'; ?>;">
                            <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $ms['belum_lapor']; ?> Belum Lapor
                        </span>
                    </div>
                </div>

                <?php if ($ms['belum_lapor'] > 0): ?>
                <!-- Sub-tabel Tugas Belum Ada Laporan -->
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;" class="no-print">
                    <div style="font-size: 0.8rem; font-weight: 700; color: #b91c1c; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Alert: Tugas Belum Ada Laporan (<?php echo $ms['belum_lapor']; ?> Tugas)
                    </div>
                    <div class="table-responsive">
                        <table class="table" style="margin: 0;">
                            <thead>
                                <tr>
                                    <th style="width: 20%;">Tanggal Kerja</th>
                                    <th style="width: 40%;">Pelaksana (Karyawan)</th>
                                    <th style="width: 40%;">Aktivitas Pekerjaan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ms['belum_lapor_list'] as $bl): ?>
                                    <tr>
                                        <td style="text-align: center; font-size: 0.82rem;"><?php echo date('d-m-Y', strtotime($bl['tanggal'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($bl['nama_karyawan']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($bl['aktivitas']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Rincian Lengkap Semua Tugas di Bawah Pengawasan -->
                <button type="button" class="btn btn-secondary btn-sm no-print" onclick="toggleMandorDetail(<?php echo $mid; ?>)" style="margin-bottom: 8px; padding: 6px 14px; font-size: 0.78rem; font-weight: 600; border-radius: 6px;">
                    <i class="fa-solid fa-list"></i> Lihat/Tutup Rincian Lengkap Semua Tugas (<?php echo $ms['total_tugas']; ?> Tugas)
                </button>
                <div id="mandorDetail<?php echo $mid; ?>" style="display: none;">
                    <div class="table-responsive">
                        <table class="table" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th style="width: 15%; text-align: center;">Tanggal Kerja</th>
                                    <th style="width: 25%;">Pelaksana (Karyawan)</th>
                                    <th style="width: 25%;">Aktivitas Pekerjaan</th>
                                    <th style="width: 20%; text-align: center;">Status Verifikasi</th>
                                    <th class="no-print" style="width: 15%; text-align: center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ms['detail'] as $d): 
                                    $sc = $d['status_key'] === 'belum_lapor' ? '#c62828' : ($status_colors[$d['status_key']] ?? '#64748b');
                                ?>
                                    <tr>
                                        <td style="text-align: center; font-size: 0.82rem; white-space: nowrap;"><?php echo date('d-m-Y', strtotime($d['tanggal'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($d['nama_karyawan']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($d['aktivitas']); ?></td>
                                        <td style="text-align:center;">
                                            <span class="badge" style="background: <?php echo $sc; ?>1a; color: <?php echo $sc; ?>; border: 1px solid <?php echo $sc; ?>44;">
                                                <?php echo htmlspecialchars($d['status_label']); ?>
                                            </span>
                                        </td>
                                        <td class="no-print" style="text-align: center;">
                                            <?php if ($d['has_report']): ?>
                                                <button type="button" class="btn btn-secondary btn-sm" data-detail="<?php echo $d['detail_json']; ?>" onclick="openMonitoringDetailModal(this)" style="padding: 3px 10px; font-size: 0.76rem;">
                                                    <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Detail
                                                </button>
                                            <?php else: ?>
                                                <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">Belum ada laporan</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Blok Tanda Tangan Resmi (Cetak Only) -->
<?php
$nama_mandor_ttd = "Mandor Lapangan";
if ($filter_mandor > 0) {
    foreach ($foremen as $f_item) {
        if ((int)$f_item['id_mandor'] === $filter_mandor) {
            $nama_mandor_ttd = $f_item['nama'];
            break;
        }
    }
}
?>
<div class="print-only" style="display:none; margin-top: 40px; page-break-inside: avoid;">
    <p style="text-align: right; font-family: 'Times New Roman', Times, serif; font-size: 0.9rem; color: #000; margin-bottom: 25px;">Jambi, <?php echo date('d F Y'); ?></p>
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; font-family: 'Times New Roman', Times, serif; font-size: 0.85rem; color: #000;">
        <div style="width: 220px; text-align: center;">
            <p style="margin-bottom: 55px; margin-top: 0;">Mandor Penanggung Jawab,</p>
            <p style="border-top: 1px solid #000; padding-top: 5px; margin: 0;"><strong><?php echo htmlspecialchars($nama_mandor_ttd); ?></strong><br>Mandor Penanggung Jawab</p>
        </div>
        <div style="width: 220px; text-align: center;">
            <p style="margin-bottom: 55px; margin-top: 0;">Disetujui oleh,</p>
            <p style="border-top: 1px solid #000; padding-top: 5px; margin: 0;"><strong><?php echo htmlspecialchars($nama); ?></strong><br>Estate Manager</p>
        </div>
    </div>
</div>

<!-- Modal Rincian Detail Monitoring -->
<div id="monitoringDetailModal" class="modal no-print" style="display:none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.7); overflow-y: auto; backdrop-filter: blur(4px);">
    <div class="modal-dialog" style="background: #ffffff; margin: 30px auto; max-width: 750px; border-radius: 14px; padding: 0; box-shadow: 0 20px 40px rgba(0,0,0,0.3); overflow: hidden; color: #1e293b;">
        
        <!-- Modal Header -->
        <div style="background: #f8fafc; padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-file-invoice" style="color: var(--primary-light);"></i> Lembar Audit Monitoring Laporan
                </h3>
                <p style="margin: 3px 0 0 0; font-size: 0.8rem; color: #64748b;">Rincian verifikasi alur bertingkat Karyawan - Mandor - Manajer</p>
            </div>
            <button onclick="closeMonitoringDetailModal()" style="background: transparent; border: none; font-size: 1.6rem; cursor: pointer; color: #64748b; line-height: 1;">&times;</button>
        </div>

        <!-- Modal Body -->
        <div style="padding: 24px; max-height: 75vh; overflow-y: auto;">
            
            <!-- Status Badge Header -->
            <div style="display: flex; justify-content: space-between; align-items: center; background: #f1f5f9; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
                <span style="font-size: 0.85rem; font-weight: 600; color: #475569;">Status Alur Verifikasi Saat Ini:</span>
                <div id="mon_status_badge"></div>
            </div>

            <!-- Grid 2 Kolom: Data Informasi -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                <div style="background: #fafafa; padding: 14px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px;">KARYAWAN PELAKSANA</div>
                    <div id="mon_karyawan" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">-</div>
                </div>
                <div style="background: #fafafa; padding: 14px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px;">MANDOR PENANGGUNG JAWAB</div>
                    <div id="mon_mandor" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">-</div>
                </div>
                <div style="background: #fafafa; padding: 14px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px;">TANGGAL KERJA</div>
                    <div id="mon_tanggal" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">-</div>
                </div>
                <div style="background: #fafafa; padding: 14px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px;">JENIS PEKERJAAN</div>
                    <div id="mon_aktivitas" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">-</div>
                </div>
                <div style="background: #fafafa; padding: 14px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px;">TARGET FISIK</div>
                    <div id="mon_target" style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">-</div>
                </div>
                <div style="background: #fafafa; padding: 14px; border-radius: 8px; border: 1px solid #f1f5f9;">
                    <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 4px;">REALISASI DILAPORKAN</div>
                    <div id="mon_realisasi" style="font-size: 0.95rem; font-weight: 700; color: #1e5235;">-</div>
                </div>
            </div>

            <!-- Foto Bukti Lapangan -->
            <div style="margin-bottom: 20px;">
                <div style="font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-image" style="color: var(--primary-light);"></i> Foto Bukti Hasil Kerja Karyawan:
                </div>
                <div style="text-align: center; background: #0f172a; padding: 10px; border-radius: 10px;">
                    <img id="mon_foto_bukti" src="" alt="Foto Bukti Lapangan" style="max-height: 250px; width: auto; max-width: 100%; border-radius: 6px; object-fit: contain; display: none;">
                    <div id="mon_no_foto" style="color: #94a3b8; font-size: 0.85rem; padding: 30px 0;">Tidak ada lampiran foto bukti.</div>
                </div>
            </div>

            <!-- Catatan Karyawan -->
            <div style="margin-bottom: 16px;">
                <div style="font-size: 0.8rem; font-weight: 700; color: #475569; margin-bottom: 4px;">Catatan Karyawan:</div>
                <div id="mon_catatan_karyawan" style="background: #f8fafc; padding: 10px 14px; border-radius: 6px; font-size: 0.85rem; color: #334155; border: 1px solid #e2e8f0;">-</div>
            </div>

            <!-- Audit Trail Alur Verifikasi -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 16px;">
                <div style="font-size: 0.85rem; font-weight: 700; color: #0f172a; margin-bottom: 12px; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-timeline" style="color: var(--primary-light);"></i> Audit Trail Log Verifikasi Bertingkat
                </div>
                
                <!-- Step 1: Mandor -->
                <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: #0d6efd1a; color: #0d6efd; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; flex-shrink: 0;">1</div>
                    <div style="flex-grow: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.83rem; font-weight: 700; color: #1e293b;">Verifikasi Mandor Lapangan</span>
                            <span id="mon_waktu_mandor" style="font-size: 0.75rem; color: #64748b;">-</span>
                        </div>
                        <div id="mon_catatan_mandor" style="font-size: 0.82rem; color: #475569; margin-top: 3px; font-style: italic;">Belum ada catatan mandor.</div>
                    </div>
                </div>

                <!-- Step 2: Manajer -->
                <div style="display: flex; gap: 12px;">
                    <div style="width: 28px; height: 28px; border-radius: 50%; background: #2e7d321a; color: #2e7d32; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; flex-shrink: 0;">2</div>
                    <div style="flex-grow: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.83rem; font-weight: 700; color: #1e293b;">Approval Final Manajer Operasional</span>
                            <span id="mon_waktu_manajer" style="font-size: 0.75rem; color: #64748b;">-</span>
                        </div>
                        <div id="mon_catatan_manajer" style="font-size: 0.82rem; color: #475569; margin-top: 3px; font-style: italic;">Belum ada catatan manajer.</div>
                    </div>
                </div>
            </div>

            <!-- Bonus / Penalti Result -->
            <div id="mon_bonus_box" style="background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.85rem; font-weight: 700; color: #1b5e20;">Insentif Kinerja Akhir:</span>
                <span id="mon_bonus_val" style="font-size: 1.1rem; font-weight: 800; color: #1b5e20;">Rp 0</span>
            </div>

        </div>

        <!-- Modal Footer -->
        <div style="background: #f8fafc; padding: 14px 24px; border-top: 1px solid #e2e8f0; text-align: right;">
            <button onclick="closeMonitoringDetailModal()" class="btn btn-secondary btn-sm" style="padding: 7px 22px; font-weight: 600; border-radius: 6px;">Tutup</button>
        </div>

    </div>
</div>

<style>
    @media screen { 
        .print-only { display: none !important; } 
    }
    @media print {
        @page { size: A4 portrait; margin: 1.5cm 1.5cm 1.5cm 1.5cm; }
        html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; width: 100% !important; font-family: "Times New Roman", Times, serif !important; font-size: 8.5pt !important; color: #000 !important; }
        *, html, body, div, p, span, h1, h2, h3, h4, h5, h6, table, th, td, tr, strong, b, small {
            font-family: "Times New Roman", Times, serif !important;
            color: #000 !important;
            border-color: #000 !important;
            box-shadow: none !important;
            text-shadow: none !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        i, .fa, .fas, .far, .fab, .fa-solid { display: none !important; }
        .no-print, header.top-header, aside.sidebar, footer, .report-tabs-header, .btn, .alert-info, .card-title, .grid-3 { display: none !important; }
        body.has-sidebar .main-wrapper,
        body .main-wrapper,
        .main-wrapper, .main-content, .container, .tab-content-panel, .card, .glass-panel {
            margin-left: 0 !important;
            margin-right: 0 !important;
            margin-top: 0 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            padding-top: 0 !important;
            width: 100% !important;
            max-width: 100% !important;
            box-shadow: none !important;
            border: none !important;
            background: transparent !important;
        }
        .print-only { display: block !important; }
        .table-responsive { overflow: visible !important; width: 100% !important; margin: 0 !important; padding: 0 !important; }
        table, table.table { width: 100% !important; table-layout: fixed !important; border-collapse: collapse !important; margin-left: 0 !important; margin-right: 0 !important; margin-top: 10px !important; margin-bottom: 20px !important; }
        th { background: #f2f2f2 !important; color: #000 !important; border: 1px solid #000 !important; text-align: center !important; font-weight: bold !important; padding: 6px 4px !important; font-size: 8.5pt !important; word-wrap: break-word !important; }
        td { border: 1px solid #000 !important; color: #000 !important; padding: 5px 4px !important; font-size: 8pt !important; vertical-align: middle !important; word-wrap: break-word !important; overflow-wrap: break-word !important; }
        td:first-child, td:nth-child(6), td:nth-child(7) { white-space: nowrap !important; }
        .badge { background: transparent !important; border: none !important; color: #000 !important; font-weight: bold !important; padding: 0 !important; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('monitoringStatusChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Menunggu Mandor', 'Terverifikasi Mandor', 'Disetujui', 'Ditolak', 'Tinjauan Sanksi'],
                datasets: [{
                    label: 'Jumlah Laporan',
                    data: [
                        <?php echo $status_counts['pending_mandor']; ?>,
                        <?php echo $status_counts['verified_by_mandor']; ?>,
                        <?php echo $status_counts['approved']; ?>,
                        <?php echo $status_counts['rejected']; ?>,
                        <?php echo $status_counts['pending_manajer_tolak']; ?>
                    ],
                    backgroundColor: ['#e0a800', '#0d6efd', '#2e7d32', '#c62828', '#e65100'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const ctxMandor = document.getElementById('mandorTaskChart');
    if (ctxMandor) {
        <?php
        $mandor_names = [];
        $mandor_totals = [];
        foreach ($mandor_stats as $ms) {
            $mandor_names[] = $ms['nama'];
            $mandor_totals[] = $ms['total_tugas'];
        }
        ?>
        new Chart(ctxMandor, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($mandor_names); ?>,
                datasets: [{
                    label: 'Jumlah Tugas',
                    data: <?php echo json_encode($mandor_totals); ?>,
                    backgroundColor: 'rgba(13, 110, 253, 0.75)',
                    borderColor: '#0d6efd',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }
});

function toggleMandorDetail(mid) {
    const el = document.getElementById('mandorDetail' + mid);
    if (el) {
        el.style.display = (el.style.display === 'none' || !el.style.display) ? 'block' : 'none';
    }
}

function switchTab(evt, tabId) {
    evt.preventDefault();
    const tabPanels = document.querySelectorAll('.tab-content-panel');
    tabPanels.forEach(panel => panel.style.display = 'none');
    
    const tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(btn => {
        btn.style.borderBottom = '3px solid transparent';
        btn.style.color = 'var(--text-muted)';
        btn.classList.remove('active');
    });

    const targetTab = document.getElementById(tabId);
    if (targetTab) {
        targetTab.style.display = 'block';
    }
    
    evt.currentTarget.style.borderBottom = '3px solid var(--primary-light)';
    evt.currentTarget.style.color = 'var(--primary-light)';
    evt.currentTarget.classList.add('active');
}

function openMonitoringDetailModal(d) {
    if (!d) return;
    let raw = d.getAttribute ? d.getAttribute('data-detail') : d;
    if (!raw) return;
    
    let dataObj = null;
    try {
        if (typeof raw === 'string') {
            if (raw.startsWith('{') || raw.startsWith('[')) {
                dataObj = JSON.parse(raw);
            } else {
                dataObj = JSON.parse(decodeURIComponent(escape(atob(raw))));
            }
        } else {
            dataObj = raw;
        }
    } catch(e) {
        try {
            dataObj = JSON.parse(atob(raw));
        } catch(e2) {
            console.error("Error parsing detail json:", e2);
            return;
        }
    }
    
    if (!dataObj) return;

    const setT = (id, val) => { const el = document.getElementById(id); if (el) el.innerText = val; };
    const setH = (id, val) => { const el = document.getElementById(id); if (el) el.innerHTML = val; };
    const setS = (id, p, val) => { const el = document.getElementById(id); if (el) el.style[p] = val; };

    setT('mon_karyawan', dataObj.nama_karyawan || '-');
    setT('mon_tanggal', dataObj.tanggal_kerja || '-');
    setT('mon_aktivitas', dataObj.aktivitas || '-');
    setT('mon_target', (dataObj.target_jumlah || '0') + ' ' + (dataObj.unit || ''));
    setT('mon_realisasi', (dataObj.jumlah_realisasi || '0') + ' ' + (dataObj.unit || ''));
    setT('mon_catatan_karyawan', dataObj.catatan_karyawan || 'Tidak ada catatan karyawan.');
    setT('mon_mandor', dataObj.nama_mandor || '-');

    const fotoImg = document.getElementById('mon_foto_bukti');
    const noFoto = document.getElementById('mon_no_foto');
    if (fotoImg) {
        if (dataObj.foto_bukti && dataObj.foto_bukti.trim() !== '') {
            fotoImg.src = dataObj.foto_bukti;
            fotoImg.style.display = 'inline-block';
            if (noFoto) noFoto.style.display = 'none';
        } else {
            fotoImg.style.display = 'none';
            if (noFoto) noFoto.style.display = 'block';
        }
    }

    let statusBg = '#0d6efd1a', statusColor = '#0d6efd';
    if (dataObj.status === 'approved') { statusBg = '#2e7d321a'; statusColor = '#2e7d32'; }
    else if (dataObj.status === 'rejected' || dataObj.status === 'pending_manajer_tolak') { statusBg = '#c628281a'; statusColor = '#c62828'; }
    else if (dataObj.status === 'pending_mandor') { statusBg = '#e0a8001a'; statusColor = '#e0a800'; }

    setH('mon_status_badge', `<span class="badge" style="background: ${statusBg}; color: ${statusColor}; padding: 4px 10px; border-radius: 6px; font-weight: 700;">${dataObj.status_label || dataObj.status}</span>`);

    setT('mon_catatan_mandor', dataObj.catatan_mandor || 'Belum ada catatan mandor.');
    setT('mon_waktu_mandor', dataObj.waktu_mandor || '-');

    setT('mon_catatan_manajer', dataObj.catatan_manajer || 'Belum ada catatan manajer.');
    setT('mon_waktu_manajer', dataObj.waktu_manajer || '-');

    const bonusBox = document.getElementById('mon_bonus_box');
    const bonusVal = document.getElementById('mon_bonus_val');
    const bonusNum = Number(dataObj.bonus_diterima || 0);

    if (bonusBox && bonusVal) {
        if (bonusNum > 0) {
            bonusBox.style.background = '#e8f5e9';
            bonusBox.style.borderColor = '#a5d6a7';
            bonusVal.style.color = '#1b5e20';
            bonusVal.innerText = '+Rp ' + bonusNum.toLocaleString('id-ID');
        } else if (bonusNum < 0) {
            bonusBox.style.background = '#ffebee';
            bonusBox.style.borderColor = '#ef9a9a';
            bonusVal.style.color = '#c62828';
            bonusVal.innerText = '-Rp ' + Math.abs(bonusNum).toLocaleString('id-ID');
        } else {
            bonusBox.style.background = '#f8fafc';
            bonusBox.style.borderColor = '#e2e8f0';
            bonusVal.style.color = '#64748b';
            bonusVal.innerText = 'Rp 0 (Target Standar)';
        }
    }

    const modal = document.getElementById('monitoringDetailModal');
    if (modal) modal.style.display = 'block';
}

function closeMonitoringDetailModal() {
    const modal = document.getElementById('monitoringDetailModal');
    if (modal) modal.style.display = 'none';
}
</script>

<?php require_once '../includes/footer.php'; ?>
