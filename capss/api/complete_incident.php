<?php
// api/complete_incident.php - Handle completed incident reports
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

// Check if user is logged in and is a responder
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$incident_id = isset($_POST['incident_id']) ? intval($_POST['incident_id']) : 0;
$report_data = isset($_POST['report_data']) ? $_POST['report_data'] : '';
$report_name = isset($_POST['report_name']) ? $_POST['report_name'] : 'Incident Report';

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Incident ID is required']);
    exit;
}

if (!$report_data) {
    echo json_encode(['success' => false, 'message' => 'Report data is required']);
    exit;
}

// Verify that this incident belongs to the responder
$verify = $conn->prepare("SELECT incident_id FROM tbl_incidents WHERE incident_id = ? AND taken_by_responder_id = ?");
$verify->bind_param("ii", $incident_id, $_SESSION['user_id']);
$verify->execute();
$result = $verify->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'You are not authorized to complete this incident']);
    exit;
}

// Parse the report data
$report = json_decode($report_data, true);

if (!$report) {
    echo json_encode(['success' => false, 'message' => 'Invalid report data format']);
    exit;
}

// Create a table for completed reports if it doesn't exist
$conn->query("
    CREATE TABLE IF NOT EXISTS `tbl_completed_reports` (
        `report_id` int(11) NOT NULL AUTO_INCREMENT,
        `incident_id` int(11) NOT NULL,
        `tracking_id` varchar(50) NOT NULL,
        `report_data` longtext NOT NULL,
        `report_name` varchar(255) NOT NULL,
        `responder_id` int(11) NOT NULL,
        `responder_name` varchar(255) NOT NULL,
        `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`report_id`),
        KEY `incident_id` (`incident_id`),
        KEY `responder_id` (`responder_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Get incident details
$incident_info = $conn->query("SELECT tracking_id FROM tbl_incidents WHERE incident_id = $incident_id")->fetch_assoc();
$tracking_id = $incident_info['tracking_id'];

// Save the completed report
$responder_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Responder';
$report_json = json_encode($report);

$stmt = $conn->prepare("INSERT INTO tbl_completed_reports (incident_id, tracking_id, report_data, report_name, responder_id, responder_name) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isssis", $incident_id, $tracking_id, $report_json, $report_name, $_SESSION['user_id'], $responder_name);

if ($stmt->execute()) {
    // Update the incident status to completed
    $update = $conn->prepare("UPDATE tbl_incidents SET status = 'completed', finished_at = NOW(), finished_by_responder_id = ? WHERE incident_id = ?");
    $update->bind_param("ii", $_SESSION['user_id'], $incident_id);
    $update->execute();
    
    // Also delete any drafts for this incident
    $conn->query("DELETE FROM tbl_incident_drafts WHERE incident_id = $incident_id");
    
    echo json_encode(['success' => true, 'message' => 'Report completed and sent to admin successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>