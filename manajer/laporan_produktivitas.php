<?php
// manajer/laporan_produktivitas.php
// LAPORAN PRODUKTIVITAS: fokus pada REKAP HASIL, RANKING PRODUKTIVITAS, INSENTIF BONUS, & SANKSII.
require_once '../config/koneksi.php';
require_once '../includes/header.php';

// Validate role
if ($_SESSION['role'] !== 'manajer') {
    header("Location: ../index.php");
    exit;
}

$error = "";
$nama = $_SESSION['nama'] ?? 'Manajer';

// 1. Capture search/filter parameters
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$filter_karyawan = isset($_GET['id_karyawan']) ? (int)$_GET['id_karyawan'] : 0;
$filter_activity = isset($_GET['aktivitas']) ? trim($_GET['aktivitas']) : '';

// 2. Fetch employees for dropdown
$employees = [];
try {
    $stmt = $pdo->query("SELECT id_karyawan, nama FROM karyawan ORDER BY nama ASC");
    $employees = $stmt->fetchAll();
} catch (\PDOException $e) {
    // Fail silently
}

// 2b. Rekap Produktivitas Pengawasan Mandor - mandor sebagai penanggung
// jawab alur juga dievaluasi. "Produktivitas" mandor diartikan sebagai
// VOLUME laporan yang berhasil ditangani (bukan tonase, karena mandor
// tidak mengerjakan panen/pupuk/semprot sendiri). Dimulai dari tabel
// `mandor` (LEFT JOIN) supaya semua mandor terdaftar tetap muncul walau
// tidak ada aktivitas di periode yang difilter.
$rekap_mandor = [];
try {
    $sql_mandor = "
        SELECT m.id_mandor, m.nama, m.spesialisasi,
               COUNT(r.id) AS total_laporan_masuk,
               SUM(CASE WHEN r.status IS NOT NULL AND r.status != 'pending_mandor' THEN 1 ELSE 0 END) AS jumlah_diverifikasi,
               SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS jumlah_disetujui,
               SUM(CASE WHEN r.status = 'rejected' OR r.status = 'pending_manajer_tolak' THEN 1 ELSE 0 END) AS jumlah_ditolak,
               SUM(CASE WHEN r.status = 'pending_mandor' THEN 1 ELSE 0 END) AS jumlah_tertahan,
               SUM(CASE WHEN r.potongan_penalti > 0 THEN 1 ELSE 0 END) AS jumlah_manipulasi_tertangkap,
               AVG(TIMESTAMPDIFF(HOUR, a.tanggal, r.tanggal_verifikasi_mandor)) AS avg_jam_respon
        FROM mandor m
        LEFT JOIN assignments a ON a.id_mandor = m.id_mandor
        LEFT JOIN work_reports r ON r.id_assignment = a.id
        WHERE 1=1
    ";
    $params_mandor = [];
    if (!empty($start_date)) { $sql_mandor .= " AND a.tanggal >= ?"; $params_mandor[] = $start_date; }
    if (!empty($end_date))   { $sql_mandor .= " AND a.tanggal <= ?"; $params_mandor[] = $end_date; }
    $sql_mandor .= " GROUP BY m.id_mandor, m.nama, m.spesialisasi ORDER BY total_laporan_masuk DESC";
    $stmt_mandor = $pdo->prepare($sql_mandor);
    $stmt_mandor->execute($params_mandor);
    $rekap_mandor = $stmt_mandor->fetchAll();
} catch (\PDOException $e) {
    $error = "Gagal memuat rekap mandor: " . $e->getMessage();
}

// 2c. Fetch detailed pending/held-up reports per Mandor
$all_pending_reports = [];
$pending_by_mandor = [];
try {
    $sql_pending = "
        SELECT r.*, a.tanggal, a.aktivitas, a.unit, a.target_jumlah,
               m.nama as nama_mandor, k.nama as nama_karyawan,
               DATEDIFF(CURRENT_DATE, a.tanggal) as hari_tertahan
        FROM work_reports r
        JOIN assignments a ON r.id_assignment = a.id
        JOIN karyawan k ON r.id_karyawan = k.id_karyawan
        LEFT JOIN mandor m ON a.id_mandor = m.id_mandor
        WHERE r.status = 'pending_mandor'
    ";
    $params_pending = [];
    if (!empty($start_date)) { $sql_pending .= " AND a.tanggal >= ?"; $params_pending[] = $start_date; }
    if (!empty($end_date))   { $sql_pending .= " AND a.tanggal <= ?"; $params_pending[] = $end_date; }
    $sql_pending .= " ORDER BY a.tanggal ASC, r.id ASC";
    $stmt_pending = $pdo->prepare($sql_pending);
    $stmt_pending->execute($params_pending);
    $all_pending_reports = $stmt_pending->fetchAll();

    foreach ($all_pending_reports as $pr) {
        $m_name = $pr['nama_mandor'] ?? 'Mandor Lapangan';
        $pr['tahap_tertahan'] = 'Tertahan di Mandor Lapangan';
        $pending_by_mandor[$m_name][] = $pr;
    }
} catch (\PDOException $e) {
    // Fail silently
}

// 2d. Fetch ALL reports per Mandor for Tab 4 Rincian Modal
$mandor_all_reports = [];
$reports_by_mandor = [];
try {
    $sql_m_all = "
        SELECT r.*, a.tanggal, a.aktivitas, a.unit, a.target_jumlah,
               m.nama as nama_mandor, k.nama as nama_karyawan
        FROM work_reports r
        JOIN assignments a ON r.id_assignment = a.id
        JOIN karyawan k ON r.id_karyawan = k.id_karyawan
        JOIN mandor m ON a.id_mandor = m.id_mandor
        WHERE 1=1
    ";
    $params_m_all = [];
    if (!empty($start_date)) { $sql_m_all .= " AND a.tanggal >= ?"; $params_m_all[] = $start_date; }
    if (!empty($end_date))   { $sql_m_all .= " AND a.tanggal <= ?"; $params_m_all[] = $end_date; }
    $sql_m_all .= " ORDER BY a.tanggal DESC, r.id DESC";
    $stmt_m_all = $pdo->prepare($sql_m_all);
    $stmt_m_all->execute($params_m_all);
    $mandor_all_reports = $stmt_m_all->fetchAll();

    foreach ($mandor_all_reports as $mar) {
        $mname = $mar['nama_mandor'] ?? 'Mandor Lapangan';
        $reports_by_mandor[$mname][] = $mar;
    }
} catch (\PDOException $e) {
    // Fail silently
}

