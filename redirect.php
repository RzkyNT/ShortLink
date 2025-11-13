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

// === 📸 AUTO GENERATE PREVIEW JIKA BELUM ADA ===
$preview_base64 = $url_data['preview_base64'] ?? '';

if (empty($preview_base64) && filter_var($target_url, FILTER_VALIDATE_URL)) {
    $api_url = 'https://api.screenshotone.com/take';
    $query = [
        'access_key' => 'duAaHYw2b-sumg',
        'url' => $target_url,
        'viewport_device' => 'galaxy_s5_landscape',
        'format' => 'jpg',
        'block_ads' => 'true',
        'block_cookie_banners' => 'true',
        'block_banners_by_heuristics' => 'false',
        'block_trackers' => 'true',
        'delay' => '0',
        'timeout' => '60',
        'response_type' => 'json',
        'image_quality' => '80',
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

                // Simpan ke DB agar tidak perlu ambil ulang
                $stmt = $conn->prepare("UPDATE urls SET preview_base64 = ? WHERE id = ?");
                $stmt->bind_param("si", $preview_base64, $url_data['id']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

$main_title = $url_data['title'] ?? '';
$host = parse_url($target_url, PHP_URL_HOST);
$qr_base64 = $url_data['qr_base64'] ?? '';

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

$conn->close();
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
  align-items: center;
  justify-content: center;
  height: 100vh;
  margin: 0;
  -webkit-user-select: none;
  user-select: none;
    overflow-x: hidden;  /* hilangkan scroll horizontal */
    overflow-y: hidden;
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
</style>
</head>
<body>

<div class="box">
<?php if (!$can_access): ?>
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
  // Decode JSON array selalu (untuk mode multi-link / public)
  $urls = json_decode($url_data['original_url'] ?? '[]', true);
  $urls = is_array($urls) ? $urls : [];
  $count = count($urls);
  $is_multi = $count > 1;

  // 🔧 Prioritaskan target_url dari tabel url_codes jika ada (mode per_code)
  if (!empty($code_data['target_url'])) {
      $single_target = $code_data['target_url'];
      // Jika ada judul khusus di code_data, gunakan, jika tidak fallback ke participant_name atau default
      $single_title = $code_data['target_title'] 
          ?? $code_data['participant_name'] 
          ?? 'Click here!';
  } 
  // 🔧 Jika tidak ada target_url per_code, pakai data dari original_url
  elseif (!empty($urls)) {
      $single_target = $urls[0]['url'] ?? '#';
      $single_title = $urls[0]['title'] ?? 'Click here!';
  } 
  // 🔧 Fallback terakhir (tidak ada data valid)
  else {
      $single_target = $target_url ?? '#';
      $single_title = 'Click here!';
  }

  // Deteksi nama host
  $host_name = parse_url($single_target, PHP_URL_HOST) ?? "Link";
?>

  <h1><i class="fas fa-external-link-alt"></i>
    <?= $is_multi ?  htmlspecialchars($main_title) :  htmlspecialchars($host ?? $target_url) ?>
  </h1>
  <p style="color:#a5b4fc;word-break:break-all;">
    <?= $is_multi ? 'Choose to visit' : 'Ready to visit'?></p>
  <?php if (!empty($preview_base64)): ?>
<div style="margin-top:25px;">
  <img src="<?= $preview_base64 ?>" width="100%" style="border-radius:12px; box-shadow:0 0 20px rgba(0,0,0,0.4);">
  <p style="font-size:12px;color:#888;">Preview website tujuan</p>
</div>
<?php endif; ?>
  <div class="result-container">
<?php if ($is_multi): ?>
    <div class="multi-links">
        <?php foreach ($urls as $u): ?>
            <div class="link-item" style="margin:10px 0;">
                <a href="<?= htmlspecialchars($u['url']) ?>" class="btn" target="_blank">
                    <?= htmlspecialchars($u['title'] ?: $u['url']) ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <a href="<?= htmlspecialchars($single_target ?? $target_url) ?>" class="btn"><?= htmlspecialchars($single_title ?? 'Click here!') ?></a>
<?php endif; ?> <!-- <-- INI HARUS DITAMBAHKAN -->
</div> <!-- result-container -->


<footer class="branding">
  🔗 Shortened with <a href="<?= BASE_URL ?? 'index.php' ?>">URL Shortener</a>
  <span style="color:#aaa;"> by <strong style="color:#fff;">Rzky.NT</strong></span>
</footer>

<!-- Modal QR untuk multi-link -->
<div id="qrModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
background:rgba(0,0,0,0.7); justify-content:center; align-items:center;">
  <div style="background:#111; padding:20px; border-radius:12px; text-align:center;">
    <h3 id="qrTitle" style="color:#fff; margin-bottom:10px;"></h3>
     <a id="qrImage" href="<?= $qr_base64 ?>" download="Qr <?= preg_replace('/[^a-zA-Z0-9-_]/','_', $url_data['title'] ?? $short_code) ?>.png">
    <img id="qrImage" src="<?= $qr_base64 ?>" style="width:200px; height:200px; border-radius:12px;">
    </a>
    <p style="font-size:12px;color:#888;">Klik QR untuk download</p>
    <button onclick="document.getElementById('qrModal').style.display='none';" 
      style="margin-top:15px;padding:8px 16px;border:none;border-radius:8px;">Close</button>
  </div>
</div>

<?php endif; ?>
<script>
window.addEventListener('DOMContentLoaded', () => {
    <?php if ($is_multi && !empty($qr_base64)): ?>
        // Tampilkan QR hanya untuk multi-link
        showQR("<?= htmlspecialchars($main_title ?? 'QR Code') ?>", "<?= $qr_base64 ?>");
    <?php endif; ?>
});
</script>

<script>
function showQR(title, qrBase64){
  document.getElementById('qrTitle').innerText = title;
  document.getElementById('qrImage').src = qrBase64;
  document.getElementById('qrModal').style.display = 'flex';
}
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
