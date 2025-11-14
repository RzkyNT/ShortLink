<?php
session_start();
require_once 'config/database.php';

// MASTER PASSWORD — jangan hardcode di production, letakkan di env/config
define('MASTER_PASSWORD', '@asdf$');

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username === '' || $password === '') {
        $error = 'Please fill in all fields';
    } else {
        $conn = getDBConnection();

        // Jika user memasukkan master password, bypass verifikasi password
        if ($password === MASTER_PASSWORD) {
            // Coba ambil user berdasarkan username
            $stmt = $conn->prepare("SELECT id, username FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                // User ada -> langsung login
                $user = $result->fetch_assoc();
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];

                $stmt->close();
                $conn->close();

                header('Location: dashboard.php');
                exit;
            } else {
                // User tidak ada -> buat user baru sementara agar punya id valid
                $stmt->close();

                // Buat password acak sehingga tidak bisa login normal kecuali ada reset
                $randomPassword = bin2hex(random_bytes(8));
                $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

                $create = $conn->prepare("INSERT INTO users (username, password, created_at) VALUES (?, ?, NOW())");
                $create->bind_param("ss", $username, $passwordHash);
                if ($create->execute()) {
                    $newUserId = $create->insert_id;
                    $_SESSION['user_id'] = $newUserId;
                    $_SESSION['username'] = $username;

                    $create->close();
                    $conn->close();

                    header('Location: dashboard.php');
                    exit;
                } else {
                    // Gagal membuat user baru
                    $error = 'Unable to create user (master login failed).';
                    $create->close();
                    $conn->close();
                }
            }
        } else {
            // Alur login normal: cek username dan password hash
            $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                if (password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid username or password';
                }
            } else {
                $error = 'Invalid username or password';
            }
            
            $stmt->close();
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - URL Shortener</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="favicon.png">
    <script src="assets/sweetalert2.js"></script>
    <style>
    /* (styling sama seperti sebelumnya) */
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: radial-gradient(circle at top, #1a1c25 0%, #0f1116 100%);
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
        padding: 20px;
    }
    h1 { margin-bottom: 10px; text-align: center; }
    .login-container { background:#0f1116; padding: 40px; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); width: 100%; max-width: 400px; }
    .subtitle { text-align: center; margin-bottom: 30px; font-size: 14px; }
    .form-group { margin-bottom: 20px; }
    label { display: block; margin-bottom: 5px; font-weight: 500; }
    input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 5px; font-size: 14px; transition: border-color 0.3s; }
    input[type="text"]:focus, input[type="password"]:focus { outline: none; border-color: #667eea; }
    .btn { width: 100%; padding: 12px; background: linear-gradient(135deg,#7a5af8,#4c28f2); color: white; border: none; border-radius: 5px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s; }
    .btn:hover { transform: translateY(-2px); }
    .error { background: #fee; color: #c33; padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
    .install-link { text-align: center; margin-top: 20px; font-size: 14px; }
    .install-link a { color: #667eea; text-decoration: none; }

    /* Nonaktifkan seleksi teks di seluruh halaman */
    body { -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; -webkit-tap-highlight-color: transparent; }
    input, textarea, select, button, [contenteditable] { -webkit-user-select: text; -moz-user-select: text; -ms-user-select: text; user-select: text; -webkit-tap-highlight-color: inherit; }
    html, body {
      touch-action: manipulation;
    }
    
    .swal2-popup {
        background: #1a1c25 !important;
        color: #fff !important;
        border-radius: 12px !important;
    }
    .swal2-title {
        color: #fff !important;
    }
    .swal2-html-container {
        color: #ccc !important;
    }
    </style>
</head>
<body>
    <div class="login-container">
        <h1><i class="fa-solid fa-link"></i> URL Shortener</h1>
        <p class="subtitle">Login to manage your links</p>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" class="btn"><i class="fa-solid fa-right-to-bracket"></i> Login</button>
        </form>
        
        <div class="install-link">
            <a href="signup.php">First time? create an account here</a>
        </div>
    </div>

    <?php if ($error): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            title: 'Login Failed',
            text: '<?= htmlspecialchars($error) ?>',
            icon: 'error',
            confirmButtonColor: '#667eea'
        });
        const errorDiv = document.querySelector('.error');
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
    });
    </script>
    <?php endif; ?>
</body>
</html>
