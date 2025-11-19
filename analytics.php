<?php

session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$url_id = $_GET['id'] ?? 0;
$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

/* =====================================================
   🔧 AUTO ALTER TABLE url_clicks (add new columns if missing)
===================================================== */
$expectedCols = [
    'region' => "VARCHAR(100) NULL DEFAULT NULL AFTER `city`",
    'postal' => "VARCHAR(30) NULL DEFAULT NULL AFTER `region`",
    'timezone' => "VARCHAR(100) NULL DEFAULT NULL AFTER `postal`",
    'device_type' => "VARCHAR(50) NULL DEFAULT NULL AFTER `timezone`",
    'browser' => "VARCHAR(50) NULL DEFAULT NULL AFTER `device_type`",
    'os' => "VARCHAR(50) NULL DEFAULT NULL AFTER `browser`",
    'org' => "VARCHAR(150) NULL DEFAULT NULL AFTER `os`",
    'asn' => "VARCHAR(100) NULL DEFAULT NULL AFTER `org`",
    'latitude' => "VARCHAR(50) NULL DEFAULT NULL AFTER `asn`",
    'longitude' => "VARCHAR(50) NULL DEFAULT NULL AFTER `latitude`"
];

$existingCols = [];
$res = $conn->query("SHOW COLUMNS FROM url_clicks");
while ($r = $res->fetch_assoc()) $existingCols[] = $r['Field'];

foreach ($expectedCols as $col => $definition) {
    if (!in_array($col, $existingCols)) {
        $conn->query("ALTER TABLE url_clicks ADD COLUMN `$col` $definition");
    }
}

/* =====================================================
   📦 LOAD URL & ANALYTICS DATA
===================================================== */
$stmt = $conn->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $url_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php');
    exit;
}

$url_data = $result->fetch_assoc();
$stmt->close();

$total_clicks = $conn->query("SELECT COUNT(*) AS total FROM url_clicks WHERE url_id = $url_id")->fetch_assoc()['total'];
$unique_visitors = $conn->query("SELECT COUNT(DISTINCT ip_address) AS total FROM url_clicks WHERE url_id = $url_id")->fetch_assoc()['total'];

