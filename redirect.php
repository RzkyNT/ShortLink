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

// === PILIH TARGET URL ===// === PILIH TARGET URL UNIVERSAL ===
if ($requires_code && !empty($code_data['target_url'])) {
    // Mode per_code → gunakan URL spesifik peserta
    $target_url = $code_data['target_url'];
} else {
    // Mode public atau multi-link
    $decoded = json_decode($url_data['original_url'], true);

    if (is_array($decoded) && !empty($decoded)) {
        // Jika array JSON (multi link)
        $target_url = $decoded[0]['url'] ?? '#';
    } elseif (filter_var($url_data['original_url'], FILTER_VALIDATE_URL)) {
        // Jika field berisi satu URL biasa
        $target_url = $url_data['original_url'];
    } else {
        // Fallback terakhir (tidak valid)
        $target_url = 'Hubungi Admin';
    }
}

// === 📸 AUTO GENERATE PREVIEW JIKA BELUM ADA (LOGIKA BARU) ===
$preview_base64 = '';

// Prioritaskan preview dari url_codes jika ada
if ($requires_code && !empty($code_data)) {
    $preview_base64 = $code_data['preview_base64'] ?? '';

    // Jika preview di url_codes kosong, generate dan simpan
    if (empty($preview_base64) && filter_var($target_url, FILTER_VALIDATE_URL)) {
        $api_url = 'https://api.screenshotone.com/take';
        $query = [
            'access_key' => 'duAaHYw2b-sumg', 'url' => $target_url, 'viewport_device' => 'galaxy_s5_landscape',
            'format' => 'jpg', 'block_ads' => 'true', 'block_cookie_banners' => 'true',
            'block_banners_by_heuristics' => 'false', 'block_trackers' => 'true', 'delay' => '0',
            'timeout' => '60', 'response_type' => 'json', 'image_quality' => '80',
        ];
        $full_url = $api_url . '?' . http_build_query($query);
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $response = @file_get_contents($full_url, false, $context);

        if ($response !== false) {
            $data = json_decode($response, true);
            if (!empty($data['screenshot_url'])) {
                $image_data = @file_get_contents($data['screenshot_url']);
                if ($image_data !== false) {
                    $preview_base64 = 'data:image/jpeg;base64,' . base64_encode($image_data);
                    // Simpan ke DB (url_codes) agar tidak perlu ambil ulang
                    $stmt = $conn->prepare("UPDATE url_codes SET preview_base64 = ? WHERE id = ?");
                    $stmt->bind_param("si", $preview_base64, $code_data['id']);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}

// Jika tidak ada preview dari url_codes, coba dari urls (link utama)
if (empty($preview_base64)) {
    $preview_base64 = $url_data['preview_base64'] ?? '';

    // Jika preview di urls kosong, generate dan simpan
    if (empty($preview_base64) && filter_var($target_url, FILTER_VALIDATE_URL)) {
        $api_url = 'https://api.screenshotone.com/take';
        $query = [
            'access_key' => 'duAaHYw2b-sumg', 'url' => $target_url, 'viewport_device' => 'galaxy_s5_landscape',
            'format' => 'jpg', 'block_ads' => 'true', 'block_cookie_banners' => 'true',
            'block_banners_by_heuristics' => 'false', 'block_trackers' => 'true', 'delay' => '0',
            'timeout' => '60', 'response_type' => 'json', 'image_quality' => '80',
        ];
        $full_url = $api_url . '?' . http_build_query($query);
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $response = @file_get_contents($full_url, false, $context);

        if ($response !== false) {
            $data = json_decode($response, true);
            if (!empty($data['screenshot_url'])) {
                $image_data = @file_get_contents($data['screenshot_url']);
                if ($image_data !== false) {
                    $preview_base64 = 'data:image/jpeg;base64,' . base64_encode($image_data);
                    // Simpan ke DB (urls) agar tidak perlu ambil ulang
                    $stmt = $conn->prepare("UPDATE urls SET preview_base64 = ? WHERE id = ?");
                    $stmt->bind_param("si", $preview_base64, $url_data['id']);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}

$main_title = $url_data['title'] ?? '';
$host = parse_url($target_url, PHP_URL_HOST);

// ==================================================
// 🧠 LOGGING DEVICE, OS, LOKASI, BROWSER, ASN, DLL
// ==================================================
if ($can_access) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ($ip_address === '127.0.0.1' || $ip_address === '::1') {
        $ip_address = '101.255.140.157'; // fallback IP utk testing localhost
    }

    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $timestamp = date('Y-m-d H:i:s');

    // === DETEKSI DEVICE & OS ===
    function detectDevice($ua) {
        $ua = strtolower($ua);
        if (strpos($ua, 'mobile') !== false) return 'Mobile';
        if (strpos($ua, 'tablet') !== false) return 'Tablet';
        return 'Desktop';
    }

    function detectBrowser($ua) {
        $ua = strtolower($ua);
        if (strpos($ua, 'chrome') !== false && strpos($ua, 'edg') === false) return 'Chrome';
        if (strpos($ua, 'firefox') !== false) return 'Firefox';
        if (strpos($ua, 'safari') !== false && strpos($ua, 'chrome') === false) return 'Safari';
        if (strpos($ua, 'edg') !== false) return 'Edge';
        if (strpos($ua, 'opr') !== false || strpos($ua, 'opera') !== false) return 'Opera';
        return 'Other';
    }

    function detectOS($ua) {
        $ua = strtolower($ua);
        if (strpos($ua, 'windows') !== false) return 'Windows';
        if (strpos($ua, 'mac') !== false) return 'MacOS';
        if (strpos($ua, 'android') !== false) return 'Android';
        if (strpos($ua, 'linux') !== false) return 'Linux';
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) return 'iOS';
        return 'Other';
    }

    $device_type = detectDevice($user_agent);
    $browser = detectBrowser($user_agent);
    $os = detectOS($user_agent);

    // === AMBIL DATA GEOLOKASI ===
    $location = [
        'country' => '',
        'city' => '',
        'region' => '',
        'postal' => '',
        'org' => '',
        'asn' => '',
        'timezone' => '',
        'lat' => '',
        'lon' => ''
    ];

    // Gunakan ipwho.is untuk hasil lebih lengkap
$api_url = "https://ipwho.is/{$ip_address}";
$context = stream_context_create(['http' => ['timeout' => 3]]);
$response = @file_get_contents($api_url, false, $context);

if ($response !== false) {
    $data = json_decode($response, true);
    if (!empty($data) && $data['success'] === true) {
        $location['country'] = $data['country'] ?? '';
        $location['region']  = $data['region'] ?? '';
        $location['city']    = $data['city'] ?? '';
        $location['postal']  = $data['postal'] ?? '';
        $location['org']     = $data['connection']['org'] ?? ($data['connection']['isp'] ?? '');
        $location['asn']     = $data['connection']['asn'] ?? '';
        $location['timezone'] = $data['timezone']['id'] ?? '';
        $location['lat']     = $data['latitude'] ?? '';
        $location['lon']     = $data['longitude'] ?? '';
    }
} else {
    // fallback ke ipinfo.io bila gagal
    $backup = @file_get_contents("https://ipinfo.io/{$ip_address}/json");
    if ($backup !== false) {
        $info = json_decode($backup, true);
        if (!empty($info)) {
            $location['country'] = $info['country'] ?? '';
            $location['city'] = $info['city'] ?? '';
            $location['org'] = $info['org'] ?? '';
            if (!empty($info['loc'])) {
                list($location['lat'], $location['lon']) = explode(',', $info['loc']);
            }
        }
    }
}


    // === SIMPAN KE DATABASE ===
    $stmt = $conn->prepare("INSERT INTO url_clicks 
        (url_id, ip_address, user_agent, referer, device_type, browser, os, country, city, region, postal, org, asn, timezone, latitude, longitude, clicked_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "issssssssssssssss",
        $url_data['id'],
        $ip_address,
        $user_agent,
        $referer,
        $device_type,
        $browser,
        $os,
        $location['country'],
        $location['city'],
        $location['region'],
        $location['postal'],
        $location['org'],
        $location['asn'],
        $location['timezone'],
        $location['lat'],
        $location['lon'],
        $timestamp
    );
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

function notFound($msg = "Short URL not found or inactive.") {
    http_response_code(404);
    echo "
    <html><head>
    <link rel='icon' type='image/png' href='favicon.png'>
    <title>404 - URL Not Found</title>
    <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css'>

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(circle at top left, #1a1c22, #0f1116);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
    overflow-x: hidden;  /* hilangkan scroll horizontal */
    overflow-y: hidden;
  -webkit-user-select: none;
  user-select: none;
        }
        .box {
            text-align: center;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 60px 40px;
            width: 90%;
            max-width: 420px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            backdrop-filter: blur(4px);
            animation: fadeIn 0.6s ease;
        }
        h1 {
            font-size: 80px;
            margin-bottom: 10px;
            color: #ff4757;
            text-shadow: 0 0 15px rgba(255,71,87,0.5);
        }
        p {
            color: #ccc;
            margin-bottom: 25px;
        }
        a {
  background: linear-gradient(135deg, #ff2020, #e35151);
  color: #fff;
  padding: 12px 24px;
  border-radius: 10px;
  text-decoration: none;
  font-weight: 600;
  transition: all 0.3s ease;
  display: inline-block;
}

a:hover {
  background: linear-gradient(135deg, #e51b1b, #c94040);
  box-shadow: 0 0 12px rgba(255, 32, 32, 0.6);
  transform: translateY(-2px);
}

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
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
  padding: 12px 24px;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-block;
  transition: all 0.3s ease;
  display: block;          /* ganti inline-block → block */
  width: 100%;             /* isi penuh lebar parent */
  box-sizing: border-box;  /* supaya padding tidak melebihi width */
}
    </style></head><body>
    <div class='box'>
    <h1>
    <i class='fas fa-exclamation-circle'></i>404</h1>
        <p>$msg</p>
        <a href='index.php'>Back to Home</a>
    </div>
    <!-- Tambahkan sebelum penutup body -->
    <script src='https://cdnjs.cloudflare.com/ajax/libs/three.js/r134/three.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/vanta@latest/dist/vanta.dots.min.js'></script>
    <script>
    VANTA.DOTS({
      el: 'body',             // Terapkan ke body
        mouseControls: true,
  touchControls: true,
  gyroControls: true,
  minHeight: 200.00,
  minWidth: 200.00,
  scale: 1.00,
  scaleMobile: 1.00,
  color: 0xff2020,
  color2: 0xe35151,
  backgroundColor: 0x0
    });
    </script>
    </body></html>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($main_title ?? 'Go to ' . ($host ?? 'Link')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="favicon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css">

<style>
body {
  background: radial-gradient(circle at top left, #1a1c22, #0f1116);
  color: #fff;
  font-family: 'Inter', sans-serif;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  margin: 0;
  padding: 40px 20px;
  -webkit-user-select: none;
  user-select: none;
  overflow-y: hidden !important;
}
input, textarea, select, button, [contenteditable] {
  -webkit-user-select: text;
  user-select: text;
}
html, body { touch-action: manipulation; }

.box {
  border: 1px solid rgba(255,255,255,0.1);
  padding: 40px;
  border-radius: 20px;
  text-align: center;
  width: 90%;
  max-width: 420px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.3);
  backdrop-filter: blur(10px);
  animation: fadeIn 0.6s ease;
}
h1, h2 {
  margin-bottom: 15px;
  color: #fff;
}
input {
  width: 100%;
  padding: 12px;
  border: 1px solid rgba(255,255,255,0.2);
  border-radius: 8px;
  background: rgba(255,255,255,0.05);
  color: #fff;
  margin-bottom: 15px;
  outline: none;
  transition: all 0.2s ease;
}
input:focus {
  border-color: #667eea;
  box-shadow: 0 0 8px rgba(102,126,234,0.4);
}
button, .btn {
  background: linear-gradient(135deg, #667eea, #4461e2ff);
  color: #fff;
  padding: 12px 24px;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-block;
  transition: all 0.3s ease;
  display: block;          /* ganti inline-block → block */
  width: 100%;             /* isi penuh lebar parent */
  box-sizing: border-box;  /* supaya padding tidak melebihi width */
}
button:hover, .btn:hover {
  background: linear-gradient(135deg, #5568d3, #2e4ed8ff);
  box-shadow: 0 0 10px rgba(102,126,234,0.5);
}
.btn.selected {
  background: linear-gradient(135deg, #5568d3, #2e4ed8ff);
  box-shadow: 0 0 12px rgba(102,126,234,0.6);
  transform: scale(1.02);
}
.error {
  color: #ff6b6b;
  margin-bottom: 15px;
}
.branding {
  position: fixed;
  bottom: 15px;
  left: 0;
  right: 0;
  text-align: center;
  font-size: 13px;
  color: #777;
}
.branding a {
  color: #667eea;
  text-decoration: none;
  font-weight: 600;
}
.branding a:hover { text-decoration: underline; }
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}
@media (max-width: 480px) {
  .box {
    padding: 24px;
  }
  h1, h2 {
    font-size: 1.3em;
  }
  input, button {
    width: 100%;
  }
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
h1 {
    white-space: nowrap;        /* Supaya teks tidak turun ke baris berikutnya */
    overflow: hidden;           /* Sembunyikan teks yang melewati batas */
    text-overflow: ellipsis;    /* Tambahkan "..." di akhir teks yang terpotong */
    max-width: 100%;            /* Pastikan mengikuti lebar div pembungkus */
    display: block;             /* Pastikan elemen h1 jadi blok penuh */
  }

  h1 i {
    margin-right: 8px;          /* Spasi antara ikon dan teks */
  }

  /* New layout for multi-links */
  .box.title-box {
      margin-bottom: 30px;
      max-width: 900px;
  }
  .multi-link-wrapper {
      width: 100%;
      max-width: 900px;
      display: flex;
      gap: 30px;
  }
  .preview-column {
      flex: 1.3;
      position: sticky;
      top: 40px;
      align-self: flex-start;
  }
  .preview-column img {
      width: 100%;
      border-radius: 12px;
      box-shadow: 0 10px 40px rgba(0,0,0,0.3);
      aspect-ratio: 16 / 10;
      object-fit: fill;
      background-color: rgba(255,255,255,0.05);
  }
  .links-column {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 15px;
  }

  .skeleton {
    background-color: rgba(255, 255, 255, 0.1);
    animation: pulse 1.5s infinite ease-in-out;
  }
  @keyframes pulse {
      0% { background-color: rgba(255, 255, 255, 0.08); }
      50% { background-color: rgba(255, 255, 255, 0.15); }
      100% { background-color: rgba(255, 255, 255, 0.08); }
  }
</style>
</head>
<body>

<?php if (!$can_access): ?>
  <div class="box">
    <h2><i class="fas fa-lock"></i> Protected Link</h2>
    <?php if (!empty($error)): ?>
      <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

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
    <?php
    // This block contains all the PHP logic to prepare variables
    $urls = json_decode($url_data['original_url'] ?? '[]', true);
    $urls = is_array($urls) ? $urls : [];
    $count = count($urls);
    $is_multi = $count > 1;

    if ($is_multi) {
      // Preview generation is now handled by client-side JavaScript
    }

    if (!empty($code_data['target_url'])) {
        $single_target = $code_data['target_url'];
        $single_title = $code_data['target_title'] ?? $code_data['participant_name'] ?? 'Click here!';
    } elseif (!empty($urls) && !$is_multi) { // Adjusted for single link from original_url
        $single_target = $urls[0]['url'] ?? '#';
        $single_title = $urls[0]['title'] ?? 'Click here!';
    } else if ($is_multi) {
        // For multi-link, these are not used, but let's not leave them empty
        $single_target = '#';
        $single_title = '';
    } else {
        $single_target = $target_url ?? '#';
        $single_title = 'Click here!';
    }
    $host_name = parse_url($single_target, PHP_URL_HOST) ?? "Link";?>

    <?php if ($is_multi): ?>
    <div class="box title-box">
      <h1><i class="fas fa-external-link-alt"></i> <?= htmlspecialchars($main_title) ?></h1>
      <p style="color:#a5b4fc;word-break:break-all;">Choose a destination to visit</p>
    <div class="multi-link-wrapper">
        <div class="preview-column">
            <img id="multi-link-preview-image" src="" alt="Link preview" class="skeleton">
        </div>
        <div class="links-column">
            <?php foreach ($urls as $key => $u): ?>
                <a href="<?= htmlspecialchars($u['url']) ?>" 
                   class="btn preview-btn" 
                   target="_blank"
                   data-preview="<?= $u['preview_base64'] ?? '' ?>"
                   data-index="<?= $key ?>"
                   data-url-id="<?= $url_data['id'] ?>">
                    <?= htmlspecialchars($u['title'] ?: $u['url']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: // Single link layout ?>
    <div class="box">
      <h1><i class="fas fa-external-link-alt"></i> <?= htmlspecialchars($host ?? $target_url) ?></h1>
      <p style="color:#a5b4fc;word-break:break-all;">Ready to visit</p>
      
      <?php if (!empty($preview_base64)): ?>
        <div style="margin-top:25px;">
          <img src="<?= $preview_base64 ?>" width="100%" style="border-radius:12px; box-shadow:0 0 20px rgba(0,0,0,0.4);">
          <p style="font-size:12px;color:#888;">Website preview</p>
        </div>
      <?php endif; ?>

      <div class="result-container" style="margin-top: 25px;">
          <a href="<?= htmlspecialchars($single_target ?? $target_url) ?>" class="btn"><?= htmlspecialchars($single_title ?? 'Click here!') ?></a>
      </div>
    </div>
    <?php endif; ?>  
  </div>

<?php endif; ?>

<footer class="branding">
  🔗 Shortened with <a href="<?= BASE_URL ?? 'index.php' ?>">URL Shortener</a>
  <span style="color:#aaa;"> by <strong style="color:#fff;">Rzky.NT</strong></span>
</footer>

<?php $conn->close(); ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const previewImage = document.getElementById('multi-link-preview-image');
    const links = Array.from(document.querySelectorAll('.links-column .preview-btn'));

    if (!previewImage || links.length === 0) return;

    // --- Preview Fetching Queue ---
    const queue = [];
    const maxConcurrent = 1;
    let inFlight = 0;

    const processQueue = () => {
        while (inFlight < maxConcurrent && queue.length > 0) {
            const { link, index, urlId } = queue.shift();
            inFlight++;
            fetch(`generate_preview.php?url_id=${urlId}&index=${index}`)
                .then(response => response.ok ? response.json() : Promise.reject('Network response was not ok.'))
                .then(data => {
                    if (data.preview_base64) {
                        link.dataset.preview = data.preview_base64;
                        if (previewImage.classList.contains('skeleton')) {
                            updatePreview(link);
                        }
                    }
                })
                .catch(error => console.error('Failed to fetch preview for index', index, error))
                .finally(() => {
                    inFlight--;
                    processQueue();
                });
        }
    };

    // --- UI Interaction Logic ---
    const isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
    let lastTappedLink = null;

    const updatePreview = (linkElement) => {
        const currentPreview = linkElement.dataset.preview;
        if (currentPreview && currentPreview !== '') {
            previewImage.src = currentPreview;
            previewImage.classList.remove('skeleton');
        }
    };
    
    const clearSelection = () => {
        lastTappedLink = null;
        links.forEach(l => l.classList.remove('selected'));
    };

    // --- Initialization ---
    let firstPreviewFound = false;
    links.forEach(link => {
        // Populate fetch queue
        const previewData = link.dataset.preview;
        if (previewData) {
            if (!firstPreviewFound) {
                updatePreview(link);
                firstPreviewFound = true;
            }
        } else {
            queue.push({ link, index: link.dataset.index, urlId: link.dataset.urlId });
        }

        // Attach event listeners based on device type
        if (isTouchDevice) {
            link.addEventListener('click', (e) => {
                if (link !== lastTappedLink) {
                    e.preventDefault();
                    clearSelection();
                    lastTappedLink = link;
                    link.classList.add('selected');
                    updatePreview(link);
                }
                // On second tap, lastTappedLink === link, so default navigation occurs
            });
        } else {
            // Desktop behavior: hover to preview, click to go
            link.addEventListener('mouseenter', () => updatePreview(link));
        }
    });

    // For touch devices, tapping outside the links should reset the selection
    if (isTouchDevice) {
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.links-column')) {
                clearSelection();
            }
        }, true); // Use capture to ensure it runs before other clicks might be stopped
    }

    // Start fetching any missing previews
    processQueue();
});
</script>

<!-- Tambahkan sebelum penutup body -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r134/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vanta@latest/dist/vanta.dots.min.js"></script>
<script>
VANTA.DOTS({
  el: "body",             // Terapkan ke body
  mouseControls: true,
  touchControls: true,
  gyroControls: true,
  minHeight: 200.00,
  minWidth: 200.00,
  scale: 1.00,
  scaleMobile: 1.00,
  color: 0x207fff,
  color2: 0x515ce3,
  backgroundColor: 0x0
});
</script>

</body>
</html>
