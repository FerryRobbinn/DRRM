<?php
// api/get_report_audit.php - Get audit history for a report
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

// Get audit logs
$stmt = $conn->prepare("
    SELECT a.*, r.fullname as responder_name, r.username
    FROM tbl_report_audit a
    JOIN tbl_responders r ON a.responder_id = r.user_id
    WHERE a.incident_id = ?
    ORDER BY a.created_at DESC
    LIMIT 100
");
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();

$audit_logs = [];
while ($row = $result->fetch_assoc()) {
    $audit_logs[] = $row;
}

// Get current access list
$access_stmt = $conn->prepare("
    SELECT a.*, r.fullname as responder_name, g.fullname as granted_by_name
    FROM tbl_report_access a
    JOIN tbl_responders r ON a.responder_id = r.user_id
    JOIN tbl_responders g ON a.granted_by = g.user_id
    WHERE a.incident_id = ? AND a.is_active = 1
");
$access_stmt->bind_param("i", $incident_id);
$access_stmt->execute();
$access_result = $access_stmt->get_result();

$access_list = [];
while ($row = $access_result->fetch_assoc()) {
    $access_list[] = $row;
}

echo json_encode([
    'success' => true,
    'audit_logs' => $audit_logs,
    'access_list' => $access_list
]);
?>