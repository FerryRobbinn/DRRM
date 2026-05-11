<?php
// api/add_comment.php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = isset($_POST['incident_id']) ? intval($_POST['incident_id']) : 0;
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

if (!$incident_id || !$comment) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO tbl_report_comments (incident_id, responder_id, comment) VALUES (?, ?, ?)");
$stmt->bind_param("iis", $incident_id, $_SESSION['user_id'], $comment);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'comment_id' => $conn->insert_id]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>