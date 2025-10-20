<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$url_data = null;

$url_id = $_GET['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $url_id) {
    $conn = getDBConnection();
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $url_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        header('Location: dashboard.php');
        exit;
    }
    
    $url_data = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url_id = $_POST['id'] ?? 0;
    $original_url = trim($_POST['original_url'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $user_id = $_SESSION['user_id'];
    
    if (empty($original_url)) {
        $error = 'Please enter a URL';
    } elseif (!filter_var($original_url, FILTER_VALIDATE_URL)) {
        $error = 'Please enter a valid URL';
    } else {
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE urls SET original_url = ?, title = ?, status = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param("sssii", $original_url, $title, $status, $url_id, $user_id);
        
        if ($stmt->execute()) {
            header('Location: dashboard.php?success=updated');
            exit;
        } else {
            $error = 'Error updating URL: ' . $conn->error;
        }
        
        $stmt->close();
        $conn->close();
    }
    
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM urls WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $url_id, $user_id);
    $stmt->execute();
    $url_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
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
</style>
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-edit"></i> Edit Short URL</h1>
        <a href="dashboard.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <div class="form-card">
        <h2><i class="fas fa-link"></i> Edit URL Details</h2>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($url_data): ?>
            <div class="form-group">
                <label>Short Code</label>
                <div class="info">
                    <?= BASE_URL . htmlspecialchars($url_data['short_code']) ?> (cannot be changed)
                </div>
            </div>
            
            <form method="POST" action="">
                <input type="hidden" name="id" value="<?= $url_data['id'] ?>">
                
                <div class="form-group">
                    <label for="original_url">Original URL *</label>
                    <input type="url" id="original_url" name="original_url" 
                           value="<?= htmlspecialchars($url_data['original_url']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" 
                           value="<?= htmlspecialchars($url_data['title'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="active" <?= $url_data['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $url_data['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                
                <button type="submit" class="btn"><i class="fas fa-save"></i> Update URL</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
