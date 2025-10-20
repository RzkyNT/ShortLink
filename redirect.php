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
        <link rel="icon" type="image/png" href="favicon.png">
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
            h1 {
                font-size: 80px;
                margin-bottom: 10px;
                color: #dc3545;
            }
            p {
                color: #ccc;
                margin-bottom: 25px;
            }
            a {
                display: inline-block;
                background: #667eea;
                color: #fff;
                padding: 12px 24px;
                border-radius: 8px;
                text-decoration: none;
                transition: 0.3s;
            }
            a:hover {
                background: #5568d3;
            }
        </style>
    </head>
    <body>
        <div class="box">
            <h1>404</h1>
            <p>🔍 Short URL not found or inactive.</p>
            <a href="index.php">Back to Home</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$url_data = $result->fetch_assoc();
$stmt->close();

// Catat klik
$ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';

$stmt = $conn->prepare("INSERT INTO url_clicks (url_id, ip_address, user_agent, referer) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $url_data['id'], $ip_address, $user_agent, $referer);
$stmt->execute();
$stmt->close();

$conn->close();

// URL Tujuan
$target_url = $url_data['original_url'];
$host = parse_url($target_url, PHP_URL_HOST);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting...</title>
    <meta http-equiv="refresh" content="3;url=<?= htmlspecialchars($target_url) ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
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
        }
        .redirect-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 50px 40px;
            text-align: center;
            max-width: 420px;
            animation: fadeIn 0.5s ease;
        }
        .redirect-box i {
            font-size: 40px;
            color: #667eea;
            margin-bottom: 20px;
            animation: spin 2s linear infinite;
        }
        .redirect-box h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        .redirect-box p {
            font-size: 15px;
            color: #ccc;
            margin-bottom: 20px;
        }
        .target {
            color: #667eea;
            font-weight: 600;
            word-break: break-all;
        }
        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 10px;
        }
        .progress-fill {
            width: 0%;
            height: 100%;
            background: #667eea;
            animation: fill 3s linear forwards;
        }

        @keyframes fill {
            from { width: 0%; }
            to { width: 100%; }
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .manual {
            color: #aaa;
            font-size: 13px;
            margin-top: 15px;
        }
        .manual a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="redirect-box">
        <i class="fa-solid fa-spinner"></i>
        <h1>Redirecting you...</h1>
        <p>You're being redirected to:</p>
        <div class="target"><?= htmlspecialchars($host ?? $target_url) ?></div>

        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>

        <div class="manual">
            Not redirected? <a href="<?= htmlspecialchars($target_url) ?>">Click here</a>
        </div>
    </div>
</body>
</html>
