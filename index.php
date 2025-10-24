<?php
require_once 'config/database.php';

$error = null;
$success_url = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shorten_url'])) {
    $original_url = trim($_POST['original_url'] ?? '');
    $title = trim($_POST['title'] ?? 'Link'); // optional title

    if (empty($original_url)) {
        $error = 'Please enter a URL';
    } elseif (!filter_var($original_url, FILTER_VALIDATE_URL)) {
        $error = 'Please enter a valid URL';
    } else {
        $conn = getDBConnection();

        // generate short code unik
        do {
            $short_code = generateShortCode();
            $check_stmt = $conn->prepare("SELECT id FROM urls WHERE short_code = ?");
            $check_stmt->bind_param("s", $short_code);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();
        } while ($exists);

        // simpan original_url sebagai JSON array
        $json_url = json_encode([['title' => $title, 'url' => $original_url]]);

        $stmt = $conn->prepare("INSERT INTO urls (short_code, original_url, status, user_id) VALUES (?, ?, 'active', NULL)");
        $stmt->bind_param("ss", $short_code, $json_url);

        if ($stmt->execute()) {
            $success_url = BASE_URL . $short_code;
        } else {
            $error = 'Error creating short URL';
        }

        $stmt->close();
        $conn->close();
    }
}

// Fungsi generate short code
function generateShortCode($length = 6) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// ambil stats seperti biasa
$conn = getDBConnection();
$stats_query = "
    SELECT 
        COUNT(DISTINCT u.id) AS total_urls,
        COUNT(c.id) AS total_clicks,
        SUM(CASE WHEN u.access_password IS NOT NULL THEN 1 ELSE 0 END) AS protected_links,
        SUM(CASE WHEN u.one_time = 1 THEN 1 ELSE 0 END) AS one_time_links,
        SUM(CASE WHEN u.expire_at IS NOT NULL AND u.expire_at < NOW() THEN 1 ELSE 0 END) AS expired_links,
        COUNT(DISTINCT u.user_id) AS active_users
    FROM urls u
    LEFT JOIN url_clicks c ON u.id = c.url_id
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>URL Shortener - Modern SaaS</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="favicon.png">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Inter', sans-serif;
        color: #eee;
        background: #0f1116;
        line-height: 1.6;
    }

    /* NAVIGATION */
    nav {
        position: sticky;
        top:1vh;
        backdrop-filter: blur(10px);
        background: rgb(15 17 22 / 28%);
        padding: 15px 0;
        z-index: 1000;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        margin-right:1vh;
        margin-left:1vh;
        border-radius:12px;
    }
    .nav-container {
        max-width: 1200px; margin: 0 auto;
        display: flex; justify-content: space-between; align-items: center;
        padding: 0 20px;
    }
    .logo {
        font-weight: 700; font-size: 22px;
        color: #fff;
        letter-spacing: 1px;
    }
    .nav-links {
        display: flex; gap: 25px; align-items: center;
    }
    .nav-links a {
        color: #ccc; text-decoration: none; font-weight: 500;
        transition: color .3s;
    }
    .nav-links a:hover { color: #7a5af8; }
    .btn-login {
        background: linear-gradient(135deg,#7a5af8,#4c28f2);
        color: white !important; padding: 10px 18px;
        border-radius: 8px; font-weight: 600;
        transition: transform .3s;
    }
    .btn-login:hover { transform: translateY(-2px); }

    /* HERO */
    .hero {
        padding: 100px 20px;
        text-align: center;
        height:90vh;
    }
    .hero h1 {
        font-size: 48px;
        font-weight: 700;
        color: white;
        margin-bottom: 15px;
    }
    .hero p {
        color: #7a5af8;
        font-size: 18px;
        max-width: 650px;
        margin: 0 auto 40px;
    }

    /* SHORTENER BOX */
    .shortener-box {
        background: rgba(255,255,255,0.05);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 15px;
        padding: 30px;
        max-width: 600px;
        margin: 0 auto;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    }
    .shortener-form {
        display: flex; gap: 10px;
        flex-wrap: wrap;
    }
    .shortener-form input {
        flex: 1;
        padding: 15px;
        border-radius: 10px;
        border: 1px solid #333;
        background: #1b1d26;
        color: white;
        font-size: 16px;
    }
    .shortener-form button {
        padding: 15px 30px;
        background: linear-gradient(135deg,#7a5af8,#4c28f2);
        border: none; color: white;
        font-weight: 600; border-radius: 10px;
        cursor: pointer; transition: all .3s;
    }
    .shortener-form button:hover {
        transform: scale(1.05);
        box-shadow: 0 0 15px #7a5af8;
    }
    .error-box {
        background: #ff4d4d33;
        border: 1px solid #ff4d4d55;
        padding: 15px; border-radius: 10px;
        margin-bottom: 15px; text-align: center;
        color: #ff6666;
    }
    .result-box {
        background: #1b1d26;
        border-radius: 10px;
        padding: 25px;
        text-align: center;
        color: #fff;
        max-height:200px;
    }
    .short-url-display {
        background: #0f1116;
        padding: 15px;
        border-radius: 8px;
        font-family: monospace;
        font-size: 18px;
        color: #7a5af8;
        margin: 10px 0;
        word-break: break-all;
    }
    .copy-btn {
        background: #28a745;
        border: none; color: white;
        padding: 10px 20px; border-radius: 8px;
        cursor: pointer; font-weight: 600;
    }

    /* FEATURES */
    .features {
        padding: 100px 20px;
        background: rgb(15 17 22 / 70%);
        backdrop-filter:blur(10px);
        margin-top:50px;
    }
    .section-title {
        text-align: center; font-size: 36px;
        margin-bottom: 50px; color: #fff;
    }
    .features-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit,minmax(280px,1fr));
        gap: 30px; max-width: 1100px; margin: 0 auto;
    }
    .feature-card {
        background: rgba(255,255,255,0.05);
        border-radius: 12px;
        padding: 30px;
        text-align: center;
        transition: all .3s;
        border: 1px solid rgba(255,255,255,0.1);
    }
    .feature-card:hover {
        transform: translateY(-8px);
        background:#0f1116;
        box-shadow: 0 0 25px rgba(122,90,248,0.2);
    }
    .feature-icon {
        font-size: 40px;
        color: #7a5af8;
        margin-bottom: 20px;
    }
    .feature-card h3 {
        color: #fff;
        margin-bottom: 10px;
    }
    .feature-card p {
        color: #aaa;
        font-size: 15px;
    }

    /* STATS */
    .stats {
        padding: 10vh 20px;
        background:radial-gradient(circle at bottom, rgb(26 28 37 / 73%) 0%, rgb(67 113 228 / 52%) 100%);
        backdrop-filter:blur(10px);
        text-align: center; color: white;
        margin-top:50px;
    }
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit,minmax(200px,1fr));
        gap: 30px; max-width: 800px; margin: 0 auto;
    }
    .stat-item h2 { font-size: 48px; margin-bottom: 10px; }
    .stat-item p { opacity: 0.9; }

    /* CTA */
    .cta {
        padding: 100px 20px;
        text-align: center;        
        background:radial-gradient(circle at top, rgb(26 28 37 / 73%) 0%, rgb(67 113 228 / 52%) 100%)
    }
    .cta h2 {
        font-size: 36px;
        color: white;
        margin-bottom: 20px;
    }
    .cta p {
        color: #bbb;
        margin-bottom: 30px;
        font-size: 18px;
    }
    .cta-buttons {
        display: flex; justify-content: center;
        gap: 20px; flex-wrap: wrap;
    }
    .btn-primary, .btn-secondary {
        padding: 14px 35px;
        border-radius: 8px; font-weight: 600;
        text-decoration: none; transition: .3s;
    }
    .btn-primary {
        background: #7a5af8;
        color: white;
    }
    .btn-secondary {
        border: 2px solid #7a5af8;
        color: #7a5af8;
    }
    .btn-primary:hover, .btn-secondary:hover {
        transform: translateY(-3px);
    }

    /* FOOTER */
    footer {
        background: #0a0c10;
        text-align: center;
        color: #666;
        padding: 40px 0;
        font-size: 14px;
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
  <div id="vanta-bg"></div> <!-- Background Awan -->

  <div class="content">
<nav>
    <div class="nav-container">
        <div class="logo"><i class="fa-solid fa-link"></i> URL Shortener</div>
        <div class="nav-links">
            <a href="#features">Features</a>
            <a href="#stats">Stats</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="dashboard.php" class="btn-login"><i class="fa-solid fa-gauge"></i> Dashboard</a>
            <?php else: ?>
                <a href="login.php" class="btn-login"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<section class="hero" id="hero">
    <h1>Shorten. Share. Track.</h1>
    <p>Turn long URLs into smart, shareable links — secure, fast, and analytics-ready.</p>
    <div class="shortener-box">
        <?php if ($error): ?>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success_url): ?>
            <div class="result-box">
                <h3><i class="fa-solid fa-check-circle"></i> Short URL Created!</h3>
                <div class="short-url-container">
                    <input type="text" class="short-url-display" id="shortUrl" readonly value="<?= htmlspecialchars($success_url) ?>">
                    <button class="copy-btn"><i class="fa-solid fa-copy"></i></button>
                </div>
                <p style="margin-top:15px;color:#999;font-size:14px;">
                    <a href="login.php" style="color:#7a5af8;">Login</a> to manage your URLs
                </p>
            </div>
        <?php else: ?>
            <form method="POST" class="shortener-form">
                <input type="url" name="original_url" placeholder="Enter your long URL..." required>
                <button type="submit" name="shorten_url"><i class="fa-solid fa-bolt"></i> Shorten</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="features" id="features">
    <h2 class="section-title">Why Choose Us</h2>
    <div class="features-grid">
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-bolt"></i></div>
            <h3>Instant Shortening</h3>
            <p>Create short links instantly — no registration needed!</p>
        </div>
         <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-chart-simple"></i></div>
            <h3>Detailed Analytics</h3>
            <p>Track clicks, visitor countries, browsers, and devices — all in real-time dashboards.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-pencil"></i></div>
            <h3>Custom Links</h3>
            <p>Create branded short codes for better recognition and marketing.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-qrcode"></i></div>
            <h3>QR Code Generator</h3>
            <p>Generate QR codes for any link — easy sharing across devices.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <h3>Security</h3>
            <p>All URLs encrypted and monitored for spam protection.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-mobile-screen"></i></div>
            <h3>Responsive</h3>
            <p>Optimized for mobile, tablet, and desktop devices.</p>
        </div>
        <!-- Fitur baru -->
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-lock"></i></div>
            <h3>Password Protection</h3>
            <p>Protect your links with a password so only authorized users can access them.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-hourglass-half"></i></div>
            <h3>One-Time Use</h3>
            <p>Create links that expire after a single click — perfect for sensitive sharing.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
            <h3>Url Expiration</h3>
            <p>Set expiration dates for individual short URLs automatically.</p>
        </div>
        </div>
