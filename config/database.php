<?php
// Deteksi base URL saat ini
$current_url = $_SERVER['HTTP_HOST'] ?? '';
$is_local = str_contains($current_url, 'localhost') || str_contains($current_url, '127.0.0.1');

// Konfigurasi berdasarkan lokasi
if ($is_local) {
    // ====== LOCAL DEVELOPMENT ======
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'url_shortener');
    define('DB_PORT', '3306');
    define('BASE_URL', 'http://localhost/url_shortener/');
} else {
    // ====== ONLINE / HOSTING ======
    define('DB_HOST', 'sql110.infinityfree.com');
    define('DB_USER', 'if0_40199145');
    define('DB_PASS', '12rizqi3');
    define('DB_NAME', 'if0_40199145_url_shortner');
    define('DB_PORT', '3306');
    define('BASE_URL', 'http://am.ct.ws/');
}

// Fungsi koneksi database
function getDBConnection() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (Exception $e) {
        die("Database connection error: " . $e->getMessage());
    }
}
?>