// 3. Build filter query
$where = " (r.status = 'approved' OR r.bonus_diterima < 0 OR r.potongan_penalti > 0) ";
$params = [];

if (!empty($start_date)) { $where .= " AND a.tanggal >= ?"; $params[] = $start_date; }
if (!empty($end_date))   { $where .= " AND a.tanggal <= ?"; $params[] = $end_date; }
if ($filter_karyawan > 0){ $where .= " AND r.id_karyawan = ?"; $params[] = $filter_karyawan; }
if (!empty($filter_activity)) { $where .= " AND a.aktivitas = ?"; $params[] = $filter_activity; }

$rekap_karyawan = [];
$rekap_aktivitas = [];
$task_summary = [];

$status_labels = [
    'pending_mandor'        => 'Menunggu Mandor',
    'verified_by_mandor'    => 'Terverifikasi Mandor',
    'approved'              => 'Disetujui',
    'rejected'              => 'Ditolak',
    'pending_manajer_tolak' => 'Tinjauan Sanksi',
];

try {
    // 1. Rekap per karyawan (Jika Kena Sanksi, Capaian = 0%)
    $sql = "
        SELECT k.id_karyawan, k.nama,
               COUNT(r.id) AS jumlah_laporan,
               AVG(CASE WHEN r.status = 'approved' AND (r.potongan_penalti IS NULL OR r.potongan_penalti = 0) THEN (r.jumlah_realisasi / a.target_jumlah * 100) ELSE 0 END) AS avg_pencapaian,
               SUM(CASE WHEN r.bonus_diterima > 0 THEN r.bonus_diterima ELSE 0 END) AS total_bonus,
               SUM(CASE WHEN r.bonus_diterima < 0 THEN ABS(r.bonus_diterima) ELSE 0 END) AS total_sanksi,
               SUM(r.bonus_diterima) AS net_bonus
        FROM work_reports r
        JOIN assignments a ON r.id_assignment = a.id
        JOIN karyawan k ON r.id_karyawan = k.id_karyawan
        WHERE $where
        GROUP BY k.id_karyawan, k.nama
        ORDER BY avg_pencapaian DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rekap_karyawan = $stmt->fetchAll();

    // 2. Rekap per jenis aktivitas (Jika Kena Sanksi, Realisasi = 0)
    $sql2 = "
        SELECT a.aktivitas, a.unit,
               COUNT(r.id) AS jumlah_laporan,
               COUNT(DISTINCT r.id_karyawan) AS total_karyawan,
               SUM(a.target_jumlah) AS total_target,
               SUM(CASE WHEN r.status = 'approved' AND (r.potongan_penalti IS NULL OR r.potongan_penalti = 0) THEN r.jumlah_realisasi ELSE 0 END) AS total_realisasi,
               AVG(CASE WHEN r.status = 'approved' AND (r.potongan_penalti IS NULL OR r.potongan_penalti = 0) THEN (r.jumlah_realisasi / a.target_jumlah * 100) ELSE 0 END) AS avg_pencapaian,
               SUM(CASE WHEN r.bonus_diterima > 0 THEN r.bonus_diterima ELSE 0 END) AS total_bonus,
               SUM(CASE WHEN r.bonus_diterima < 0 THEN ABS(r.bonus_diterima) ELSE 0 END) AS total_sanksi
        FROM work_reports r
        JOIN assignments a ON r.id_assignment = a.id
        WHERE $where
        GROUP BY a.aktivitas, a.unit
        ORDER BY total_realisasi DESC
    ";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute($params);
    $rekap_aktivitas = $stmt2->fetchAll();

    // 3. Rekap Penugasan (Target vs Yang Dihasilkan)
    $sql_ts = "
        SELECT a.tanggal, a.aktivitas, a.unit, m.nama as nama_mandor,
               COUNT(DISTINCT a.id_karyawan) as total_karyawan,
               AVG(a.target_jumlah) as target_per_orang,
               SUM(a.target_jumlah) as target_total,
               SUM(CASE WHEN r.status = 'approved' AND (r.potongan_penalti IS NULL OR r.potongan_penalti = 0) THEN r.jumlah_realisasi ELSE 0 END) as hasil_total
        FROM assignments a
        JOIN mandor m ON a.id_mandor = m.id_mandor
        LEFT JOIN work_reports r ON r.id_assignment = a.id
        WHERE $where
        GROUP BY a.tanggal, a.aktivitas, a.unit, a.id_mandor
        ORDER BY a.tanggal DESC
    ";
    $stmt_ts = $pdo->prepare($sql_ts);
    $stmt_ts->execute($params);
    $task_summary = $stmt_ts->fetchAll();

    // 4. Fetch all detailed reports for employee modal mapping
    $sql_emp_reports = "
        SELECT r.*, a.tanggal, a.aktivitas, a.unit, a.target_jumlah,
               m.nama as nama_mandor, k.nama as nama_karyawan
        FROM work_reports r
        JOIN assignments a ON r.id_assignment = a.id
        JOIN karyawan k ON r.id_karyawan = k.id_karyawan
        LEFT JOIN mandor m ON a.id_mandor = m.id_mandor
        WHERE $where
        ORDER BY a.tanggal DESC, r.id DESC
    ";
    $stmt_emp = $pdo->prepare($sql_emp_reports);
    $stmt_emp->execute($params);
    $all_emp_reports = $stmt_emp->fetchAll();

    $emp_reports_map = [];
    $task_reports_map = [];
    $activity_reports_map = [];

    foreach ($all_emp_reports as $er) {
        $report_item = [
            'id'               => $er['id'],
            'tanggal'          => date('d-m-Y', strtotime($er['tanggal'])),
            'nama_karyawan'    => $er['nama_karyawan'],
            'nama_mandor'      => $er['nama_mandor'] ?? '-',
            'aktivitas'        => $er['aktivitas'],
            'target_jumlah'    => (float)$er['target_jumlah'],
            'jumlah_realisasi' => (float)$er['jumlah_realisasi'],
            'unit'             => $er['unit'],
            'status'           => $er['status'],
            'status_label'     => $status_labels[$er['status']] ?? $er['status'],
            'potongan_penalti' => (float)($er['potongan_penalti'] ?? 0),
            'bonus_diterima'   => (float)($er['bonus_diterima'] ?? 0),
            'catatan_karyawan' => $er['catatan_karyawan'] ?? '',
            'catatan_mandor'   => $er['catatan_mandor'] ?? '',
            'catatan_manajer'  => $er['catatan_manajer'] ?? '',
        ];
        
        $emp_reports_map[$er['id_karyawan']][] = $report_item;
        
        $task_key = $er['tanggal'] . '_' . $er['aktivitas'];
        $task_reports_map[$task_key][] = $report_item;
        
        $activity_reports_map[$er['aktivitas']][] = $report_item;
    }

} catch (\PDOException $e) {
    $error = "Gagal memuat rekap: " . $e->getMessage();
}
?>