</section>

<section class="stats" id="stats">
  <h2 class="section-title">Our Impact</h2>
  <div class="stats-grid">
    
    <div class="stat-item">
      <i class="fa-solid fa-link fa-2x"></i>
      <h2><?= number_format($stats['total_urls']) ?>+</h2>
      <p>URLs Shortened</p>
    </div>

    <div class="stat-item">
      <i class="fa-solid fa-mouse-pointer fa-2x"></i>
      <h2><?= number_format($stats['total_clicks']) ?>+</h2>
      <p>Total Clicks</p>
    </div>

    <div class="stat-item">
      <i class="fa-solid fa-lock fa-2x"></i>
      <h2><?= number_format($stats['protected_links']) ?>+</h2>
      <p>Password-Protected Links</p>
    </div>

    <div class="stat-item">
      <i class="fa-solid fa-user-clock fa-2x"></i>
      <h2><?= number_format($stats['one_time_links']) ?>+</h2>
      <p>One-Time Use Links</p>
    </div>

    <div class="stat-item">
      <i class="fa-solid fa-hourglass-end fa-2x"></i>
      <h2><?= number_format($stats['expired_links']) ?>+</h2>
      <p>Expired Links</p>
    </div>

    <div class="stat-item">
      <i class="fa-solid fa-server fa-2x"></i>
      <h2>99.9%</h2>
      <p>Uptime</p>
    </div>
    
  </div>
