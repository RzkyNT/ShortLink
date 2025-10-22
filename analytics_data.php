<?php
require_once 'config/database.php';
date_default_timezone_set('Asia/Jakarta');

$conn = getDBConnection();

$url_id = intval($_GET['id'] ?? 0);
$filter = $_GET['filter'] ?? '30';
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;

$where = "url_id = $url_id";

if ($filter === '7') {
    $where .= " AND clicked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($filter === '30') {
    $where .= " AND clicked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} elseif ($filter === 'month') {
    $where .= " AND MONTH(clicked_at) = MONTH(NOW()) AND YEAR(clicked_at) = YEAR(NOW())";
} elseif ($filter === 'custom' && $start && $end) {
    $where .= " AND DATE(clicked_at) BETWEEN '$start' AND '$end'";
}
// else “all time” → no filter

// Query utama
$clicks = $conn->query("
    SELECT DATE(clicked_at) as date, COUNT(*) as clicks 
    FROM url_clicks 
    WHERE $where 
    GROUP BY DATE(clicked_at)
    ORDER BY date ASC
");

$dates = [];
$clickCount = [];
while ($r = $clicks->fetch_assoc()) {
    $dates[] = date('M d', strtotime($r['date']));
    $clickCount[] = (int)$r['clicks'];
}

// Total dan unique
$total_clicks = $conn->query("SELECT COUNT(*) AS total FROM url_clicks WHERE $where")->fetch_assoc()['total'];
$unique_visitors = $conn->query("SELECT COUNT(DISTINCT ip_address) AS total FROM url_clicks WHERE $where")->fetch_assoc()['total'];

$conn->close();

header('Content-Type: application/json');
echo json_encode([
    'dates' => $dates,
    'clicks' => $clickCount,
    'total_clicks' => $total_clicks,
    'unique_visitors' => $unique_visitors
]);
?>
