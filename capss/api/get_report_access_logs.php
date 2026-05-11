<?php
// api/get_report_access_logs.php - Get access logs and grants for a report
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

// Check if user is logged in and is a responder
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = isset($_GET['incident_id']) ? intval($_GET['incident_id']) : 0;

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Incident ID required']);
    exit;
}

$responder_id = $_SESSION['user_id'];

// First, verify that this responder has access to this incident
// (either they created it, took it, or it was shared with them)
$access_check = $conn->prepare("
    SELECT incident_id FROM tbl_incidents 
    WHERE incident_id = ? AND (taken_by_responder_id = ? OR finished_by_responder_id = ?)
    UNION
    SELECT incident_id FROM tbl_report_access_grants 
    WHERE incident_id = ? AND granted_to_responder_id = ? AND is_active = 1
");
$access_check->bind_param("iiiii", $incident_id, $responder_id, $responder_id, $incident_id, $responder_id);
$access_check->execute();
$access_result = $access_check->get_result();

if ($access_result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'You do not have access to this report']);
    exit;
}

// Get access logs with responder names
$logs_stmt = $conn->prepare("
    SELECT l.*, 
           u.fullname as responder_name,
           DATE_FORMAT(l.created_at, '%M %d, %Y at %h:%i %p') as formatted_time
    FROM tbl_report_access_log l
    LEFT JOIN tbl_users u ON l.responder_id = u.user_id
    WHERE l.incident_id = ?
    ORDER BY l.created_at DESC
    LIMIT 200
");
$logs_stmt->bind_param("i", $incident_id);
$logs_stmt->execute();
$logs_result = $logs_stmt->get_result();

$logs = [];
while ($row = $logs_result->fetch_assoc()) {
    // If responder_name is empty, try to get from session or set default
    if (empty($row['responder_name'])) {
        $row['responder_name'] = $row['responder_name'] ?? ('Responder ID: ' . $row['responder_id']);
    }
    // Format the time if not already formatted
    if (!isset($row['formatted_time']) || empty($row['formatted_time'])) {
        $row['formatted_time'] = date('M d, Y H:i:s', strtotime($row['created_at']));
    }
    $logs[] = $row;
}

// Get access grants with names
$grants_stmt = $conn->prepare("
    SELECT g.*, 
           u1.fullname as granted_to_name,
           u2.fullname as granted_by_name,
           DATE_FORMAT(g.granted_at, '%M %d, %Y at %h:%i %p') as formatted_granted_at
    FROM tbl_report_access_grants g
    LEFT JOIN tbl_users u1 ON g.granted_to_responder_id = u1.user_id
    LEFT JOIN tbl_users u2 ON g.granted_by_responder_id = u2.user_id
    WHERE g.incident_id = ?
    ORDER BY g.granted_at DESC
");
$grants_stmt->bind_param("i", $incident_id);
$grants_stmt->execute();
$grants_result = $grants_stmt->get_result();

$grants = [];
while ($row = $grants_result->fetch_assoc()) {
    $grants[] = [
        'grant_id' => $row['grant_id'],
        'granted_to_responder_id' => $row['granted_to_responder_id'],
        'granted_to_name' => $row['granted_to_name'] ?? 'Unknown User',
        'granted_by_responder_id' => $row['granted_by_responder_id'],
        'granted_by_name' => $row['granted_by_name'] ?? 'Unknown',
        'access_level' => $row['access_level'],
        'granted_at' => $row['granted_at'],
        'formatted_granted_at' => $row['formatted_granted_at'] ?? date('M d, Y H:i:s', strtotime($row['granted_at'])),
        'expires_at' => $row['expires_at'],
        'is_active' => $row['is_active']
    ];
}

echo json_encode([
    'success' => true,
    'logs' => $logs,
    'grants' => $grants
]);

$logs_stmt->close();
$grants_stmt->close();
$conn->close();
?>