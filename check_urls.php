<?php
require_once 'config/database.php';

echo "<pre>";
echo "Memulai pengecekan integritas data URL...\n\n";

$conn = getDBConnection();

$result = $conn->query("SELECT id, short_code, original_url FROM urls");

if (!$result) {
    die("Gagal melakukan query ke database: " . $conn->error);
}

$corrupted_count = 0;
$total_count = $result->num_rows;

while ($row = $result->fetch_assoc()) {
    $original_url = $row['original_url'];

    // Lewati jika kosong atau merupakan URL tunggal yang valid.
    if (empty($original_url) || filter_var($original_url, FILTER_VALIDATE_URL)) {
        continue;
    }

    // Coba decode JSON
    json_decode($original_url);

    // Periksa error JSON, tapi hanya jika data tersebut terlihat seperti JSON (diawali dengan [ atau { )
    if (json_last_error() !== JSON_ERROR_NONE && (strpos(trim($original_url), '[') === 0)) {
        echo "Ditemukan entri multi-link yang rusak:\n";
        echo "  ID: " . $row['id'] . "\n";
        echo "  Short Code: " . $row['short_code'] . "\n";
        echo "  Tindakan: Silakan edit link ini melalui aplikasi untuk memperbaikinya.\n\n";
        $corrupted_count++;
    }
}

echo "----------------------------------------\n";
echo "Pengecekan selesai.\n";
echo "Total entri dipindai: " . $total_count . "\n";
echo "Entri rusak ditemukan: " . $corrupted_count . "\n";
echo "</pre>";

$conn->close();
?>
