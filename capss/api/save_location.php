<?php
// api/save_location.php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if (isset($_POST['lat']) && isset($_POST['lng'])) {
    $lat = floatval($_POST['lat']);
    $lng = floatval($_POST['lng']);
    $user_id = $_SESSION['user_id'];
    
    // Check if table exists
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_responder_locations (
        location_id INT AUTO_INCREMENT PRIMARY KEY,
        responder_id INT NOT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        accuracy INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_responder (responder_id),
        INDEX idx_responder (responder_id)
    )");
    
    // Insert or update location
    $stmt = $conn->prepare("INSERT INTO tbl_responder_locations (responder_id, latitude, longitude) VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE latitude = ?, longitude = ?, updated_at = NOW()");
    $stmt->bind_param("idddd", $user_id, $lat, $lng, $lat, $lng);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Location saved']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No coordinates provided']);
}
?>