<?php
// api/get_notifications.php - Get unread notifications for responder
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'notifications' => []]);
    exit;
}

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT n.*, i.tracking_id 
                        FROM tbl_notifications n 
                        LEFT JOIN tbl_incidents i ON n.incident_id = i.incident_id 
                        WHERE n.user_id = ? AND n.is_read = 0 
                        ORDER BY n.created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

// Mark as read after fetching
$update = $conn->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE user_id = ?");
$update->bind_param("i", $user_id);
$update->execute();

echo json_encode(['success' => true, 'notifications' => $notifications]);
?>