<?php
// api/dispatch_incident.php
session_start();
require_once '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die('Unauthorized');
}

$incident_id = $_POST['incident_id'];
$responder_id = $_POST['responder_id'];
$notes = $_POST['notes'];

// Update incident
$stmt = $conn->prepare("UPDATE tbl_incidents SET assigned_responder_id = ?, status = 'dispatched' WHERE incident_id = ?");
$stmt->bind_param("ii", $responder_id, $incident_id);
$stmt->execute();

// Log dispatch
$stmt2 = $conn->prepare("INSERT INTO tbl_dispatch_logs (incident_id, dispatcher_id, responder_id, action, notes) VALUES (?, ?, ?, 'dispatched', ?)");
$stmt2->bind_param("iiis", $incident_id, $_SESSION['user_id'], $responder_id, $notes);
$stmt2->execute();

echo 'success';
?>