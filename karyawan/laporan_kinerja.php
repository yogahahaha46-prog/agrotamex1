<?php
// karyawan/laporan_kinerja.php
// LAPORAN KINERJA KARYAWAN: Rekapitulasi hasil kerja, pencapaian target, dan bonus/sanksi individual.
require_once '../config/koneksi.php';
require_once '../includes/header.php';

// Validate role
if ($_SESSION['role'] !== 'karyawan') {
    header("Location: ../index.php");
    exit;
}

$karyawan_id = $_SESSION['user_id'];
$nama = $_SESSION['nama'] ?? 'Karyawan';
$error = "";
$history_reports = [];

// 1. Capture search/filter parameters
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$filter_activity = isset($_GET['aktivitas']) ? trim($_GET['aktivitas']) : '';

// 2. Build filtered SQL query
$query_str = "
    SELECT r.id, r.jumlah_realisasi, r.foto_bukti, r.catatan_karyawan, r.status, 
           r.catatan_mandor, r.catatan_manajer, r.tanggal_verifikasi_mandor, r.bonus_diterima, r.potongan_penalti, r.created_at as tanggal_lapor,
           a.tanggal as tanggal_tugas, a.aktivitas, a.target_jumlah, a.unit,
           m.nama as nama_mandor
    FROM work_reports r
    JOIN assignments a ON r.id_assignment = a.id
    JOIN mandor m ON a.id_mandor = m.id_mandor
    WHERE r.id_karyawan = ?
";
$params = [$karyawan_id];

if (!empty($start_date)) {
    $query_str .= " AND a.tanggal >= ?";
    $params[] = $start_date;
}
if (!empty($end_date)) {
    $query_str .= " AND a.tanggal <= ?";
    $params[] = $end_date;
}
if (!empty($filter_activity)) {
    $query_str .= " AND a.aktivitas = ?";
    $params[] = $filter_activity;
}

$query_str .= " ORDER BY a.tanggal DESC, r.created_at DESC";

try {
    $stmt = $pdo->prepare($query_str);
    $stmt->execute($params);
    $history_reports = $stmt->fetchAll();
} catch (\PDOException $e) {
    $error = "Gagal memuat histori kinerja: " . $e->getMessage();
}

