<?php
// api/get_responder_locations.php - Get all responder locations for map
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Check if table exists
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_responder_locations'");
if ($table_check->num_rows == 0) {
    echo json_encode(['success' => true, 'responders' => []]);
    exit;
}

// Get all responder locations updated in the last 30 minutes
$query = "
    SELECT u.user_id, u.fullname, u.username, rl.latitude, rl.longitude, rl.updated_at
    FROM tbl_responder_locations rl
    JOIN tbl_users u ON rl.responder_id = u.user_id
    WHERE u.role = 'responder' 
    AND rl.updated_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ORDER BY rl.updated_at DESC
";

$result = $conn->query($query);
$responders = [];

while ($row = $result->fetch_assoc()) {
    $responders[] = [
        'user_id' => $row['user_id'],
        'fullname' => $row['fullname'],
        'username' => $row['username'],
        'latitude' => floatval($row['latitude']),
        'longitude' => floatval($row['longitude']),
        'updated_at' => $row['updated_at']
    ];
}

echo json_encode(['success' => true, 'responders' => $responders]);
?>