<div style="margin-bottom: 25px;" class="no-print">
    <a href="index.php" style="color: var(--text-muted); font-size: 0.9rem;"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
    <h2 style="margin-top: 10px;">Laporan Produktivitas &amp; Insentif Bonus</h2>
    <p style="color: var(--text-muted);">Rekapitulasi pencapaian target, ranking produktivitas, dan insentif bonus/sanksi karyawan serta pengawasan mandor</p>
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
            LAPORAN PRODUKTIVITAS &amp; INSENTIF KARYAWAN &amp; MANDOR
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
    <form method="GET" action="laporan_produktivitas.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0;">
        <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-color); display: flex; align-items: center; gap: 6px;">
            <i class="fa-solid fa-filter" style="color: var(--primary-light);"></i> Filter:
        </span>
        
        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 140px; margin: 0;" title="Mulai Tanggal">
        <span style="font-size: 0.8rem; color: var(--text-muted);">s/d</span>
        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 140px; margin: 0;" title="Sampai Tanggal">
        
        <select name="aktivitas" class="form-control" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 160px; margin: 0;">
            <option value="">Semua Aktivitas</option>
            <option value="Pemanenan" <?php echo $filter_activity === 'Pemanenan' ? 'selected' : ''; ?>>Pemanenan</option>
            <option value="Penyemprotan" <?php echo $filter_activity === 'Penyemprotan' ? 'selected' : ''; ?>>Penyemprotan</option>
            <option value="Pemupukan" <?php echo $filter_activity === 'Pemupukan' ? 'selected' : ''; ?>>Pemupukan</option>
        </select>

        <select name="id_karyawan" class="form-control" style="padding: 6px 10px; font-size: 0.8rem; height: 36px; width: 170px; margin: 0;">
            <option value="0">Semua Karyawan</option>
            <?php foreach ($employees as $k): ?>
                <option value="<?php echo $k['id_karyawan']; ?>" <?php echo $filter_karyawan === (int)$k['id_karyawan'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($k['nama']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <div style="display: flex; gap: 6px; margin-left: auto;">
            <a href="laporan_produktivitas.php" class="btn btn-secondary" style="padding: 0 12px; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; height: 36px; border-radius: 6px;" title="Reset Filter"><i class="fa-solid fa-rotate-left"></i></a>
            <button type="submit" class="btn btn-primary" style="padding: 0 16px; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; gap: 6px; height: 36px; border-radius: 6px;"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
        </div>
    </form>
</div>

<!-- Stat Summary Cards -->
<?php
$total_gross_bonus = 0.0;
$total_sanksi_denda = 0.0;
$saldo_bonus_bersih = 0.0;

foreach ($rekap_karyawan as $rk) {
    $total_gross_bonus += (float)$rk['total_bonus'];
    $total_sanksi_denda += (float)$rk['total_sanksi'];
    $saldo_bonus_bersih += (float)$rk['net_bonus'];
}
?>

<div class="grid-3 no-print" style="margin-bottom: 22px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">
    <!-- Card 1: SALDO BONUS BERSIH -->
    <div class="card glass-panel" style="padding: 16px 20px; display: flex; align-items: center; gap: 16px; border-left: 5px solid #2e7d32; border-radius: 12px; margin: 0; background: #ffffff; box-shadow: 0 2px 10px rgba(0,0,0,0.04);">
        <div style="background: #e8f5e9; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i class="fa-solid fa-wallet" style="font-size: 1.2rem; color: #2e7d32;"></i>
        </div>
        <div>
            <div style="font-size: 0.72rem; color: #5c7567; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">SALDO BONUS BERSIH</div>
            <div style="font-size: 1.35rem; font-weight: 800; color: #2e7d32; margin-top: 2px;">
                Rp <?php echo number_format($saldo_bonus_bersih, 0, ',', '.'); ?>
            </div>
        </div>
    </div>

    <!-- Card 2: TOTAL AKUMULASI BONUS -->
    <div class="card glass-panel" style="padding: 16px 20px; display: flex; align-items: center; gap: 16px; border-left: 5px solid #2e7d32; border-radius: 12px; margin: 0; background: #ffffff; box-shadow: 0 2px 10px rgba(0,0,0,0.04);">
        <div style="background: #e8f5e9; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i class="fa-solid fa-gift" style="font-size: 1.2rem; color: #2e7d32;"></i>
        </div>
        <div>
            <div style="font-size: 0.72rem; color: #5c7567; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">TOTAL AKUMULASI BONUS</div>
            <div style="font-size: 1.35rem; font-weight: 800; color: #2e7d32; margin-top: 2px;">
                Rp <?php echo number_format($total_gross_bonus, 0, ',', '.'); ?>
            </div>
        </div>
    </div>

    <!-- Card 3: TOTAL SANKSI DENDA -->
    <div class="card glass-panel" style="padding: 16px 20px; display: flex; align-items: center; gap: 16px; border-left: 5px solid #c62828; border-radius: 12px; margin: 0; background: #ffffff; box-shadow: 0 2px 10px rgba(0,0,0,0.04);">
        <div style="background: #ffebee; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
            <i class="fa-solid fa-circle-minus" style="font-size: 1.2rem; color: #c62828;"></i>
        </div>
        <div>
            <div style="font-size: 0.72rem; color: #5c7567; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">TOTAL SANKSI DENDA</div>
            <div style="font-size: 1.35rem; font-weight: 800; color: #c62828; margin-top: 2px;">
                Rp <?php echo number_format($total_sanksi_denda, 0, ',', '.'); ?>
            </div>
        </div>
    </div>
</div>

<!-- Navigation Tabs Rapi -->
<div class="report-tabs-header no-print" style="display: flex; gap: 6px; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
    <button class="tab-btn active" onclick="switchTab(event, 'tab-rekap-penugasan')" style="padding: 10px 18px; font-weight: 600; font-size: 0.85rem; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid var(--primary-light); color: var(--primary-light); display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-list-check"></i> 1. Rekap Penugasan
    </button>
    <button class="tab-btn" onclick="switchTab(event, 'tab-ranking-insentif')" style="padding: 10px 18px; font-weight: 600; font-size: 0.85rem; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid transparent; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-trophy"></i> 2. Ranking Produktivitas &amp; Bonus
    </button>
    <button class="tab-btn" onclick="switchTab(event, 'tab-rekap-aktivitas')" style="padding: 10px 18px; font-weight: 600; font-size: 0.85rem; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid transparent; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-chart-pie"></i> 3. Rekap per Jenis Pekerjaan
    </button>
    <button class="tab-btn" onclick="switchTab(event, 'tab-produktivitas-mandor')" style="padding: 10px 18px; font-weight: 600; font-size: 0.85rem; border: none; background: transparent; cursor: pointer; border-bottom: 3px solid transparent; color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-user-tie"></i> 4. Produktivitas Mandor (<?php echo count($rekap_mandor); ?>)
    </button>
</div>

<!-- ================= TAB 1: REKAP PENUGASAN ================= -->
<div id="tab-rekap-penugasan" class="tab-content-panel" style="display: block;">
    <div class="card glass-panel" style="margin-bottom: 25px;">
        <div class="card-title no-print">
            <span><i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Rekap Penugasan (Target vs Yang Dihasilkan)</span>
            <button onclick="window.print()" class="btn btn-gold btn-sm no-print"><i class="fa-solid fa-print"></i> Cetak Laporan</button>
        </div>

        <h3 class="print-only" style="font-family: 'Times New Roman', Times, serif; font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; display:none;">1. Rekapitulasi Penugasan (Target vs Yang Dihasilkan)</h3>

        <!-- Info Box Penjelasan Akademik -->
        <div class="alert alert-info no-print" style="background: rgba(46,125,50,0.03); border: 1.5px solid var(--primary-light); color: var(--text-color); padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.83rem; line-height: 1.5;">
            <div style="font-weight: 700; color: var(--primary); margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-graduation-cap" style="font-size: 1.1rem;"></i> Keterangan Rumus &amp; Metrik Rekap Penugasan:
            </div>
            <ul style="margin: 0; padding-left: 20px; color: var(--text-muted);">
                <li><strong>Target / Orang</strong>: Patokan target kerja dasar per individu yang ditetapkan Manajer.</li>
                <li><strong>Target Penugasan</strong>: Total akumulasi target <code>(Target per Orang &times; Jumlah Karyawan)</code>.</li>
                <li><strong>Yang Dihasilkan</strong>: Total realisasi fisik hasil panen/kerja yang disetujui di lapangan.</li>
                <li><strong>% Capaian</strong>: Indeks persentase ketercapaian target <code>(Yang Dihasilkan / Target Penugasan) &times; 100%</code>.</li>
            </ul>
        </div>

        <!-- Grafik Rekap Penugasan -->
        <?php if (!empty($task_summary)): ?>
        <div class="card glass-panel no-print" style="margin-bottom: 20px; padding: 18px;">
            <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-color); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-chart-column" style="color: var(--primary-light);"></i> Grafik Perbandingan Target Penugasan vs Yang Dihasilkan
            </h4>
            <div style="height: 210px; position: relative;">
                <canvas id="taskSummaryChart"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($task_summary)): ?>
            <div style="text-align: center; padding: 30px 20px; color: var(--text-muted);">Belum ada data penugasan.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Aktivitas</th>
                            <th>Mandor</th>
                            <th>Jml Karyawan</th>
                            <th>Target / Orang</th>
                            <th>Target Penugasan</th>
                            <th>Yang Dihasilkan</th>
                            <th>% Capaian</th>
                            <th class="no-print" style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($task_summary as $ts): 
                            $ind_target = (float)$ts['target_per_orang'];
                            $t_target = (float)$ts['target_total'];
                            $t_hasil = (float)$ts['hasil_total'];
                            $pct_ts = $t_target > 0 ? round(($t_hasil / $t_target) * 100, 1) : 0;
                            $pct_color = $pct_ts >= 100 ? '#2e7d32' : ($pct_ts >= 80 ? '#e65100' : '#c62828');
                            $task_key = $ts['tanggal'] . '_' . $ts['aktivitas'];
                            $task_json = htmlspecialchars(json_encode($task_reports_map[$task_key] ?? []), ENT_QUOTES, 'UTF-8');
                            $task_title = 'Penugasan: ' . date('d-m-Y', strtotime($ts['tanggal'])) . ' (' . $ts['aktivitas'] . ')';
                        ?>
                            <tr>
                                <td><?php echo date('d-m-Y', strtotime($ts['tanggal'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($ts['aktivitas']); ?></strong></td>
                                <td><?php echo htmlspecialchars($ts['nama_mandor']); ?></td>
                                <td><?php echo (int)$ts['total_karyawan']; ?> Orang</td>
                                <td><?php echo number_format($ind_target, 2, '.', '') . ' ' . htmlspecialchars($ts['unit']); ?></td>
                                <td><strong><?php echo number_format($t_target, 2, '.', '') . ' ' . htmlspecialchars($ts['unit']); ?></strong></td>
                                <td><strong><?php echo number_format($t_hasil, 2, '.', '') . ' ' . htmlspecialchars($ts['unit']); ?></strong></td>
                                <td><strong style="color: <?php echo $pct_color; ?>;"><?php echo $pct_ts; ?>%</strong></td>
                                <td class="no-print" style="text-align: center;">
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            onclick='openGenericReportModal(<?php echo json_encode($task_title); ?>, <?php echo $task_json; ?>)'
                                            style="padding: 3px 10px; font-size: 0.76rem;">
                                        <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Rincian
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

<!-- ================= TAB 2: RANKING PRODUKTIVITAS & INSENTIF ================= -->
<div id="tab-ranking-insentif" class="tab-content-panel" style="display: none;">
    <!-- Grafik Ranking Produktivitas Karyawan -->
    <?php if (!empty($rekap_karyawan)): ?>
    <div class="card glass-panel no-print" style="margin-bottom: 20px; padding: 18px;">
        <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-color); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-chart-column" style="color: var(--primary-light);"></i> Grafik Peringkat Rata-Rata Capaian Seluruh Karyawan (%)
        </h4>
        <div style="height: 220px; position: relative;">
            <canvas id="prodRankingChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <div class="card glass-panel" style="margin-bottom: 25px;">
        <div class="card-title no-print">
            <span><i class="fa-solid fa-trophy" style="color: var(--primary);"></i> Peringkat Produktivitas &amp; Insentif Karyawan</span>
            <button onclick="window.print()" class="btn btn-gold btn-sm no-print"><i class="fa-solid fa-print"></i> Cetak Laporan</button>
        </div>

        <h3 class="print-only" style="font-family: 'Times New Roman', Times, serif; font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; display:none;">2. Peringkat Kinerja &amp; Rekapitulasi Insentif Bonus Karyawan</h3>

        <?php if (empty($rekap_karyawan)): ?>
            <div style="text-align: center; padding: 30px 20px; color: var(--text-muted);">Tidak ada data produktivitas.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Karyawan</th>
                            <th>Laporan</th>
                            <th>Rata-rata Capaian</th>
                            <th>Total Bonus</th>
                            <th>Total Sanksi</th>
                            <th>Bonus Bersih</th>
                            <th class="no-print" style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach ($rekap_karyawan as $row):
                            $emp_id = (int)($row['id_karyawan'] ?? 0);
                            $avg = round((float)$row['avg_pencapaian'], 1);
                            $color = $avg >= 100 ? 'var(--primary-light)' : ($avg >= 80 ? 'var(--gold)' : 'var(--danger)');
                            $emp_json = htmlspecialchars(json_encode($emp_reports_map[$emp_id] ?? []), ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr>
                                <td><strong>#<?php echo $rank++; ?></strong></td>
                                <td><strong><?php echo htmlspecialchars($row['nama']); ?></strong></td>
                                <td><?php echo (int)$row['jumlah_laporan']; ?></td>
                                <td><strong style="color: <?php echo $color; ?>;"><?php echo $avg; ?>%</strong></td>
                                <td style="color: var(--success);">Rp <?php echo number_format($row['total_bonus'], 0, ',', '.'); ?></td>
                                <td style="color: #c62828;">Rp <?php echo number_format($row['total_sanksi'], 0, ',', '.'); ?></td>
                                <td><strong>Rp <?php echo number_format($row['net_bonus'], 0, ',', '.'); ?></strong></td>
                                <td class="no-print" style="text-align: center;">
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            onclick='openEmployeeReportModal(<?php echo $emp_id; ?>, <?php echo json_encode($row["nama"]); ?>, <?php echo $emp_json; ?>)'
                                            style="padding: 3px 10px; font-size: 0.76rem;">
                                        <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Rincian
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

<!-- ================= TAB 3: REKAP PER AKTIVITAS ================= -->
<div id="tab-rekap-aktivitas" class="tab-content-panel" style="display: none;">
    <!-- Grafik Realisasi per Aktivitas -->
    <?php if (!empty($rekap_aktivitas)): ?>
    <div class="card glass-panel no-print" style="margin-bottom: 20px; padding: 18px;">
        <h4 style="font-size: 0.95rem; font-weight: 700; color: var(--text-color); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-chart-pie" style="color: var(--primary-light);"></i> Grafik Perbandingan Realisasi per Jenis Pekerjaan
        </h4>
        <div style="height: 220px; position: relative;">
            <canvas id="prodActivityChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <div class="card glass-panel" style="margin-bottom: 25px;">
        <div class="card-title no-print">
            <span><i class="fa-solid fa-chart-pie" style="color: var(--primary);"></i> Rekap Hasil Kerja per Jenis Pekerjaan</span>
            <button onclick="window.print()" class="btn btn-gold btn-sm no-print"><i class="fa-solid fa-print"></i> Cetak Laporan</button>
        </div>

        <h3 class="print-only" style="font-family: 'Times New Roman', Times, serif; font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; display:none;">3. Rekapitulasi Hasil Kerja per Jenis Pekerjaan</h3>

        <?php if (empty($rekap_aktivitas)): ?>
            <div style="text-align: center; padding: 30px 20px; color: var(--text-muted);">Tidak ada data.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Aktivitas</th>
                            <th>Karyawan Terlibat</th>
                            <th>Jml Laporan</th>
                            <th>Total Target</th>
                            <th>Total Realisasi</th>
                            <th>Surplus / Defisit</th>
                            <th>Rata-rata Capaian</th>
                            <th>Bonus Kinerja</th>
                            <th>Status Evaluasi</th>
                            <th class="no-print" style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rekap_aktivitas as $row):
                            $avg = round((float)$row['avg_pencapaian'], 1);
                            $target_val = (float)$row['total_target'];
                            $real_val = (float)$row['total_realisasi'];
                            $diff = $real_val - $target_val;
                            $diff_str = ($diff >= 0 ? '+' : '') . number_format($diff, 2) . ' ' . htmlspecialchars($row['unit']);
                            $diff_color = $diff >= 0 ? '#2e7d32' : '#c62828';
                            
                            $status_label = 'Sangat Produktif';
                            $status_bg = '#dcfce7';
                            $status_text_color = '#15803d';
                            if ($avg < 80) {
                                $status_label = 'Perlu Evaluasi';
                                $status_bg = '#fee2e2';
                                $status_text_color = '#b91c1c';
                            } elseif ($avg < 100) {
                                $status_label = 'Cukup Produktif';
                                $status_bg = '#fef9c3';
                                $status_text_color = '#a16207';
                            }
                            $act_json = htmlspecialchars(json_encode($activity_reports_map[$row['aktivitas']] ?? []), ENT_QUOTES, 'UTF-8');
                            $act_title = 'Jenis Pekerjaan: ' . $row['aktivitas'];
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['aktivitas']); ?></strong></td>
                                <td><?php echo (int)$row['total_karyawan']; ?> Orang</td>
                                <td><?php echo (int)$row['jumlah_laporan']; ?> Transaksi</td>
                                <td><?php echo number_format($target_val, 2); ?> <?php echo htmlspecialchars($row['unit']); ?></td>
                                <td><strong><?php echo number_format($real_val, 2); ?> <?php echo htmlspecialchars($row['unit']); ?></strong></td>
                                <td><strong style="color: <?php echo $diff_color; ?>;"><?php echo $diff_str; ?></strong></td>
                                <td><strong style="color: <?php echo $status_text_color; ?>;"><?php echo $avg; ?>%</strong></td>
                                <td style="color: #2e7d32; font-weight: 700;">+Rp <?php echo number_format((float)$row['total_bonus'], 0, ',', '.'); ?></td>
                                <td>
                                    <span class="badge" style="background: <?php echo $status_bg; ?>; color: <?php echo $status_text_color; ?>; padding: 4px 10px; border-radius: 6px; font-weight: 700;">
                                        <?php echo $status_label; ?>
                                    </span>
                                </td>
                                <td class="no-print" style="text-align: center;">
                                    <button type="button" class="btn btn-secondary btn-sm"
                                            onclick='openGenericReportModal(<?php echo json_encode($act_title); ?>, <?php echo $act_json; ?>)'
                                            style="padding: 3px 10px; font-size: 0.76rem;">
                                        <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Rincian
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

<!-- ================= TAB 4: PRODUKTIVITAS PENGAWASAN MANDOR ================= -->
<div id="tab-produktivitas-mandor" class="tab-content-panel" style="display: none;">
    <div class="card glass-panel" style="margin-bottom: 25px;">
        <div class="card-title no-print">
            <span><i class="fa-solid fa-user-tie" style="color: var(--primary);"></i> Laporan Produktivitas Pengawasan Mandor</span>
            <button onclick="window.print()" class="btn btn-gold btn-sm no-print"><i class="fa-solid fa-print"></i> Cetak Laporan</button>
        </div>

        <h3 class="print-only" style="font-family: 'Times New Roman', Times, serif; font-size: 1.1rem; font-weight: bold; margin-bottom: 10px; display:none;">4. Rekapitulasi Produktivitas Pengawasan Mandor (Penanggung Jawab)</h3>

        <p style="color: var(--text-muted); font-size: 0.83rem; margin-top: -8px; margin-bottom: 15px;" class="no-print">
            Mandor juga berstatus staf yang produktivitasnya dinilai - bukan dari tonase panen/pupuk/semprot (karena itu bukan tugasnya), melainkan dari volume laporan yang berhasil ditangani sebagai penanggung jawab verifikasi. Diurutkan dari yang menangani laporan paling banyak.
        </p>

        <?php if (empty($rekap_mandor)): ?>
            <div style="text-align: center; padding: 30px 20px; color: var(--text-muted);">Belum ada data mandor terdaftar.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="text-align: center; width: 6%;">Rank</th>
                            <th style="width: 20%;">Nama Mandor (Penanggung Jawab)</th>
                            <th style="width: 12%;">Spesialisasi</th>
                            <th style="text-align: center; width: 9%;">Total Masuk</th>
                            <th style="text-align: center; width: 10%;">Diverifikasi</th>
                            <th style="text-align: center; width: 11%;">Tertahan (Pending)</th>
                            <th style="text-align: center; width: 8%;">Disetujui</th>
                            <th style="text-align: center; width: 8%;">Ditolak</th>
                            <th style="text-align: center; width: 9%;">Manipulasi Tertangkap</th>
                            <th style="text-align: center; width: 10%;">Rata-rata Waktu Respons</th>
                            <th class="no-print" style="text-align: center; width: 7%;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank_m = 1; foreach ($rekap_mandor as $rm):
                            $jam = $rm['avg_jam_respon'] !== null ? round((float)$rm['avg_jam_respon'], 1) : null;
                            $tot_masuk = (int)($rm['total_laporan_masuk'] ?? 0);
                            $tot_diver = (int)($rm['jumlah_diverifikasi'] ?? 0);
                            $tot_tertahan = (int)($rm['jumlah_tertahan'] ?? 0);
                        ?>
                            <tr>
                                <td style="text-align:center;">
                                    <?php if ($rank_m === 1 && $tot_diver > 0): ?>
                                        <span class="badge" style="background:#2e7d321a; color:#2e7d32; border:1px solid #2e7d3244; font-weight:700;"><i class="fa-solid fa-medal"></i> #1</span>
                                    <?php else: ?>
                                        #<?php echo $rank_m; ?>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo htmlspecialchars($rm['nama']); ?></strong></td>
                                <td><?php echo htmlspecialchars($rm['spesialisasi']); ?></td>
                                <td style="text-align: center;"><?php echo $tot_masuk; ?> Laporan</td>
                                <td style="text-align: center; color: var(--success); font-weight: 700;"><?php echo $tot_diver; ?> Laporan</td>
                                <td style="text-align: center;">
                                    <?php 
                                        $m_pending_list = $pending_by_mandor[$rm['nama']] ?? [];
                                        $m_pending_json = htmlspecialchars(json_encode($m_pending_list), ENT_QUOTES, 'UTF-8');
                                        $m_pending_title = 'Laporan Tertahan Pengawasan: Mandor ' . $rm['nama'];
                                    ?>
                                    <?php if ($tot_tertahan > 0): ?>
                                        <button type="button" class="btn btn-sm no-print" 
                                                onclick='openGenericReportModal(<?php echo json_encode($m_pending_title); ?>, <?php echo $m_pending_json; ?>)'
                                                style="background:#ffebee; color:#c62828; border:1px solid #ef9a9a; font-weight:700; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 0.78rem;"
                                                title="Klik untuk melihat rincian laporan tertahan">
                                            <i class="fa-solid fa-hourglass-half"></i> <?php echo $tot_tertahan; ?> Tertahan
                                        </button>
                                        <span class="print-only" style="display:none; color: #c62828; font-weight: bold;"><?php echo $tot_tertahan; ?> Tertahan</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.82rem;">0 (Lancar)</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center; color:#2e7d32; font-weight:700;"><?php echo (int)$rm['jumlah_disetujui']; ?></td>
                                <td style="text-align: center; color:#c62828; font-weight:700;"><?php echo (int)$rm['jumlah_ditolak']; ?></td>
                                <td style="text-align: center;">
                                    <?php if ((int)$rm['jumlah_manipulasi_tertangkap'] > 0): ?>
                                        <span class="badge" style="background:#e651001a; color:#e65100; border:1px solid #e6510044; font-weight:700;"><?php echo (int)$rm['jumlah_manipulasi_tertangkap']; ?> kasus</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">0 kasus</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($jam === null): ?>
                                        <span style="color: var(--text-muted);">Belum ada aktivitas</span>
                                    <?php else: ?>
                                        <strong><?php echo $jam >= 24 ? round($jam / 24, 1) . ' hari' : $jam . ' jam'; ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td class="no-print" style="text-align: center;">
                                    <?php 
                                        $m_rep_list = $reports_by_mandor[$rm['nama']] ?? [];
                                        $m_rep_json = htmlspecialchars(json_encode($m_rep_list), ENT_QUOTES, 'UTF-8');
                                        $m_rep_title = 'Rincian Pengawasan Laporan: Mandor ' . $rm['nama'];
                                    ?>
                                    <?php if (!empty($m_rep_list)): ?>
                                        <button type="button" class="btn btn-secondary btn-sm"
                                                onclick='openGenericReportModal(<?php echo json_encode($m_rep_title); ?>, <?php echo $m_rep_json; ?>)'
                                                style="padding: 3px 10px; font-size: 0.76rem;">
                                            <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Rincian
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.78rem;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php $rank_m++; endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Blok Tanda Tangan Resmi (Cetak Only) -->
<div class="print-only" style="display:none; margin-top: 40px; page-break-inside: avoid;">
    <p style="text-align: right; font-family: 'Times New Roman', Times, serif; font-size: 0.9rem; color: #000; margin-bottom: 30px;">Jambi, <?php echo date('d F Y'); ?></p>
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; gap: 20px; font-family: 'Times New Roman', Times, serif; font-size: 0.85rem; color: #000;">
        <?php foreach ($rekap_mandor as $rm): ?>
            <div style="width: 180px; text-align: center;">
                <p style="margin-bottom: 55px;">Mandor Penanggung Jawab,</p>
                <p style="border-top: 1px solid #000; padding-top: 5px; margin: 0;"><strong><?php echo htmlspecialchars($rm['nama']); ?></strong><br>Mandor <?php echo htmlspecialchars($rm['spesialisasi']); ?></p>
            </div>
        <?php endforeach; ?>
        <div style="width: 200px; text-align: center;">
            <p style="margin-bottom: 55px;">Disetujui oleh,</p>
            <p style="border-top: 1px solid #000; padding-top: 5px; margin: 0;"><strong><?php echo htmlspecialchars($nama); ?></strong><br>Estate Manager</p>
        </div>
    </div>
</div>

<!-- Modal Rincian Laporan Karyawan -->
<div id="employeeReportModal" class="modal no-print" style="display:none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.7); overflow-y: auto; backdrop-filter: blur(4px);">
    <div class="modal-dialog" style="background: #ffffff; margin: 30px auto; max-width: 850px; border-radius: 14px; padding: 0; box-shadow: 0 20px 40px rgba(0,0,0,0.3); overflow: hidden; color: #1e293b;">
        <div style="background: #f8fafc; padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 id="empModalTitle" style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Rincian Laporan Kerja Karyawan
                </h3>
                <p style="margin: 3px 0 0 0; font-size: 0.8rem; color: #64748b;">Daftar transaksi pekerjaan fisik dan status insentif/sanksi</p>
            </div>
            <button onclick="closeEmployeeReportModal()" style="background: transparent; border: none; font-size: 1.6rem; cursor: pointer; color: #64748b; line-height: 1;">&times;</button>
        </div>
        <div style="padding: 24px; max-height: 75vh; overflow-y: auto;">
            <div class="table-responsive">
                <table class="table" style="width: 100%; font-size: 0.83rem;">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Aktivitas</th>
                            <th>Mandor</th>
                            <th>Target Dasar</th>
                            <th>Realisasi</th>
                            <th>Status Alur</th>
                            <th>Bonus / Penalti</th>
                        </tr>
                    </thead>
                    <tbody id="empModalBody">
                    </tbody>
                </table>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 14px 24px; border-top: 1px solid #e2e8f0; text-align: right;">
            <button onclick="closeEmployeeReportModal()" class="btn btn-secondary btn-sm" style="padding: 7px 22px; font-weight: 600; border-radius: 6px;">Tutup</button>
        </div>
    </div>
</div>

<!-- Modal Rincian Generic (Penugasan / Aktivitas) -->
<div id="genericReportModal" class="modal no-print" style="display:none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.7); overflow-y: auto; backdrop-filter: blur(4px);">
    <div class="modal-dialog" style="background: #ffffff; margin: 30px auto; max-width: 850px; border-radius: 14px; padding: 0; box-shadow: 0 20px 40px rgba(0,0,0,0.3); overflow: hidden; color: #1e293b;">
        <div style="background: #f8fafc; padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 id="genModalTitle" style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Rincian Data Laporan
                </h3>
                <p style="margin: 3px 0 0 0; font-size: 0.8rem; color: #64748b;">Rincian transaksi laporan kerja per kategori</p>
            </div>
            <button onclick="closeGenericReportModal()" style="background: transparent; border: none; font-size: 1.6rem; cursor: pointer; color: #64748b; line-height: 1;">&times;</button>
        </div>
        <div style="padding: 24px; max-height: 75vh; overflow-y: auto;">
            <div class="table-responsive">
                <table class="table" style="width: 100%; font-size: 0.83rem;">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Karyawan</th>
                            <th>Mandor</th>
                            <th>Aktivitas</th>
                            <th>Target Dasar</th>
                            <th>Realisasi</th>
                            <th>Status Alur</th>
                        </tr>
                    </thead>
                    <tbody id="genModalBody">
                    </tbody>
                </table>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 14px 24px; border-top: 1px solid #e2e8f0; text-align: right;">
            <button onclick="closeGenericReportModal()" class="btn btn-secondary btn-sm" style="padding: 7px 22px; font-weight: 600; border-radius: 6px;">Tutup</button>
        </div>
    </div>
</div>

<style>
    @media screen { 
        .print-only { display: none !important; } 
    }
    @media print {
        @page { size: A4 portrait; margin: 1.5cm; }
        html, body, body.has-sidebar { background: #fff !important; margin: 0 !important; padding: 0 !important; width: 100% !important; font-family: "Times New Roman", Times, serif !important; font-size: 8.5pt !important; color: #000 !important; }
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
            margin: 0 !important;
            padding: 0 !important;
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
        td:first-child { white-space: nowrap !important; }
        .badge { background: transparent !important; border: none !important; color: #000 !important; font-weight: bold !important; padding: 0 !important; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 0. Chart Rekap Penugasan (Grouped Bar Chart)
    const ctxTask = document.getElementById('taskSummaryChart');
    if (ctxTask) {
        <?php
        $ts_labels = [];
        $ts_targets = [];
        $ts_outputs = [];
        foreach (array_slice(array_reverse($task_summary), 0, 8) as $t_item) {
            $ts_labels[] = date('d M', strtotime($t_item['tanggal'])) . ' - ' . $t_item['aktivitas'];
            $ts_targets[] = (float)$t_item['target_total'];
            $ts_outputs[] = (float)$t_item['hasil_total'];
        }
        ?>
        new Chart(ctxTask, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($ts_labels); ?>,
                datasets: [
                    {
                        label: 'Target Penugasan',
                        data: <?php echo json_encode($ts_targets); ?>,
                        backgroundColor: 'rgba(212, 175, 55, 0.85)',
                        borderColor: '#d4af37',
                        borderWidth: 1,
                        borderRadius: 4
                    },
                    {
                        label: 'Yang Dihasilkan',
                        data: <?php echo json_encode($ts_outputs); ?>,
                        backgroundColor: 'rgba(46, 125, 50, 0.85)',
                        borderColor: '#2e7d32',
                        borderWidth: 1,
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'top' } },
                scales: {
                    y: { beginAtZero: true },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 1. Chart Ranking Produktivitas Karyawan (Bar Chart)
    const ctxRank = document.getElementById('prodRankingChart');
    if (ctxRank) {
        <?php
        $rank_names = [];
        $rank_percents = [];
        foreach ($rekap_karyawan as $rk) {
            $rank_names[] = $rk['nama'];
            $rank_percents[] = round((float)$rk['avg_pencapaian'], 1);
        }
        ?>
        new Chart(ctxRank, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($rank_names); ?>,
                datasets: [{
                    label: 'Rata-Rata Capaian (%)',
                    data: <?php echo json_encode($rank_percents); ?>,
                    backgroundColor: 'rgba(46, 125, 50, 0.85)',
                    borderColor: '#1e5235',
                    borderWidth: 1.5,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 2. Chart Perbandingan Realisasi per Aktivitas (Doughnut Chart dengan Persentase % di Sumbu Lingkaran)
    const ctxAct = document.getElementById('prodActivityChart');
    if (ctxAct) {
        <?php
        $act_names = [];
        $act_totals = [];
        $sum_total_realisasi = 0;
        foreach ($rekap_aktivitas as $act) {
            $sum_total_realisasi += (float)$act['total_realisasi'];
        }
        foreach ($rekap_aktivitas as $act) {
            $val = (float)$act['total_realisasi'];
            $pct = $sum_total_realisasi > 0 ? round(($val / $sum_total_realisasi) * 100, 1) : 0;
            $act_names[] = $act['aktivitas'] . ' (' . $pct . '% - ' . number_format($val, 2) . ' ' . $act['unit'] . ')';
            $act_totals[] = $val;
        }
        ?>

        const slicePercentagePlugin = {
            id: 'slicePercentagePlugin',
            afterDraw(chart) {
                const { ctx } = chart;
                ctx.save();
                chart.data.datasets.forEach((dataset, i) => {
                    const meta = chart.getDatasetMeta(i);
                    const total = dataset.data.reduce((a, b) => a + b, 0);
                    if (!total || total === 0) return;

                    meta.data.forEach((element, index) => {
                        const value = dataset.data[index];
                        if (!value || value === 0) return;
                        
                        const percentage = ((value / total) * 100).toFixed(1) + '%';
                        const { x, y, startAngle, endAngle, innerRadius, outerRadius } = element;
                        const midAngle = startAngle + (endAngle - startAngle) / 2;
                        const radius = innerRadius + (outerRadius - innerRadius) * 0.55;
                        
                        const posX = x + Math.cos(midAngle) * radius;
                        const posY = y + Math.sin(midAngle) * radius;
                        
                        ctx.fillStyle = '#ffffff';
                        ctx.font = 'bold 13px system-ui, -apple-system, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        
                        ctx.shadowColor = 'rgba(0, 0, 0, 0.7)';
                        ctx.shadowBlur = 4;
                        ctx.shadowOffsetX = 1;
                        ctx.shadowOffsetY = 1;
                        
                        ctx.fillText(percentage, posX, posY);
                    });
                });
                ctx.restore();
            }
        };

        new Chart(ctxAct, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($act_names); ?>,
                datasets: [{
                    data: <?php echo json_encode($act_totals); ?>,
                    backgroundColor: ['#2e7d32', '#0d6efd', '#e0a800', '#9c27b0', '#e65100'],
                    borderWidth: 2
                }]
            },
            plugins: [slicePercentagePlugin],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { 
                        position: 'right',
                        labels: {
                            font: { size: 12, weight: 'bold' },
                            padding: 12
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return ` Realisasi: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
});

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

function openEmployeeReportModal(empId, empName, reportsData) {
    const titleEl = document.getElementById('empModalTitle');
    if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-user-check" style="color: var(--primary-light);"></i> Rincian Laporan: <strong>${empName}</strong>`;
    
    const bodyEl = document.getElementById('empModalBody');
    if (!bodyEl) return;
    
    if (!reportsData || reportsData.length === 0) {
        bodyEl.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 20px;">Tidak ada data laporan fisik.</td></tr>`;
    } else {
        let rowsHtml = '';
        reportsData.forEach(r => {
            let bonusStr = '-';
            if (r.bonus_diterima > 0) bonusStr = `<strong style="color: var(--success);">+Rp ${r.bonus_diterima.toLocaleString('id-ID')}</strong>`;
            else if (r.bonus_diterima < 0) bonusStr = `<strong style="color: #c62828;">-Rp ${Math.abs(r.bonus_diterima).toLocaleString('id-ID')}</strong>`;
            
            rowsHtml += `
                <tr>
                    <td>${r.tanggal}</td>
                    <td><strong>${r.aktivitas}</strong></td>
                    <td>${r.nama_mandor}</td>
                    <td>${r.target_jumlah} ${r.unit}</td>
                    <td><strong>${r.jumlah_realisasi} ${r.unit}</strong></td>
                    <td><span class="badge" style="background:#0d6efd1a; color:#0d6efd; border:1px solid #0d6efd44;">${r.status_label || r.status}</span></td>
                    <td>${bonusStr}</td>
                </tr>
            `;
        });
        bodyEl.innerHTML = rowsHtml;
    }
    
    const modal = document.getElementById('employeeReportModal');
    if (modal) modal.style.display = 'block';
}

function closeEmployeeReportModal() {
    const modal = document.getElementById('employeeReportModal');
    if (modal) modal.style.display = 'none';
}

function openGenericReportModal(titleStr, reportsData) {
    const titleEl = document.getElementById('genModalTitle');
    if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> ${titleStr}`;
    
    const bodyEl = document.getElementById('genModalBody');
    if (!bodyEl) return;
    
    if (!reportsData || reportsData.length === 0) {
        bodyEl.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 20px;">Tidak ada data laporan.</td></tr>`;
    } else {
        let rowsHtml = '';
        reportsData.forEach(r => {
            rowsHtml += `
                <tr>
                    <td>${r.tanggal}</td>
                    <td><strong>${r.nama_karyawan}</strong></td>
                    <td>${r.nama_mandor}</td>
                    <td>${r.aktivitas}</td>
                    <td>${r.target_jumlah} ${r.unit}</td>
                    <td><strong>${r.jumlah_realisasi} ${r.unit}</strong></td>
                    <td><span class="badge" style="background:#0d6efd1a; color:#0d6efd; border:1px solid #0d6efd44;">${r.status_label || r.status}</span></td>
                </tr>
            `;
        });
        bodyEl.innerHTML = rowsHtml;
    }
    
    const modal = document.getElementById('genericReportModal');
    if (modal) modal.style.display = 'block';
}

function closeGenericReportModal() {
    const modal = document.getElementById('genericReportModal');
    if (modal) modal.style.display = 'none';
}
</script>

<?php require_once '../includes/footer.php'; ?>
