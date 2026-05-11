<?php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

$grant_id = intval($_POST['grant_id'] ?? 0);

if (!$grant_id) {
    echo json_encode(['success' => false, 'message' => 'Grant ID required']);
    exit;
}

// Get incident_id for logging
$get_incident = $conn->prepare("SELECT incident_id FROM tbl_report_access_grants WHERE grant_id = ?");
$get_incident->bind_param("i", $grant_id);
$get_incident->execute();
$incident_result = $get_incident->get_result();
$incident_data = $incident_result->fetch_assoc();
$incident_id = isset($incident_data['incident_id']) ? $incident_data['incident_id'] : 0;

$stmt = $conn->prepare("UPDATE tbl_report_access_grants SET is_active = 0 WHERE grant_id = ?");
$stmt->bind_param("i", $grant_id);

if ($stmt->execute()) {
    // Log the action
    if ($incident_id) {
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
        
        $log_stmt = $conn->prepare("INSERT INTO tbl_report_access_log (incident_id, responder_id, action_type, ip_address, user_agent) VALUES (?, ?, 'revoke_access', ?, ?)");
        $log_stmt->bind_param("iisss", $incident_id, $_SESSION['user_id'], $ip_address, $user_agent);
        $log_stmt->execute();
    }
    
    echo json_encode(['success' => true, 'message' => 'Access revoked successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>