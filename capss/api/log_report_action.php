<?php
// api/log_report_action.php - Log all report actions
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = isset($_POST['incident_id']) ? intval($_POST['incident_id']) : 0;
$action_type = isset($_POST['action_type']) ? $_POST['action_type'] : 'view';
$action_details = isset($_POST['action_details']) ? $_POST['action_details'] : '';

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid incident ID']);
    exit;
}

// Get IP address
$ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;

// Get user agent
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$stmt = $conn->prepare("INSERT INTO tbl_report_audit (incident_id, responder_id, action_type, action_details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iissss", $incident_id, $_SESSION['user_id'], $action_type, $action_details, $ip_address, $user_agent);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>