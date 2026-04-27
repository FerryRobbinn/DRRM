<?php
// api/take_incident.php - Prevent multiple responders from taking same incident

// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = intval($_POST['incident_id']);
$responder_id = $_SESSION['user_id'];

// Use transaction to prevent race conditions
$conn->begin_transaction();

try {
    // Check current status with lock
    $check = $conn->prepare("SELECT status, taken_by_responder_id FROM tbl_incidents WHERE incident_id = ? FOR UPDATE");
    $check->bind_param("i", $incident_id);
    $check->execute();
    $result = $check->get_result();
    $incident = $result->fetch_assoc();
    
    if (!$incident) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Incident not found']);
        exit;
    }
    
    if ($incident['status'] !== 'pending') {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'This incident has already been taken by another responder']);
        exit;
    }
    
    // Update incident status
    $update = $conn->prepare("UPDATE tbl_incidents SET status = 'dispatched', taken_by_responder_id = ?, taken_at = NOW() WHERE incident_id = ?");
    $update->bind_param("ii", $responder_id, $incident_id);
    $update->execute();
    
    // Log the action
    $log = $conn->prepare("INSERT INTO tbl_responder_actions (incident_id, responder_id, action_type) VALUES (?, ?, 'taken')");
    $log->bind_param("ii", $incident_id, $responder_id);
    $log->execute();
    
    // Get responder name
    $getName = $conn->prepare("SELECT fullname FROM tbl_users WHERE user_id = ?");
    $getName->bind_param("i", $responder_id);
    $getName->execute();
    $responder = $getName->get_result()->fetch_assoc();
    $responder_name = $responder ? $responder['fullname'] : 'Responder';
    
    // AUTO-SHARE WITH ALL RESPONDERS
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
            INDEX idx_granted_to (granted_to_responder_id)
        )");
    }
    
    // Check if is_active column exists
    $column_check = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'is_active'");
    $has_is_active = $column_check->num_rows > 0;
    
    // Get all other responders
    if ($has_is_active) {
        $all_responders = $conn->prepare("SELECT user_id FROM tbl_users WHERE role = 'responder' AND is_active = 1 AND user_id != ?");
    } else {
        $all_responders = $conn->prepare("SELECT user_id FROM tbl_users WHERE role = 'responder' AND user_id != ?");
    }
    $all_responders->bind_param("i", $responder_id);
    $all_responders->execute();
    $responders_result = $all_responders->get_result();
    
    $shared_count = 0;
    while ($other_responder = $responders_result->fetch_assoc()) {
        $grant_stmt = $conn->prepare("INSERT INTO tbl_report_access_grants (incident_id, granted_to_responder_id, granted_by_responder_id, access_level) VALUES (?, ?, ?, 'view') ON DUPLICATE KEY UPDATE is_active = 1, access_level = 'view', granted_at = NOW()");
        $grant_stmt->bind_param("iii", $incident_id, $other_responder['user_id'], $responder_id);
        if ($grant_stmt->execute()) {
            $shared_count++;
        }
    }
    
    // Log the auto-share action
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
    
    $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
    
    $log_share = $conn->prepare("INSERT INTO tbl_report_access_log (incident_id, responder_id, action_type, action_details, ip_address, user_agent) VALUES (?, ?, 'auto_share', ?, ?, ?)");
    $share_details = "Auto-shared with $shared_count responder(s)";
    $log_share->bind_param("iisss", $incident_id, $responder_id, $share_details, $ip_address, $user_agent);
    $log_share->execute();
    
    // FIXED: Notify other responders - using responder_id instead of user_id
    // Check the actual column name in tbl_notifications
    $notify_cols = $conn->query("SHOW COLUMNS FROM tbl_notifications");
    $has_user_id = false;
    $has_responder_id = false;
    while ($col = $notify_cols->fetch_assoc()) {
        if ($col['Field'] == 'user_id') $has_user_id = true;
        if ($col['Field'] == 'responder_id') $has_responder_id = true;
    }
    
    $id_column = $has_user_id ? 'user_id' : ($has_responder_id ? 'responder_id' : 'user_id');
    
    if ($has_is_active) {
        $notify = $conn->prepare("INSERT INTO tbl_notifications ($id_column, incident_id, message, is_read) 
                                  SELECT user_id, ?, CONCAT('A new incident report has been shared with you by ', ?, '. You can view it in My Reports.'), 0 FROM tbl_users 
                                  WHERE role = 'responder' AND user_id != ? AND is_active = 1");
    } else {
        $notify = $conn->prepare("INSERT INTO tbl_notifications ($id_column, incident_id, message, is_read) 
                                  SELECT user_id, ?, CONCAT('A new incident report has been shared with you by ', ?, '. You can view it in My Reports.'), 0 FROM tbl_users 
                                  WHERE role = 'responder' AND user_id != ?");
    }
    $notify->bind_param("isi", $incident_id, $responder_name, $responder_id);
    $notify->execute();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Incident taken and shared with ' . $shared_count . ' responder(s)',
        'incident_id' => $incident_id,
        'shared_count' => $shared_count
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>