<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$url_data = null;
$participants = [];
$url_id = $_GET['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $url_id) {
    $conn = getDBConnection();
    $user_id = $_SESSION['user_id'];

    // Ambil data URL
    $stmt = $conn->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $url_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        header('Location: dashboard.php');
        exit;
    }

    $url_data = $result->fetch_assoc();

    // Jika mode per_code, ambil daftar peserta
    if ($url_data['access_mode'] === 'per_code') {
        $pstmt = $conn->prepare("SELECT * FROM url_codes WHERE url_id = ?");
        $pstmt->bind_param("i", $url_id);
        $pstmt->execute();
        $participants = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $pstmt->close();
    }

    $stmt->close();
    $conn->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDBConnection();
    $user_id = $_SESSION['user_id'];

    $url_id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? 'active';
    $expire_at = !empty($_POST['expire_at']) ? date('Y-m-d H:i:s', strtotime($_POST['expire_at'])) : null;
    $one_time = isset($_POST['one_time']) ? 1 : 0;
    $title = trim($_POST['title'] ?? ''); // hanya ambil dari input main title, bukan dari original_url

    // Ambil mode
    $stmt = $conn->prepare("SELECT access_mode FROM urls WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $url_id, $user_id);
    $stmt->execute();
    $mode_result = $stmt->get_result()->fetch_assoc();
    $access_mode = $mode_result['access_mode'] ?? 'public';
    $stmt->close();
    $access_password = trim($_POST['access_password'] ?? '');
    $access_password = $access_password !== '' ? $access_password : null;


    if ($access_mode === 'public') {
    $original_url_input = $_POST['original_url'] ?? '';
    $original_url = '';

    if (is_array($original_url_input)) {
        // Multi link
        $clean_urls = [];

        foreach ($original_url_input as $item) {
            $item_title = trim($item['title'] ?? '');
            $item_url   = trim($item['url'] ?? '');

            if ($item_url === '') continue;
            if (!filter_var($item_url, FILTER_VALIDATE_URL)) {
                $error = 'Please enter a valid URL';
                break;
            }

            $clean_urls[] = [
                'title' => $item_title,
                'url'   => $item_url
            ];
        }


        if (empty($clean_urls)) {
            $error = 'Please enter at least one valid URL';
        } else {
            $original_url = json_encode($clean_urls, JSON_UNESCAPED_SLASHES);
        }
    } else {
        // Single URL
        $original_url = trim($original_url_input);
        if (empty($original_url)) {
            $error = 'Please enter a URL';
        } elseif (!filter_var($original_url, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid URL';
        }
    }

    // ✅ UPDATE selalu dijalankan selama tidak ada error
    if (empty($error)) {
        $stmt = $conn->prepare("
            UPDATE urls 
            SET original_url = ?, title = ?, status = ?, expire_at = ?, one_time = ?, access_password = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ssssissi", $original_url, $title, $status, $expire_at, $one_time, $access_password, $url_id, $user_id);
        $stmt->execute();
        $stmt->close();

        header('Location: dashboard.php?success=updated');
        exit;
    }
    } elseif ($access_mode === 'per_code') {
        // Update data utama
        $stmt = $conn->prepare("
            UPDATE urls 
            SET title = ?, status = ?, expire_at = ?, one_time = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("sssiii", $title, $status, $expire_at, $one_time, $url_id, $user_id);
        $stmt->execute();
        $stmt->close();

        // Update daftar peserta
        if (isset($_POST['participants']) && is_array($_POST['participants'])) {
            foreach ($_POST['participants'] as $p) {
                $pid = intval($p['id'] ?? 0);
                $name = trim($p['name'] ?? '');
                $code = trim($p['code'] ?? '');
                $target_url = trim($p['target_url'] ?? '');

                if (empty($code) || empty($target_url)) continue;

                if ($pid > 0) {
                    // Update existing participant
                    $pstmt = $conn->prepare("UPDATE url_codes SET participant_name=?, code=?, target_url=? WHERE id=? AND url_id=?");
                    $pstmt->bind_param("sssii", $name, $code, $target_url, $pid, $url_id);
                } else {
                    // Insert new participant
                    $pstmt = $conn->prepare("INSERT INTO url_codes (url_id, participant_name, code, target_url) VALUES (?, ?, ?, ?)");
                    $pstmt->bind_param("isss", $url_id, $name, $code, $target_url);
                }
                $pstmt->execute();
                $pstmt->close();
            }
        }
        // Hapus peserta yang ditandai
            $deleted_ids = $_POST['deleted_participants'] ?? '';
            if (!empty($deleted_ids)) {
                $ids = array_filter(array_map('intval', explode(',', $deleted_ids)));
                if (!empty($ids)) {
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $types = str_repeat('i', count($ids));

                    $sql = "DELETE FROM url_codes WHERE id IN ($placeholders) AND url_id = ?";
                    $stmt = $conn->prepare($sql);

                    // Gabungkan semua ID + url_id
                    $params = [...$ids, $url_id];
                    $stmt->bind_param($types . 'i', ...$params);
                    $stmt->execute();
                    $stmt->close();
                }
            }

        header('Location: dashboard.php?success=updated');
        exit;
    }

    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit URL</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css">
    <link rel="icon" type="image/png" href="favicon.png">
    <script src="assets/sweetalert2.js"></script>
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

    .header h1 i {
        color: #667eea;
    }

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
    .back-btn:hover {
        background: rgba(255,255,255,0.25);
    }

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

    .form-group {
        margin-bottom: 20px;
    }

    label {
        display: block;
        font-weight: 600;
        margin-bottom: 6px;
        color: #ccc;
    }

    input[type="text"], input[type="url"], select {
        width: 100%;
        padding: 12px 14px;
        border: 2px solid rgba(255,255,255,0.1);
        background: rgba(0,0,0,0.3);
        color: #fff;
        border-radius: 8px;
        font-size: 14px;
        transition: 0.3s;
    }

    input:focus, select:focus {
        outline: none;
        border-color: #667eea;
    }

    select {
        color: #fff;
        background: rgba(0,0,0,0.3);
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
        cursor: pointer;
    }
    .btn:hover {
        background: #5568d3;
    }

    .error {
        background: rgba(255, 76, 76, 0.15);
        color: #ff6b6b;
        border-left: 4px solid #ff6b6b;
        padding: 14px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .info {
        background: rgba(255,255,255,0.05);
        padding: 12px;
        border-radius: 8px;
        color: #a5b4fc;
        font-family: monospace;
    }

    @media (max-width: 600px) {
        .header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .form-card { padding: 25px; }
    }
input, select {
    width:100%; padding:12px; border-radius:8px;
    border:2px solid rgba(255,255,255,0.1);
    background:rgba(0,0,0,0.3); color:white;
}

/* Menambahkan style fokus yang konsisten untuk select */
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
.delete-btn {
    background: #ff4d4d;
    border: none;
    width: 35px;
    height: 45px;
    color: white;
    border-radius: 8px;
    cursor: pointer;
    transition: 0.3s;
}
.delete-btn:hover {
    background: #e03e3e;
}
.swal2-popup {
  border-radius: 1rem !important;
  font-family: 'Poppins', sans-serif;
}
.swal2-title {
  font-weight: 600;
}

.url-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 10px;
}
.url-row input {
    flex: 1 1 45%;
}
.delete-url-btn {
    background: #ff4d4d;
    border: none;
    color: white;
    width: 40px;
    border-radius: 8px;
    cursor: pointer;
    transition: 0.3s;
}
.delete-url-btn:hover {
    background: #e03e3e;
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
    <h1><i class="fas fa-edit"></i> Edit Short URL</h1>
    <a href="dashboard.php" class="btn"><i class="fas fa-arrow-left"></i> Back</a>
</div>

<div class="form-card">
<?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
        <?php if ($url_data): ?>
            <div class="form-group">
                <label>Short Code</label>
                <div class="info">
                    <?= BASE_URL . htmlspecialchars($url_data['short_code']) ?> (cannot be changed)
                    <br>
                    Mode:<?= htmlspecialchars($url_data['access_mode']) ?>
                </div>
            </div>
            
            <form method="POST" action="">
        <input type="hidden" name="id" value="<?= $url_data['id'] ?>">

        <label>Main Title</label>
        <input type="text" name="title" value="<?= htmlspecialchars($url_data['title']) ?>" placeholder="Main title for this link group">

        <label style="margin-top: 10px;">Status</label>
        <select name="status">
            <option value="active" <?= $url_data['status']==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $url_data['status']==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        
        <label style="margin-top: 10px;">Expiration Date</label>
        <input type="datetime-local" name="expire_at" value="<?= $url_data['expire_at'] ? date('Y-m-d\TH:i', strtotime($url_data['expire_at'])) : '' ?>">
        <div class="checkbox-group" style="margin-top: 20px;">
                <input type="checkbox" id="one_time" name="one_time" <?= $url_data['one_time'] ? 'checked' : '' ?>>
                <label for="one_time">One-time Access</label>
            </div>

        <?php if ($url_data['access_mode'] === 'public'): ?>
            <label>Original URL(s) *</label>
            <div id="url-container">
            <?php
            $urls = json_decode($url_data['original_url'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($urls)) {
                foreach ($urls as $i => $item): ?>
                    <div class="url-row">
                        <input type="text" name="original_url[<?= $i ?>][title]" placeholder="Title" 
                            value="<?= htmlspecialchars($item['title'] ?? '') ?>">
                        <input type="url" name="original_url[<?= $i ?>][url]" placeholder="URL" 
                            value="<?= htmlspecialchars($item['url'] ?? '') ?>" required>
                        <button type="button" class="delete-url-btn"><i class="fas fa-trash"></i></button>
                    </div>
                <?php endforeach;
            } else { ?>
                <div class="url-row">
                    <input type="text" name="original_url[0][title]" placeholder="Title (optional)">
                    <input type="url" name="original_url[0][url]" value="<?= htmlspecialchars($url_data['original_url']) ?>" required>
                    <button type="button" class="delete-url-btn"><i class="fas fa-trash"></i></button>
                </div>
            <?php } ?>
            </div>
            <button type="button" id="addUrlBtn" class="btn" style="margin-top:10px;">+ Add Link</button>
            <label>Password</label>
            <input type="text" name="access_password" placeholder="Optional" value="<?= $url_data['access_password']?? '' ?>">
        
        <?php else: ?>
            <h3>Participants</h3>
            <div id="participants-container">
                <?php foreach ($participants as $i => $p): ?>
                <div class="participant-row">
                    <input type="hidden" name="participants[<?= $i ?>][id]" value="<?= $p['id'] ?>">
                    <input type="text" name="participants[<?= $i ?>][name]" value="<?= htmlspecialchars($p['participant_name']) ?>" placeholder="Nama">
                    <input type="text" name="participants[<?= $i ?>][code]" value="<?= htmlspecialchars($p['code']) ?>" placeholder="Kode Unik">
                    <input type="url" name="participants[<?= $i ?>][target_url]" value="<?= htmlspecialchars($p['target_url']) ?>" placeholder="URL Tujuan">
                    <button type="button" class="delete-btn" data-id="<?= $p['id'] ?>"><i class="fas fa-trash"></i></button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="addParticipantBtn" class="btn" type="button">+ Add Participant</button>
        <?php endif; ?>
        <input type="hidden" id="deleted_participants" name="deleted_participants" value="">
        <button style="margin-top: 10px;" type="submit" class="btn"><i class="fas fa-save"></i> Update</button>
    </form>
<?php endif; ?>
</div>

<script>
// ====== MULTI URL HANDLER ======
document.getElementById('addUrlBtn')?.addEventListener('click', () => {
    const container = document.getElementById('url-container');
    const idx = container.children.length;
    const row = document.createElement('div');
    row.className = 'url-row';
    row.innerHTML = `
        <input type="text" name="original_url[${idx}][title]" placeholder="Title (optional)">
        <input type="url" name="original_url[${idx}][url]" placeholder="URL" required>
        <button type="button" class="delete-url-btn"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(row);
});

// Hapus URL row
document.addEventListener('click', function(e) {
    if (e.target.closest('.delete-url-btn')) {
        const row = e.target.closest('.url-row');
        if (document.querySelectorAll('.url-row').length > 1) {
            row.remove();
        } else {
            Swal.fire({
                icon: 'info',
                text: 'Minimal satu URL harus ada.',
                timer: 1500,
                showConfirmButton: false
            });
        }
    }
});

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

    document.getElementById('addParticipantBtn')?.addEventListener('click', () => {
    const container = document.getElementById('participants-container');
    const idx = container.children.length;
    const row = document.createElement('div');
    row.className = 'participant-row';
    
    // Assign a temporary unique class to easily target this row for deletion
    row.id = `participant-row-${idx}`; 
    
    row.innerHTML = `
        <input type="hidden" name="participants[${idx}][id]" value="0">
        <input type="text" name="participants[${idx}][name]" placeholder="Nama">
        <input type="text" name="participants[${idx}][code]" placeholder="Kode Unik" required>
        <input type="url" name="participants[${idx}][target_url]" placeholder="URL Tujuan" required>
        
        <button type="button" class="delete-btn new-row" onclick="removeParticipantRow('participant-row-${idx}')" title="Hapus baris ini">
            <i class="fas fa-trash"></i>
        </button>
    `;
    container.appendChild(row);
});

// Tambahkan fungsi global untuk menghapus baris
function removeParticipantRow(rowId) {
    const row = document.getElementById(rowId);
    if (row) {
        row.remove();
    }
}
        // SweetAlert Delete Confirmation
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('delete-btn')) {
        const btn = e.target;
        const row = btn.closest('.participant-row');
        const pid = btn.getAttribute('data-id');

        Swal.fire({
            title: 'Hapus Peserta?',
            text: 'Data peserta ini akan dihapus permanen.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, hapus!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                // Catat ID yang dihapus
                const deletedInput = document.getElementById('deleted_participants');
                if (pid && pid !== "0") {
                    let ids = deletedInput.value ? deletedInput.value.split(',') : [];
                    ids.push(pid);
                    deletedInput.value = ids.join(',');
                }
                row.remove();

                Swal.fire({
                    icon: 'success',
                    title: 'Peserta dihapus!',
                    text: 'Data peserta berhasil dihapus dari form.',
                    timer: 1200,
                    showConfirmButton: false
                });
            }
        });
    }
});
document.querySelector('form').addEventListener('submit', () => {
  Swal.fire({
    title: 'Menyimpan...',
    text: 'Tunggu sebentar, data sedang diperbarui.',
    allowOutsideClick: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });
});

</script>
<?php if (isset($_GET['success'])): ?>
<script>
Swal.fire({
    icon: 'success',
    title: 'Berhasil!',
    text: 'Data URL dan peserta berhasil diperbarui 🎉',
    timer: 2000,
    showConfirmButton: false
});
</script>
<?php elseif (isset($_GET['error'])): ?>
<script>
Swal.fire({
    icon: 'error',
    title: 'Gagal!',
    text: 'Terjadi kesalahan saat memperbarui data 😢',
});
</script>
<?php endif; ?>

</body>
</html>

