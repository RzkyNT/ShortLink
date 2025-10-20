<?php
session_start();
require_once 'config/database.php';
require_once 'phpqrcode/qrlib.php'; // ✅ Library QR

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$created_url = '';
$qr_path = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $original_url = trim($_POST['original_url'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $custom_code = trim($_POST['custom_code'] ?? '');

    if (empty($original_url)) {
        $error = 'Please enter a URL';
    } elseif (!filter_var($original_url, FILTER_VALIDATE_URL)) {
        $error = 'Please enter a valid URL';
    } else {
        $conn = getDBConnection();
        $user_id = $_SESSION['user_id'];

        // Generate short code
        if (!empty($custom_code)) {
            $short_code = preg_replace('/[^a-zA-Z0-9-_]/', '', $custom_code);
            if (strlen($short_code) < 3) {
                $error = 'Custom code must be at least 3 characters';
            } else {
                $check_stmt = $conn->prepare("SELECT id FROM urls WHERE short_code = ?");
                $check_stmt->bind_param("s", $short_code);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $error = 'This custom code is already taken';
                }
                $check_stmt->close();
            }
        } else {
            do {
                $short_code = generateShortCode();
                $check_stmt = $conn->prepare("SELECT id FROM urls WHERE short_code = ?");
                $check_stmt->bind_param("s", $short_code);
                $check_stmt->execute();
                $exists = $check_stmt->get_result()->num_rows > 0;
                $check_stmt->close();
            } while ($exists);
        }

        if (empty($error)) {
            $created_url = BASE_URL . $short_code;

            // ✅ Generate QR code sebelum insert
            $qrDir = __DIR__ . '/qrcodes/';
            if (!file_exists($qrDir)) mkdir($qrDir, 0755, true);

            $qrFile = $qrDir . $short_code . '.png';
            QRcode::png($created_url, $qrFile, QR_ECLEVEL_L, 4);
            $qr_path = 'qrcodes/' . $short_code . '.png';

            // ✅ Simpan ke database
            $stmt = $conn->prepare("INSERT INTO urls (short_code, original_url, title, qr_path, status, user_id) VALUES (?, ?, ?, ?, 'active', ?)");
            $stmt->bind_param("ssssi", $short_code, $original_url, $title, $qr_path, $user_id);

            if ($stmt->execute()) {
                $success = 'Short URL created successfully!';
            } else {
                $error = 'Error creating short URL: ' . $conn->error;
            }

            $stmt->close();
        }

        $conn->close();
    }
}

function generateShortCode($length = 6) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Short URL</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body {
            background: linear-gradient(135deg, #0f111a 0%, #1a1c25 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
            color: #fff;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            max-width: 850px;
            margin-bottom: 30px;
        }

        .header h1 {
            font-weight: 700;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header h1 i { color: #667eea; }

        .back-btn {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,0.15);
            padding: 10px 18px;
            border-radius: 8px;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .back-btn:hover { background: rgba(255,255,255,0.25); }

        .form-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            padding: 35px;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 850px;
        }

        .form-card h2 {
            color: #fff;
            font-size: 22px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group { margin-bottom: 20px; }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #ccc;
        }

        input[type="text"], input[type="url"] {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.3);
            color: #fff;
            border-radius: 8px;
            font-size: 14px;
            transition: 0.3s;
        }

        input:focus { outline: none; border-color: #667eea; }

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
        .btn:hover { background: #5568d3; }

        .error, .success {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .error {
            background: rgba(255, 76, 76, 0.15);
            color: #ff6b6b;
            border-left: 4px solid #ff6b6b;
        }
        .success {
            background: rgba(72, 187, 120, 0.15);
            color: #4fd1c5;
            border-left: 4px solid #4fd1c5;
        }

        .created-url {
            margin-top: 12px;
            padding: 14px;
            background: rgba(255,255,255,0.05);
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .url-text { font-family: monospace; font-size: 15px; color: #a5b4fc; }

        .copy-btn {
            background: #4fd1c5;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
            transition: 0.2s;
        }
        .copy-btn:hover { background: #38b2ac; }

        @media (max-width: 600px) {
            .header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .form-card { padding: 25px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fa-solid fa-link"></i> URL Shortener</h1>
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>

    <div class="form-card">
        <h2><i class="fa-solid fa-wand-magic-sparkles"></i> Create New Short URL</h2>

        <?php if ($error): ?>
            <div class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                <div class="created-url">
                    <span class="url-text" id="createdUrl"><?= htmlspecialchars($created_url) ?></span>
                    <button class="copy-btn" onclick="copyUrl()"><i class="fa-solid fa-copy"></i> Copy</button>
                </div>
                <?php if ($qr_path): ?>
                    <div style="margin-top:15px; text-align:center;">
                        <p style="color:#a5b4fc;">Scan QR Code:</p>
                        <img src="<?= htmlspecialchars($qr_path) ?>" alt="QR Code" style="margin-top:10px; margin-bottom: 10px; width:160px; height:160px; border-radius:8px;">
                        <div style="margin-top:8px;">
                            <a href="<?= htmlspecialchars($qr_path) ?>" download class="btn" style="background:#4fd1c5;"><i class="fa-solid fa-download"></i> Download QR</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="original_url"><i class="fa-solid fa-globe"></i> Original URL *</label>
                <input type="url" id="original_url" name="original_url" placeholder="https://example.com" required>
            </div>

            <div class="form-group">
                <label for="title"><i class="fa-solid fa-pen"></i> Title (Optional)</label>
                <input type="text" id="title" name="title" placeholder="My Website">
            </div>

            <div class="form-group">
                <label for="custom_code"><i class="fa-solid fa-key"></i> Custom Short Code (Optional)</label>
                <input type="text" id="custom_code" name="custom_code" placeholder="my-link" pattern="[a-zA-Z0-9-_]{3,}">
            </div>

            <button type="submit" class="btn"><i class="fa-solid fa-scissors"></i> Create Short URL</button>
        </form>
    </div>

    <script>
        function copyUrl() {
            const urlText = document.getElementById('createdUrl').textContent;
            navigator.clipboard.writeText(urlText).then(() => {
                alert('URL copied to clipboard!');
            });
        }
    </script>
</body>
</html>