</section>


<section class="cta">
  <div class="cta-content">
    <h2>Track, Shorten & Optimize Your Links</h2>
    <p>Sign up today to get real-time analytics, track every click, and maximize your link performance.</p>
    <div class="cta-buttons">
      <a href="login.php" class="btn btn-primary" style="color:#0a0c10">
        <i class="fa-solid fa-user" aria-hidden="true"></i> Access Dashboard
      </a>
      <a href="#hero" class="btn btn-secondary" id="create-link-btn">
        <i class="fa-solid fa-link" aria-hidden="true"></i> Shorten Your First Link
      </a>
    </div>
  </div>
</section>

<style>
    .short-url-container {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
}

.short-url-display {
    flex: 1;
    background: #1a1c25;
    color: #fff;
    border: 2px solid #4c28f2;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 15px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.copy-btn {
    background: linear-gradient(135deg, #7a5af8, #4c28f2);
    border: none;
    color: #fff;
    padding: 10px 14px;
    border-radius: 6px;
    cursor: pointer;
    transition: transform 0.2s ease, opacity 0.2s ease;
}

.copy-btn:hover {
    transform: translateY(-2px);
    opacity: 0.9;
}

.cta {
  padding: 80px 20px;
  text-align: center;
  color: #fff;
  border-radius: 12px;
  margin: 40px auto;
  max-width: 900px;
  box-shadow: 0 8px 24px rgba(0,0,0,0.15);
}
.cta h2 {
  font-size: 2.5rem;
  margin-bottom: 20px;
}
.cta p {
  font-size: 1.2rem;
  margin-bottom: 30px;
}
.cta-buttons {
  display: flex;
  justify-content: center;
  gap: 20px;
  flex-wrap: wrap;
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 12px 28px;
  font-size: 1rem;
  font-weight: 600;
  border-radius: 8px;
  text-decoration: none;
  transition: all 0.3s ease;
}
.btn-primary {
  background-color: #fff;
  color: #2575fc;
}
.btn-primary:hover {
  background-color: #e0e0e0;
}
.btn-secondary {
  background-color: transparent;
  border: 2px solid #fff;
  color: #fff;
}
.btn-secondary:hover {
  background-color: rgba(255,255,255,0.2);
  border-color: #fff;
}
@media(max-width: 600px) {
  .cta h2 {
    font-size: 2rem;
  }
  .cta p {
    font-size: 1rem;
  }
}
    #vanta-bg {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: -1; /* supaya di belakang konten */
}

