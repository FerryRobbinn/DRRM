<?php
// api/get_responder_incidents.php - Get incidents for responder dashboard
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$responder_id = $_SESSION['user_id'];

// Get pending incidents
$pending = $conn->query("
    SELECT incident_id, tracking_id, incident_type, severity, location_lat, location_lng, location_address, description, reporter_name, reporter_phone, created_at, status
    FROM tbl_incidents 
    WHERE status = 'pending'
    ORDER BY FIELD(severity, 'Dead', 'Immediate', 'Delayed', 'Minor'), created_at ASC
");

// Get active incidents (taken by this responder, not completed)
$active = $conn->query("
    SELECT incident_id, tracking_id, incident_type, severity, location_lat, location_lng, location_address, description, reporter_name, reporter_phone, created_at, status, taken_at
    FROM tbl_incidents 
    WHERE taken_by_responder_id = $responder_id AND status = 'dispatched'
    ORDER BY taken_at DESC
");

// Get shared incidents (granted to this responder by others)
$shared = $conn->query("
    SELECT i.incident_id, i.tracking_id, i.incident_type, i.severity, i.location_lat, i.location_lng, 
           i.location_address, i.description, i.reporter_name, i.reporter_phone, i.created_at, i.status,
           g.access_level, g.granted_by_responder_id, u.fullname as shared_by_name,
           (SELECT COUNT(*) FROM tbl_incident_drafts WHERE incident_id = i.incident_id) as has_draft
    FROM tbl_report_access_grants g
    JOIN tbl_incidents i ON g.incident_id = i.incident_id
    JOIN tbl_users u ON g.granted_by_responder_id = u.user_id
    WHERE g.granted_to_responder_id = $responder_id 
    AND g.is_active = 1
    AND i.status = 'dispatched'
    AND i.taken_by_responder_id != $responder_id
    ORDER BY g.granted_at DESC
");

// Get completed incidents
$completed = $conn->query("
    SELECT incident_id, tracking_id, incident_type, severity, location_lat, location_lng, location_address, description, reporter_name, reporter_phone, created_at, status, finished_at
    FROM tbl_incidents 
    WHERE finished_by_responder_id = $responder_id AND status = 'completed'
    ORDER BY finished_at DESC LIMIT 20
");

$response = [
    'pending' => [],
    'active' => [],
    'shared' => [],
    'completed' => []
];

while ($row = $pending->fetch_assoc()) {
    $response['pending'][] = $row;
}
while ($row = $active->fetch_assoc()) {
    $response['active'][] = $row;
}
while ($row = $shared->fetch_assoc()) {
    $response['shared'][] = $row;
}
while ($row = $completed->fetch_assoc()) {
    $response['completed'][] = $row;
}

echo json_encode($response);
?>