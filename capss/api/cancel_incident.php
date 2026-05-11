<?php
// api/cancel_incident.php - Cancel/delete an incident
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = isset($_POST['incident_id']) ? intval($_POST['incident_id']) : 0;
$responder_id = $_SESSION['user_id'];

if ($incident_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid incident ID']);
    exit;
}

// Check if incident is taken by this responder
$check = $conn->prepare("SELECT incident_id FROM tbl_incidents WHERE incident_id = ? AND taken_by_responder_id = ?");
$check->bind_param("ii", $incident_id, $responder_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Incident not found or not assigned to you']);
    exit;
}

// Update status to pending (release the incident)
$update = $conn->prepare("UPDATE tbl_incidents SET status = 'pending', taken_by_responder_id = NULL, taken_at = NULL WHERE incident_id = ?");
$update->bind_param("i", $incident_id);

if ($update->execute()) {
    // Log the action
    $log = $conn->prepare("INSERT INTO tbl_responder_actions (incident_id, responder_id, action_type) VALUES (?, ?, 'cancelled')");
    $log->bind_param("ii", $incident_id, $responder_id);
    $log->execute();
    
    echo json_encode(['success' => true, 'message' => 'Incident cancelled successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>