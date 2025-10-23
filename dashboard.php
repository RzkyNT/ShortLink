<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];

// Get user's URLs with click count
$query = "SELECT u.*, COUNT(c.id) as click_count 
          FROM urls u 
          LEFT JOIN url_clicks c ON u.id = c.url_id 
          WHERE u.user_id = ?
          GROUP BY u.id 
          ORDER BY u.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

// Get statistics
$stats_query = "SELECT 
    COUNT(DISTINCT u.id) as total_urls,
    COUNT(c.id) as total_clicks,
    COUNT(DISTINCT c.ip_address) as unique_visitors
    FROM urls u
    LEFT JOIN url_clicks c ON u.id = c.url_id
    WHERE u.user_id = ?";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - URL Shortener</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="favicon.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0f1116;
            color: #fff;
            line-height: 1.5;
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
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 14px;
        }
        .logout-btn {
            background: #667eea;
            color: #fff;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            transition: background 0.3s;
            font-weight: 500;
        }
        .logout-btn:hover {
            background: #5568d3;
        }

        /* Container */
        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 25px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        }
        .stat-card h3 {
            color: #aaa;
            font-size: 13px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .stat-card .number {
            font-size: 34px;
            font-weight: 700;
            color: #fff;
        }

        /* Action Bar */
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 22px;
            border-radius: 8px;
            font-size: 15px;
            text-decoration: none;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #28a745;
        }
        .btn-secondary:hover {
            background: #218838;
        }

        /* URLs Table */
        .urls-table {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            color: #fff;
        }
        th, td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        th {
            text-transform: uppercase;
            font-size: 13px;
            color: #aaa;
            background: rgba(255,255,255,0.03);
        }
        tr:hover {
            background: rgba(255,255,255,0.04);
        }
        .short-url {
            color: #667eea;
            font-weight: 600;
            text-decoration: none;
        }
        .short-url:hover {
            text-decoration: underline;
        }

        /* Actions */
        .action-btns {
            display: flex;
            gap: 8px;
        }
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            transition: background 0.3s;
        }
        .btn-view { background: rgba(40,167,69,0.2); color: #28a745; }
        .btn-qrcode { background: rgba(76, 40, 167, 0.2); color: #667eea; }
        .btn-edit { background: rgba(255,193,7,0.2); color: #ffc107; }
        .btn-delete { background: rgba(220,53,69,0.2); color: #dc3545; }
        .action-btn:hover {
            opacity: 0.8;
        }

        /* Status Tag */
        .status {
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-active {
            background: rgba(40,167,69,0.2);
            color: #28a745;
        }
        .status-inactive {
            background: rgba(220,53,69,0.2);
            color: #dc3545;
        }

        .status-expired {
            background: rgba(255, 255, 255, 0.24);
            color: #000000bb;
        }
        /* Empty State */
        .no-data {
            text-align: center;
            padding: 40px;
            color: #aaa;
        }

        /* Fade In Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .container, .stat-card, .urls-table {
            animation: fadeIn 0.5s ease forwards;
        }
        /* ===== Responsive: Table to Card on Mobile ===== */
@media (max-width: 768px) {
    table, thead, tbody, th, td, tr {
        display: block;
        width: 100%;
    }

    thead {
        display: none; /* Sembunyikan header */
    }

    tr {
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 10px;
        margin-bottom: 15px;
        padding: 12px;
    }

    td {
        border: none;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        font-size: 14px;
    }

    td::before {
        content: attr(data-label);
        flex: 1;
        color: #aaa;
        text-transform: uppercase;
        font-size: 12px;
    }

    td:last-child {
        justify-content: flex-start;
        flex-direction: column;
        align-items: flex-start;
    }

    .action-btns {
        flex-wrap: wrap;
    }
}
.copy-btn {
        background: #28a745;
        border: none; color: white;
        padding: 10px 20px; border-radius: 8px;
        cursor: pointer; font-weight: 600;
    }
    .copy-btn:hover {
        background: #218838;
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

    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <a href="index.php" style="text-decoration: none; color: white;">
            <h1><i class="fa-solid fa-link"></i> URL Shortener Dashboard</h1>
            </a>
            <div class="user-info">
                <span><i class="fa-regular fa-user"></i> <?= htmlspecialchars($_SESSION['username']) ?></span>
                <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <h3><i class="fa-solid fa-link"></i> Total URLs</h3>
                <div class="number"><?= $stats['total_urls'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fa-solid fa-mouse-pointer"></i> Total Clicks</h3>
                <div class="number"><?= $stats['total_clicks'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fa-solid fa-user-group"></i> Unique Visitors</h3>
                <div class="number"><?= $stats['unique_visitors'] ?? 0 ?></div>
            </div>
        </div>

        <div class="actions-bar">
            <a href="create.php" class="btn"><i class="fa-solid fa-plus"></i> Create Short URL</a>
            <a href="index.php" class="btn btn-secondary"><i class="fa-solid fa-house"></i> Back to Home</a>
        </div>

        <div class="urls-table">
            <table>
                <thead>
                    <tr>
                        <th>Short Code</th>
                        <th>Original URL</th>
                        <th>Title</th>
                        <th>Clicks</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td data-label="Short Code">
                                <button class="copy-btn" onclick="copyUrl('<?= BASE_URL . $row['short_code'] ?>', this)">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                                <a href="<?= BASE_URL . $row['short_code'] ?>" target="_blank" class="short-url">
                                    <?= htmlspecialchars($row['short_code']) ?>
                                </a>

                            </td>
                            <td data-label="Original URL" style="max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= htmlspecialchars($row['original_url']) ?>
                            </td>
                            <td data-label="Title"><?= htmlspecialchars($row['title'] ?? '-') ?></td>
                            <td data-label="Clicks"><?= $row['click_count'] ?></td>
                            <td data-label="Status">
                                <span class="status status-<?= $row['status'] ?>">
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                            <td data-label="Created"><?= date('Y-m-d H:i', strtotime($row['created_at'])) ?></td>
                            <td data-label="Actions">
                                <div class="action-btns">
                                    <a href="analytics.php?id=<?= $row['id'] ?>" class="action-btn btn-view">
                                        <i class="fa-solid fa-chart-line"></i> View
                                    </a>
                                    <a href="edit.php?id=<?= $row['id'] ?>" class="action-btn btn-edit">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <a href="delete.php?id=<?= $row['id'] ?>"
                                    onclick="return confirm('Are you sure you want to delete this URL?')"
                                    class="action-btn btn-delete">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </a>
                                   <?php if (!empty($row['qr_base64'])): ?>
                                        <a href="#"
                                        type="button"
                                        class="action-btn btn-qrcode"
                                        onclick="showQR('<?= $row['qr_base64'] ?>', '<?= BASE_URL . $row['short_code'] ?>')">
                                            <i class="fa-solid fa-qrcode"></i> QR
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="no-data">
                            <i class="fa-regular fa-folder-open fa-lg"></i><br>
                            No URLs created yet. <a href="create.php" style="color:#667eea;">Create your first short URL</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
            </table>
        </div>
    </div>
    <div id="qrModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7);
        justify-content:center; align-items:center; z-index:9999;">
            <div style="background:#1a1c25; padding:30px; border-radius:12px; text-align:center;
                max-width:300px; width:90%; box-shadow:0 10px 30px rgba(0,0,0,0.5); position:relative;">
                <button onclick="closeQR()" style="position:absolute; top:8px; right:12px; background:none; border:none;
                    color:#fff; font-size:20px; cursor:pointer;">&times;</button>
                <h3 style="margin-bottom:15px; color:#a5b4fc;">QR Code</h3>
                <img id="qrImage" src="" alt="QR Code" style="width:180px; height:180px; border-radius:8px;">
                <div style="margin-top:15px;">
                    <a id="qrDownload" href="#" download class="btn" style="background:#4fd1c5; display:inline-block;">
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                </div>
                <p id="qrUrl" style="margin-top:10px; color:#a5b4fc; font-size:13px; word-break:break-all;"></p>
            </div>
        </div>


<script>
 function copyUrl(url, btn) {
    navigator.clipboard.writeText(url).then(() => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
        btn.style.background = '#3ccf5a';
        btn.title = "Copied!";
        setTimeout(() => {
            btn.innerHTML = '<i class="fa-solid fa-copy"></i>';
            btn.style.background = '';
            btn.title = "Copy URL";
        }, 2000);
    }).catch(err => {
        console.error("Copy failed:", err);
        alert("Gagal menyalin URL.");
    });
}

function showQR(qrBase64, shortUrl) {
    const modal = document.getElementById('qrModal');
    const img = document.getElementById('qrImage');
    const download = document.getElementById('qrDownload');
    const qrUrlText = document.getElementById('qrUrl');

    img.src = qrBase64;
    download.href = qrBase64;
    download.download = `Qr ${shortUrl.replace(/[^a-zA-Z0-9-_]/g,'_')}.png`;
    qrUrlText.textContent = shortUrl;

    modal.style.display = 'flex';
}

function closeQR() {
    document.getElementById('qrModal').style.display = 'none';
}
</script>

</body>
</html>
<?php $conn->close(); ?>

