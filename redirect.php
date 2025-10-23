<?php
require_once 'config/database.php';
date_default_timezone_set('Asia/Jakarta');

$short_code = $_GET['code'] ?? '';
if (empty($short_code)) {
    header('Location: index.php');
    exit;
}

$conn = getDBConnection();

// === AMBIL DATA URL ===
$stmt = $conn->prepare("SELECT * FROM urls WHERE short_code = ? AND status = 'active'");
$stmt->bind_param("s", $short_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    notFound();
}

$url_data = $result->fetch_assoc();
$stmt->close();

// === CEK KADALUARSA ===
if (!empty($url_data['expire_at']) && strtotime($url_data['expire_at']) < time()) {
    $conn->query("UPDATE urls SET status='expired' WHERE id=" . intval($url_data['id']));
    notFound("This short link has expired.");
}

// === CEK ONE-TIME AKSES GLOBAL ===
if ($url_data['one_time'] == 1 && isset($_COOKIE["visited_" . $short_code])) {
    notFound("This link was already used (one-time access).");
}

// === CEK PASSWORD ===
$requires_password = !empty($url_data['access_password']);
$password_valid = false;
$error = null;

if ($requires_password) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_password'])) {
        $entered_password = trim($_POST['access_password']);
        $entered_password = $_POST['access_password'];
        if ($entered_password === $url_data['access_password']) {
            $password_valid = true;
            setcookie("access_ok_" . $short_code, '1', time() + 3600, "/");
        } else {
            $error = "Incorrect password!";
        }
    } elseif (isset($_COOKIE["access_ok_" . $short_code])) {
        $password_valid = true;
    }
}

// === CEK MODE PER-CODE ===
$requires_code = ($url_data['access_mode'] === 'per_code');
$code_valid = false;
$code_data = null;

if ($requires_code) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_code'])) {
        $entered_code = trim($_POST['access_code']);
        $stmt = $conn->prepare("SELECT * FROM url_codes WHERE url_id = ? AND code = ?");
        $stmt->bind_param("is", $url_data['id'], $entered_code);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $code_data = $res->fetch_assoc();

            // === One-time per code ===
            if ($code_data['used'] == 1) {
                $error = "Kode ini sudah pernah digunakan.";
            } else {
                $code_valid = true;
                // tandai hanya jika one-time diaktifkan di URL
                if ($url_data['one_time'] == 1) {
                    $conn->query("UPDATE url_codes SET used = 1 WHERE id = " . intval($code_data['id']));
                }
                setcookie("code_ok_" . $short_code, $entered_code, time() + 3600, "/");
            }
        } else {
            $error = "Kode unik salah atau tidak ditemukan!";
        }

        $stmt->close();
        } elseif (isset($_COOKIE["code_ok_" . $short_code])) {
        $code_valid = true;
        $saved_code = $_COOKIE["code_ok_" . $short_code];

        // Ambil ulang data code dari DB agar target_url bisa dipakai
        $stmt = $conn->prepare("SELECT * FROM url_codes WHERE url_id = ? AND code = ?");
        $stmt->bind_param("is", $url_data['id'], $saved_code);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $code_data = $res->fetch_assoc();
        }
        $stmt->close();
    }
}

// === CEK KELAYAKAN AKSES ===
$can_access = (!$requires_password && !$requires_code)
           || ($requires_password && $password_valid)
           || ($requires_code && $code_valid);

// === PILIH TARGET URL ===
if ($requires_code && isset($code_data['target_url'])) {
    $target_url = $code_data['target_url'];
} else {
    $target_url = $url_data['original_url'];
}
$title = $url_data['title'];
$host = parse_url($target_url, PHP_URL_HOST);
$qr_base64 = $url_data['qr_base64'] ?? '';

if ($can_access) {
    // catat klik
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    $stmt = $conn->prepare("INSERT INTO url_clicks (url_id, ip_address, user_agent, referer) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $url_data['id'], $ip_address, $user_agent, $referer);
    $stmt->execute();
    $stmt->close();

    // === One-time global ===
    if ($url_data['one_time'] == 1) {
        setcookie("visited_" . $short_code, "1", time() + 86400, "/");
        if (!$requires_code) {
            $conn->query("UPDATE urls SET status='inactive' WHERE id=" . intval($url_data['id']));
        }
    }
}

$conn->close();

function notFound($msg = "Short URL not found or inactive.") {
    http_response_code(404);
    echo "
    <html><head>
     <link rel=\"icon\" type=\"image/png\" href=\"favicon.png\">
     <title>404 - URL Not Found</title>
    <style>
        body {font-family:Inter,sans-serif;background:#0f1116;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;}
        .box{text-align:center;background:rgba(255,255,255,0.05);border-radius:16px;padding:60px 40px;max-width:400px;}
        h1{font-size:80px;margin-bottom:10px;color:#dc3545;}
        p{color:#ccc;margin-bottom:25px;}
        a{background:#667eea;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;}
        a:hover{background:#5568d3;}
    </style></head><body>
    <div class='box'><h1>404</h1><p>$msg</p><a href='index.php'>Back to Home</a></div>
    </body></html>"; // Jangan lupa titik koma di akhir!
    exit;
}
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
body{background:#0f1116;color:#fff;font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;}
.box{background:rgba(255,255,255,0.05);padding:40px;border-radius:16px;text-align:center;width:90%;max-width:420px;}
input{padding:12px;border:1px solid rgba(255,255,255,0.2);border-radius:8px;background:rgba(0,0,0,0.3);color:#fff;margin-bottom:15px;}
button,.btn{background:#667eea;color:#fff;padding:12px 24px;border:none;border-radius:8px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;}
button:hover,.btn:hover{background:#5568d3;}
.error{color:#ff6b6b;margin-bottom:15px;}
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
<div class="box">
<?php if (!$can_access): ?>
    <h2>Protected Link</h2>
    <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($requires_password): ?>
        <form method="POST">
            <p>Enter password to access this link:</p>
            <input type="password" name="access_password" placeholder="Password" required>
            <button type="submit">Access</button>
        </form>
    <?php elseif ($requires_code): ?>
        <form method="POST">
            <p>Enter your unique access code:</p>
            <input type="text" name="access_code" placeholder="Unique Code" required>
            <button type="submit">Access</button>
        </form>
    <?php endif; ?>
<?php else: ?>
    <h1>Ready to visit:</h1>
    <p style="color:#a5b4fc;"><?= htmlspecialchars($host ?? $target_url) ?></p>
    <?php if (!empty($qr_base64)): ?>
    <div style="margin-top:25px; text-align:center;">
        <a href="<?= $qr_base64 ?>" download="Qr <?= preg_replace('/[^a-zA-Z0-9-_]/','_', $url_data['title'] ?? $short_code) ?>.png">
            <img src="<?= $qr_base64 ?>" width="130" height="130" style="border-radius:8px; cursor:pointer;">
        </a>
        <p style="font-size:12px;color:#888;">Klik QR untuk download</p>
        <a href="<?= htmlspecialchars($target_url) ?>" class="btn">Lanjutkan ke URL</a>
    </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

    <footer class="branding">
        🔗 Shortened with <a href="<?= BASE_URL ?? 'index.php' ?>">URL Shortener <p style="color:white">By Rzkt.NT</p></a>
    </footer>
</body>
</html>

