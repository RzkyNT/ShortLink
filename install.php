<?php
require_once 'config/database.php';

echo "<h2>URL Shortener - Installation</h2>";

$conn = getDBConnection();

// Create URLs table
$sql_urls = "CREATE TABLE IF NOT EXISTS urls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_code VARCHAR(10) UNIQUE NOT NULL,
    original_url TEXT NOT NULL,
    title VARCHAR(255),
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active',
    INDEX idx_short_code (short_code),
    INDEX idx_status (status),
    INDEX idx_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Create clicks/analytics table
$sql_clicks = "CREATE TABLE IF NOT EXISTS url_clicks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    url_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    referer TEXT,
    country VARCHAR(100),
    city VARCHAR(100),
    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (url_id) REFERENCES urls(id) ON DELETE CASCADE,
    INDEX idx_url_id (url_id),
    INDEX idx_clicked_at (clicked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Create admin users table
$sql_users = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

// Execute table creation in correct order
if ($conn->query($sql_users) === TRUE) {
    echo "<p>✓ Table 'users' created successfully</p>";
} else {
    echo "<p>✗ Error creating users table: " . $conn->error . "</p>";
}

if ($conn->query($sql_urls) === TRUE) {
    echo "<p>✓ Table 'urls' created successfully</p>";
} else {
    echo "<p>✗ Error creating urls table: " . $conn->error . "</p>";
}

if ($conn->query($sql_clicks) === TRUE) {
    echo "<p>✓ Table 'url_clicks' created successfully</p>";
} else {
    echo "<p>✗ Error creating url_clicks table: " . $conn->error . "</p>";
}

// Insert default admin user (username: admin, password: admin123)
$default_password = password_hash('admin123', PASSWORD_BCRYPT);
$check_user = $conn->query("SELECT id FROM users WHERE username = 'admin'");

if ($check_user->num_rows == 0) {
    $insert_admin = "INSERT INTO users (username, password, email) VALUES ('admin', '$default_password', 'admin@example.com')";
    if ($conn->query($insert_admin) === TRUE) {
        echo "<p>✓ Default admin user created (username: admin, password: admin123)</p>";
        echo "<p><strong>IMPORTANT: Please change the default password after login!</strong></p>";
    } else {
        echo "<p>✗ Error creating admin user: " . $conn->error . "</p>";
    }
} else {
    echo "<p>ℹ Admin user already exists</p>";
}

$conn->close();

echo "<hr>";
echo "<p><a href='login.php'>Go to Login Page</a></p>";
?>