// 3. Fetch last 10 approved reports for productivity trend chart
$chart_reports = [];
$chart_labels = [];
$chart_actuals = [];
try {
    $stmt_chart = $pdo->prepare("
        SELECT a.tanggal, a.aktivitas, r.jumlah_realisasi
        FROM work_reports r
        JOIN assignments a ON r.id_assignment = a.id
        WHERE r.id_karyawan = ? AND r.status = 'approved'
        ORDER BY a.tanggal ASC LIMIT 10
    ");
    $stmt_chart->execute([$karyawan_id]);
    $chart_reports = $stmt_chart->fetchAll();

    foreach ($chart_reports as $c) {
        $chart_labels[] = date('d M', strtotime($c['tanggal']));
        $chart_actuals[] = (float)$c['jumlah_realisasi'];
    }
} catch (\PDOException $e) {
    // Fail silently
}

// 4. Calculate cumulative bonus statistics
$total_net_bonus = 0.00;
$total_gross_bonus = 0.00;
$total_penalty_deduction = 0.00;

try {
    $stmt_stats = $pdo->prepare("
        SELECT 
            SUM(bonus_diterima) as net_bonus,
            SUM(CASE WHEN bonus_diterima > 0 THEN bonus_diterima ELSE 0 END) as gross_bonus,
            SUM(CASE WHEN bonus_diterima < 0 THEN ABS(bonus_diterima) ELSE 0 END) as penalty_deduction
        FROM work_reports 
        WHERE id_karyawan = ?
    ");
    $stmt_stats->execute([$karyawan_id]);
    $bonus_stats = $stmt_stats->fetch();
    
    $total_net_bonus = (float)($bonus_stats['net_bonus'] ?? 0);
    $total_gross_bonus = (float)($bonus_stats['gross_bonus'] ?? 0);
    $total_penalty_deduction = (float)($bonus_stats['penalty_deduction'] ?? 0);
} catch (\PDOException $e) {
    // Fail silently
}

// 5. Calculate average achievement index
$avg_achievement = 0;
try {
    $stmt_avg = $pdo->prepare("
        SELECT r.jumlah_realisasi, a.target_jumlah, r.potongan_penalti 
        FROM work_reports r
        JOIN assignments a ON r.id_assignment = a.id
        WHERE r.id_karyawan = ? AND (r.status = 'approved' OR r.potongan_penalti > 0) AND a.target_jumlah > 0
    ");
    $stmt_avg->execute([$karyawan_id]);
    $approved_rows = $stmt_avg->fetchAll();
    
    if (!empty($approved_rows)) {
        $total_percentage_sum = 0;
        foreach ($approved_rows as $row) {
            $real_val = (float)$row['jumlah_realisasi'];
            if ((float)$row['potongan_penalti'] > 0) {
                $real_val = 0;
            }
            $pct = ($real_val / (float)$row['target_jumlah']) * 100;
            $total_percentage_sum += min(100.0, $pct);
        }
        $avg_achievement = round($total_percentage_sum / count($approved_rows), 1);
    }
} catch (\PDOException $e) {
    // Fail silently
}

$predikat_text = "Kurang Produktif";
$predikat_color = "#c62828";
if ($avg_achievement >= 80) {
    $predikat_text = "Sangat Produktif";
    $predikat_color = "#2e7d32";
} elseif ($avg_achievement >= 60) {
    $predikat_text = "Cukup Produktif";
    $predikat_color = "#e65100";
}
?>

<div style="margin-bottom: 25px;" class="no-print">
    <a href="index.php" style="color: var(--text-muted); font-size: 0.9rem;"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
    <h2 style="margin-top: 10px;">Laporan Kinerja &amp; Produktivitas Anda</h2>
    <p style="color: var(--text-muted);">Pantau akumulasi bonus, pencapaian target kerja, dan riwayat laporan harian Anda</p>
</div>

<!-- Kop Surat (Cetak Only) -->
<div style="display:none;" class="print-only">
    <div style="display: flex; align-items: center; gap: 15px; border-bottom: 3px solid #000; padding-bottom: 12px; margin-bottom: 20px;">
        <img src="../assets/logo.png" alt="Logo PT" style="height: 50px; width: auto;">
        <div>
            <div style="font-size: 1.2rem; font-weight: 700; color: #000;">PT AGROTAMEX SUMINDO ABADI</div>
            <div style="font-size: 0.85rem; color: #333;">Sistem Pemantauan Produktivitas Karyawan Perkebunan</div>
        </div>
    </div>
    <div style="text-align: center; margin-bottom: 20px;">
        <h2 style="color: #000; margin-bottom: 4px; text-decoration: underline;">LAPORAN KINERJA KARYAWAN</h2>
        <p style="font-size: 0.85rem; color: #333; margin-top: 5px;">
            Nama Karyawan: <strong><?php echo htmlspecialchars($nama); ?></strong> 
            &nbsp;|&nbsp; Dicetak: <?php echo date('d-m-Y H:i'); ?>
            <?php if (!empty($start_date) || !empty($end_date)): ?>
                &nbsp;|&nbsp; Periode: <?php echo !empty($start_date) ? htmlspecialchars($start_date) : 'Awal Data'; ?> s/d <?php echo !empty($end_date) ? htmlspecialchars($end_date) : 'Sekarang'; ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger no-print"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Filter Panel Inline (Kompak & Simpel) -->
<div class="card glass-panel no-print" style="margin-bottom: 20px; padding: 14px 18px;">
    <form method="GET" action="laporan_kinerja.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0;">
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
        
        <div style="display: flex; gap: 6px; margin-left: auto;">
            <a href="laporan_kinerja.php" class="btn btn-secondary" style="padding: 0 12px; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; height: 36px; border-radius: 6px;" title="Reset Filter"><i class="fa-solid fa-rotate-left"></i></a>
            <button type="submit" class="btn btn-primary" style="padding: 0 16px; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; gap: 6px; height: 36px; border-radius: 6px;"><i class="fa-solid fa-magnifying-glass"></i> Cari</button>
        </div>
    </form>
</div>

<!-- Ringkasan Bonus & Indeks Kinerja -->
<div class="grid-4 no-print" style="margin-bottom: 25px;">
    <div class="card glass-panel" style="display: flex; align-items: center; gap: 14px; padding: 15px 18px; border-left: 5px solid var(--primary-light);">
        <div style="background: rgba(46,125,50,0.1); padding: 10px; border-radius: 50%; width: 44px; height: 44px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <i class="fa-solid fa-wallet" style="font-size: 1.3rem; color: var(--primary-light);"></i>
        </div>
        <div>
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Saldo Bonus Bersih</div>
            <div style="font-size: 1.25rem; font-weight: 700; color: var(--primary);">Rp <?php echo number_format($total_net_bonus, 0, ',', '.'); ?></div>
        </div>
    </div>

    <div class="card glass-panel" style="display: flex; align-items: center; gap: 14px; padding: 15px 18px; border-left: 5px solid #2e7d32;">
        <div style="background: rgba(46,125,50,0.1); padding: 10px; border-radius: 50%; width: 44px; height: 44px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <i class="fa-solid fa-gift" style="font-size: 1.3rem; color: #2e7d32;"></i>
        </div>
        <div>
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Total Akumulasi Bonus</div>
            <div style="font-size: 1.25rem; font-weight: 700; color: #2e7d32;">Rp <?php echo number_format($total_gross_bonus, 0, ',', '.'); ?></div>
        </div>
    </div>

    <div class="card glass-panel" style="display: flex; align-items: center; gap: 14px; padding: 15px 18px; border-left: 5px solid #c62828;">
        <div style="background: rgba(198,40,40,0.1); padding: 10px; border-radius: 50%; width: 44px; height: 44px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <i class="fa-solid fa-circle-minus" style="font-size: 1.3rem; color: #c62828;"></i>
        </div>
        <div>
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Total Sanksi Denda</div>
            <div style="font-size: 1.25rem; font-weight: 700; color: #c62828;">Rp <?php echo number_format($total_penalty_deduction, 0, ',', '.'); ?></div>
        </div>
    </div>

    <div class="card glass-panel" style="display: flex; align-items: center; gap: 14px; padding: 15px 18px; border-left: 5px solid <?php echo $predikat_color; ?>;">
        <div style="background: rgba(0,0,0,0.05); padding: 10px; border-radius: 50%; width: 44px; height: 44px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
            <i class="fa-solid fa-gauge-high" style="font-size: 1.3rem; color: <?php echo $predikat_color; ?>;"></i>
        </div>
        <div>
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Indeks Kinerja (Avg)</div>
            <div style="font-size: 1.25rem; font-weight: 700; color: <?php echo $predikat_color; ?>;"><?php echo $avg_achievement; ?>%</div>
        </div>
    </div>
</div>

<p class="no-print" style="color: var(--text-muted); font-size: 0.8rem; margin-top: -15px; margin-bottom: 20px;">
    <i class="fa-solid fa-circle-info" style="color: var(--primary);"></i> <strong>Ketentuan Sanksi:</strong> Apabila terbukti melakukan manipulasi data/pelanggaran (sanksi dikonfirmasi), akumulasi bonus secara otomatis dipotong sebesar 10%.
</p>

<!-- Chart Card (No Print) -->
<?php if (!empty($chart_reports)): ?>
<div class="card glass-panel no-print" style="margin-bottom: 25px;">
    <h3 class="card-title"><i class="fa-solid fa-chart-line" style="color: var(--primary);"></i> Tren Produktivitas Hasil Kerja Anda</h3>
    <div style="height: 230px; position: relative;">
        <canvas id="karyawanHistoryChart"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Tabel Histori Laporan -->
<div class="card glass-panel">
    <div class="card-title">
        <span>Histori Laporan Kinerja (<?php echo count($history_reports); ?> Data)</span>
        <button onclick="window.print()" class="btn btn-gold btn-sm no-print">
            <i class="fa-solid fa-print"></i> Cetak Laporan
        </button>
    </div>

    <?php if (empty($history_reports)): ?>
        <div style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
            Belum ada histori laporan kinerja yang cocok dengan filter pencarian.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Aktivitas</th>
                        <th>Target</th>
                        <th>Hasil</th>
                        <th>Capaian (%)</th>
                        <th>Status</th>
                        <th>Bonus</th>
                        <th class="no-print">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $status_labels = [
                        'pending_mandor'        => 'Menunggu Mandor',
                        'verified_by_mandor'    => 'Terverifikasi Mandor',
                        'approved'              => 'Disetujui',
                        'rejected'              => 'Ditolak',
                        'pending_manajer_tolak' => 'Tinjauan Sanksi',
                    ];
                    $status_colors = [
                        'pending_mandor'        => '#e0a800',
                        'verified_by_mandor'    => '#0d6efd',
                        'approved'              => '#2e7d32',
                        'rejected'              => '#c62828',
                        'pending_manajer_tolak' => '#e65100',
                    ];

                    foreach ($history_reports as $row): 
                        $real_val = (float)$row['jumlah_realisasi'];
                        if ((float)$row['potongan_penalti'] > 0) {
                            $real_val = 0;
                        }
                        $pct = $row['target_jumlah'] > 0 ? round(($real_val / (float)$row['target_jumlah']) * 100, 1) : 0;
                        $color = $pct >= 100 ? 'var(--primary-light)' : ($pct >= 80 ? 'var(--gold)' : 'var(--danger)');

                        $detail_json = base64_encode(json_encode([
                            'nama_karyawan'    => $_SESSION['nama'] ?? 'Karyawan',
                            'nama_mandor'      => $row['nama_mandor'] ?? '-',
                            'tanggal_kerja'    => date('d F Y', strtotime($row['tanggal_tugas'])),
                            'aktivitas'        => $row['aktivitas'],
                            'target_jumlah'    => (float)$row['target_jumlah'],
                            'unit'             => $row['unit'],
                            'jumlah_realisasi' => (float)$row['jumlah_realisasi'],
                            'catatan_karyawan' => $row['catatan_karyawan'] ?? '',
                            'foto_bukti'       => !empty($row['foto_bukti']) ? '../' . $row['foto_bukti'] : '',
                            'status'           => $row['status'],
                            'status_label'     => $status_labels[$row['status']] ?? $row['status'],
                            'catatan_mandor'   => $row['catatan_mandor'] ?? '',
                            'waktu_mandor'     => $row['tanggal_verifikasi_mandor'] ? date('d-m-Y H:i', strtotime($row['tanggal_verifikasi_mandor'])) : '',
                            'catatan_manajer'  => $row['catatan_manajer'] ?? '',
                            'waktu_manajer'    => '',
                            'bonus_diterima'   => (float)($row['bonus_diterima'] ?? 0),
                        ]));
                    ?>
                        <tr>
                            <td><?php echo date('d-m-Y', strtotime($row['tanggal_tugas'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($row['aktivitas']); ?></strong></td>
                            <td><?php echo (float)$row['target_jumlah'] . ' ' . htmlspecialchars($row['unit']); ?></td>
                            <td><?php echo (float)$real_val . ' ' . htmlspecialchars($row['unit']); ?></td>
                            <td><strong style="color: <?php echo $color; ?>;"><?php echo $pct; ?>%</strong></td>
                            <td>
                                <span class="badge" style="background: <?php echo $status_colors[$row['status']]; ?>1a; color: <?php echo $status_colors[$row['status']]; ?>; border: 1px solid <?php echo $status_colors[$row['status']]; ?>44;">
                                    <?php echo $status_labels[$row['status']] ?? $row['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($row['bonus_diterima'] > 0): ?>
                                    <strong style="color: var(--success);">+Rp <?php echo number_format($row['bonus_diterima'], 0, ',', '.'); ?></strong>
                                <?php elseif ($row['bonus_diterima'] < 0): ?>
                                    <strong style="color: #c62828;">-Rp <?php echo number_format(abs($row['bonus_diterima']), 0, ',', '.'); ?></strong>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">-</span>
                                <?php endif; ?>
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
    <?php endif; ?>
</div>

<!-- Blok Tanda Tangan Resmi (Print Only) -->
<div class="print-only" style="display:none; margin-top: 40px; page-break-inside: avoid;">
    <p style="text-align: right; font-family: 'Times New Roman', Times, serif; font-size: 0.9rem; color: #000; margin-bottom: 25px;">Jambi, <?php echo date('d F Y'); ?></p>
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; font-family: 'Times New Roman', Times, serif; font-size: 0.85rem; color: #000;">
        <div style="width: 220px; text-align: center;">
            <p style="margin-bottom: 55px; margin-top: 0;">Pelaksana Pekerjaan,</p>
            <p style="border-top: 1px solid #000; padding-top: 5px; margin: 0;"><strong><?php echo htmlspecialchars($nama); ?></strong><br>Karyawan Lapangan</p>
        </div>
        <div style="width: 220px; text-align: center;">
            <p style="margin-bottom: 55px; margin-top: 0;">Pemeriksa / Verifikator,</p>
            <p style="border-top: 1px solid #000; padding-top: 5px; margin: 0;"><strong>Mandor Penanggung Jawab</strong><br>Mandor Lapangan</p>
        </div>
    </div>
</div>

<style>
    @media screen { .print-only { display: none !important; } }
    @media print {
        @page {
            size: A4 portrait;
            margin: 1cm;
        }
        *, html, body, div, p, span, h1, h2, h3, h4, h5, h6, table, th, td, tr, strong, b, small {
            font-family: "Times New Roman", Times, serif !important;
            color: #000 !important;
            border-color: #000 !important;
            background: transparent !important;
            box-shadow: none !important;
            text-shadow: none !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        i, .fa, .fas, .far, .fab, .fa-solid {
            display: none !important;
        }
        .print-only { display: block !important; }
        .glass-panel { background: #fff !important; color: #000 !important; border: 0 !important; }
        th { background: #f2f2f2 !important; color: #000 !important; border: 1px solid #000 !important; text-align: center !important; font-weight: bold !important; }
        td { border: 1px solid #000 !important; color: #000 !important; }
    }
</style>

<script>
<?php if (!empty($chart_reports)): ?>
document.addEventListener('DOMContentLoaded', () => {
    const labels = <?php echo json_encode($chart_labels); ?>;
    const values = <?php echo json_encode($chart_actuals); ?>;
    initProductivityChart('karyawanHistoryChart', labels, null, values);
});
<?php endif; ?>

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
    if (fotoImg) {
        if (dataObj.foto_bukti) {
            fotoImg.src = dataObj.foto_bukti;
            fotoImg.style.display = 'block';
            fotoImg.onclick = () => window.open(dataObj.foto_bukti, '_blank');
        } else {
            fotoImg.style.display = 'none';
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
    const bonusNum = Number(dataObj.bonus_diterima || 0);
    const targetNum = Number(dataObj.target_jumlah || 0);
    const realisasiNum = Number(dataObj.jumlah_realisasi || 0);
    const unitStr = dataObj.unit || '';

    if (bonusBox) {
        if (bonusNum > 0) {
            bonusBox.style.background = '#f0fdf4';
            bonusBox.style.borderColor = '#bbf7d0';
            setT('mon_bonus_val', '+Rp ' + bonusNum.toLocaleString('id-ID'));
            setS('mon_bonus_val', 'color', '#2e7d32');
            setT('mon_bonus_badge', 'Bonus Kinerja');
            setS('mon_bonus_badge', 'background', '#dcfce7');
            setS('mon_bonus_badge', 'color', '#15803d');
            const surplus = (realisasiNum - targetNum).toFixed(2);
            setH('mon_bonus_breakdown', `
                <div><strong>Ketentuan:</strong> Realisasi (${realisasiNum} ${unitStr}) melampaui Target Dasar (${targetNum} ${unitStr}).</div>
                <div><strong>Surplus Hasil:</strong> +${surplus} ${unitStr} (Surplus Produktif).</div>
                <div><strong>Skema Insentif:</strong> Nominal bonus dihitung dari kelebihan hasil kerja fisik.</div>
            `);
        } else if (bonusNum < 0 || dataObj.status === 'rejected' || dataObj.status === 'pending_manajer_tolak') {
            bonusBox.style.background = '#fef2f2';
            bonusBox.style.borderColor = '#fecaca';
            setT('mon_bonus_val', '-Rp ' + Math.abs(bonusNum).toLocaleString('id-ID'));
            setS('mon_bonus_val', 'color', '#c62828');
            setT('mon_bonus_badge', 'Sanksi Denda 10%');
            setS('mon_bonus_badge', 'background', '#fee2e2');
            setS('mon_bonus_badge', 'color', '#b91c1c');
            setH('mon_bonus_breakdown', `
                <div><strong>Ketentuan:</strong> Terdeteksi pelanggaran / manipulasi data laporan kerja.</div>
                <div><strong>Dampak Capaian:</strong> Hasil realisasi dianulir menjadi <strong>0 ${unitStr} (0%)</strong>.</div>
                <div><strong>Denda Penalti:</strong> Pemotongan otomatis 10% sebesar <strong>-Rp ${Math.abs(bonusNum).toLocaleString('id-ID')}</strong> dari akumulasi insentif.</div>
            `);
        } else {
            bonusBox.style.background = '#f8fafc';
            bonusBox.style.borderColor = '#e2e8f0';
            setT('mon_bonus_val', 'Rp 0 (Target Terpenuhi)');
            setS('mon_bonus_val', 'color', '#64748b');
            setT('mon_bonus_badge', 'Standar');
            setS('mon_bonus_badge', 'background', '#f1f5f9');
            setS('mon_bonus_badge', 'color', '#475569');
            setH('mon_bonus_breakdown', `
                <div><strong>Ketentuan:</strong> Realisasi (${realisasiNum} ${unitStr}) sesuai dengan Target Dasar (${targetNum} ${unitStr}).</div>
                <div><strong>Keterangan:</strong> Pekerjaan tuntas 100% tanpa kelebihan bonus surplus atau denda sanksi.</div>
            `);
        }
    }

    const modal = document.getElementById('monitoringDetailModal');
    if (modal) {
        modal.style.display = 'block';
    }
}

function closeMonitoringDetailModal() {
    const modal = document.getElementById('monitoringDetailModal');
    if (modal) {
        modal.style.display = 'none';
    }
}
function openReportDetailModal(d) { openMonitoringDetailModal(d); }
function closeReportDetailModal() { closeMonitoringDetailModal(); }
</script>

<!-- Modal Detail Verifikasi & Alur Monitoring -->
<div id="monitoringDetailModal" class="modal no-print" style="display:none; position: fixed; z-index: 999999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.7); overflow-y: auto; backdrop-filter: blur(4px);">
    <div class="modal-dialog" style="background: #ffffff; margin: 30px auto; max-width: 900px; border-radius: 14px; padding: 0; box-shadow: 0 20px 40px rgba(0,0,0,0.3); overflow: hidden; color: #1e293b;">
        <!-- Header Modal -->
        <div style="background: #f8fafc; padding: 18px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="margin: 0; font-size: 1.2rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-list-check" style="color: var(--primary-light);"></i> Rincian Detail Alur Verifikasi Laporan Kerja
                </h3>
                <p style="margin: 3px 0 0 0; font-size: 0.8rem; color: #64748b;">
                    Melacak status input laporan karyawan, verifikasi mandor, hingga persetujuan manajer
                </p>
            </div>
            <button onclick="closeMonitoringDetailModal()" style="background: transparent; border: none; font-size: 1.6rem; cursor: pointer; color: #64748b; line-height: 1;">&times;</button>
        </div>

        <!-- Body Modal (3 Step Panels) -->
        <div style="padding: 24px; max-height: 80vh; overflow-y: auto;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- Panel Kiri: Input Laporan Karyawan & Foto Bukti -->
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px;">
                    <h4 style="margin: 0 0 14px 0; font-size: 0.95rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                        <i class="fa-solid fa-user-pen" style="color: #0d6efd;"></i> 1. Hasil Input Laporan Karyawan
                    </h4>
                    
                    <div style="margin-bottom: 10px;">
                        <span style="font-size: 0.75rem; color: #64748b; display: block;">Pelaksana / Karyawan:</span>
                        <strong id="mon_karyawan" style="font-size: 0.95rem; color: #0f172a;">-</strong>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <span style="font-size: 0.75rem; color: #64748b; display: block;">Tanggal Kerja:</span>
                            <strong id="mon_tanggal" style="font-size: 0.88rem; color: #334155;">-</strong>
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; color: #64748b; display: block;">Aktivitas:</span>
                            <strong id="mon_aktivitas" style="font-size: 0.88rem; color: #334155;">-</strong>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                        <div>
                            <span style="font-size: 0.75rem; color: #64748b; display: block;">Target Dasar:</span>
                            <span id="mon_target" style="font-weight: 700; color: #15803d; font-size: 0.9rem;">-</span>
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; color: #64748b; display: block;">Realisasi Lapangan:</span>
                            <span id="mon_realisasi" style="font-weight: 700; color: #2e7d32; font-size: 0.9rem;">-</span>
                        </div>
                    </div>

                    <div style="margin-bottom: 14px;">
                        <span style="font-size: 0.75rem; color: #64748b; display: block;">Catatan / Keterangan Karyawan:</span>
                        <div id="mon_catatan_karyawan" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.82rem; color: #334155; margin-top: 4px; font-style: italic;">
                            -
                        </div>
                    </div>

                    <div>
                        <span style="font-size: 0.75rem; color: #64748b; display: block; margin-bottom: 4px;">Foto Bukti Fisik Lapangan:</span>
                        <div id="mon_foto_wrapper">
                            <img id="mon_foto_bukti" src="" alt="Bukti Fisik" style="width: 100%; height: 170px; object-fit: cover; border-radius: 8px; border: 1px solid #cbd5e1; cursor: pointer;">
                        </div>
                    </div>
                </div>

                <!-- Panel Kanan: Verifikasi Mandor & Persetujuan Manajer -->
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <!-- Section Verifikasi Mandor -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 0.95rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-user-check" style="color: #0d6efd;"></i> 2. Verifikasi Mandor Lapangan
                        </h4>
                        <div style="margin-bottom: 6px;">
                            <span style="font-size: 0.75rem; color: #64748b;">Mandor Penanggung Jawab:</span>
                            <strong id="mon_mandor" style="font-size: 0.88rem; display: block; color: #0f172a;">-</strong>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <span style="font-size: 0.75rem; color: #64748b;">Catatan Verifikasi Mandor:</span>
                            <div id="mon_catatan_mandor" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.82rem; color: #334155; margin-top: 3px; font-style: italic;">
                                -
                            </div>
                        </div>
                        <div style="font-size: 0.76rem; color: #64748b;">
                            <i class="fa-regular fa-clock"></i> Waktu Verifikasi Mandor: <span id="mon_waktu_mandor" style="font-weight: 600; color: #334155;">-</span>
                        </div>
                    </div>

                    <!-- Section Persetujuan Manajer -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 0.95rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 6px;">
                            <i class="fa-solid fa-user-shield" style="color: #15803d;"></i> 3. Persetujuan Final Manajer
                        </h4>
                        <div style="margin-bottom: 6px;">
                            <span style="font-size: 0.75rem; color: #64748b;">Status Alur Verifikasi:</span>
                            <div id="mon_status_badge" style="margin-top: 2px;">-</div>
                        </div>
                        <div style="margin-bottom: 8px;">
                            <span style="font-size: 0.75rem; color: #64748b;">Catatan Persetujuan Manajer:</span>
                            <div id="mon_catatan_manajer" style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; font-size: 0.82rem; color: #334155; margin-top: 3px;">
                                -
                            </div>
                        </div>
                        <div style="font-size: 0.76rem; color: #64748b;">
                            <i class="fa-regular fa-clock"></i> Waktu Persetujuan Manajer: <span id="mon_waktu_manajer" style="font-weight: 600; color: #334155;">-</span>
                        </div>
                    </div>

                    <!-- Box Summary Bonus / Denda -->
                    <div id="mon_bonus_box" style="padding: 12px 14px; background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 8px; margin-top: auto;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.73rem; color: #166534; font-weight: 700; text-transform: uppercase;">
                                <i class="fa-solid fa-calculator"></i> Status Bonus / Denda:
                            </span>
                            <span id="mon_bonus_badge" class="badge" style="font-size: 0.7rem;">-</span>
                        </div>
                        <div id="mon_bonus_val" style="font-size: 1.15rem; font-weight: 700; color: #2e7d32; margin-top: 3px;">-</div>
                        <div id="mon_bonus_breakdown" style="margin-top: 6px; padding-top: 6px; border-top: 1px dashed rgba(0,0,0,0.12); font-size: 0.78rem; color: #334155; line-height: 1.4;">
                            -
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Modal -->
        <div style="background: #f8fafc; padding: 14px 24px; border-top: 1px solid #e2e8f0; text-align: right;">
            <button onclick="closeMonitoringDetailModal()" class="btn btn-secondary btn-sm" style="padding: 7px 22px; font-weight: 600; border-radius: 6px;">Tutup</button>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
