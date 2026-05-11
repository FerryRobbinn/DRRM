<?php
// api/log_responder_action.php - Log responder actions (arrived, navigating, etc.)
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$incident_id = intval($_POST['incident_id'] ?? 0);
$action_type = $_POST['action_type'] ?? 'viewed';
$location_lat = isset($_POST['location_lat']) ? floatval($_POST['location_lat']) : null;
$location_lng = isset($_POST['location_lng']) ? floatval($_POST['location_lng']) : null;
$notes = $_POST['notes'] ?? null;

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Incident ID required']);
    exit;
}

// Check if tbl_responder_actions exists, create if not
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_responder_actions'");
if ($table_check->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_responder_actions (
        action_id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        responder_id INT NOT NULL,
        action_type ENUM('viewed', 'taken', 'arrived', 'completed', 'transferred', 'navigating', 'cancelled') DEFAULT 'viewed',
        location_lat DECIMAL(10,8) NULL,
        location_lng DECIMAL(11,8) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_incident (incident_id),
        INDEX idx_responder (responder_id)
    )");
}

// Insert the action
$stmt = $conn->prepare("INSERT INTO tbl_responder_actions (incident_id, responder_id, action_type, location_lat, location_lng, notes) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iisdds", $incident_id, $_SESSION['user_id'], $action_type, $location_lat, $location_lng, $notes);

if ($stmt->execute()) {
    // Also log to access log
    $responder_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Unknown';
    $action_details = "Action: $action_type" . ($notes ? " - $notes" : "");
    
    $log_stmt = $conn->prepare("INSERT INTO tbl_report_access_log (incident_id, responder_id, responder_name, action_type, action_details) VALUES (?, ?, ?, ?, ?)");
    $log_action = 'action_' . $action_type;
    $log_stmt->bind_param("iisss", $incident_id, $_SESSION['user_id'], $responder_name, $log_action, $action_details);
    $log_stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Action logged successfully']);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>