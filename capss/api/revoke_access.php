<?php
// api/revoke_access.php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$access_id = isset($_POST['access_id']) ? intval($_POST['access_id']) : 0;

if (!$access_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid access ID']);
    exit;
}

$stmt = $conn->prepare("UPDATE tbl_report_access SET is_active = 0 WHERE access_id = ?");
$stmt->bind_param("i", $access_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>