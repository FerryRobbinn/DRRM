<?php
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
$action_type = isset($_POST['action_type']) ? $_POST['action_type'] : 'view';
$action_details = isset($_POST['action_details']) ? $_POST['action_details'] : '';
$field_changed = isset($_POST['field_changed']) ? $_POST['field_changed'] : '';
$new_value = isset($_POST['new_value']) ? $_POST['new_value'] : '';

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Incident ID required']);
    exit;
}

$ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
$responder_name = isset($_SESSION['fullname']) ? $_SESSION['fullname'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'Unknown');

// Create table if it doesn't exist
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_report_access_log'");
if ($table_check->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_report_access_log (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        responder_id INT NOT NULL,
        responder_name VARCHAR(255) NULL,
        action_type VARCHAR(50) DEFAULT 'view',
        action_details TEXT NULL,
        field_changed VARCHAR(255) NULL,
        new_value TEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_incident (incident_id),
        INDEX idx_responder (responder_id)
    )");
}

// Add responder_name column if missing
$column_check = $conn->query("SHOW COLUMNS FROM tbl_report_access_log LIKE 'responder_name'");
if ($column_check->num_rows == 0) {
    $conn->query("ALTER TABLE tbl_report_access_log ADD COLUMN responder_name VARCHAR(255) NULL AFTER responder_id");
}

// Add field_changed and new_value columns if missing
$field_check = $conn->query("SHOW COLUMNS FROM tbl_report_access_log LIKE 'field_changed'");
if ($field_check->num_rows == 0) {
    $conn->query("ALTER TABLE tbl_report_access_log ADD COLUMN field_changed VARCHAR(255) NULL AFTER action_details");
}

$new_value_check = $conn->query("SHOW COLUMNS FROM tbl_report_access_log LIKE 'new_value'");
if ($new_value_check->num_rows == 0) {
    $conn->query("ALTER TABLE tbl_report_access_log ADD COLUMN new_value TEXT NULL AFTER field_changed");
}

$stmt = $conn->prepare("INSERT INTO tbl_report_access_log (incident_id, responder_id, responder_name, action_type, action_details, field_changed, new_value, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("iisssssss", $incident_id, $_SESSION['user_id'], $responder_name, $action_type, $action_details, $field_changed, $new_value, $ip_address, $user_agent);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>