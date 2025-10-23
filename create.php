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
$qr_base64 = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ 1. Set access mode dulu
    $access_mode = $_POST['access_mode'] ?? 'public';
    error_log("Mode: $access_mode, Participants: " . print_r($_POST['participants'] ?? [], true));
    // Ambil input URL
    $original_url = trim($_POST['original_url'] ?? '');

    // Ambil title dan custom code sesuai mode
    if ($access_mode === 'public') {
        $title = trim($_POST['title_public'] ?? '');
        $custom_code = trim($_POST['custom_code_public'] ?? '');
        $one_time = isset($_POST['one_time_public']) ? 1 : 0;
    } else { // per_code / individual
        $title = trim($_POST['title_individual'] ?? '');
        $custom_code = trim($_POST['custom_code_individual'] ?? '');
        $one_time = isset($_POST['one_time_individual']) ? 1 : 0;

        // Jika original_url kosong, pakai placeholder
        if (empty($original_url)) {
            $original_url = 'INDIVIDUAL_LINK_MODE';
        }
    }

    $access_password = trim($_POST['access_password'] ?? '');
    $expire_at = !empty($_POST['expire_at']) ? date('Y-m-d H:i:s', strtotime($_POST['expire_at'])) : null;
    $password_plain = !empty($access_password) ? $access_password : null;

    // Validasi dasar untuk mode publik
    if ($access_mode === 'public') {
        if (empty($original_url)) {
            $error = 'Please enter a URL';
        } elseif (!filter_var($original_url, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid URL';
        }
    }

    if (empty($error)) {
        $conn = getDBConnection();
        $user_id = $_SESSION['user_id'];

        // =========================
        // Tentukan short code
        // =========================
        if (!empty($custom_code)) {
            $short_code = preg_replace('/[^a-zA-Z0-9-_]/', '', $custom_code);

            // Cek apakah sudah dipakai
            $check_stmt = $conn->prepare("SELECT id FROM urls WHERE short_code = ?");
            $check_stmt->bind_param("s", $short_code);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();

            if ($exists) {
                $error = 'This custom code is already taken.';
            }
        } else {
            // Generate otomatis jika tidak ada custom code
            do {
                $short_code = generateShortCode();
                $check_stmt = $conn->prepare("SELECT id FROM urls WHERE short_code = ?");
                $check_stmt->bind_param("s", $short_code);
                $check_stmt->execute();
                $exists = $check_stmt->get_result()->num_rows > 0;
                $check_stmt->close();
            } while ($exists);
        }

        // =========================
        // Simpan ke tabel urls
        // =========================
        if (empty($error)) {
            $created_url = BASE_URL . $short_code;

            ob_start();
            QRcode::png($created_url, null, QR_ECLEVEL_L, 4);
            $imageString = ob_get_contents();
            ob_end_clean();

            $qr_base64 = 'data:image/png;base64,' . base64_encode($imageString);

            $stmt = $conn->prepare("
                INSERT INTO urls (
                    short_code, original_url, title, qr_base64,
                    status, user_id, access_mode, access_password, expire_at, one_time
                ) VALUES (?, ?, ?, ?, 'active', ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "ssssisssi",
                $short_code,
                $original_url,
                $title,
                $qr_base64,
                $user_id,
                $access_mode,
                $password_plain,
                $expire_at,
                $one_time
            );

            if ($stmt->execute()) {
                $url_id = $conn->insert_id;
    error_log("URL ID created: $url_id");
                // =========================
                // Simpan peserta jika mode per_code
                // =========================
                if ($access_mode === 'per_code' && !empty($_POST['participants'])) {
                    $participants = $_POST['participants'];

                    $insert_code = $conn->prepare("
                        INSERT INTO url_codes (url_id, participant_name, code, target_url)
                        VALUES (?, ?, ?, ?)
                    ");

                    foreach ($participants as $p) {
                        $name   = trim($p['name'] ?? '');
                        $code   = trim($p['code'] ?? '');
                        $target = trim($p['target_url'] ?? '');

                        // ✅ Skip jika data peserta tidak lengkap
                        if (empty($name) || empty($code) || empty($target)) continue;

                        // Pastikan code unik
                        $check_code = $conn->prepare("SELECT id FROM url_codes WHERE code = ? AND url_id = ?");
                        $check_code->bind_param("si", $code, $url_id);
                        $check_code->execute();
                        $exists = $check_code->get_result()->num_rows > 0;
                        $check_code->close();

                        if ($exists) continue;


                        $insert_code->bind_param("isss", $url_id, $name, $code, $target);
                        if (!$insert_code->execute()) {
                            error_log("Insert failed: " . $insert_code->error);
                        } else {
                            error_log("Insert success for $name");
                        }
                    }

                    $insert_code->close();
                }

                $success = '✅ Short URL created successfully!';
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
<link rel="icon" type="image/png" href="favicon.png">
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
        background: #28a745;
        border: none; color: white;
        padding: 10px 20px; border-radius: 8px;
        cursor: pointer; font-weight: 600;
    }
    .copy-btn:hover {
        background: #218838;
    }
        @media (max-width: 600px) {
            .header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .form-card { padding: 25px; }
        }

        
.form-card {
    background: rgba(255, 255, 255, 0.08);
    backdrop-filter: blur(10px);
    padding: 35px;
    border-radius: 16px;
    max-width: 850px;
    width: 100%;
}
label { display:block; margin:10px 0 5px; color:#ccc; font-weight:600; }
input, select {
    width:100%; padding:12px; border-radius:8px;
    border:2px solid rgba(255,255,255,0.1);
    background:rgba(0,0,0,0.3); color:white;
}
.btn {
    background:#667eea; color:white; border:none;
    padding:12px 22px; border-radius:8px; margin-top:10px; cursor:pointer;
}
.btn:hover { background:#5568d3; }
/* Style untuk Checkbox */
.checkbox-group {
    /* Menampung checkbox dan label */
    display: flex;
    align-items: center;
    margin-bottom: 20px; /* Jarak bawah sesuai form-group */
}

/* Sembunyikan checkbox asli */
.checkbox-group input[type="checkbox"] {
    position: absolute;
    opacity: 0; /* Sembunyikan, tapi tetap dapat diakses */
    width: 0;
    height: 0;
}

/* Style untuk label kustom (pengganti checkbox) */
.checkbox-group label {
    /* Reset style label form-group yang umum */
    display: inline-flex; 
    align-items: center;
    cursor: pointer;
    font-weight: 400; /* Font yang lebih ringan untuk teks opsi */
    color: #ccc;
    margin: 0; /* Hapus margin yang tidak perlu */
    padding-left: 0;
}

/* Visual kotak checkbox kustom */
.checkbox-group label::before {
    content: '';
    display: inline-block;
    width: 18px;
    height: 18px;
    margin-right: 10px; /* Jarak antara kotak dan teks */
    border: 2px solid rgba(255, 255, 255, 0.2); /* Border agar sesuai dengan input */
    border-radius: 4px; /* Sudut sedikit melengkung */
    background: rgba(0, 0, 0, 0.3); /* Background gelap */
    transition: all 0.2s ease-in-out;
    flex-shrink: 0; /* Agar kotak tidak mengecil */
}

/* Style saat checkbox dicentang */
.checkbox-group input[type="checkbox"]:checked + label::before {
    background: #667eea; /* Warna background ungu/biru saat dicentang */
    border-color: #667eea; /* Warna border yang sama */
    /* Tambahkan tanda centang kustom (seperti ikon atau bayangan) */
    box-shadow: inset 0 0 0 4px #0f111a; /* Efek 'tick' sederhana */
}

/* Style saat checkbox mendapatkan fokus (keyboard accessibility) */
.checkbox-group input[type="checkbox"]:focus + label::before {
    outline: none;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.5); /* Glowing effect dari warna utama */
}

/* Style saat kursor di atas label */
.checkbox-group label:hover::before {
    border-color: #667eea;
}

/* Teks label untuk opsi 'One-time Access' (Jika ada dalam HTML Anda) */
.checkbox-group label span {
    font-size: 14px; /* Ukuran font yang konsisten */
}

input, select {
    width:100%; padding:12px; border-radius:8px;
    border:2px solid rgba(255,255,255,0.1);
    background:rgba(0,0,0,0.3); color:white;
}

select:focus {
    outline: none; 
    border-color: #667eea; /* Warna fokus yang sama dengan input teks */
}

/* Style tambahan untuk <select> agar panah dropdown lebih terlihat */
select {
    /* Menghilangkan panah default pada beberapa browser */
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    
    /* Tambahkan panah kustom menggunakan background image */
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='' width='24' height='24' viewBox='0 0 24 24'%3E%3Cpath fill='%23ccc' d='M7 10l5 5 5-5z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px top 50%;
    padding-right: 30px; /* Tambah padding agar panah tidak menimpa teks */
    cursor: pointer;
}

/* Style untuk opsi di dalam dropdown, walau implementasinya tergantung OS/Browser */
select option {
    background-color: #1a1c25; /* Background gelap saat dropdown terbuka */
    color: #fff;
    padding: 10px;
}


/* Override warna ikon kalender dan jam pada input[type="datetime-local"] (hanya bekerja pada beberapa browser) */
input[type="datetime-local"]::-webkit-calendar-picker-indicator {
    filter: invert(1); /* Membalik warna agar terlihat di background gelap */
    opacity: 0.7;
    cursor: pointer;
}
input[type="datetime-local"]::-webkit-calendar-picker-indicator:hover {
    opacity: 1;
}

/* Style Placeholder agar terlihat di input datetime-local yang kosong */
input[type="datetime-local"]:not([value]):valid {
    color: #ccc; /* Warna teks normal */
}
input[type="datetime-local"]:not([value]):valid::-webkit-datetime-edit-text,
input[type="datetime-local"]:not([value]):valid::-webkit-datetime-edit-month-field,
input[type="datetime-local"]:not([value]):valid::-webkit-datetime-edit-day-field,
input[type="datetime-local"]:not([value]):valid::-webkit-datetime-edit-year-field,
input[type="datetime-local"]:not([value]):valid::-webkit-datetime-edit-hour-field,
input[type="datetime-local"]:not([value]):valid::-webkit-datetime-edit-minute-field {
    color: #ccc; /* Pastikan bagian-bagian tanggal/waktu juga putih */
}

.participant-row input {
    margin-bottom: 8px;
}

.participant-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
.participant-row input {
    flex: 1 1 30%;
}
.participant-row input {
    background-color: rgba(255, 255, 255, 0.05);
}
#public-section, #individual-section {
    transition: all 0.3s ease;
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
        <h1><i class="fa-solid fa-link"></i> URL Shortener</h1>
        <a href="dashboard.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
    </div>
    <div class="form-card">
        <h2><i class="fa-solid fa-wand-magic-sparkles"></i> Create New Short URL</h2>

        <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="success">
            <?= htmlspecialchars($success) ?><br>
            <div class="created-url">
                <span id="createdUrl"><?= htmlspecialchars($created_url) ?></span>
                <button class="copy-btn" onclick="copyUrl()">Copy</button>
            </div>
            <?php if ($qr_base64): ?>
                <div style="margin-top:15px;text-align:center;">
                    <p>Scan QR:</p>
                    <img src="<?= $qr_base64 ?>" width="150" style="border-radius: 15px;">
                    <br><br>
                    <a href="<?= $qr_base64 ?>" 
                    download="<?= 'Qr ' . preg_replace('/[^a-zA-Z0-9-_]/', '_', $title ?: $short_code) ?>.png" 
                    class="btn" 
                    style="background:#4fd1c5;">
                    <i class="fa-solid fa-download"></i> Download QR
                    </a>
                </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Access Mode</label>
                <select name="access_mode" id="access_mode">
                    <option value="public">Public</option>
                    <option value="per_code">Individual</option>
                </select>
            </div>

            <!-- === PUBLIC SECTION === -->
            <div id="public-section">
                <label>Original URL *</label>
                <input type="url" name="original_url" placeholder="Required">
                <label>Title</label>
                <input type="text" name="title_public" placeholder="Optional">
                <label>Custom Code (short link)</label>
                <input type="text" name="custom_code_public" placeholder="Optional">
                <label>Password</label>
                <input type="text" name="access_password" placeholder="Optional">
                <label>Expiration Date</label>
                <input type="datetime-local" id="expire_at_public" name="expire_at">
                <div class="checkbox-group" style="margin-top: 20px;">
                    <input type="checkbox" id="one_time" name="one_time_public">
                    <label for="one_time"><span>One-time Access</span></label>
                </div>
            </div>

            <!-- === INDIVIDUAL SECTION === -->
            <div id="individual-section" style="display:none;">
                <label>Title</label>
                <input type="text" name="title_individual">
                <label>Custom Code (short link)</label>
                <input type="text" name="custom_code_individual" placeholder="Optional">
                <label>Expiration Date</label>
                <input type="datetime-local" id="expire_at_individual" name="expire_at">
                <div class="checkbox-group" style="margin-top: 20px;">
                    <input type="checkbox" id="one_time_individual" name="one_time_individual">
                    <label for="one_time_individual"><span>One-time Access</span></label>
                </div>
                <label>Participants</label>
                <div id="participants-container">
                    <div class="participant-row">
                        <input type="text" name="participants[0][name]" placeholder="Nama Peserta">
                        <input type="text" name="participants[0][code]" placeholder="Kode Unik">
                        <input type="url" name="participants[0][target_url]" placeholder="URL Tujuan">
                    </div>
                </div>
                <button type="button" id="addParticipantBtn" class="btn">+ Tambah Peserta</button>
            </div>
            <button type="submit" class="btn"><i class="fa-solid fa-scissors"></i> Create Short URL</button>
        </form>
    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modeSelect = document.getElementById('access_mode');
    const publicSection = document.getElementById('public-section');
    const individualSection = document.getElementById('individual-section');
    const originalInput = document.querySelector('input[name="original_url"]');

    // Format datetime untuk atribut min
    function formatDateTime(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        const h = String(date.getHours()).padStart(2, '0');
        const i = String(date.getMinutes()).padStart(2, '0');
        return `${y}-${m}-${d}T${h}:${i}`;
    }
    // Set min semua input datetime-local
    document.querySelectorAll('input[type="datetime-local"]').forEach(input => {
        input.min = formatDateTime(new Date());
    });

    // Atur tampilan awal (saat halaman pertama kali dimuat)
    
    function updateMode() {
    if (modeSelect.value === 'per_code') {
        publicSection.style.display = 'none';
        individualSection.style.display = 'block';
        originalInput.removeAttribute('required');

        // Disable input public
        publicSection.querySelectorAll('input, select, textarea').forEach(el => {
            el.readonly = true;
        });
        // Enable input individual, terutama participants
        individualSection.querySelectorAll('input, select, textarea').forEach(el => {
            el.readonly = false; // Jangan readonly, pakai disabled=false
        });
    } else {
        publicSection.style.display = 'block';
        individualSection.style.display = 'none';
        originalInput.setAttribute('required', true);

        // Disable input individual
        individualSection.querySelectorAll('input, select, textarea').forEach(el => {
            el.readonly = true;
        });
        // Enable input public
        publicSection.querySelectorAll('input, select, textarea').forEach(el => {
            el.readonly = false;
        });
    }
}

    updateMode(); // Jalankan saat load pertama
    modeSelect.addEventListener('change', updateMode);
    // Tombol tambah peserta
        const addBtn = document.getElementById('addParticipantBtn');
        if (addBtn) {
            addBtn.addEventListener('click', () => {
        const container = document.getElementById('participants-container');
        const idx = container.children.length;
        const row = document.createElement('div');
        row.className = 'participant-row';
        row.innerHTML = `
            <input type="text" name="participants[${idx}][name]" placeholder="Nama Peserta">
            <input type="text" name="participants[${idx}][code]" placeholder="Kode Unik">
            <input type="url" name="participants[${idx}][target_url]" placeholder="URL Tujuan">
        `;
        container.appendChild(row);

        // pastikan semua input baru tidak disabled
        row.querySelectorAll('input').forEach(el => el.disabled = false);
    });
    }

    // Tombol copy link
    window.copyUrl = function() {
        const urlText = document.getElementById('createdUrl').textContent.trim();
        navigator.clipboard.writeText(urlText).then(() => {
            const btn = document.querySelector('.copy-btn');
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
            btn.style.background = '#3ccf5a';
            setTimeout(() => {
                btn.innerHTML = original;
                btn.style.background = '#28a745';
            }, 2000);
        });
    };
});
</script>

</body>
</html>

