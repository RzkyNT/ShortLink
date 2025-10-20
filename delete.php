<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$url_id = $_GET['id'] ?? 0;
$user_id = $_SESSION['user_id'];

if ($url_id) {
    $conn = getDBConnection();
    
    // Delete URL only if it belongs to the user
    $stmt = $conn->prepare("DELETE FROM urls WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $url_id, $user_id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

header('Location: dashboard.php?success=deleted');
exit;
?>
