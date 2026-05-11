<?php
// api/accept_incident.php
session_start();
require_once '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = $_POST['incident_id'];
$responder_id = $_SESSION['user_id'];

// Update incident - assign to this responder and change status
$stmt = $conn->prepare("UPDATE tbl_incidents SET assigned_responder_id = ?, status = 'dispatched' WHERE incident_id = ? AND status = 'pending'");
$stmt->bind_param("ii", $responder_id, $incident_id);

if ($stmt->execute() && $stmt->affected_rows > 0) {
    echo json_encode(['success' => true, 'message' => 'Incident accepted']);
} else {
    echo json_encode(['success' => false, 'message' => 'Incident already taken or not available']);
}
?>