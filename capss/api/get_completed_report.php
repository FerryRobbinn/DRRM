<?php
// api/get_completed_report.php - Get completed report details
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$report_id = isset($_POST['report_id']) ? intval($_POST['report_id']) : 0;

if (!$report_id) {
    echo json_encode(['error' => 'Report ID required']);
    exit;
}

$stmt = $conn->prepare("
    SELECT cr.*, i.tracking_id, i.incident_type, i.severity, i.location_address
    FROM tbl_completed_reports cr
    LEFT JOIN tbl_incidents i ON cr.incident_id = i.incident_id
    WHERE cr.report_id = ?
");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();

if (!$report) {
    echo json_encode(['error' => 'Report not found']);
    exit;
}

echo json_encode($report);
?>