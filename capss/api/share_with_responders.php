<?php
// api/share_with_responders.php - Share report with all responders
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = isset($_POST['incident_id']) ? intval($_POST['incident_id']) : 0;
$permission = isset($_POST['permission']) ? $_POST['permission'] : 'view';
$expires_in_days = isset($_POST['expires_in_days']) ? intval($_POST['expires_in_days']) : 30;

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid incident ID']);
    exit;
}

// Get all other responders
$responders_stmt = $conn->prepare("SELECT user_id FROM tbl_responders WHERE user_id != ?");
$responders_stmt->bind_param("i", $_SESSION['user_id']);
$responders_stmt->execute();
$responders_result = $responders_stmt->get_result();

$expires_at = date('Y-m-d H:i:s', strtotime("+$expires_in_days days"));
$shared_count = 0;

while ($responder = $responders_result->fetch_assoc()) {
    // Check if already shared
    $check = $conn->prepare("SELECT access_id FROM tbl_report_access WHERE incident_id = ? AND responder_id = ?");
    $check->bind_param("ii", $incident_id, $responder['user_id']);
    $check->execute();
    $check_result = $check->get_result();
    
    if ($check_result->num_rows == 0) {
        $insert = $conn->prepare("INSERT INTO tbl_report_access (incident_id, responder_id, permission, granted_by, expires_at) VALUES (?, ?, ?, ?, ?)");
        $insert->bind_param("iisss", $incident_id, $responder['user_id'], $permission, $_SESSION['user_id'], $expires_at);
        if ($insert->execute()) {
            $shared_count++;
        }
    }
}

// Log the share action
$log_stmt = $conn->prepare("INSERT INTO tbl_report_audit (incident_id, responder_id, action_type, action_details) VALUES (?, ?, 'share', ?)");
$share_details = "Shared with all responders with $permission permission (expires in $expires_in_days days)";
$log_stmt->bind_param("iis", $incident_id, $_SESSION['user_id'], $share_details);
$log_stmt->execute();

echo json_encode([
    'success' => true,
    'message' => "Report shared with $shared_count responder(s)",
    'shared_count' => $shared_count,
    'expires_at' => $expires_at
]);
?>