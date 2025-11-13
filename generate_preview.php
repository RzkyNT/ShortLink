<?php
// generate_preview.php

require_once 'config/database.php';

// Basic validation
if (!isset($_GET['url_id']) || !isset($_GET['index'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$url_id = intval($_GET['url_id']);
$link_index = intval($_GET['index']);

$conn = getDBConnection();

// Fetch the main URL record
$stmt = $conn->prepare("SELECT id, original_url FROM urls WHERE id = ?");
$stmt->bind_param("i", $url_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'URL record not found']);
    exit;
}

$url_data = $result->fetch_assoc();
$stmt->close();

$urls_array = json_decode($url_data['original_url'], true);

// Check if index is valid and target URL exists
if (!is_array($urls_array) || !isset($urls_array[$link_index]) || empty($urls_array[$link_index]['url'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid link index or URL']);
    exit;
}

// Check if preview already exists (race condition check)
if (!empty($urls_array[$link_index]['preview_base64'])) {
    header('Content-Type: application/json');
    echo json_encode(['preview_base64' => $urls_array[$link_index]['preview_base64']]);
    exit;
}

$target_url = $urls_array[$link_index]['url'];

// --- ScreenshotOne API Call ---
$query = [
    'access_key' => 'duAaHYw2b-sumg', 'url' => $target_url, 'viewport_device' => 'galaxy_s5_landscape',
    'format' => 'jpg', 'block_ads' => 'true', 'block_cookie_banners' => 'true',
    'response_type' => 'json', 'image_quality' => '70',
];
$full_url = 'https://api.screenshotone.com/take?' . http_build_query($query);

$ch = curl_init($full_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 25);
$api_response = curl_exec($ch);
curl_close($ch);

$api_data = json_decode($api_response, true);

if (empty($api_data['screenshot_url'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to get screenshot URL from API']);
    exit;
}

// --- Download image ---
$ch_img = curl_init($api_data['screenshot_url']);
curl_setopt($ch_img, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_img, CURLOPT_TIMEOUT, 25);
$image_data = curl_exec($ch_img);
curl_close($ch_img);

if ($image_data === false || empty($image_data)) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to download screenshot image']);
    exit;
}

$preview_base64 = 'data:image/jpeg;base64,' . base64_encode($image_data);

// --- Update DB and return response ---
$urls_array[$link_index]['preview_base64'] = $preview_base64;
$updated_json = json_encode($urls_array);

$update_stmt = $conn->prepare("UPDATE urls SET original_url = ? WHERE id = ?");
$update_stmt->bind_param("si", $updated_json, $url_id);
$update_stmt->execute();
$update_stmt->close();
$conn->close();

header('Content-Type: application/json');
echo json_encode(['preview_base64' => $preview_base64]);
?>