$clicks_data = $conn->query("
    SELECT DATE(clicked_at) as date, COUNT(*) as clicks 
    FROM url_clicks 
    WHERE url_id = $url_id AND clicked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(clicked_at)
    ORDER BY date ASC
");

$recent_clicks = $conn->query("
    SELECT * FROM url_clicks 
    WHERE url_id = $url_id 
    ORDER BY clicked_at DESC
");

$top_ref = $conn->query("
    SELECT referer, COUNT(*) AS count 
    FROM url_clicks 
    WHERE url_id = $url_id AND referer IS NOT NULL AND referer != ''
    GROUP BY referer 
    ORDER BY count DESC
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics - <?= htmlspecialchars($url_data['short_code']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="icon" type="image/png" href="favicon.png">
<link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.min.css" />

<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Inter', sans-serif;
    background: #0f1116;
    color: #fff;
    line-height: 1.6;
}

/* Header */
.header {
    background: rgba(255, 255, 255, 0.05);
    backdrop-filter: blur(10px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    padding: 20px 40px;
}
.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    max-width: 1200px;
    margin: auto;
}
.header h1 {
    font-size: 22px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}
.header h1 i {
    color: #667eea;
}
.back-btn {
    background: #667eea;
    color: #fff;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-size: 14px;
    transition: background 0.3s;
}
.back-btn:hover {
    background: #5568d3;
}

/* Container */
.container {
    max-width: 1200px;
    margin: 40px auto;
    padding: 0 20px;
    animation: fadeIn 0.6s ease;
}

/* Card */
.card {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
    margin-top: 30px;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 20px;
}
.stat-card {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 25px;
    text-align: center;
    transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.3);
}
.stat-card i {
    font-size: 26px;
    color: #667eea;
    margin-bottom: 8px;
}
.stat-card .number {
    font-size: 30px;
    font-weight: 700;
}
.stat-card .label {
    color: #aaa;
    font-size: 13px;
}

/* Section Titles */
.section-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Table */
table {
    width: 100%;
    border-collapse: collapse;
    color: #fff;
}
th, td {
    padding: 14px;
    text-align: left;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    font-size: 14px;
}
th {
    text-transform: uppercase;
    font-size: 12px;
    color: #aaa;
    background: rgba(255,255,255,0.03);
}
tr:hover {
    background: rgba(255,255,255,0.04);
}

/* Fade In Animation */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

    /* 🔒 Nonaktifkan seleksi teks di seluruh halaman */
body {
  -webkit-user-select: none;  /* Safari/Chrome */
  -moz-user-select: none;     /* Firefox */
  -ms-user-select: none;      /* IE/Edge lama */
  user-select: none;          /* Standar */
  -webkit-tap-highlight-color: transparent; /* Hilangkan highlight saat tap di mobile */
}

/* ✅ Izinkan seleksi & interaksi normal di elemen form */
input,
textarea,
select,
button,
[contenteditable] {
  -webkit-user-select: text;
  -moz-user-select: text;
  -ms-user-select: text;
  user-select: text;
  -webkit-tap-highlight-color: inherit;
}
html, body {
  touch-action: manipulation;
}

a {
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: break-all;
  white-space: normal;
  display: inline-block;
  max-width: 100%;
}

/* DataTables Dark Theme Overrides */
.dt-search input, .dt-input {
    background-color: rgba(0,0,0,0.3) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    border-radius: 6px;
    margin-left: 5px;
}
.dt-search label, .dt-length label {
    color: #aaa;
}
.dt-length select {
    background-color: rgba(0,0,0,0.3) !important;
    color: #fff !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    border-radius: 6px;
    margin: 0 5px;
}
.dt-info {
    color: #aaa !important;
}
.dt-paging .dt-paging-button {
    background: rgba(255,255,255,0.1) !important;
    border: 1px solid rgba(255,255,255,0.1) !important;
    color: #fff !important;
    border-radius: 4px;
    margin: 0 2px;
}
.dt-paging .dt-paging-button.current, .dt-paging .dt-paging-button:hover {
    background: #667eea !important;
    color: #fff !important;
    border-color: #667eea !important;
}
.dt-layout-row {
    padding: 10px 0;
}

</style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1><i class="fas fa-chart-line"></i> URL Analytics</h1>
            <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </header>

    <div class="container">
        <div class="card">
            <h2><?= htmlspecialchars($url_data['title'] ?: 'Untitled') ?></h2>
            <p><strong>Short:</strong> 
            <a style="color: #667eea; text-decoration: none;" href="<?= BASE_URL . htmlspecialchars($url_data['short_code']) ?>">
                <?= BASE_URL . htmlspecialchars($url_data['short_code']) ?>
            </a>
            </p>

            <p><strong>Original Url:</strong></p>
            <div style="margin-top:6px;">
                <?php
                $original = $url_data['original_url'] ?? '';
                $decoded = json_decode($original, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // Jika berisi array JSON (multi URL)
                    foreach ($decoded as $item) {
                        $title = htmlspecialchars($item['title'] ?? '(no title)');
                        $url = htmlspecialchars($item['url'] ?? '#');
                        echo "<div style='margin-bottom:4px;'>
                                <strong>$title</strong> 
                                <a href='$url' target='_blank' style='color:#667eea; text-decoration:none;'>→ $url</a>
                            </div>";
                    }
                } else {
                    // Jika bukan JSON array, tampilkan sebagai single URL
                    $safe_url = htmlspecialchars($original);
                    echo "<a href='$safe_url' target='_blank' style='color:#667eea; text-decoration:none;'>$safe_url</a>";
                }
                ?>
            </div>


        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-mouse-pointer"></i>
                <div class="number"><?= $total_clicks ?></div>
                <div class="label">Total Clicks</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-user-check"></i>
                <div class="number"><?= $unique_visitors ?></div>
                <div class="label">Unique Visitors</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-calendar-alt"></i>
                <div class="number"><?= date('M d, Y', strtotime($url_data['created_at'])) ?></div>
                <div class="label">Created</div>
            </div>
        </div>

        <div class="card">
            <div class="section-title"><i class="fas fa-chart-bar"></i> Clicks in Last 30 Days</div>
            <div class="chart-container">
                <div class="filter-bar" style="margin-bottom:20px; display:flex; align-items:center; gap:10px;">
                    <label for="timeFilter" style="font-size:14px;color:#aaa;">Filter:</label>
                    <select id="timeFilter" style="padding:8px 12px; border-radius:8px; background:#1a1c23; color:#fff; border:1px solid rgba(255,255,255,0.1);">
                        <option value="7">Last 7 Days</option>
                        <option value="30" selected>Last 30 Days</option>
                        <option value="all">All Time</option>
                        <option value="month">This Month</option>
                        <option value="custom">Custom Range</option>
                    </select>
                    <input type="date" id="startDate" style="display:none; padding:6px 10px; border-radius:8px; background:#1a1c23; color:#fff; border:1px solid rgba(255,255,255,0.1);" />
                    <input type="date" id="endDate" style="display:none; padding:6px 10px; border-radius:8px; background:#1a1c23; color:#fff; border:1px solid rgba(255,255,255,0.1);" />
                    <button id="applyCustom" style="display:none; padding:8px 14px; background:#667eea; border:none; border-radius:8px; color:white; cursor:pointer;">Apply</button>
                </div>
                <canvas id="clickChart"></canvas>
            </div>
        </div>

        <div class="card">
            <div class="section-title"><i class="fas fa-globe"></i> Top Referrers</div>
            <?php if ($top_ref->num_rows > 0): ?>
            <table id="referrersTable" class="display" style="width:100%">
                <thead>
                    <tr><th>Referrer</th><th>Clicks</th></tr>
                </thead>
                <tbody>
                    <?php while ($r = $top_ref->fetch_assoc()): ?>
                        <tr><td><?= htmlspecialchars($r['referer']) ?></td><td><?= $r['count'] ?></td></tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color:#aaa;">No referrer data yet.</p>
            <?php endif; ?>
        </div>

            <div class="card">
        <div class="section-title"><i class="fas fa-clock"></i> History Clicks (Detailed)</div>
        <?php if ($recent_clicks->num_rows > 0): ?>
        <div style="overflow-x:auto;">
        <table id="clicksTable" class="display" style="width:100%">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>IP</th>
                    <th>Region</th>
                    <th>Postal</th>
                    <th>Timezone</th>
                    <th>Device</th>
                    <th>Browser</th>
                    <th>OS</th>
                    <th>Org</th>
                    <th>ASN</th>
                    <th>Lat</th>
                    <th>Lon</th>
                    <th>Referrer</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($c = $recent_clicks->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['clicked_at']) ?></td>
                        <td><?= htmlspecialchars($c['ip_address']) ?></td>
                        <td><?= htmlspecialchars($c['region'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['postal'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['timezone'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['device_type'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['browser'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['os'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['org'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['asn'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['latitude'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($c['longitude'] ?? '-') ?></td>
                        <td><?= $c['referer'] ? htmlspecialchars($c['referer']) : 'Direct' ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
            </table>
            <?php else: ?>
                <p style="color:#aaa;">No clicks recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.min.js"></script>
<script src="assets/sweetalert2.js"></script>
<script>
$(document).ready(function() {
    $('#referrersTable').DataTable({
        "order": [[ 1, "desc" ]],
        responsive: true
    });
    $('#clicksTable').DataTable({
        "order": [[ 0, "desc" ]],
        responsive: true
    });
});

const ctx = document.getElementById('clickChart');
let clickChart;

// --- Data awal dari PHP ---
const chartData = {
    labels: [<?php
        $dates = []; $clicks = [];
        // Reset pointer and re-fetch if necessary, or clone the result before the first loop.
        // For simplicity, we assume the data is available or re-fetched if needed.
        // A better approach would be to store data in an array first.
        if (mysqli_data_seek($clicks_data, 0)) {
            while ($row = $clicks_data->fetch_assoc()) {
                $dates[] = '"' . date('M d', strtotime($row['date'])) . '"';
                $clicks[] = $row['clicks'];
            }
        }
        echo implode(',', $dates);
    ?>],
    clicks: [<?= implode(',', $clicks) ?>]
};

// --- Fungsi render chart ---
function renderChart(labels, data) {
    if (clickChart) clickChart.destroy();
    clickChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Clicks',
                data: data,
                backgroundColor: 'rgba(102,126,234,0.3)',
                borderColor: '#667eea',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#aaa' } },
                x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#aaa' } }
            }
        }
    });
}

// --- Render awal ---
renderChart(chartData.labels, chartData.clicks);

// --- Filter control ---
const filterSelect = document.getElementById('timeFilter');
const startDate = document.getElementById('startDate');
const endDate = document.getElementById('endDate');
const applyBtn = document.getElementById('applyCustom');

filterSelect.addEventListener('change', () => {
    const val = filterSelect.value;
    if (val === 'custom') {
        startDate.style.display = endDate.style.display = applyBtn.style.display = 'inline-block';
    } else {
        startDate.style.display = endDate.style.display = applyBtn.style.display = 'none';
        loadChartData(val);
    }
});

applyBtn.addEventListener('click', () => {
    if (!startDate.value || !endDate.value) {
        Swal.fire({
            title: 'Incomplete Date Range',
            text: 'Please select both start and end date.',
            icon: 'warning',
            background: '#1a1c25',
            color: '#fff'
        });
        return;
    }
    loadChartData('custom', startDate.value, endDate.value);
});

// --- Ambil data dari PHP (AJAX) ---
function loadChartData(filter, start = '', end = '') {
    fetch(`analytics_data.php?id=<?= $url_id ?>&filter=${filter}&start=${start}&end=${end}`)
        .then(res => res.json())
        .then(data => {
            renderChart(data.dates, data.clicks);
            document.querySelectorAll('.stat-card .number')[0].textContent = data.total_clicks;
            document.querySelectorAll('.stat-card .number')[1].textContent = data.unique_visitors;
        })
        .catch(err => {
            console.error(err);
            Swal.fire({
                title: 'Error!',
                text: 'Failed to load chart data.',
                icon: 'error',
                background: '#1a1c25',
                color: '#fff'
            });
        });
}
</script>

</body>
</html>
