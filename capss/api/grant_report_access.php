<?php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$incident_id = intval($_POST['incident_id'] ?? 0);
$grant_to_user_id = intval($_POST['user_id'] ?? 0);
$access_level = isset($_POST['access_level']) ? $_POST['access_level'] : 'view';

if (!$incident_id || !$grant_to_user_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Check if current user has access to this incident (taken or finished)
$check = $conn->prepare("SELECT incident_id FROM tbl_incidents WHERE incident_id = ? AND (taken_by_responder_id = ? OR finished_by_responder_id = ?)");
$check->bind_param("iii", $incident_id, $_SESSION['user_id'], $_SESSION['user_id']);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to grant access to this report']);
    exit;
}

// Create grants table if it doesn't exist (using correct column names for your DB)
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_report_access_grants'");
if ($table_check->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_report_access_grants (
        grant_id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        granted_to_responder_id INT NOT NULL,
        granted_by_responder_id INT NOT NULL,
        access_level ENUM('view', 'edit') DEFAULT 'view',
        granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL,
        is_active TINYINT(1) DEFAULT 1,
        UNIQUE KEY unique_grant (incident_id, granted_to_responder_id),
        INDEX idx_incident (incident_id),
        INDEX idx_granted_to (granted_to_responder_id),
        INDEX idx_granted_by (granted_by_responder_id)
    )");
}

// First, check if grant already exists
$check_grant = $conn->prepare("SELECT grant_id, is_active FROM tbl_report_access_grants WHERE incident_id = ? AND granted_to_responder_id = ?");
$check_grant->bind_param("ii", $incident_id, $grant_to_user_id);
$check_grant->execute();
$existing = $check_grant->get_result();

if ($existing->num_rows > 0) {
    // Update existing grant
    $row = $existing->fetch_assoc();
    if ($row['is_active'] == 1) {
        echo json_encode(['success' => false, 'message' => 'This user already has access to this report']);
        exit;
    } else {
        // Reactivate existing grant
        $stmt = $conn->prepare("UPDATE tbl_report_access_grants SET access_level = ?, is_active = 1, granted_at = NOW() WHERE grant_id = ?");
        $stmt->bind_param("si", $access_level, $row['grant_id']);
    }
} else {
    // Insert new grant
    $stmt = $conn->prepare("INSERT INTO tbl_report_access_grants (incident_id, granted_to_responder_id, granted_by_responder_id, access_level) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("iiis", $incident_id, $grant_to_user_id, $_SESSION['user_id'], $access_level);
}

if ($stmt->execute()) {
    // Get the grantee name for logging
    $getName = $conn->prepare("SELECT fullname FROM tbl_users WHERE user_id = ?");
    $getName->bind_param("i", $grant_to_user_id);
    $getName->execute();
    $grantee = $getName->get_result()->fetch_assoc();
    $granted_to_name = isset($grantee['fullname']) ? $grantee['fullname'] : "User $grant_to_user_id";
    
    $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
    
    // Create access log table if it doesn't exist
    $log_table_check = $conn->query("SHOW TABLES LIKE 'tbl_report_access_log'");
    if ($log_table_check->num_rows == 0) {
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_report_access_log (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            incident_id INT NOT NULL,
            responder_id INT NOT NULL,
            action_type VARCHAR(50) DEFAULT 'view',
            action_details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_incident (incident_id),
            INDEX idx_responder (responder_id)
        )");
    }
    
    // Log the grant action
    $log_stmt = $conn->prepare("INSERT INTO tbl_report_access_log (incident_id, responder_id, action_type, action_details, ip_address, user_agent) VALUES (?, ?, 'grant_access', ?, ?, ?)");
    $log_details = "Granted $access_level access to $granted_to_name";
    $log_stmt->bind_param("iissss", $incident_id, $_SESSION['user_id'], $log_details, $ip_address, $user_agent);
    $log_stmt->execute();
    
    echo json_encode(['success' => true, 'message' => "Access granted successfully to $granted_to_name"]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>