.content {
  position: relative;
  z-index: 1;
}

</style>

<script>
document.getElementById('create-link-btn').addEventListener('click', function(e){
  e.preventDefault();
  const input = document.querySelector('input[name=original_url]');
  if(input){
    input.focus();
  }
});
</script>

<footer>
    <p>&copy; <?= date('Y') ?> URL Shortener — All rights reserved.</p>
</footer>

<script>
    
function copyUrl() {
    const urlText = document.getElementById('shortUrl')?.value;
    if (!urlText) return;

    navigator.clipboard.writeText(urlText).then(() => {
        const btn = document.querySelector('.copy-btn');
        if (!btn) return;
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
        btn.style.background = '#3ccf5a';

        setTimeout(() => {
            btn.innerHTML = original;
            btn.style.background = '#28a745';
        }, 2000);
    });
}

// Pasang event listener, CSP-friendly
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.querySelector('.copy-btn');
    if (btn) btn.addEventListener('click', copyUrl);
});

</script>
      <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r134/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vanta@latest/dist/vanta.clouds.min.js"></script>

<script>
VANTA.CLOUDS({
  el: "#vanta-bg",   // ✅ pakai id background tadi
  mouseControls: true,
  touchControls: true,
  gyroControls: false,
  minHeight: 200.00,
  minWidth: 200.00,
  skyColor: 0x0,
  sunColor: 0x2218ff,
  sunGlareColor: 0x307cff,
  sunlightColor: 0x30ff8c,
  speed: 0.80
})
</script>

</body>
</html>
