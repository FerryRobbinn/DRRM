<?php
// api/get_responder_completed_reports.php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$responder_id = $_SESSION['user_id'];

$query = "
    SELECT cr.*, i.tracking_id, i.incident_type, i.severity, i.location_address
    FROM tbl_completed_reports cr
    LEFT JOIN tbl_incidents i ON cr.incident_id = i.incident_id
    WHERE cr.responder_id = ?
    ORDER BY cr.submitted_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $responder_id);
$stmt->execute();
$result = $stmt->get_result();

$reports = [];
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}

echo json_encode(['success' => true, 'reports' => $reports]);
?>