<?php
// api/get_comments.php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = isset($_GET['incident_id']) ? intval($_GET['incident_id']) : 0;

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid incident ID']);
    exit;
}

$stmt = $conn->prepare("
    SELECT c.*, r.fullname as responder_name
    FROM tbl_report_comments c
    JOIN tbl_responders r ON c.responder_id = r.user_id
    WHERE c.incident_id = ?
    ORDER BY c.created_at ASC
");
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();

$comments = [];
while ($row = $result->fetch_assoc()) {
    $comments[] = $row;
}

echo json_encode(['success' => true, 'comments' => $comments]);
?>