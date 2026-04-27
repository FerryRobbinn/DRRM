<?php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get all responders (excluding current user)
// Check if is_active column exists, if not, don't use it
$columns = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'is_active'");
$has_is_active = $columns->num_rows > 0;

if ($has_is_active) {
    $query = $conn->prepare("SELECT user_id, fullname, username, email FROM tbl_users WHERE role = 'responder' AND is_active = 1 AND user_id != ? ORDER BY fullname");
} else {
    $query = $conn->prepare("SELECT user_id, fullname, username, email FROM tbl_users WHERE role = 'responder' AND user_id != ? ORDER BY fullname");
}
$query->bind_param("i", $_SESSION['user_id']);
$query->execute();
$result = $query->get_result();

$responders = [];
while ($row = $result->fetch_assoc()) {
    $responders[] = [
        'user_id' => $row['user_id'],
        'fullname' => isset($row['fullname']) && !empty($row['fullname']) ? $row['fullname'] : $row['username'],
        'username' => $row['username'],
        'email' => isset($row['email']) ? $row['email'] : ''
    ];
}

echo json_encode(['success' => true, 'responders' => $responders]);
?>