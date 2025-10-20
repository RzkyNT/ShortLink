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
    ORDER BY clicked_at DESC LIMIT 10
");

$top_ref = $conn->query("
    SELECT referer, COUNT(*) AS count 
    FROM url_clicks 
    WHERE url_id = $url_id AND referer IS NOT NULL AND referer != ''
    GROUP BY referer 
    ORDER BY count DESC LIMIT 10
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

/* Chart */
.chart-container {
    height: 300px;
}

/* Fade In Animation */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
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

            <p><strong>Original:</strong> 
                <a style="color: #667eea; text-decoration: none;" href="<?= htmlspecialchars($url_data['original_url']) ?>">
                    <?= htmlspecialchars($url_data['original_url']) ?>
                </a>
            </p>
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
                <canvas id="clickChart"></canvas>
            </div>
        </div>

        <div class="card">
            <div class="section-title"><i class="fas fa-globe"></i> Top Referrers</div>
            <?php if ($top_ref->num_rows > 0): ?>
            <table>
                <tr><th>Referrer</th><th>Clicks</th></tr>
                <?php while ($r = $top_ref->fetch_assoc()): ?>
                    <tr><td><?= htmlspecialchars($r['referer']) ?></td><td><?= $r['count'] ?></td></tr>
                <?php endwhile; ?>
            </table>
            <?php else: ?>
                <p style="color:#aaa;">No referrer data yet.</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="section-title"><i class="fas fa-clock"></i> Recent Clicks</div>
            <?php if ($recent_clicks->num_rows > 0): ?>
            <table>
                <tr><th>Date</th><th>IP</th><th>Referrer</th></tr>
                <?php while ($c = $recent_clicks->fetch_assoc()): ?>
                    <tr>
                        <td><?= $c['clicked_at'] ?></td>
                        <td><?= htmlspecialchars($c['ip_address']) ?></td>
                        <td><?= $c['referer'] ? htmlspecialchars($c['referer']) : 'Direct' ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
            <?php else: ?>
                <p style="color:#aaa;">No clicks recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>

<script>
const ctx = document.getElementById('clickChart');
const chartData = {
    labels: [<?php
        $dates = []; $clicks = [];
        while ($row = $clicks_data->fetch_assoc()) {
            $dates[] = '"' . date('M d', strtotime($row['date'])) . '"';
            $clicks[] = $row['clicks'];
        }
        echo implode(',', $dates);
    ?>],
    datasets: [{
        label: 'Clicks',
        data: [<?= implode(',', $clicks) ?>],
        backgroundColor: 'rgba(102,126,234,0.3)',
        borderColor: '#667eea',
        borderWidth: 2,
        fill: true,
        tension: 0.4,
        pointRadius: 4,
        pointHoverRadius: 6
    }]
};
new Chart(ctx, {
    type: 'line',
    data: chartData,
    options: {
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#aaa' } },
            x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#aaa' } }
        }
    }
});
</script>
</body>
</html>
