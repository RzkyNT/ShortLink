<?php
require_once 'config/database.php';

$short_code = $_GET['code'] ?? '';

if (empty($short_code)) {
    header('Location: index.php');
    exit;
}

$conn = getDBConnection();

// Ambil data URL
$stmt = $conn->prepare("SELECT * FROM urls WHERE short_code = ? AND status = 'active'");
$stmt->bind_param("s", $short_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>404 - URL Not Found</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Inter', sans-serif;
                background: #0f1116;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
            }
            .box {
                text-align: center;
                background: rgba(255,255,255,0.05);
                border: 1px solid rgba(255,255,255,0.1);
                border-radius: 16px;
                padding: 60px 40px;
                max-width: 400px;
                backdrop-filter: blur(10px);
            }
            h1 { font-size: 80px; margin-bottom: 10px; color: #dc3545; }
            p { color: #ccc; margin-bottom: 25px; }
            a {
                display: inline-block;
                background: #667eea;
                color: #fff;
                padding: 12px 24px;
                border-radius: 8px;
                text-decoration: none;
                transition: 0.3s;
            }
            a:hover { background: #5568d3; }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>404</h1>
            <p>Short URL not found or inactive.</p>
            <a href="index.php">Back to Home</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$url_data = $result->fetch_assoc();
$stmt->close();

// Catat klik (tidak langsung redirect)
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

$stmt = $conn->prepare("INSERT INTO url_clicks (url_id, ip_address, user_agent, referer) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $url_data['id'], $ip_address, $user_agent, $referer);
$stmt->execute();
$stmt->close();

$conn->close();

$target_url = $url_data['original_url'];
$host = parse_url($target_url, PHP_URL_HOST);
$qr_path = $url_data['qr_path'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Go to <?= htmlspecialchars($host ?? 'Destination') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="favicon.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #0f1116;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            padding: 20px;
        }
        .redirect-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 50px 40px;
            text-align: center;
            max-width: 420px;
            width: 100%;
            animation: fadeIn 0.5s ease;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        p {
            font-size: 15px;
            color: #ccc;
            margin-bottom: 20px;
        }
        .target {
            color: #667eea;
            font-weight: 600;
            word-break: break-all;
            margin-bottom: 25px;
        }
        .btn {
            display: inline-block;
            background: #667eea;
            color: #fff;
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
        }
        .btn:hover {
            background: #5568d3;
            transform: scale(1.03);
        }
        .qr-box {
            margin-top: 25px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            padding: 12px;
            border-radius: 12px;
            display: inline-block;
            transition: 0.3s;
        }
        .qr-box:hover { transform: scale(1.05); }
        .qr-box img {
            width: 130px;
            height: 130px;
            border-radius: 8px;
        }
        .download-note {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }
        .branding {
            position: fixed;
            bottom: 15px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 13px;
            color: #666;
        }
        .branding a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .branding a:hover { text-decoration: underline; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="redirect-box">
        <h1>Ready to visit:</h1>
        <div class="target"><?= htmlspecialchars($host ?? $target_url) ?></div>

        <a href="<?= htmlspecialchars($target_url) ?>" class="btn">Lanjutkan ke URL</a>

        <?php if (!empty($qr_path) && file_exists($qr_path)): ?>
            <div class="qr-box">
                <a href="<?= htmlspecialchars($qr_path) ?>" download="QRCode_<?= htmlspecialchars($short_code) ?>.png">
                    <img src="<?= htmlspecialchars($qr_path) ?>" alt="QR Code">
                </a>
                <div class="download-note">Klik QR untuk download</div>
            </div>
        <?php endif; ?>
    </div>

    <footer class="branding">
        🔗 Shortened with <a href="<?= BASE_URL ?? 'index.php' ?>">URL Shortener <p style="color:white">By Rzkt.NT</p></a>
    </footer>
</body>
</html>
