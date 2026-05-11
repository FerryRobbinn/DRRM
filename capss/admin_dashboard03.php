<?php
// admin_dashboard.php - Enhanced Admin Dashboard with Complete Report Management
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Helper functions
function getSeverityColor_php($severity) {
    $s = strtolower(trim($severity ?? ''));
    if ($s === 'dead') return '#6b7280';
    if ($s === 'high' || $s === 'critical' || $s === 'immediate') return '#ef4444';
    if ($s === 'moderate' || $s === 'delayed') return '#f59e0b';
    return '#10b981';
}

function getSeverityBadge($severity) {
    $severity = trim(strtolower($severity));
    if (empty($severity)) {
        return '<span class="severity-badge minor"><i class="fas fa-band-aid"></i> MINOR</span>';
    }
    
    if ($severity === 'dead' || $severity === 'deceased' || $severity === 'black') {
        return '<span class="severity-badge dead"><i class="fas fa-skull"></i> DEAD</span>';
    }
    if ($severity === 'high' || $severity === 'critical' || $severity === 'immediate' || $severity === 'red') {
        return '<span class="severity-badge immediate"><i class="fas fa-exclamation-triangle"></i> IMMEDIATE</span>';
    }
    if ($severity === 'moderate' || $severity === 'delayed' || $severity === 'yellow' || $severity === 'serious') {
        return '<span class="severity-badge delayed"><i class="fas fa-exclamation-circle"></i> DELAYED</span>';
    }
    if ($severity === 'low' || $severity === 'minor' || $severity === 'green') {
        return '<span class="severity-badge minor"><i class="fas fa-band-aid"></i> MINOR</span>';
    }
    return '<span class="severity-badge minor"><i class="fas fa-band-aid"></i> MINOR</span>';
}

function getSeverityClass($severity) {
    $severity = trim(strtolower($severity));
    if (empty($severity)) return '';
    if ($severity === 'dead' || $severity === 'deceased' || $severity === 'black') return 'border-severity-dead';
    if ($severity === 'high' || $severity === 'critical' || $severity === 'immediate' || $severity === 'red') return 'border-severity-immediate';
    if ($severity === 'moderate' || $severity === 'delayed' || $severity === 'yellow' || $severity === 'serious') return 'border-severity-delayed';
    if ($severity === 'low' || $severity === 'minor' || $severity === 'green') return 'border-severity-minor';
    return '';
}

function getSeverityDot($severity) {
    $severity = trim(strtolower($severity));
    if (empty($severity)) return 'minor';
    if ($severity === 'dead' || $severity === 'deceased' || $severity === 'black') return 'dead';
    if ($severity === 'high' || $severity === 'critical' || $severity === 'immediate' || $severity === 'red') return 'immediate';
    if ($severity === 'moderate' || $severity === 'delayed' || $severity === 'yellow' || $severity === 'serious') return 'delayed';
    if ($severity === 'low' || $severity === 'minor' || $severity === 'green') return 'minor';
    return 'minor';
}

// Check if access log table exists, create if not
$access_log_table = $conn->query("SHOW TABLES LIKE 'tbl_report_access_log'");
if ($access_log_table->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_report_access_log (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        responder_id INT NULL,
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

// Check if access grants table exists
$access_grants_table = $conn->query("SHOW TABLES LIKE 'tbl_report_access_grants'");
if ($access_grants_table->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_report_access_grants (
        grant_id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        granted_to_responder_id INT NOT NULL,
        granted_by_responder_id INT NOT NULL,
        access_level ENUM('view', 'edit') DEFAULT 'view',
        granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL,
        is_active TINYINT(1) DEFAULT 1,
        UNIQUE KEY unique_grant (incident_id, granted_to_responder_id)
    )");
}

// Check if responder locations table exists
$locations_check = $conn->query("SHOW TABLES LIKE 'tbl_responder_locations'");
if ($locations_check->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_responder_locations (
        location_id INT AUTO_INCREMENT PRIMARY KEY,
        responder_id INT NOT NULL,
        latitude DECIMAL(10,8) NOT NULL,
        longitude DECIMAL(11,8) NOT NULL,
        accuracy FLOAT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_responder (responder_id),
        INDEX idx_location (latitude, longitude)
    )");
}

// Check if videos table exists
$video_table_exists = $conn->query("SHOW TABLES LIKE 'tbl_incident_videos'")->num_rows > 0;

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Create new report (full creation)
    if ($_POST['action'] === 'create_full_report') {
        $reportData = json_decode($_POST['report_data'], true);
        $tracking_id = $_POST['tracking_id'] ?? 'ADMIN-' . strtoupper(uniqid());
        
        $stmt = $conn->prepare("INSERT INTO tbl_incidents (tracking_id, incident_type, location_address, location_lat, location_lng, severity, description, reporter_name, reporter_phone, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())");
        $stmt->bind_param("sssddssss", $tracking_id, $reportData['incidentPurpose'], $reportData['placeIncident'], $_POST['location_lat'], $_POST['location_lng'], $reportData['severity'], $reportData['description'], $reportData['reporter_name'], $reportData['reporter_phone']);
        
        if ($stmt->execute()) {
            $incident_id = $conn->insert_id;
            
            $stmt2 = $conn->prepare("INSERT INTO tbl_completed_reports (incident_id, responder_id, report_name, report_data, submitted_at) VALUES (?, ?, ?, ?, NOW())");
            $report_name = $reportData['reportName'] ?? 'Report ' . $tracking_id;
            $responder_id = $_SESSION['user_id'];
            $report_json = json_encode($reportData);
            $stmt2->bind_param("iiss", $incident_id, $responder_id, $report_name, $report_json);
            $stmt2->execute();
            
            echo json_encode(['success' => true, 'incident_id' => $incident_id, 'tracking_id' => $tracking_id]);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    }
    
    // Update existing report with full form data
    if ($_POST['action'] === 'update_full_report') {
        $incident_id = intval($_POST['incident_id']);
        $reportData = json_decode($_POST['report_data'], true);
        $tracking_id = $_POST['tracking_id'];
        
        $stmt = $conn->prepare("UPDATE tbl_incidents SET tracking_id = ?, incident_type = ?, location_address = ?, location_lat = ?, location_lng = ?, severity = ?, description = ?, reporter_name = ?, reporter_phone = ?, status = 'completed' WHERE incident_id = ?");
        $stmt->bind_param("sssddssssi", $tracking_id, $reportData['incidentPurpose'], $reportData['placeIncident'], $_POST['location_lat'], $_POST['location_lng'], $reportData['severity'], $reportData['description'], $reportData['reporter_name'], $reportData['reporter_phone'], $incident_id);
        $stmt->execute();
        
        $check = $conn->prepare("SELECT report_id FROM tbl_completed_reports WHERE incident_id = ?");
        $check->bind_param("i", $incident_id);
        $check->execute();
        $result = $check->get_result();
        
        $report_json = json_encode($reportData);
        $report_name = $reportData['reportName'] ?? 'Updated Report';
        
        if ($result->num_rows > 0) {
            $stmt2 = $conn->prepare("UPDATE tbl_completed_reports SET report_data = ?, report_name = ?, submitted_at = NOW() WHERE incident_id = ?");
            $stmt2->bind_param("ssi", $report_json, $report_name, $incident_id);
        } else {
            $stmt2 = $conn->prepare("INSERT INTO tbl_completed_reports (incident_id, responder_id, report_name, report_data, submitted_at) VALUES (?, ?, ?, ?, NOW())");
            $responder_id = $_SESSION['user_id'];
            $stmt2->bind_param("iiss", $incident_id, $responder_id, $report_name, $report_json);
        }
        $stmt2->execute();
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Create new incident (like responder)
    if ($_POST['action'] === 'create_new_incident') {
        $tracking_id = 'ADMIN-' . strtoupper(uniqid());
        $incident_type = $_POST['incident_type'] ?? 'Medical';
        $location_address = $_POST['location_address'] ?? '';
        $location_lat = isset($_POST['location_lat']) && $_POST['location_lat'] !== 'null' ? $_POST['location_lat'] : null;
        $location_lng = isset($_POST['location_lng']) && $_POST['location_lng'] !== 'null' ? $_POST['location_lng'] : null;
        $severity = $_POST['severity'] ?? 'low';
        $description = $_POST['description'] ?? '';
        $reporter_name = $_POST['reporter_name'] ?? $_SESSION['fullname'] ?? 'Admin';
        $reporter_phone = $_POST['reporter_phone'] ?? '';
        
        $stmt = $conn->prepare("INSERT INTO tbl_incidents (tracking_id, incident_type, location_address, location_lat, location_lng, severity, description, reporter_name, reporter_phone, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->bind_param("sssdsssss", $tracking_id, $incident_type, $location_address, $location_lat, $location_lng, $severity, $description, $reporter_name, $reporter_phone);
        
        if ($stmt->execute()) {
            $incident_id = $conn->insert_id;
            echo json_encode(['success' => true, 'incident_id' => $incident_id, 'tracking_id' => $tracking_id]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error creating incident: ' . $conn->error]);
        }
        exit;
    }
    
    // Get full report for editing
    if ($_POST['action'] === 'get_full_report_for_edit') {
        $incident_id = intval($_POST['incident_id']);
        
        $stmt = $conn->prepare("
            SELECT i.*, cr.report_data, cr.report_name, cr.report_id
            FROM tbl_incidents i
            LEFT JOIN tbl_completed_reports cr ON i.incident_id = cr.incident_id
            WHERE i.incident_id = ?
        ");
        $stmt->bind_param("i", $incident_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'report' => $row]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Report not found']);
        }
        exit;
    }
    
    // Get completed report
    if ($_POST['action'] === 'get_completed_report') {
        $report_id = intval($_POST['report_id']);
        
        $stmt = $conn->prepare("
            SELECT cr.*, i.tracking_id, i.incident_type, i.severity, i.location_address, 
                   i.location_lat, i.location_lng, i.created_at as incident_created,
                   u.fullname as responder_name, u.responder_type, u.badge_number
            FROM tbl_completed_reports cr
            LEFT JOIN tbl_incidents i ON cr.incident_id = i.incident_id
            LEFT JOIN tbl_users u ON cr.responder_id = u.user_id
            WHERE cr.report_id = ?
        ");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'report' => $row]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Report not found']);
        }
        exit;
    }
    
    // Get report access logs (enhanced with grants info)
    if ($_POST['action'] === 'get_report_access_logs') {
        $incident_id = intval($_POST['incident_id']);
        $logs = [];
        $grants = [];
        
        $table_check = $conn->query("SHOW TABLES LIKE 'tbl_report_access_log'");
        if ($table_check->num_rows > 0) {
            $stmt = $conn->prepare("
                SELECT ral.*, u.fullname as actor_name
                FROM tbl_report_access_log ral
                LEFT JOIN tbl_users u ON ral.responder_id = u.user_id
                WHERE ral.incident_id = ?
                ORDER BY ral.created_at DESC
            ");
            $stmt->bind_param("i", $incident_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) { $logs[] = $row; }
        }
        
        // Get access grants
        $grants_check = $conn->query("SHOW TABLES LIKE 'tbl_report_access_grants'");
        if ($grants_check->num_rows > 0) {
            $stmt2 = $conn->prepare("
                SELECT g.*, 
                       u1.fullname as granted_to_name,
                       u2.fullname as granted_by_name
                FROM tbl_report_access_grants g
                LEFT JOIN tbl_users u1 ON g.granted_to_responder_id = u1.user_id
                LEFT JOIN tbl_users u2 ON g.granted_by_responder_id = u2.user_id
                WHERE g.incident_id = ?
            ");
            $stmt2->bind_param("i", $incident_id);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            while ($row = $result2->fetch_assoc()) { $grants[] = $row; }
        }
        
        echo json_encode(['success' => true, 'logs' => $logs, 'grants' => $grants]);
        exit;
    }
    
    // Get analytics with period filter
    if ($_POST['action'] === 'get_filtered_analytics') {
        $period = $_POST['period'] ?? 'monthly';
        
        $dateCondition = "";
        switch($period) {
            case 'weekly': $dateCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; break;
            case 'monthly': $dateCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; break;
            case 'yearly': $dateCondition = "AND created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)"; break;
        }
        
        $analytics = [];
        
        $statusResult = $conn->query("SELECT status, COUNT(*) as count, ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tbl_incidents WHERE 1=1 $dateCondition), 1) as percentage FROM tbl_incidents WHERE 1=1 $dateCondition GROUP BY status");
        $analytics['by_status'] = [];
        while ($row = $statusResult->fetch_assoc()) { $analytics['by_status'][] = $row; }
        
        $typeResult = $conn->query("SELECT incident_type, COUNT(*) as count, ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tbl_incidents WHERE 1=1 $dateCondition), 1) as percentage FROM tbl_incidents WHERE 1=1 $dateCondition GROUP BY incident_type ORDER BY count DESC LIMIT 6");
        $analytics['by_type'] = [];
        while ($row = $typeResult->fetch_assoc()) { $analytics['by_type'][] = $row; }
        
        $severityResult = $conn->query("SELECT COALESCE(severity, 'Minor') as severity, COUNT(*) as count, ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM tbl_incidents WHERE 1=1 $dateCondition), 1) as percentage FROM tbl_incidents WHERE 1=1 $dateCondition GROUP BY severity");
        $analytics['by_severity'] = [];
        while ($row = $severityResult->fetch_assoc()) { $analytics['by_severity'][] = $row; }
        
        $trendResult = $conn->query("SELECT DATE(created_at) as date, COUNT(*) as count FROM tbl_incidents WHERE 1=1 $dateCondition GROUP BY DATE(created_at) ORDER BY date");
        $analytics['trend'] = [];
        while ($row = $trendResult->fetch_assoc()) { $analytics['trend'][] = $row; }
        
        $analytics['summary'] = [
            'total' => $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE 1=1 $dateCondition")->fetch_assoc()['total'],
            'completed' => $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE status = 'completed' $dateCondition")->fetch_assoc()['total'],
            'ongoing' => $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE status IN ('pending', 'dispatched') $dateCondition")->fetch_assoc()['total'],
            'completion_rate' => 0
        ];
        
        if ($analytics['summary']['total'] > 0) {
            $analytics['summary']['completion_rate'] = round(($analytics['summary']['completed'] / $analytics['summary']['total']) * 100, 1);
        }
        
        echo json_encode(['success' => true, 'analytics' => $analytics]);
        exit;
    }
    
    // Get all incidents (for auto-refresh) with photo/video counts
    if ($_POST['action'] === 'get_all_incidents') {
        $video_count_sql = $video_table_exists 
            ? "(SELECT COUNT(*) FROM tbl_incident_videos WHERE incident_id = i.incident_id) as video_count"
            : "0 as video_count";
            
        $incidents = $conn->query("
            SELECT i.*, 
                   (SELECT COUNT(*) FROM tbl_incident_photos WHERE incident_id = i.incident_id) as photo_count,
                   $video_count_sql,
                   r1.fullname as taken_by_name, 
                   r2.fullname as finished_by_name
            FROM tbl_incidents i 
            LEFT JOIN tbl_users r1 ON i.taken_by_responder_id = r1.user_id
            LEFT JOIN tbl_users r2 ON i.finished_by_responder_id = r2.user_id
            ORDER BY i.created_at DESC
        ");
        
        $result = [];
        while ($row = $incidents->fetch_assoc()) {
            $result[] = $row;
        }
        echo json_encode(['success' => true, 'incidents' => $result]);
        exit;
    }
    
    // Get completed reports list
    if ($_POST['action'] === 'get_completed_reports') {
        $reports = $conn->query("
            SELECT cr.*, i.tracking_id, i.incident_type, i.severity, i.location_address, i.incident_id,
                   u.fullname as responder_name
            FROM tbl_completed_reports cr
            LEFT JOIN tbl_incidents i ON cr.incident_id = i.incident_id
            LEFT JOIN tbl_users u ON cr.responder_id = u.user_id
            ORDER BY cr.submitted_at DESC
        ");
        
        $result = [];
        while ($row = $reports->fetch_assoc()) {
            $result[] = $row;
        }
        echo json_encode(['success' => true, 'reports' => $result]);
        exit;
    }
    
    // Delete report (move to trash equivalent)
    if ($_POST['action'] === 'delete_report') {
        $incident_id = intval($_POST['incident_id']);
        
        // First delete related records
        $conn->query("DELETE FROM tbl_completed_reports WHERE incident_id = $incident_id");
        $conn->query("DELETE FROM tbl_incident_photos WHERE incident_id = $incident_id");
        if ($video_table_exists) {
            $conn->query("DELETE FROM tbl_incident_videos WHERE incident_id = $incident_id");
        }
        $conn->query("DELETE FROM tbl_incident_drafts WHERE incident_id = $incident_id");
        $conn->query("DELETE FROM tbl_report_access_log WHERE incident_id = $incident_id");
        $conn->query("DELETE FROM tbl_report_access_grants WHERE incident_id = $incident_id");
        $conn->query("DELETE FROM tbl_responder_actions WHERE incident_id = $incident_id");
        
        // Finally delete the incident
        $stmt = $conn->prepare("DELETE FROM tbl_incidents WHERE incident_id = ?");
        $stmt->bind_param("i", $incident_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Report permanently deleted']);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    }
    
    // Revoke report access
    if ($_POST['action'] === 'revoke_report_access') {
        $grant_id = intval($_POST['grant_id']);
        
        $stmt = $conn->prepare("UPDATE tbl_report_access_grants SET is_active = 0 WHERE grant_id = ?");
        $stmt->bind_param("i", $grant_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Access revoked successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    }
    
    // Responder management handlers
    if ($_POST['action'] === 'create_responder') {
        $username = trim($_POST['username']);
        $password = password_hash('responder123', PASSWORD_DEFAULT);
        $fullname = trim($_POST['fullname']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $responder_type = $_POST['responder_type'];
        $badge_number = trim($_POST['badge_number']);
        
        $check = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit;
        }
        
        $stmt = $conn->prepare("INSERT INTO tbl_users (username, password, fullname, role, email, phone, responder_type, badge_number, is_active) VALUES (?, ?, ?, 'responder', ?, ?, ?, ?, 1)");
        $stmt->bind_param("sssssss", $username, $password, $fullname, $email, $phone, $responder_type, $badge_number);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Responder created. Password: responder123']);
        } else {
            echo json_encode(['success' => false, 'message' => $conn->error]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'toggle_status') {
        $user_id = intval($_POST['user_id']);
        $current_status = intval($_POST['current_status']);
        $new_status = $current_status == 1 ? 0 : 1;
        $conn->query("UPDATE tbl_users SET is_active = $new_status WHERE user_id = $user_id AND role = 'responder'");
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($_POST['action'] === 'reset_password') {
        $user_id = intval($_POST['user_id']);
        $new_password = password_hash('responder123', PASSWORD_DEFAULT);
        $conn->query("UPDATE tbl_users SET password = '$new_password' WHERE user_id = $user_id AND role = 'responder'");
        echo json_encode(['success' => true, 'message' => 'Password reset to: responder123']);
        exit;
    }
    
    if ($_POST['action'] === 'delete_responder') {
        $user_id = intval($_POST['user_id']);
        $conn->query("DELETE FROM tbl_users WHERE user_id = $user_id AND role = 'responder'");
        echo json_encode(['success' => true]);
        exit;
    }
}

// Get statistics
$stats = [];
$stats['total_reports'] = $conn->query("SELECT COUNT(*) as total FROM tbl_incidents")->fetch_assoc()['total'] ?? 0;
$stats['pending'] = $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE status = 'pending'")->fetch_assoc()['total'] ?? 0;
$stats['dispatched'] = $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE status = 'dispatched'")->fetch_assoc()['total'] ?? 0;
$stats['completed'] = $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0;
$stats['total_responders'] = $conn->query("SELECT COUNT(*) as total FROM tbl_users WHERE role = 'responder'")->fetch_assoc()['total'] ?? 0;
$stats['active_responders'] = $conn->query("SELECT COUNT(*) as total FROM tbl_users WHERE role = 'responder' AND is_active = 1")->fetch_assoc()['total'] ?? 0;
$stats['completed_reports'] = $conn->query("SELECT COUNT(*) as total FROM tbl_completed_reports")->fetch_assoc()['total'] ?? 0;

// Get responders with location info
$responders_query = "
    SELECT u.*, 
           rl.latitude, rl.longitude, rl.updated_at as location_updated
    FROM tbl_users u 
    LEFT JOIN tbl_responder_locations rl ON u.user_id = rl.responder_id
    WHERE u.role = 'responder' 
    ORDER BY u.created_at DESC
";
$responders = $conn->query($responders_query);

// Get completed reports
$completed_reports = $conn->query("
    SELECT cr.*, i.tracking_id, i.incident_type, i.severity, i.location_address, i.incident_id,
           u.fullname as responder_name
    FROM tbl_completed_reports cr
    LEFT JOIN tbl_incidents i ON cr.incident_id = i.incident_id
    LEFT JOIN tbl_users u ON cr.responder_id = u.user_id
    ORDER BY cr.submitted_at DESC
");

// Get theme preference from cookie - DEFAULT TO LIGHT MODE
$theme = isset($_COOKIE['admin_theme']) ? $_COOKIE['admin_theme'] : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>MDRRMO Admin - Command Center</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    
    <style>
        /* Theme Variables - LIGHT MODE DEFAULT */
        :root {
            --bg-primary: #f4f6f9;
            --bg-secondary: #ffffff;
            --bg-card: #ffffff;
            --border-color: #e5e7eb;
            --text-primary: #1f2937;
            --text-muted: #6b7280;
            --accent-yellow: #e67e22;
            --accent-yellow-dark: #d35400;
            --danger: #dc2626;
            --success: #059669;
            --info: #2563eb;
            --purple: #7c3aed;
            --sidebar-bg: #1e2a36;
            --modal-bg: #ffffff;
            --input-bg: #ffffff;
            --table-header-bg: #f9fafb;
            --chart-text: #1f2937;
            --stat-icon-bg: rgba(230, 126, 34, 0.1);
            --incident-item-bg: #ffffff;
            --incident-item-hover: #f9fafb;
        }
        
        [data-theme="dark"] {
            --bg-primary: #0a0a0a;
            --bg-secondary: #1a1a1a;
            --bg-card: #1e1e1e;
            --border-color: #2a2a2a;
            --text-primary: #e5e5e5;
            --text-muted: #9ca3af;
            --accent-yellow: #fbbf24;
            --accent-yellow-dark: #f59e0b;
            --danger: #ef4444;
            --success: #10b981;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --sidebar-bg: #1a1a1a;
            --modal-bg: #1e1e1e;
            --input-bg: #0a0a0a;
            --table-header-bg: #1a1a1a;
            --chart-text: #e5e5e5;
            --stat-icon-bg: rgba(251, 191, 36, 0.1);
            --incident-item-bg: #1a1a1a;
            --incident-item-hover: #1e1e1e;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: var(--bg-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            color: var(--text-primary);
            display: flex;
        }
        
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            min-height: 100vh;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            border-right: 2px solid var(--accent-yellow);
            overflow-y: auto;
            z-index: 100;
        }
        
        .sidebar .logo-section {
            padding: 24px 16px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .sidebar .logo-section i { color: var(--accent-yellow); font-size: 36px; margin-bottom: 8px; }
        .sidebar .logo-section h5 { color: white; margin: 8px 0 4px; }
        
        [data-theme="light"] .sidebar .logo-section h5 { color: white; }
        [data-theme="light"] .sidebar .logo-section small { color: #cbd5e1; }
        
        .sidebar .nav-item {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            cursor: pointer;
        }
        
        [data-theme="light"] .sidebar .nav-item { color: #cbd5e1; }
        
        .sidebar .nav-item i { width: 24px; margin-right: 12px; font-size: 18px; }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.05); color: var(--accent-yellow); }
        .sidebar .nav-item.active { background: rgba(251, 191, 36, 0.1); color: var(--accent-yellow); border-left-color: var(--accent-yellow); }
        .sidebar .nav-item.danger { color: var(--danger); }
        .sidebar .nav-item.danger:hover { background: rgba(239, 68, 68, 0.1); }
        
        .main-content {
            margin-left: 280px;
            padding: 24px;
            background: var(--bg-primary);
            min-height: 100vh;
            width: calc(100% - 280px);
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .page-header h2 { color: var(--text-primary); font-size: 24px; margin: 0; }
        .page-header h2 i { color: var(--accent-yellow); margin-right: 10px; }
        .datetime-display { color: var(--text-muted); font-size: 14px; }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .theme-toggle {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--accent-yellow);
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .theme-toggle:hover {
            background: var(--accent-yellow);
            color: var(--bg-primary);
        }
        
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 20px;
            border-left: 4px solid var(--accent-yellow);
        }
        
        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            background: var(--stat-icon-bg);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
        }
        
        .stat-card .stat-icon i { font-size: 20px; color: var(--accent-yellow); }
        .stat-card .stat-value { font-size: 28px; font-weight: 700; color: var(--text-primary); }
        .stat-card .stat-label { color: var(--text-muted); font-size: 12px; }
        
        .admin-card {
            background: var(--bg-card);
            border-radius: 16px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        
        .admin-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-secondary);
        }
        
        .admin-card-header h5 { margin: 0; color: var(--text-primary); font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .admin-card-header h5 i { color: var(--accent-yellow); }
        .admin-card-body { padding: 20px; }
        
        .incident-list { display: flex; flex-direction: column; gap: 12px; }
        
        .incident-item {
            background: var(--incident-item-bg);
            border-radius: 12px;
            padding: 16px;
            border-left: 4px solid var(--accent-yellow);
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .incident-item:hover { 
            transform: translateX(5px); 
            background: var(--incident-item-hover);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        /* Severity border colors only - subtle left border */
        .incident-item.border-severity-minor { border-left-color: #10b981; }
        .incident-item.border-severity-delayed { border-left-color: #f59e0b; }
        .incident-item.border-severity-immediate { border-left-color: #ef4444; }
        .incident-item.border-severity-dead { border-left-color: #6b7280; }
        
        /* Status border overrides */
        .incident-item.pending { border-left-color: var(--accent-yellow-dark); }
        .incident-item.dispatched { border-left-color: var(--info); }
        .incident-item.completed { border-left-color: var(--success); }
        
        .incident-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; flex-wrap: wrap; gap: 10px; }
        .incident-id { font-family: monospace; font-weight: 600; color: var(--accent-yellow); font-size: 14px; }
        
        .incident-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .incident-badge.pending { background: rgba(245, 158, 11, 0.2); color: var(--accent-yellow-dark); }
        .incident-badge.dispatched { background: rgba(59, 130, 246, 0.2); color: var(--info); }
        .incident-badge.completed { background: rgba(16, 185, 129, 0.2); color: var(--success); }
        
        .incident-type { font-size: 16px; font-weight: 600; color: var(--text-primary); margin-bottom: 6px; }
        
        .incident-meta {
            display: flex;
            gap: 16px;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 8px;
            flex-wrap: wrap;
        }
        
        .incident-meta i { margin-right: 4px; color: var(--accent-yellow); }
        .incident-actions { display: flex; gap: 8px; margin-top: 12px; }
        
        /* Severity Badges - Subtle and clean */
        .severity-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: transparent;
            border: 1px solid;
        }
        
        .severity-badge.minor { 
            color: #10b981; 
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.05);
        }
        .severity-badge.delayed { 
            color: #f59e0b; 
            border-color: #f59e0b;
            background: rgba(245, 158, 11, 0.05);
        }
        .severity-badge.immediate { 
            color: #ef4444; 
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.05);
        }
        .severity-badge.dead { 
            color: #6b7280; 
            border-color: #6b7280;
            background: rgba(107, 114, 128, 0.05);
        }
        
        [data-theme="light"] .severity-badge.minor { background: rgba(16, 185, 129, 0.08); }
        [data-theme="light"] .severity-badge.delayed { background: rgba(245, 158, 11, 0.08); }
        [data-theme="light"] .severity-badge.immediate { background: rgba(239, 68, 68, 0.08); }
        [data-theme="light"] .severity-badge.dead { background: rgba(107, 114, 128, 0.08); }
        
        .btn-primary {
            background: var(--accent-yellow);
            color: var(--bg-primary);
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-primary:hover { background: var(--accent-yellow-dark); }
        
        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary:hover { background: var(--bg-card); border-color: var(--accent-yellow); }
        
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .btn-icon {
            background: none;
            border: none;
            color: var(--text-muted);
            padding: 8px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-icon:hover { background: var(--border-color); color: var(--accent-yellow); }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .quick-action-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover { background: var(--bg-secondary); border-color: var(--accent-yellow); }
        .quick-action-btn i { font-size: 24px; color: var(--accent-yellow); }
        .quick-action-btn .action-text { font-weight: 500; color: var(--text-primary); }
        .quick-action-btn .action-sub { font-size: 12px; color: var(--text-muted); }
        
        .table-responsive { overflow-x: auto; }
        
        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .admin-table th {
            text-align: left;
            padding: 12px 16px;
            background: var(--table-header-bg);
            color: var(--accent-yellow);
            font-weight: 600;
            font-size: 13px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .admin-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .form-control, .form-select {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 14px;
            border-radius: 8px;
        }
        
        .form-control:focus, .form-select:focus { border-color: var(--accent-yellow); outline: none; box-shadow: none; }
        .form-label { color: var(--text-muted); margin-bottom: 6px; font-size: 13px; }
        
        /* Filter Bar Styles */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            padding: 16px;
            background: var(--bg-secondary);
            border-radius: 12px;
            border: 1px solid var(--border-color);
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .filter-group label {
            color: var(--text-muted);
            font-size: 12px;
            margin-right: 4px;
        }
        
        .filter-select, .filter-input {
            padding: 8px 12px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 13px;
            min-width: 150px;
        }
        
        .filter-input {
            min-width: 250px;
        }
        
        .filter-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: var(--stat-icon-bg);
            border-radius: 20px;
            font-size: 12px;
            color: var(--accent-yellow);
            cursor: pointer;
        }
        
        .filter-badge.active {
            background: var(--accent-yellow);
            color: var(--bg-primary);
        }
        
        .search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }
        
        .search-box input {
            width: 100%;
            padding: 10px 12px 10px 38px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
        }
        
        .filter-stats {
            margin-left: auto;
            color: var(--text-muted);
            font-size: 12px;
        }
        
        /* Analytics - Charts */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        .chart-container {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 16px;
        }
        
        .chart-container h6 {
            color: var(--text-primary);
            margin-bottom: 12px;
            font-size: 14px;
        }
        
        .chart-container canvas {
            max-height: 180px !important;
            width: 100% !important;
        }
        
        .chart-percentage {
            margin-top: 10px;
            font-size: 11px;
            color: var(--text-muted);
        }
        
        .period-filter {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
        }
        
        .period-btn {
            padding: 6px 14px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 12px;
        }
        
        .period-btn.active {
            background: var(--accent-yellow);
            color: var(--bg-primary);
            border-color: var(--accent-yellow);
        }
        
        /* Improved Live Map */
        .map-wrapper {
            border-radius: 16px;
            overflow: hidden;
            border: 2px solid var(--border-color);
            margin-bottom: 16px;
        }
        
        #liveMap {
            height: 500px;
            width: 100%;
            z-index: 1;
        }
        
        .map-controls-panel {
            display: flex;
            gap: 12px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        
        .map-stats {
            display: flex;
            gap: 16px;
            margin-left: auto;
        }
        
        .map-stat-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .map-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            padding: 12px 16px;
            background: var(--bg-card);
            border-radius: 12px;
            margin-top: 16px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-muted);
        }
        
        .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }
        
        .incident-popup {
            min-width: 220px;
        }
        
        .incident-popup h6 {
            color: var(--accent-yellow);
            margin-bottom: 8px;
        }
        
        .incident-popup .popup-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            font-size: 12px;
        }
        
        .incident-popup .popup-detail i {
            width: 16px;
            color: var(--accent-yellow);
        }
        
        .timeline { max-height: 400px; overflow-y: auto; }
        
        .timeline-item {
            display: flex;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .timeline-icon {
            width: 32px;
            height: 32px;
            background: var(--stat-icon-bg);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .timeline-icon i { color: var(--accent-yellow); font-size: 14px; }
        .timeline-content { flex: 1; }
        .timeline-title { font-weight: 500; color: var(--text-primary); font-size: 13px; }
        .timeline-meta { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
        
        .modal-content { 
            background: var(--modal-bg); 
            color: var(--text-primary); 
            border: 1px solid var(--border-color); 
        }
        .modal-header { border-bottom: 1px solid var(--border-color); background: var(--bg-secondary); }
        .modal-header .modal-title { color: var(--accent-yellow); }
        .modal-header .btn-close { filter: invert(1); }
        [data-theme="light"] .modal-header .btn-close { filter: none; }
        .modal-footer { border-top: 1px solid var(--border-color); }
        
        .notification-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
            max-width: 350px;
        }
        
        .toast-notification {
            background: var(--bg-secondary);
            border-left: 4px solid var(--accent-yellow);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 10px;
            display: flex;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .upload-area:hover { border-color: var(--accent-yellow); background: var(--stat-icon-bg); }
        .upload-area i { font-size: 40px; color: var(--accent-yellow); margin-bottom: 12px; }
        
        /* Report Form Styles */
        .report-editor {
            background: var(--input-bg);
            color: var(--text-primary);
            border-radius: 12px;
            padding: 20px;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        [data-theme="light"] .report-editor { background: white; color: #1f2937; }
        
        .form-page { background: var(--input-bg); color: var(--text-primary); }
        [data-theme="light"] .form-page { background: white; color: #1f2937; }
        
        .form-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            background: var(--input-bg);
        }
        
        [data-theme="light"] .form-table { background: white; }
        
        .form-table th {
            background: var(--accent-yellow);
            color: var(--bg-primary);
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            text-align: left;
        }
        
        .form-table td {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        .label-cell {
            font-weight: 600;
            background: var(--stat-icon-bg);
            width: 40%;
            font-size: 12px;
        }
        
        [data-theme="light"] .label-cell { background: #fef9e6; }
        
        .signature-container {
            background: white;
            border-radius: 8px;
            padding: 8px;
        }
        
        .signature-canvas {
            width: 100%;
            height: 70px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: white;
        }
        
        .sig-buttons { display: flex; gap: 6px; margin-top: 6px; }
        .sig-btn { padding: 4px 10px; background: #e0e0e0; border: none; border-radius: 6px; font-size: 11px; cursor: pointer; }
        
        .injury-map-container {
            background: white;
            border-radius: 8px;
            padding: 12px;
        }
        
        #bodyCanvas {
            width: 100%;
            height: auto;
            background: white;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        
        .draw-tools {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
        }
        
        .tool-btn {
            padding: 6px 10px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
        }
        
        .tool-btn.active {
            background: var(--accent-yellow);
            color: white;
            border-color: var(--accent-yellow);
        }
        
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 12px;
        }
        
        .photo-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #ddd;
        }
        
        .photo-item img { width: 100%; height: 100%; object-fit: cover; }
        
        .photo-remove {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 24px;
            height: 24px;
            border-radius: 12px;
            background: rgba(239,68,68,0.9);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        .add-photo-btn {
            width: 100%;
            padding: 12px;
            background: #f5f5f5;
            border: 2px dashed #ddd;
            border-radius: 8px;
            color: #666;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .two-column-layout { display: flex; gap: 16px; flex-wrap: wrap; }
        .left-column, .right-column { flex: 1; min-width: 300px; }
        
        .refusal-text {
            font-size: 11px;
            line-height: 1.4;
            background: var(--stat-icon-bg);
            padding: 12px;
            border-radius: 6px;
        }
        
        .filipino-text {
            font-size: 10px;
            color: var(--text-muted);
            font-style: italic;
            display: block;
        }
        
        /* Tab Buttons for Access Modal */
        .tab-btn {
            background: none;
            border: none;
            padding: 10px 20px;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
            border-radius: 8px 8px 0 0;
        }
        
        .tab-btn:hover {
            color: var(--accent-yellow);
            background: var(--stat-icon-bg);
        }
        
        .tab-btn.active {
            color: var(--accent-yellow);
            border-bottom: 2px solid var(--accent-yellow);
        }
        
        /* Access Log Item */
        .access-log-item {
            transition: all 0.2s ease;
            border-radius: 8px;
            margin-bottom: 12px;
            padding: 12px;
            background: var(--bg-card);
            border-left: 3px solid var(--accent-yellow);
        }
        
        .access-log-item:hover {
            transform: translateX(5px);
        }
        
        /* Media Badges */
        .media-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
        }
        
        .media-badge.photo {
            background: rgba(251, 191, 36, 0.2);
            color: var(--accent-yellow);
        }
        
        .media-badge.video {
            background: rgba(37, 99, 235, 0.2);
            color: var(--info);
        }
        
        /* New Incident Alert */
        .new-incident-alert {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1050;
            max-width: 350px;
            animation: slideDown 0.5s ease;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        /* Print Styles */
        @media print {
            .sidebar, .btn-primary, .btn-secondary, .btn-icon, .quick-actions,
            .stats-grid, .notification-toast, .modal-footer button,
            .incident-actions, .period-filter, .upload-area, .menu-toggle,
            .admin-card-header .btn-secondary, .admin-card-header .btn-primary,
            .sig-buttons, .add-photo-btn, .photo-remove, .draw-tools,
            .theme-toggle, .header-actions, .filter-bar {
                display: none !important;
            }
            
            .main-content { margin-left: 0 !important; width: 100% !important; padding: 0 !important; background: white !important; }
            .report-viewer, .report-editor, .form-page { background: white !important; color: black !important; }
            .form-table { width: 100% !important; border-collapse: collapse !important; }
            .form-table td, .form-table th { border: 1px solid #000 !important; padding: 6px 4px !important; color: black !important; }
            .section-header, .form-table th { background: #e67e22 !important; color: white !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .label-cell { background: #fef9e6 !important; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            #bodyCanvas { border: 1px solid #000 !important; }
            @page { size: A4; margin: 1.5cm; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
        
        @media (max-width: 768px) {
            .sidebar { left: -280px; transition: left 0.3s; }
            .sidebar.open { left: 0; }
            .main-content { margin-left: 0; width: 100%; }
            .menu-toggle {
                display: block !important;
                position: fixed;
                top: 20px;
                left: 20px;
                z-index: 101;
                background: var(--accent-yellow);
                color: var(--bg-primary);
                border: none;
                width: 45px;
                height: 45px;
                border-radius: 12px;
                font-size: 20px;
                cursor: pointer;
            }
            .charts-grid { grid-template-columns: 1fr; }
            .two-column-layout { flex-direction: column; }
            .quick-actions { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .filter-select, .filter-input, .search-box { width: 100%; }
        }
        
        .menu-toggle { display: none; }
        
        .alert-info {
            background: var(--stat-icon-bg);
            color: var(--accent-yellow);
            padding: 12px 16px;
            border-radius: 8px;
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-top: 16px;
            text-align: center;
        }
        
        .summary-stats h4 { color: var(--accent-yellow); font-size: 24px; margin-bottom: 4px; }
        .summary-stats p { color: var(--text-muted); font-size: 12px; }
        
        .badge-new {
            background: var(--danger);
            color: white;
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 8px;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Severity Indicator Dot */
        .severity-indicator {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .severity-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .severity-dot.minor { background: #10b981; }
        .severity-dot.delayed { background: #f59e0b; }
        .severity-dot.immediate { background: #ef4444; }
        .severity-dot.dead { background: #6b7280; }
        
        /* Time Ago */
        .time-ago-text {
            font-size: 11px;
            color: var(--text-muted);
        }
        
        /* List Group Item */
        .list-group-item {
            background: var(--card-black, #1a1a1a);
            margin-bottom: 10px;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid var(--border-color);
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    
    <!-- Fixed Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="logo-section">
            <i class="fas fa-shield-hal"></i>
            <h5>MDRRMO Bongabon</h5>
            <small style="color: var(--text-muted);">Admin Command Center</small>
            <div style="margin-top: 10px;">
                <span style="background: var(--accent-yellow); color: var(--bg-primary); padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 12px;">
                    <i class="fas fa-crown"></i> Administrator
                </span>
            </div>
        </div>
        
        <nav class="mt-3">
            <a class="nav-item active" data-tab="dashboard">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="nav-item" data-tab="analytics">
                <i class="fas fa-chart-pie"></i> Analytics
            </a>
            <a class="nav-item" data-tab="reports">
                <i class="fas fa-file-alt"></i> All Reports
            </a>
            <a class="nav-item" data-tab="responders">
                <i class="fas fa-users"></i> Responders
            </a>
            <a class="nav-item" data-tab="map">
                <i class="fas fa-map-marked-alt"></i> Live Map
            </a>
            <a class="nav-item" data-tab="completed">
                <i class="fas fa-check-circle"></i> Completed
                <span class="badge" style="margin-left: auto; background: var(--accent-yellow); color: black;"><?= $stats['completed_reports'] ?></span>
            </a>
            <div style="margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                <a class="nav-item danger" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <h2>
                <i class="fas fa-tachometer-alt"></i>
                <span id="pageTitle">Dashboard</span>
            </h2>
            <div class="header-actions">
                <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                </button>
                <span class="datetime-display" id="datetimeDisplay"></span>
            </div>
        </div>
        
        <div class="notification-toast" id="notificationToast"></div>
        <div id="newIncidentAlert" class="new-incident-alert" style="display: none;">
            <div class="alert alert-danger d-flex align-items-center">
                <i class="fas fa-bell me-2"></i>
                <div>
                    <strong>New Incident Reported!</strong><br>
                    <small>A new incident has been added to the system.</small>
                </div>
                <button type="button" class="btn-close ms-auto" onclick="this.parentElement.parentElement.style.display='none'"></button>
            </div>
        </div>
        
        <!-- Dashboard Tab -->
        <div class="tab-pane active" id="dashboard-tab">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-value" id="statTotalReports"><?= $stats['total_reports'] ?></div>
                    <div class="stat-label">Total Reports</div>
                </div>
                <div class="stat-card" style="border-left-color: var(--accent-yellow-dark);">
                    <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1);"><i class="fas fa-clock" style="color: var(--accent-yellow-dark);"></i></div>
                    <div class="stat-value" id="statPending"><?= $stats['pending'] ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card" style="border-left-color: var(--info);">
                    <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1);"><i class="fas fa-truck" style="color: var(--info);"></i></div>
                    <div class="stat-value" id="statDispatched"><?= $stats['dispatched'] ?></div>
                    <div class="stat-label">In Progress</div>
                </div>
                <div class="stat-card" style="border-left-color: var(--success);">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1);"><i class="fas fa-check-circle" style="color: var(--success);"></i></div>
                    <div class="stat-value" id="statCompleted"><?= $stats['completed'] ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-user-shield"></i></div>
                    <div class="stat-value" id="statTotalResponders"><?= $stats['total_responders'] ?></div>
                    <div class="stat-label">Responders</div>
                </div>
                <div class="stat-card" style="border-left-color: var(--success);">
                    <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1);"><i class="fas fa-user-check" style="color: var(--success);"></i></div>
                    <div class="stat-value" id="statActiveResponders"><?= $stats['active_responders'] ?></div>
                    <div class="stat-label">Active</div>
                </div>
            </div>
            
            <div class="quick-actions">
                <div class="quick-action-btn" onclick="switchTab('completed')">
                    <i class="fas fa-folder-open"></i>
                    <div>
                        <div class="action-text">View Reports</div>
                        <div class="action-sub">Browse completed reports</div>
                    </div>
                </div>
                <div class="quick-action-btn" onclick="switchTab('map')">
                    <i class="fas fa-map"></i>
                    <div>
                        <div class="action-text">Live Map</div>
                        <div class="action-sub">Track incidents in real-time</div>
                    </div>
                </div>
                <div class="quick-action-btn" onclick="switchTab('analytics')">
                    <i class="fas fa-chart-bar"></i>
                    <div>
                        <div class="action-text">Analytics</div>
                        <div class="action-sub">View statistics & trends</div>
                    </div>
                </div>
            </div>
            
            <div class="admin-card">
                <div class="admin-card-header">
                    <h5><i class="fas fa-bell"></i> Recent Incidents</h5>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn-secondary btn-sm" onclick="showCreateIncidentModal()">
                            <i class="fas fa-plus"></i> New Incident
                        </button>
                        <button class="btn-secondary btn-sm" onclick="switchTab('reports')">
                            View All <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
                <div class="admin-card-body">
                    <div class="incident-list" id="recentIncidentsList">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Analytics Tab -->
        <div class="tab-pane" id="analytics-tab">
            <div class="period-filter">
                <button class="period-btn active" data-period="weekly">Weekly</button>
                <button class="period-btn" data-period="monthly">Monthly</button>
                <button class="period-btn" data-period="yearly">Yearly</button>
            </div>
            
            <div class="charts-grid">
                <div class="chart-container">
                    <h6><i class="fas fa-chart-pie" style="color: var(--accent-yellow);"></i> Report Status</h6>
                    <canvas id="statusChart"></canvas>
                    <div id="statusPercentages" class="chart-percentage"></div>
                </div>
                <div class="chart-container">
                    <h6><i class="fas fa-chart-pie" style="color: var(--accent-yellow);"></i> Incident Types</h6>
                    <canvas id="typeChart"></canvas>
                    <div id="typePercentages" class="chart-percentage"></div>
                </div>
                <div class="chart-container">
                    <h6><i class="fas fa-chart-bar" style="color: var(--accent-yellow);"></i> Daily Trend</h6>
                    <canvas id="trendChart"></canvas>
                </div>
                <div class="chart-container">
                    <h6><i class="fas fa-chart-pie" style="color: var(--accent-yellow);"></i> Severity</h6>
                    <canvas id="severityChart"></canvas>
                    <div id="severityPercentages" class="chart-percentage"></div>
                </div>
            </div>
            
            <div class="summary-stats" id="analyticsSummary"></div>
        </div>
        
        <!-- All Reports Tab with Enhanced Filtering -->
        <div class="tab-pane" id="reports-tab">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h5><i class="fas fa-file-alt"></i> All Reports</h5>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn-secondary btn-sm" onclick="showCreateIncidentModal()">
                            <i class="fas fa-plus"></i> New Incident
                        </button>
                        <button class="btn-primary btn-sm" onclick="showFullReportEditor()">
                            <i class="fas fa-plus"></i> Full Report
                        </button>
                    </div>
                </div>
                <div class="admin-card-body">
                    <!-- Enhanced Filter Bar -->
                    <div class="filter-bar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="reportSearchInput" placeholder="Search by ID, type, location, reporter..." onkeyup="filterReports()">
                        </div>
                        
                        <div class="filter-group">
                            <label><i class="fas fa-tag"></i> Type:</label>
                            <select id="filterType" class="filter-select" onchange="filterReports()">
                                <option value="all">All Types</option>
                                <option value="Medical">Medical</option>
                                <option value="Trauma">Trauma</option>
                                <option value="Fire">Fire</option>
                                <option value="Flood">Flood</option>
                                <option value="Vehicular Accident">Vehicular Accident</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label><i class="fas fa-exclamation-triangle"></i> Severity:</label>
                            <select id="filterSeverity" class="filter-select" onchange="filterReports()">
                                <option value="all">All</option>
                                <option value="minor">Minor</option>
                                <option value="delayed">Delayed</option>
                                <option value="immediate">Immediate</option>
                                <option value="dead">Dead</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label><i class="fas fa-circle"></i> Status:</label>
                            <select id="filterStatus" class="filter-select" onchange="filterReports()">
                                <option value="all">All</option>
                                <option value="pending">Pending</option>
                                <option value="dispatched">Dispatched</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label><i class="fas fa-calendar"></i> Date:</label>
                            <select id="filterDate" class="filter-select" onchange="filterReports()">
                                <option value="all">All Time</option>
                                <option value="today">Today</option>
                                <option value="yesterday">Yesterday</option>
                                <option value="week">This Week</option>
                                <option value="month">This Month</option>
                            </select>
                        </div>
                        
                        <div class="filter-stats">
                            <span id="filteredCount">0</span> reports
                        </div>
                        
                        <button class="filter-badge" onclick="clearFilters()">
                            <i class="fas fa-times"></i> Clear Filters
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="admin-table" id="reportsTable">
                            <thead>
                                <tr>
                                    <th onclick="sortTable('tracking')" style="cursor: pointer;">
                                        Tracking ID <i class="fas fa-sort"></i>
                                    </th>
                                    <th onclick="sortTable('type')" style="cursor: pointer;">
                                        Type <i class="fas fa-sort"></i>
                                    </th>
                                    <th onclick="sortTable('severity')" style="cursor: pointer;">
                                        Severity <i class="fas fa-sort"></i>
                                    </th>
                                    <th>Location</th>
                                    <th onclick="sortTable('status')" style="cursor: pointer;">
                                        Status <i class="fas fa-sort"></i>
                                    </th>
                                    <th onclick="sortTable('date')" style="cursor: pointer;">
                                        Created <i class="fas fa-sort"></i>
                                    </th>
                                    <th>Media</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="allIncidentsTableBody">
                                <!-- Populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div id="noResultsMessage" class="empty-state" style="display: none;">
                        <i class="fas fa-search"></i>
                        <h5>No reports found</h5>
                        <p>Try adjusting your filters or create a new report.</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Responders Tab -->
        <div class="tab-pane" id="responders-tab">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h5><i class="fas fa-users"></i> Responder Accounts</h5>
                    <button class="btn-primary btn-sm" onclick="showCreateResponderModal()">
                        <i class="fas fa-user-plus"></i> Add Responder
                    </button>
                </div>
                <div class="admin-card-body">
                    <div class="table-responsive">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Type</th>
                                    <th>Badge #</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="respondersTableBody">
                                <?php if ($responders && $responders->num_rows > 0): 
                                    while($r = $responders->fetch_assoc()): 
                                        $hasLocation = !empty($r['latitude']) && !empty($r['longitude']);
                                        $locationAge = '';
                                        if ($hasLocation && !empty($r['location_updated'])) {
                                            $updated = strtotime($r['location_updated']);
                                            $diff = time() - $updated;
                                            if ($diff < 60) $locationAge = 'Just now';
                                            else if ($diff < 3600) $locationAge = floor($diff/60) . 'm ago';
                                            else if ($diff < 86400) $locationAge = floor($diff/3600) . 'h ago';
                                            else $locationAge = date('M d', $updated);
                                        }
                                ?>
                                <tr>
                                    <td>#<?= str_pad($r['user_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                    <td><strong><?= htmlspecialchars($r['username']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['fullname']) ?></td>
                                    <td><span class="incident-badge" style="background: rgba(59,130,246,0.2); color: #3b82f6;"><?= ucfirst($r['responder_type'] ?? 'Responder') ?></span></td>
                                    <td><code><?= htmlspecialchars($r['badge_number'] ?? 'N/A') ?></code></td>
                                    <td>
                                        <?php if ($hasLocation): ?>
                                            <span class="media-badge photo" onclick="viewResponderLocation(<?= $r['latitude'] ?>, <?= $r['longitude'] ?>, '<?= htmlspecialchars($r['fullname']) ?>')" style="cursor: pointer;">
                                                <i class="fas fa-map-marker-alt"></i> <?= $locationAge ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">No location</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="incident-badge <?= $r['is_active'] ? 'completed' : '' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                                    <td>
                                        <button class="btn-icon" onclick="toggleResponderStatus(<?= $r['user_id'] ?>, <?= $r['is_active'] ?>)" title="Toggle Status">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                        <button class="btn-icon" onclick="resetResponderPassword(<?= $r['user_id'] ?>)" title="Reset Password">
                                            <i class="fas fa-key"></i>
                                        </button>
                                        <button class="btn-icon danger" onclick="deleteResponder(<?= $r['user_id'] ?>, '<?= htmlspecialchars($r['fullname']) ?>')" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert-info" style="margin-top: 20px;">
                        <i class="fas fa-info-circle"></i> <strong>Default Password:</strong> <code style="background: var(--bg-primary); padding: 2px 8px; border-radius: 4px;">responder123</code>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Map Tab -->
        <div class="tab-pane" id="map-tab">
            <div class="map-wrapper">
                <div id="liveMap"></div>
            </div>
            
            <div class="map-controls-panel">
                <button class="btn-primary btn-sm" onclick="refreshMap()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <button class="btn-secondary btn-sm" onclick="centerMap()">
                    <i class="fas fa-crosshairs"></i> Center
                </button>
                <button class="btn-secondary btn-sm" onclick="locateMe()">
                    <i class="fas fa-location-dot"></i> My Location
                </button>
                <div class="map-stats">
                    <div class="map-stat-item">
                        <i class="fas fa-map-marker-alt" style="color: var(--danger);"></i>
                        <span id="mapIncidentCount">0</span> incidents
                    </div>
                    <div class="map-stat-item">
                        <i class="fas fa-user-shield" style="color: var(--info);"></i>
                        <span id="mapResponderCount">0</span> responders
                    </div>
                </div>
            </div>
            
            <div class="map-legend">
                <div class="legend-item"><span class="legend-dot" style="background: #10b981;"></span> Minor</div>
                <div class="legend-item"><span class="legend-dot" style="background: #f59e0b;"></span> Delayed</div>
                <div class="legend-item"><span class="legend-dot" style="background: #ef4444;"></span> Immediate</div>
                <div class="legend-item"><span class="legend-dot" style="background: #6b7280;"></span> Dead</div>
                <div class="legend-item"><span class="legend-dot" style="background: #3b82f6;"></span> Responder</div>
                <div class="legend-item"><span class="legend-dot" style="background: #10b981;"></span> Your Location</div>
            </div>
        </div>
        
        <!-- Completed Reports Tab -->
        <div class="tab-pane" id="completed-tab">
            <div class="admin-card">
                <div class="admin-card-header">
                    <h5><i class="fas fa-check-circle"></i> Completed Reports</h5>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn-secondary btn-sm" onclick="showUploadReportModal()">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                        <button class="btn-primary btn-sm" onclick="showFullReportEditor()">
                            <i class="fas fa-plus"></i> New Report
                        </button>
                    </div>
                </div>
                <div class="admin-card-body">
                    <!-- Search for completed reports -->
                    <div class="filter-bar" style="margin-bottom: 16px;">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="completedSearchInput" placeholder="Search completed reports..." onkeyup="filterCompletedReports()">
                        </div>
                        <select id="completedFilterType" class="filter-select" onchange="filterCompletedReports()">
                            <option value="all">All Types</option>
                            <option value="Medical">Medical</option>
                            <option value="Trauma">Trauma</option>
                            <option value="Fire">Fire</option>
                            <option value="Flood">Flood</option>
                            <option value="Vehicular Accident">Vehicular Accident</option>
                        </select>
                        <button class="filter-badge" onclick="clearCompletedFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                        <span class="filter-stats" id="completedFilteredCount">0</span>
                    </div>
                    
                    <div class="incident-list" id="completedReportsList">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Incident Modal (Simple) -->
    <div class="modal fade" id="createIncidentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Create New Incident</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Incident Type *</label>
                        <select id="newIncidentType" class="form-select">
                            <option value="Medical">Medical Emergency</option>
                            <option value="Trauma">Trauma / Injury</option>
                            <option value="Fire">Fire Incident</option>
                            <option value="Flood">Flood Rescue</option>
                            <option value="Vehicular Accident">Vehicular Accident</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Severity *</label>
                        <select id="newSeverity" class="form-select">
                            <option value="low">MINOR</option>
                            <option value="moderate">DELAYED</option>
                            <option value="high">IMMEDIATE</option>
                            <option value="dead">DEAD</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Location *</label>
                        <input type="text" id="newLocationAddress" class="form-control" placeholder="Enter location">
                        <button type="button" class="btn-secondary btn-sm mt-2" onclick="getCurrentLocationForNew()">
                            <i class="fas fa-location-dot"></i> Use Current Location
                        </button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reporter Name</label>
                        <input type="text" id="newReporterName" class="form-control" value="<?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reporter Phone</label>
                        <input type="text" id="newReporterPhone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea id="newDescription" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn-primary" onclick="createNewIncident()">Create Incident</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Full Report Editor Modal -->
    <div class="modal fade" id="fullReportEditorModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> <span id="reportEditorTitle">Create New Report</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="report-editor">
                        <div class="form-page">
                            <div style="margin-bottom: 20px; display: flex; gap: 15px;">
                                <div style="flex: 1;">
                                    <label class="form-label">Tracking ID *</label>
                                    <input type="text" id="reportTrackingId" class="form-control">
                                </div>
                                <div style="flex: 1;">
                                    <label class="form-label">Report Name</label>
                                    <input type="text" id="reportName" class="form-control">
                                </div>
                            </div>
                            
                            <div class="two-column-layout">
                                <div class="left-column">
                                    <table class="form-table">
                                        <tr><th colspan="2">INCIDENT DETAILS</th></tr>
                                        <tr><td class="label-cell">Date of Incident:</td><td><input type="date" id="incidentDate" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Time of Call:</td><td><input type="time" id="callTime" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Time of Incident:</td><td><input type="time" id="incidentTime" class="form-control"></td></tr>
                                        <tr><td class="label-cell">At Scene:</td><td><input type="time" id="atScene" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Incident/Transfer/Purpose:</td><td><input type="text" id="incidentPurpose" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Depart Scene / At Hospital:</td><td><input type="time" id="departScene" class="form-control" style="margin-bottom:5px;"><input type="time" id="atHospital" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Place of Incident:</td><td><input type="text" id="placeIncident" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Handover / Back to Base:</td><td><input type="time" id="handover" class="form-control" style="margin-bottom:5px;"><input type="time" id="backToBase" class="form-control"></td></tr>
                                    </table>
                                    
                                    <table class="form-table">
                                        <tr><th colspan="2">PATIENT'S INFORMATION</th></tr>
                                        <tr><td class="label-cell">Name:</td><td><input type="text" id="patientName" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Emergency Contact:</td><td><input type="text" id="emergencyContact" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Age:</td><td><input type="number" id="patientAge" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Gender:</td><td><select id="patientGender" class="form-control"><option>Male</option><option>Female</option></select></td></tr>
                                        <tr><td class="label-cell">Address:</td><td><input type="text" id="patientAddress" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Contact Number:</td><td><input type="text" id="emergencyNumber" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Patient Signature:</td><td><div class="signature-container"><canvas id="patientSigCanvas" class="signature-canvas" width="280" height="70"></canvas><div class="sig-buttons"><button type="button" class="sig-btn" onclick="clearSignature('patientSigCanvas')">Clear</button></div></div></td></tr>
                                        <tr><td class="label-cell">Emergency Signature:</td><td><div class="signature-container"><canvas id="emergencySigCanvas" class="signature-canvas" width="280" height="70"></canvas><div class="sig-buttons"><button type="button" class="sig-btn" onclick="clearSignature('emergencySigCanvas')">Clear</button></div></div></td></tr>
                                    </table>
                                    
                                    <table class="form-table">
                                        <tr><th colspan="2">INJURY MAP</th></tr>
                                        <tr><td colspan="2">
                                            <div class="injury-map-container">
                                                <div class="draw-tools">
                                                    <button type="button" id="drawBtn" class="tool-btn active">Draw</button>
                                                    <button type="button" id="eraseBtn" class="tool-btn">Erase</button>
                                                    <button type="button" id="undoBtn" class="tool-btn">Undo</button>
                                                    <button type="button" id="clearCanvasBtn" class="tool-btn">Clear</button>
                                                </div>
                                                <div style="display: flex; gap: 8px; margin-bottom: 8px;">
                                                    <input type="color" id="penColor" value="#ff0000" style="width: 50px; height: 35px;">
                                                    <input type="range" id="brushSize" min="2" max="20" value="5" style="flex: 1;">
                                                </div>
                                                <canvas id="bodyCanvas" width="360" height="420"></canvas>
                                                <input type="hidden" id="bodyImageData">
                                            </div>
                                        </td></tr>
                                    </table>
                                    
                                    <table class="form-table">
                                        <tr><th colspan="2">CHIEF COMPLAINT</th></tr>
                                        <tr><td colspan="2"><textarea id="chiefComplaint" class="form-control" rows="3"></textarea></td></tr>
                                    </table>
                                </div>
                                
                                <div class="right-column">
                                    <table class="form-table">
                                        <tr><th colspan="2">VITAL SIGNS & HISTORY</th></tr>
                                        <tr><td class="label-cell">Signs & Symptoms:</td><td><textarea id="symptoms" class="form-control" rows="2"></textarea></td></tr>
                                        <tr><td class="label-cell">Blood Pressure:</td><td><input type="text" id="bp" class="form-control" placeholder="___ / ___"></td></tr>
                                        <tr><td class="label-cell">Allergy:</td><td><input type="text" id="allergy" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Pulse Rate:</td><td><input type="text" id="pulse" class="form-control" placeholder="___ bpm"></td></tr>
                                        <tr><td class="label-cell">Medications:</td><td><input type="text" id="medications" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Respiratory Rate:</td><td><input type="text" id="respiratory" class="form-control" placeholder="___ breaths/min"></td></tr>
                                        <tr><td class="label-cell">Past Medical History:</td><td><input type="text" id="pastHistory" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Body Temperature:</td><td><input type="text" id="temperature" class="form-control" placeholder="___ °C"></td></tr>
                                        <tr><td class="label-cell">Last Intake/Output:</td><td><input type="text" id="lastIntake" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Events Leading to Injury:</td><td><textarea id="events" class="form-control" rows="2"></textarea></td></tr>
                                    </table>
                                    
                                    <table class="form-table">
                                        <tr><th colspan="2">MANAGEMENT / INTERVENTION</th></tr>
                                        <tr><td colspan="2"><textarea id="actionsGiven" class="form-control" rows="3"></textarea></td></tr>
                                    </table>
                                    
                                    <table class="form-table">
                                        <tr><th colspan="2">REFUSAL OF TREATMENT</th></tr>
                                        <tr><td colspan="2" class="refusal-text">I, the undersigned, have been properly informed about my condition and the medical services I need, but for personal reasons I have refused transfer or treatment...</td></tr>
                                        <tr><td class="label-cell">Witness:</td><td><input type="text" id="refusalWitness" class="form-control"></td></tr>
                                        <tr><td class="label-cell">Date Signed:</td><td><input type="date" id="refusalDate" class="form-control"></td></tr>
                                    </table>
                                    
                                    <table class="form-table">
                                        <tr><th>PROVIDER'S INFORMATION</th><th>RECEIVING FACILITY</th></tr>
                                        <tr>
                                            <td>
                                                Crew 1: <input type="text" id="crew1" class="form-control" style="margin-bottom:5px;" value="<?= htmlspecialchars($_SESSION['fullname'] ?? '') ?>"><br>
                                                Crew 2: <input type="text" id="crew2" class="form-control" style="margin-bottom:5px;"><br>
                                                Crew 3: <input type="text" id="crew3" class="form-control" style="margin-bottom:5px;"><br>
                                                Crew 4: <input type="text" id="crew4" class="form-control" style="margin-bottom:5px;"><br>
                                                Crew 5: <input type="text" id="crew5" class="form-control" style="margin-bottom:5px;"><br>
                                                Driver: <input type="text" id="driver" class="form-control" style="margin-bottom:5px;"><br>
                                                Vehicle: <input type="text" id="vehicle" class="form-control">
                                            </td>
                                            <td>
                                                Place/Hospital:<br><textarea id="receivingPlace" class="form-control" rows="2"></textarea><br>
                                                Receiving Person:<br><input type="text" id="receivingPerson" class="form-control" style="margin-bottom:5px;"><br>
                                                Name & Signature:<br>
                                                <div class="signature-container">
                                                    <canvas id="providerSigCanvas" class="signature-canvas" width="280" height="70"></canvas>
                                                    <div class="sig-buttons">
                                                        <button type="button" class="sig-btn" onclick="clearSignature('providerSigCanvas')">Clear</button>
                                                    </div>
                                                </div>
                                                <input type="text" id="receivingSignName" class="form-control" style="margin-top:5px;" placeholder="Name and Signature">
                                             </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            
                            <!-- Photos Section -->
                            <div style="margin-top: 20px;">
                                <div style="background: var(--accent-yellow); color: var(--bg-primary); padding: 10px; border-radius: 8px; text-align: center; font-weight: bold; margin-bottom: 15px;">
                                    <i class="fas fa-camera"></i> INCIDENT PHOTOGRAPHS
                                </div>
                                <div class="photo-grid" id="incidentImageGallery"></div>
                                <button class="add-photo-btn" onclick="document.getElementById('incidentImagesInput').click()">
                                    <i class="fas fa-plus"></i> Add Photos
                                </button>
                                <input type="file" id="incidentImagesInput" accept="image/*" multiple style="display:none;">
                                <input type="hidden" id="incidentImagesData" value="[]">
                                <div style="text-align: center; margin-top: 8px; font-size: 11px; color: var(--text-muted);">
                                    <span id="imageCount">0 photo(s)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn-primary" id="saveFullReportBtn">Save Report</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Upload Report Modal -->
    <div class="modal fade" id="uploadReportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-upload"></i> Upload Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="upload-area" onclick="document.getElementById('reportFileInput').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <h5>Click to upload file</h5>
                        <p style="color: var(--text-muted);">Excel, Word, or Image files</p>
                    </div>
                    <input type="file" id="reportFileInput" accept=".xlsx,.xls,.doc,.docx,.jpg,.jpeg,.png,.pdf" style="display: none;" onchange="handleFileUpload(this)">
                    <div id="uploadPreview" style="margin-top: 20px; display: none;">
                        <p><strong>Selected file:</strong> <span id="selectedFileName"></span></p>
                        <div class="alert-info">
                            <i class="fas fa-info-circle"></i> File will be parsed and converted to a report.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn-primary" id="processUploadBtn" disabled>Process & Create</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Access Log Modal (Enhanced with Tabs) -->
    <div class="modal fade" id="accessLogModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-history"></i> Access Log: <span id="accessLogTrackingId"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div style="display: flex; gap: 10px; border-bottom: 1px solid var(--border-color); margin-bottom: 20px;">
                        <button type="button" id="tabActivityBtn" class="tab-btn active">
                            <i class="fas fa-history"></i> Activity Log
                        </button>
                        <button type="button" id="tabUsersBtn" class="tab-btn">
                            <i class="fas fa-users"></i> Users with Access
                        </button>
                    </div>
                    <div id="activityTabPanel" style="display: block;">
                        <div class="timeline" id="accessLogTimeline">Loading...</div>
                    </div>
                    <div id="usersTabPanel" style="display: none;">
                        <div id="usersWithAccessList">Loading...</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Responder Modal -->
    <div class="modal fade" id="createResponderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add Responder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createResponderForm">
                        <div class="mb-3"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Full Name *</label><input type="text" name="fullname" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Responder Type *</label><select name="responder_type" class="form-select" required><option value="medic">Medic / EMT</option><option value="firefighter">Firefighter</option><option value="rescuer">Rescuer</option><option value="driver">Driver</option></select></div>
                        <div class="mb-3"><label class="form-label">Badge Number *</label><input type="text" name="badge_number" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                        <div class="mb-3"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn-primary" onclick="createResponder()">Create</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Incident View Modal -->
    <div class="modal fade" id="incidentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> Incident Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="incidentModalBody">Loading...</div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Completed Report View Modal -->
    <div class="modal fade" id="completedReportModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-check-circle"></i> Completed Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="completedReportBody">Loading...</div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let liveMap = null;
        let mapMarkers = [];
        let responderMarkers = [];
        let userLocationMarker = null;
        let charts = {};
        let currentPeriod = 'weekly';
        let editingIncidentId = null;
        let incidentImages = [];
        let sigPads = {};
        let lastIncidentCount = 0;
        let autoRefreshInterval = null;
        let newIncidentLat = null;
        let newIncidentLng = null;
        let allIncidentsData = [];
        let allCompletedReportsData = [];
        let currentAccessIncidentId = null;
        let currentSortColumn = 'date';
        let currentSortDirection = 'desc';
        let videoTableExists = <?= $video_table_exists ? 'true' : 'false' ?>;
        
        // Injury Map Variables
        let drawingLayer, drawCtx, historyStack = [], currentMode = 'draw', currentColor = '#ff0000', brushSize = 5;
        let drawing = false, lastX, lastY, backgroundImage = null;
        
        // ============================================
        // TIME AGO FUNCTION
        // ============================================
        
        function formatTimeAgo(timestamp) {
            if (!timestamp) return 'Just now';
            
            const now = Math.floor(Date.now() / 1000);
            const incidentTime = Math.floor(new Date(timestamp).getTime() / 1000);
            const diff = now - incidentTime;
            
            if (diff < 0) return 'Just now';
            
            const seconds = diff;
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);
            
            if (seconds < 5) return 'Just now';
            if (seconds < 60) return `${seconds} second${seconds !== 1 ? 's' : ''} ago`;
            if (minutes < 60) return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;
            if (hours < 24) return `${hours} hour${hours !== 1 ? 's' : ''} ago`;
            if (days < 7) return `${days} day${days !== 1 ? 's' : ''} ago`;
            
            return new Date(timestamp).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }
        
        // ============================================
        // THEME TOGGLE
        // ============================================
        
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', newTheme);
            document.cookie = `admin_theme=${newTheme}; path=/; max-age=31536000`;
            
            const icon = document.querySelector('#themeToggle i');
            icon.className = newTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
            
            // Update chart colors
            if (Object.keys(charts).length > 0) {
                const textColor = newTheme === 'light' ? '#1f2937' : '#e5e5e5';
                Object.values(charts).forEach(chart => {
                    if (chart && chart.config) {
                        if (chart.config.type === 'doughnut' || chart.config.type === 'pie') {
                            chart.config.options.plugins.legend.labels.color = textColor;
                        }
                        if (chart.config.type === 'line') {
                            chart.config.options.scales.x.ticks.color = textColor;
                            chart.config.options.scales.y.ticks.color = textColor;
                        }
                        chart.update();
                    }
                });
            }
        }
        
        // Set initial theme icon
        document.addEventListener('DOMContentLoaded', function() {
            const theme = document.documentElement.getAttribute('data-theme') || 'light';
            const icon = document.querySelector('#themeToggle i');
            icon.className = theme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
        });
        
        // ============================================
        // UTILITY FUNCTIONS
        // ============================================
        
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m]);
        }
        
        function showToast(title, message, type = 'info') {
            const toast = $(`<div class="toast-notification"><i class="fas fa-${type === 'danger' ? 'times-circle' : (type === 'success' ? 'check-circle' : 'info-circle')}"></i><div class="toast-content"><div class="toast-title">${escapeHtml(title)}</div><div class="toast-message">${escapeHtml(message)}</div></div><button class="toast-close" onclick="this.parentElement.remove()">&times;</button></div>`);
            $('#notificationToast').append(toast);
            setTimeout(() => toast.remove(), 5000);
        }
        
        function updateDateTime() {
            const now = new Date();
            $('#datetimeDisplay').text(now.toLocaleString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }));
        }
        
        function getSeverityDot(severity) {
            if (!severity) return 'minor';
            const s = String(severity).toLowerCase();
            if (s === 'dead') return 'dead';
            if (s === 'high' || s === 'immediate' || s === 'critical') return 'immediate';
            if (s === 'moderate' || s === 'delayed') return 'delayed';
            return 'minor';
        }
        
        function getSeverityClassJs(severity) {
            if (!severity) return '';
            const s = String(severity).toLowerCase();
            if (s === 'dead') return 'border-severity-dead';
            if (s === 'high' || s === 'immediate') return 'border-severity-immediate';
            if (s === 'moderate' || s === 'delayed') return 'border-severity-delayed';
            return 'border-severity-minor';
        }
        
        function getSeverityBadgeJs(severity) {
            if (!severity) return '<span class="severity-badge minor"><i class="fas fa-band-aid"></i> MINOR</span>';
            const s = String(severity).toLowerCase();
            if (s === 'dead') return '<span class="severity-badge dead"><i class="fas fa-skull"></i> DEAD</span>';
            if (s === 'high' || s === 'immediate') return '<span class="severity-badge immediate"><i class="fas fa-exclamation-triangle"></i> IMMEDIATE</span>';
            if (s === 'moderate' || s === 'delayed') return '<span class="severity-badge delayed"><i class="fas fa-exclamation-circle"></i> DELAYED</span>';
            return '<span class="severity-badge minor"><i class="fas fa-band-aid"></i> MINOR</span>';
        }
        
        function getSeverityMapColor(severity) {
            const s = String(severity || '').toLowerCase();
            if (s === 'dead') return '#6b7280';
            if (s === 'high' || s === 'immediate' || s === 'critical') return '#ef4444';
            if (s === 'moderate' || s === 'delayed') return '#f59e0b';
            return '#10b981';
        }
        
        // ============================================
        // TAB NAVIGATION
        // ============================================
        
        function switchTab(tabId) {
            $('.tab-pane').removeClass('active');
            $(`#${tabId}-tab`).addClass('active');
            $('.nav-item').removeClass('active');
            $(`.nav-item[data-tab="${tabId}"]`).addClass('active');
            
            const titles = {'dashboard': 'Dashboard', 'analytics': 'Analytics', 'reports': 'All Reports', 'responders': 'Responders', 'map': 'Live Map', 'completed': 'Completed Reports'};
            $('#pageTitle').text(titles[tabId] || 'Dashboard');
            
            if (tabId === 'map') setTimeout(() => { if (!liveMap) initLiveMap(); else liveMap.invalidateSize(); }, 100);
            if (tabId === 'analytics') loadAnalytics(currentPeriod);
            if (tabId === 'reports') renderReportsTable();
            if (tabId === 'completed') renderCompletedReportsList();
            
            $('#sidebar').removeClass('open');
        }
        
        $('.nav-item').click(function() { switchTab($(this).data('tab')); });
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // ============================================
        // AUTO-REFRESH
        // ============================================
        
        function startAutoRefresh() {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(() => {
                refreshAllData();
            }, 10000);
        }
        
        function refreshAllData() {
            $.ajax({
                url: 'admin_dashboard.php',
                method: 'POST',
                data: { action: 'get_all_incidents' },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        allIncidentsData = result.incidents;
                        updateStatsAndLists(result.incidents);
                        if ($('#reports-tab').hasClass('active')) {
                            renderReportsTable();
                        }
                    }
                }
            });
            
            $.ajax({
                url: 'admin_dashboard.php',
                method: 'POST',
                data: { action: 'get_completed_reports' },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        allCompletedReportsData = result.reports;
                        updateCompletedReportsList(result.reports);
                        if ($('#completed-tab').hasClass('active')) {
                            renderCompletedReportsList();
                        }
                    }
                }
            });
        }
        
        function updateStatsAndLists(incidents) {
            incidents.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            
            const total = incidents.length;
            const pending = incidents.filter(i => i.status === 'pending').length;
            const dispatched = incidents.filter(i => i.status === 'dispatched').length;
            const completed = incidents.filter(i => i.status === 'completed').length;
            
            $('#statTotalReports').text(total);
            $('#statPending').text(pending);
            $('#statDispatched').text(dispatched);
            $('#statCompleted').text(completed);
            
            if (total > lastIncidentCount && lastIncidentCount > 0) {
                const newCount = total - lastIncidentCount;
                $('#newIncidentAlert').fadeIn().delay(5000).fadeOut();
                showToast('New Incident' + (newCount > 1 ? 's' : ''), `${newCount} new incident${newCount > 1 ? 's' : ''} reported!`, 'danger');
            }
            lastIncidentCount = total;
            
            // Update recent incidents list
            let recentHtml = '';
            incidents.slice(0, 5).forEach(incident => {
                const statusClass = incident.status || 'pending';
                const severityClass = getSeverityClassJs(incident.severity);
                const severityBadge = getSeverityBadgeJs(incident.severity);
                const severityDot = getSeverityDot(incident.severity);
                const isNew = (new Date() - new Date(incident.created_at)) < 300000;
                const timeAgo = formatTimeAgo(incident.created_at);
                
                let mediaBadges = '';
                if (incident.photo_count > 0) {
                    mediaBadges += `<span class="media-badge photo"><i class="fas fa-image"></i> ${incident.photo_count}</span>`;
                }
                if (videoTableExists && incident.video_count > 0) {
                    mediaBadges += `<span class="media-badge video"><i class="fas fa-video"></i> ${incident.video_count}</span>`;
                }
                
                recentHtml += `
                    <div class="incident-item ${statusClass} ${severityClass}" onclick="viewIncident(${incident.incident_id})">
                        <div class="incident-header">
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <span class="incident-id">${escapeHtml(incident.tracking_id)}</span>
                                <div class="severity-indicator">
                                    <span class="severity-dot ${severityDot}"></span>
                                    ${severityBadge}
                                </div>
                                ${isNew ? '<span class="badge-new">NEW</span>' : ''}
                            </div>
                            <span class="incident-badge ${statusClass}">${statusClass.toUpperCase()}</span>
                        </div>
                        <div class="incident-type">${escapeHtml(incident.incident_type)}</div>
                        <div class="incident-meta">
                            <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml((incident.location_address || 'N/A').substring(0, 30))}...</span>
                            <span><i class="far fa-clock"></i> ${timeAgo}</span>
                            ${mediaBadges}
                        </div>
                        <div class="incident-actions">
                            <button class="btn-icon" onclick="event.stopPropagation(); editFullReport(${incident.incident_id})" title="Edit Full Report">
                                <i class="fas fa-edit"></i>
                            </button>
                            ${incident.location_lat ? `
                            <button class="btn-icon" onclick="event.stopPropagation(); viewLocationOnMap(${incident.location_lat}, ${incident.location_lng}, '${escapeHtml(incident.tracking_id)}')" title="View on Map">
                                <i class="fas fa-map-marked-alt"></i>
                            </button>
                            ` : ''}
                            <button class="btn-icon" onclick="event.stopPropagation(); viewAccessLog(${incident.incident_id}, '${escapeHtml(incident.tracking_id)}')" title="Access Log">
                                <i class="fas fa-history"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            $('#recentIncidentsList').html(recentHtml || '<div class="empty-state"><i class="fas fa-inbox"></i><h5>No incidents</h5></div>');
        }
        
        function updateCompletedReportsList(reports) {
            let html = '';
            reports.forEach(report => {
                const severityClass = getSeverityClassJs(report.severity);
                const severityBadge = getSeverityBadgeJs(report.severity);
                const severityDot = getSeverityDot(report.severity);
                
                html += `
                    <div class="incident-item completed ${severityClass}">
                        <div class="incident-header">
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <span class="incident-id">${escapeHtml(report.tracking_id)}</span>
                                <div class="severity-indicator">
                                    <span class="severity-dot ${severityDot}"></span>
                                    ${severityBadge}
                                </div>
                            </div>
                            <span class="incident-badge completed">COMPLETED</span>
                        </div>
                        <div class="incident-type">${escapeHtml(report.incident_type)}</div>
                        <div class="incident-meta">
                            <span><i class="fas fa-user-check"></i> ${escapeHtml(report.responder_name)}</span>
                            <span><i class="far fa-calendar-check"></i> ${new Date(report.submitted_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                        </div>
                        <div class="incident-actions">
                            <button class="btn-icon" onclick="viewCompletedReport(${report.report_id})" title="View Full Report">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-icon" onclick="editFullReport(${report.incident_id})" title="Edit Full Report">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-icon" onclick="viewAccessLog(${report.incident_id}, '${escapeHtml(report.tracking_id)}')" title="Access Log">
                                <i class="fas fa-history"></i>
                            </button>
                            <button class="btn-icon danger" onclick="deleteReport(${report.incident_id}, '${escapeHtml(report.tracking_id)}')" title="Delete Report">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            $('#completedReportsList').html(html || '<div class="empty-state"><i class="fas fa-inbox"></i><h5>No Completed Reports</h5><p>Create a new report or upload one to get started.</p></div>');
            
            $('.nav-item[data-tab="completed"] .badge').text(reports.length);
        }
        
        // ============================================
        // FILTERING AND SEARCH FUNCTIONS
        // ============================================
        
        let currentFilters = {
            search: '',
            type: 'all',
            severity: 'all',
            status: 'all',
            date: 'all'
        };
        
        function filterReports() {
            currentFilters.search = $('#reportSearchInput').val().toLowerCase();
            currentFilters.type = $('#filterType').val();
            currentFilters.severity = $('#filterSeverity').val();
            currentFilters.status = $('#filterStatus').val();
            currentFilters.date = $('#filterDate').val();
            
            renderReportsTable();
        }
        
        function clearFilters() {
            $('#reportSearchInput').val('');
            $('#filterType').val('all');
            $('#filterSeverity').val('all');
            $('#filterStatus').val('all');
            $('#filterDate').val('all');
            
            currentFilters = { search: '', type: 'all', severity: 'all', status: 'all', date: 'all' };
            
            renderReportsTable();
        }
        
        function getFilteredIncidents() {
            if (!allIncidentsData.length) return [];
            
            return allIncidentsData.filter(incident => {
                // Search filter
                if (currentFilters.search) {
                    const searchable = `${incident.tracking_id} ${incident.incident_type} ${incident.location_address} ${incident.reporter_name} ${incident.reporter_phone}`.toLowerCase();
                    if (!searchable.includes(currentFilters.search)) return false;
                }
                
                // Type filter
                if (currentFilters.type !== 'all' && incident.incident_type !== currentFilters.type) return false;
                
                // Severity filter
                if (currentFilters.severity !== 'all') {
                    const sev = String(incident.severity || '').toLowerCase();
                    if (currentFilters.severity === 'minor' && !['low', 'minor', 'green'].includes(sev)) return false;
                    if (currentFilters.severity === 'delayed' && !['moderate', 'delayed', 'yellow', 'serious'].includes(sev)) return false;
                    if (currentFilters.severity === 'immediate' && !['high', 'critical', 'immediate', 'red'].includes(sev)) return false;
                    if (currentFilters.severity === 'dead' && !['dead', 'deceased', 'black'].includes(sev)) return false;
                }
                
                // Status filter
                if (currentFilters.status !== 'all' && incident.status !== currentFilters.status) return false;
                
                // Date filter
                if (currentFilters.date !== 'all') {
                    const incidentDate = new Date(incident.created_at);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    
                    const yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    
                    const weekStart = new Date(today);
                    weekStart.setDate(weekStart.getDate() - weekStart.getDay());
                    
                    const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                    
                    switch(currentFilters.date) {
                        case 'today':
                            if (incidentDate < today) return false;
                            break;
                        case 'yesterday':
                            if (incidentDate < yesterday || incidentDate >= today) return false;
                            break;
                        case 'week':
                            if (incidentDate < weekStart) return false;
                            break;
                        case 'month':
                            if (incidentDate < monthStart) return false;
                            break;
                    }
                }
                
                return true;
            });
        }
        
        function sortTable(column) {
            if (currentSortColumn === column) {
                currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortColumn = column;
                currentSortDirection = 'asc';
            }
            renderReportsTable();
        }
        
        function getSortedIncidents(incidents) {
            return [...incidents].sort((a, b) => {
                let valA, valB;
                
                switch(currentSortColumn) {
                    case 'tracking':
                        valA = a.tracking_id;
                        valB = b.tracking_id;
                        break;
                    case 'type':
                        valA = a.incident_type;
                        valB = b.incident_type;
                        break;
                    case 'severity':
                        valA = getSeverityWeight(a.severity);
                        valB = getSeverityWeight(b.severity);
                        break;
                    case 'status':
                        valA = getStatusWeight(a.status);
                        valB = getStatusWeight(b.status);
                        break;
                    case 'date':
                        valA = new Date(a.created_at);
                        valB = new Date(b.created_at);
                        break;
                    default:
                        valA = new Date(a.created_at);
                        valB = new Date(b.created_at);
                }
                
                if (valA < valB) return currentSortDirection === 'asc' ? -1 : 1;
                if (valA > valB) return currentSortDirection === 'asc' ? 1 : -1;
                return 0;
            });
        }
        
        function getSeverityWeight(severity) {
            const s = String(severity || '').toLowerCase();
            if (s === 'dead') return 4;
            if (s === 'high' || s === 'immediate' || s === 'critical') return 3;
            if (s === 'moderate' || s === 'delayed') return 2;
            return 1;
        }
        
        function getStatusWeight(status) {
            if (status === 'pending') return 1;
            if (status === 'dispatched') return 2;
            if (status === 'completed') return 3;
            return 0;
        }
        
        function renderReportsTable() {
            const filtered = getFilteredIncidents();
            const sorted = getSortedIncidents(filtered);
            
            $('#filteredCount').text(filtered.length);
            
            if (filtered.length === 0) {
                $('#reportsTable').hide();
                $('#noResultsMessage').show();
                return;
            }
            
            $('#reportsTable').show();
            $('#noResultsMessage').hide();
            
            let html = '';
            sorted.forEach(incident => {
                const statusClass = incident.status || 'pending';
                const severityBadge = getSeverityBadgeJs(incident.severity);
                const isNew = (new Date() - new Date(incident.created_at)) < 300000;
                const timeAgo = formatTimeAgo(incident.created_at);
                
                let mediaHtml = '';
                if (incident.photo_count > 0) {
                    mediaHtml += `<span class="media-badge photo"><i class="fas fa-image"></i> ${incident.photo_count}</span>`;
                }
                if (videoTableExists && incident.video_count > 0) {
                    mediaHtml += `<span class="media-badge video"><i class="fas fa-video"></i> ${incident.video_count}</span>`;
                }
                
                html += `
                    <tr>
                        <td>
                            <code style="color: var(--accent-yellow);">${escapeHtml(incident.tracking_id)}</code>
                            ${isNew ? '<span class="badge-new">NEW</span>' : ''}
                        </td>
                        <td>${escapeHtml(incident.incident_type)}</td>
                        <td>${severityBadge}</td>
                        <td>${escapeHtml((incident.location_address || 'N/A').substring(0, 30))}...</td>
                        <td><span class="incident-badge ${statusClass}">${statusClass.charAt(0).toUpperCase() + statusClass.slice(1)}</span></td>
                        <td title="${new Date(incident.created_at).toLocaleString()}">${timeAgo}</td>
                        <td>${mediaHtml || '-'}</td>
                        <td>
                            <button class="btn-icon" onclick="viewIncident(${incident.incident_id})" title="View">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-icon" onclick="editFullReport(${incident.incident_id})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            ${incident.location_lat ? `
                            <button class="btn-icon" onclick="viewLocationOnMap(${incident.location_lat}, ${incident.location_lng}, '${escapeHtml(incident.tracking_id)}')" title="Map">
                                <i class="fas fa-map-marked-alt"></i>
                            </button>
                            ` : ''}
                            <button class="btn-icon" onclick="viewAccessLog(${incident.incident_id}, '${escapeHtml(incident.tracking_id)}')" title="Log">
                                <i class="fas fa-history"></i>
                            </button>
                            <button class="btn-icon danger" onclick="deleteReport(${incident.incident_id}, '${escapeHtml(incident.tracking_id)}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            $('#allIncidentsTableBody').html(html);
        }
        
        // Completed Reports Filtering
        function filterCompletedReports() {
            const search = $('#completedSearchInput').val().toLowerCase();
            const type = $('#completedFilterType').val();
            
            let filtered = allCompletedReportsData.filter(report => {
                if (search) {
                    const searchable = `${report.tracking_id} ${report.incident_type} ${report.responder_name}`.toLowerCase();
                    if (!searchable.includes(search)) return false;
                }
                if (type !== 'all' && report.incident_type !== type) return false;
                return true;
            });
            
            $('#completedFilteredCount').text(filtered.length);
            
            let html = '';
            filtered.forEach(report => {
                const severityClass = getSeverityClassJs(report.severity);
                const severityBadge = getSeverityBadgeJs(report.severity);
                const severityDot = getSeverityDot(report.severity);
                
                html += `
                    <div class="incident-item completed ${severityClass}">
                        <div class="incident-header">
                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                <span class="incident-id">${escapeHtml(report.tracking_id)}</span>
                                <div class="severity-indicator">
                                    <span class="severity-dot ${severityDot}"></span>
                                    ${severityBadge}
                                </div>
                            </div>
                            <span class="incident-badge completed">COMPLETED</span>
                        </div>
                        <div class="incident-type">${escapeHtml(report.incident_type)}</div>
                        <div class="incident-meta">
                            <span><i class="fas fa-user-check"></i> ${escapeHtml(report.responder_name)}</span>
                            <span><i class="far fa-calendar-check"></i> ${new Date(report.submitted_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</span>
                        </div>
                        <div class="incident-actions">
                            <button class="btn-icon" onclick="viewCompletedReport(${report.report_id})" title="View">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-icon" onclick="editFullReport(${report.incident_id})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-icon" onclick="viewAccessLog(${report.incident_id}, '${escapeHtml(report.tracking_id)}')" title="Log">
                                <i class="fas fa-history"></i>
                            </button>
                            <button class="btn-icon danger" onclick="deleteReport(${report.incident_id}, '${escapeHtml(report.tracking_id)}')" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            $('#completedReportsList').html(html || '<div class="empty-state"><i class="fas fa-search"></i><h5>No reports found</h5></div>');
        }
        
        function clearCompletedFilters() {
            $('#completedSearchInput').val('');
            $('#completedFilterType').val('all');
            filterCompletedReports();
        }
        
        function renderCompletedReportsList() {
            filterCompletedReports();
        }
        
        // ============================================
        // DELETE REPORT
        // ============================================
        
        function deleteReport(incidentId, trackingId) {
            Swal.fire({
                title: 'Delete Report?',
                text: `This will permanently delete report "${trackingId}" and all associated data. This cannot be undone!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'admin_dashboard.php',
                        method: 'POST',
                        data: { action: 'delete_report', incident_id: incidentId },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Deleted!', 'Report has been deleted.', 'success');
                                refreshAllData();
                                showToast('Report Deleted', `Report ${trackingId} has been removed`, 'success');
                            } else {
                                Swal.fire('Error', response.message || 'Failed to delete report', 'error');
                            }
                        },
                        error: function() {
                            Swal.fire('Error', 'Connection failed', 'error');
                        }
                    });
                }
            });
        }
        
        // ============================================
        // CREATE INCIDENT
        // ============================================
        
        function showCreateIncidentModal() {
            $('#newIncidentType').val('Medical');
            $('#newSeverity').val('low');
            $('#newLocationAddress').val('');
            $('#newReporterName').val('<?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?>');
            $('#newReporterPhone').val('');
            $('#newDescription').val('');
            newIncidentLat = null;
            newIncidentLng = null;
            $('#createIncidentModal').modal('show');
        }
        
        function getCurrentLocationForNew() {
            if (!navigator.geolocation) {
                Swal.fire('Error', 'Geolocation not supported', 'error');
                return;
            }
            
            Swal.fire({ title: 'Getting Location...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            navigator.geolocation.getCurrentPosition(pos => {
                newIncidentLat = pos.coords.latitude;
                newIncidentLng = pos.coords.longitude;
                
                fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${newIncidentLat}&lon=${newIncidentLng}&zoom=18`)
                    .then(r => r.json())
                    .then(data => {
                        $('#newLocationAddress').val(data.display_name || `${newIncidentLat}, ${newIncidentLng}`);
                        Swal.fire('Success', 'Location captured!', 'success');
                    })
                    .catch(() => {
                        $('#newLocationAddress').val(`${newIncidentLat}, ${newIncidentLng}`);
                        Swal.fire('Success', 'Coordinates captured!', 'success');
                    });
            }, () => Swal.fire('Error', 'Could not get location', 'error'));
        }
        
        function createNewIncident() {
            const data = {
                action: 'create_new_incident',
                incident_type: $('#newIncidentType').val(),
                severity: $('#newSeverity').val(),
                location_address: $('#newLocationAddress').val(),
                location_lat: newIncidentLat,
                location_lng: newIncidentLng,
                reporter_name: $('#newReporterName').val(),
                reporter_phone: $('#newReporterPhone').val(),
                description: $('#newDescription').val()
            };
            
            if (!data.location_address) {
                Swal.fire('Error', 'Please enter location', 'error');
                return;
            }
            
            Swal.fire({ title: 'Creating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            $.post('admin_dashboard.php', data, result => {
                if (result.success) {
                    $('#createIncidentModal').modal('hide');
                    Swal.fire('Success!', `Incident created: ${result.tracking_id}`, 'success');
                    refreshAllData();
                    showToast('Incident Created', `Tracking ID: ${result.tracking_id}`, 'success');
                } else {
                    Swal.fire('Error', result.message || 'Failed', 'error');
                }
            }, 'json').fail(() => Swal.fire('Error', 'Connection failed', 'error'));
        }
        
        // ============================================
        // SIGNATURE PAD
        // ============================================
        
        function initSignatures() {
            ['patientSigCanvas', 'emergencySigCanvas', 'providerSigCanvas'].forEach(id => {
                const canvas = document.getElementById(id);
                if (canvas) {
                    canvas.width = 280;
                    canvas.height = 70;
                    sigPads[id] = new SignaturePad(canvas, { backgroundColor: 'white', penColor: 'black' });
                }
            });
        }
        
        window.clearSignature = function(id) { if (sigPads[id]) sigPads[id].clear(); };
        
        // ============================================
        // INJURY MAP
        // ============================================
        
        function initInjuryMap() {
            const canvas = document.getElementById('bodyCanvas');
            if (!canvas) return;
            
            canvas.width = 360; canvas.height = 420;
            const ctx = canvas.getContext('2d');
            drawingLayer = document.createElement('canvas');
            drawingLayer.width = 360; drawingLayer.height = 420;
            drawCtx = drawingLayer.getContext('2d');
            
            function composite() {
                ctx.clearRect(0, 0, 360, 420);
                if (backgroundImage) {
                    ctx.drawImage(backgroundImage, 0, 0, 360, 420);
                } else {
                    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, 360, 420);
                    ctx.strokeStyle = '#333'; ctx.lineWidth = 2;
                    ctx.beginPath(); ctx.arc(180, 65, 32, 0, Math.PI*2); ctx.stroke();
                    ctx.beginPath(); ctx.moveTo(148, 95); ctx.lineTo(148, 175); ctx.lineTo(212, 175); ctx.lineTo(212, 95); ctx.stroke();
                    ctx.beginPath(); ctx.moveTo(148, 115); ctx.lineTo(105, 160); ctx.stroke();
                    ctx.beginPath(); ctx.moveTo(212, 115); ctx.lineTo(255, 160); ctx.stroke();
                    ctx.beginPath(); ctx.moveTo(148, 175); ctx.lineTo(125, 260); ctx.stroke();
                    ctx.beginPath(); ctx.moveTo(212, 175); ctx.lineTo(235, 260); ctx.stroke();
                    ctx.beginPath(); ctx.moveTo(148, 175); ctx.lineTo(148, 320); ctx.stroke();
                    ctx.beginPath(); ctx.moveTo(212, 175); ctx.lineTo(212, 320); ctx.stroke();
                }
                ctx.drawImage(drawingLayer, 0, 0);
                $('#bodyImageData').val(canvas.toDataURL());
            }
            
            function saveState() { historyStack.push(drawingLayer.toDataURL()); if (historyStack.length > 30) historyStack.shift(); }
            
            function getCoords(e) {
                const rect = canvas.getBoundingClientRect();
                const scaleX = canvas.width / rect.width, scaleY = canvas.height / rect.height;
                const cx = e.touches ? e.touches[0].clientX : e.clientX;
                const cy = e.touches ? e.touches[0].clientY : e.clientY;
                return { x: Math.min(Math.max(0, (cx - rect.left) * scaleX), canvas.width), y: Math.min(Math.max(0, (cy - rect.top) * scaleY), canvas.height) };
            }
            
            function draw(x, y) {
                drawCtx.globalCompositeOperation = currentMode === 'draw' ? 'source-over' : 'destination-out';
                drawCtx.strokeStyle = currentColor; drawCtx.fillStyle = currentColor;
                drawCtx.lineWidth = brushSize; drawCtx.lineCap = 'round';
                drawCtx.beginPath(); drawCtx.moveTo(lastX, lastY); drawCtx.lineTo(x, y); drawCtx.stroke();
                drawCtx.beginPath(); drawCtx.arc(x, y, brushSize/2, 0, Math.PI*2); drawCtx.fill();
                lastX = x; lastY = y; composite();
            }
            
            function startDraw(e) { drawing = true; const coords = getCoords(e); lastX = coords.x; lastY = coords.y; saveState(); draw(lastX, lastY); e.preventDefault(); }
            function drawMove(e) { if (!drawing) return; draw(...Object.values(getCoords(e))); e.preventDefault(); }
            
            canvas.addEventListener('mousedown', startDraw);
            canvas.addEventListener('mousemove', drawMove);
            canvas.addEventListener('mouseup', () => drawing = false);
            canvas.addEventListener('touchstart', startDraw);
            canvas.addEventListener('touchmove', drawMove);
            canvas.addEventListener('touchend', () => drawing = false);
            
            $('#drawBtn').click(() => { currentMode = 'draw'; $('#drawBtn').addClass('active'); $('#eraseBtn').removeClass('active'); });
            $('#eraseBtn').click(() => { currentMode = 'erase'; $('#eraseBtn').addClass('active'); $('#drawBtn').removeClass('active'); });
            $('#penColor').on('change', e => currentColor = e.target.value);
            $('#brushSize').on('input', e => brushSize = parseInt(e.target.value));
            $('#clearCanvasBtn').click(() => { drawCtx.clearRect(0, 0, 360, 420); composite(); saveState(); });
            $('#undoBtn').click(() => { if (historyStack.length > 1) { historyStack.pop(); restoreBodyDrawing(historyStack[historyStack.length - 1]); } });
            
            window.restoreBodyDrawing = function(dataURL) {
                const img = new Image();
                img.onload = () => { drawCtx.clearRect(0, 0, 360, 420); drawCtx.drawImage(img, 0, 0); composite(); };
                img.src = dataURL;
            };
            
            window.clearDrawing = function() { drawCtx.clearRect(0, 0, 360, 420); composite(); saveState(); };
            
            composite(); saveState();
        }
        
        // ============================================
        // PHOTO GALLERY
        // ============================================
        
        function updateImageGallery() {
            const gallery = $('#incidentImageGallery');
            gallery.empty();
            incidentImages.forEach((img, idx) => {
                gallery.append(`<div class="photo-item"><img src="${img}"><button class="photo-remove" onclick="removeImage(${idx})">&times;</button></div>`);
            });
            $('#imageCount').text(`${incidentImages.length} photo(s)`);
            $('#incidentImagesData').val(JSON.stringify(incidentImages));
        }
        
        window.removeImage = function(idx) { incidentImages.splice(idx, 1); updateImageGallery(); };
        
        $('#incidentImagesInput').on('change', function(e) {
            Array.from(e.target.files).forEach(f => {
                if (f.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = ev => { incidentImages.push(ev.target.result); updateImageGallery(); };
                    reader.readAsDataURL(f);
                }
            });
            e.target.value = '';
        });
        
        // ============================================
        // FULL REPORT EDITOR
        // ============================================
        
        function showFullReportEditor() {
            editingIncidentId = null;
            $('#reportEditorTitle').text('Create New Report');
            $('#reportTrackingId').val('ADMIN-' + new Date().getFullYear() + '-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0'));
            $('#reportName').val('');
            clearReportForm();
            const today = new Date().toISOString().split('T')[0];
            $('#incidentDate').val(today);
            $('#refusalDate').val(today);
            $('#crew1').val('<?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?>');
            incidentImages = [];
            updateImageGallery();
            clearDrawing();
            Object.values(sigPads).forEach(pad => pad?.clear());
            $('#fullReportEditorModal').modal('show');
        }
        
        function editFullReport(incidentId) {
            editingIncidentId = incidentId;
            $('#reportEditorTitle').text('Edit Full Report');
            
            $.ajax({
                url: 'admin_dashboard.php',
                method: 'POST',
                data: { action: 'get_full_report_for_edit', incident_id: incidentId },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        const report = result.report;
                        $('#reportTrackingId').val(report.tracking_id || '');
                        
                        if (report.report_data) {
                            try {
                                const data = JSON.parse(report.report_data);
                                $('#reportName').val(data.reportName || '');
                                $('#incidentDate').val(data.incidentDate || '');
                                $('#callTime').val(data.callTime || '');
                                $('#incidentTime').val(data.incidentTime || '');
                                $('#atScene').val(data.atScene || '');
                                $('#incidentPurpose').val(data.incidentPurpose || report.incident_type || '');
                                $('#departScene').val(data.departScene || '');
                                $('#atHospital').val(data.atHospital || '');
                                $('#placeIncident').val(data.placeIncident || report.location_address || '');
                                $('#handover').val(data.handover || '');
                                $('#backToBase').val(data.backToBase || '');
                                $('#patientName').val(data.patientName || '');
                                $('#emergencyContact').val(data.emergencyContact || '');
                                $('#patientAge').val(data.patientAge || '');
                                $('#patientGender').val(data.patientGender || 'Male');
                                $('#patientAddress').val(data.patientAddress || '');
                                $('#emergencyNumber').val(data.emergencyNumber || '');
                                $('#symptoms').val(data.symptoms || '');
                                $('#bp').val(data.bp || '');
                                $('#allergy').val(data.allergy || '');
                                $('#pulse').val(data.pulse || '');
                                $('#medications').val(data.medications || '');
                                $('#respiratory').val(data.respiratory || '');
                                $('#pastHistory').val(data.pastHistory || '');
                                $('#temperature').val(data.temperature || '');
                                $('#lastIntake').val(data.lastIntake || '');
                                $('#events').val(data.events || '');
                                $('#chiefComplaint').val(data.chiefComplaint || '');
                                $('#actionsGiven').val(data.actionsGiven || '');
                                $('#refusalWitness').val(data.refusalWitness || '');
                                $('#refusalDate').val(data.refusalDate || '');
                                $('#crew1').val(data.crew1 || '<?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?>');
                                $('#crew2').val(data.crew2 || '');
                                $('#crew3').val(data.crew3 || '');
                                $('#crew4').val(data.crew4 || '');
                                $('#crew5').val(data.crew5 || '');
                                $('#driver').val(data.driver || '');
                                $('#vehicle').val(data.vehicle || '');
                                $('#receivingPlace').val(data.receivingPlace || '');
                                $('#receivingPerson').val(data.receivingPerson || '');
                                $('#receivingSignName').val(data.receivingSignName || '');
                                
                                if (data.bodyImage) restoreBodyDrawing(data.bodyImage);
                                if (data.patientSig && sigPads['patientSigCanvas']) sigPads['patientSigCanvas'].fromDataURL(data.patientSig);
                                if (data.emergencySig && sigPads['emergencySigCanvas']) sigPads['emergencySigCanvas'].fromDataURL(data.emergencySig);
                                if (data.providerSig && sigPads['providerSigCanvas']) sigPads['providerSigCanvas'].fromDataURL(data.providerSig);
                                if (data.incidentImages) {
                                    try { incidentImages = JSON.parse(data.incidentImages); updateImageGallery(); } catch(e) {}
                                }
                            } catch(e) {}
                        } else {
                            $('#incidentPurpose').val(report.incident_type || '');
                            $('#placeIncident').val(report.location_address || '');
                        }
                        
                        $('#fullReportEditorModal').modal('show');
                    }
                }
            });
        }
        
        function clearReportForm() {
            $('#fullReportEditorModal input, #fullReportEditorModal textarea, #fullReportEditorModal select').not('[type=hidden]').val('');
        }
        
        function captureReportFormData() {
            const fields = ['incidentDate','callTime','incidentTime','atScene','incidentPurpose','departScene','atHospital',
                            'placeIncident','handover','backToBase','patientName','emergencyContact','patientAge','patientGender',
                            'patientAddress','emergencyNumber','symptoms','bp','allergy','pulse','medications','respiratory',
                            'pastHistory','temperature','lastIntake','events','chiefComplaint','actionsGiven','refusalWitness',
                            'refusalDate','crew1','crew2','crew3','crew4','crew5','driver','vehicle','receivingPlace','receivingPerson','receivingSignName'];
            
            let data = {};
            fields.forEach(f => { data[f] = $('#' + f).val() || ''; });
            data.reportName = $('#reportName').val() || 'Report ' + $('#reportTrackingId').val();
            data.incidentImages = JSON.stringify(incidentImages);
            data.bodyImage = $('#bodyImageData').val() || '';
            data.patientSig = sigPads['patientSigCanvas']?.toDataURL() || '';
            data.emergencySig = sigPads['emergencySigCanvas']?.toDataURL() || '';
            data.providerSig = sigPads['providerSigCanvas']?.toDataURL() || '';
            return data;
        }
        
        $('#saveFullReportBtn').click(function() {
            const reportData = captureReportFormData();
            const data = {
                action: editingIncidentId ? 'update_full_report' : 'create_full_report',
                tracking_id: $('#reportTrackingId').val(),
                incident_id: editingIncidentId || '',
                report_data: JSON.stringify(reportData),
                location_lat: null,
                location_lng: null
            };
            
            $.ajax({
                url: 'admin_dashboard.php',
                method: 'POST',
                data: data,
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        $('#fullReportEditorModal').modal('hide');
                        showToast('Success', editingIncidentId ? 'Report updated' : 'Report created', 'success');
                        refreshAllData();
                    } else {
                        showToast('Error', result.message, 'danger');
                    }
                }
            });
        });
        
        // ============================================
        // UPLOAD REPORT
        // ============================================
        
        function showUploadReportModal() {
            $('#uploadPreview').hide();
            $('#processUploadBtn').prop('disabled', true);
            $('#uploadReportModal').modal('show');
        }
        
        function handleFileUpload(input) {
            if (input.files && input.files[0]) {
                $('#selectedFileName').text(input.files[0].name);
                $('#uploadPreview').show();
                $('#processUploadBtn').prop('disabled', false);
            }
        }
        
        $('#processUploadBtn').click(function() {
            Swal.fire({title: 'Processing...', text: 'File upload simulation'});
            setTimeout(() => {
                $('#uploadReportModal').modal('hide');
                showFullReportEditor();
                showToast('File Processed', 'You can now edit the extracted data', 'success');
            }, 1500);
        });
        
        // ============================================
        // ACCESS LOG
        // ============================================
        
        function viewAccessLog(incidentId, trackingId) {
            currentAccessIncidentId = incidentId;
            $('#accessLogTrackingId').text(trackingId);
            $('#accessLogTimeline').html('<div class="text-center p-4">Loading...</div>');
            $('#usersWithAccessList').html('<div class="text-center p-4">Loading...</div>');
            
            // Reset tabs
            $('#tabActivityBtn').addClass('active');
            $('#tabUsersBtn').removeClass('active');
            $('#activityTabPanel').show();
            $('#usersTabPanel').hide();
            
            $('#accessLogModal').modal('show');
            
            $.ajax({
                url: 'admin_dashboard.php',
                method: 'POST',
                data: { action: 'get_report_access_logs', incident_id: incidentId },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        // Render activity logs
                        if (result.logs && result.logs.length > 0) {
                            let html = '';
                            result.logs.forEach(log => {
                                const actionType = log.action_type || 'view';
                                let icon = 'eye';
                                let color = '#6b7280';
                                
                                switch(actionType) {
                                    case 'view': case 'viewed': icon = 'eye'; color = '#3b82f6'; break;
                                    case 'edit': case 'edited': icon = 'edit'; color = '#fbbf24'; break;
                                    case 'saved_draft': icon = 'save'; color = '#10b981'; break;
                                    case 'printed': icon = 'print'; color = '#8b5cf6'; break;
                                    case 'granted_access': icon = 'user-plus'; color = '#f59e0b'; break;
                                    case 'completed': icon = 'check-circle'; color = '#10b981'; break;
                                    case 'taken': icon = 'hand-paper'; color = '#fbbf24'; break;
                                    case 'navigating': icon = 'directions'; color = '#3b82f6'; break;
                                    case 'deleted': icon = 'trash'; color = '#dc2626'; break;
                                }
                                
                                const displayName = log.actor_name || 'System';
                                const actionDisplay = (actionType || 'view').replace(/_/g, ' ').toUpperCase();
                                
                                html += `
                                    <div class="access-log-item" style="border-left: 3px solid ${color};">
                                        <div style="display: flex; justify-content: space-between;">
                                            <div>
                                                <i class="fas fa-${icon}" style="color: ${color};"></i>
                                                <strong>${escapeHtml(displayName)}</strong>
                                                <span class="badge" style="background: ${color};">${escapeHtml(actionDisplay)}</span>
                                            </div>
                                            <small>${new Date(log.created_at).toLocaleString()}</small>
                                        </div>
                                    </div>
                                `;
                            });
                            $('#accessLogTimeline').html(html);
                        } else {
                            $('#accessLogTimeline').html('<div class="text-center p-4">No activity recorded yet</div>');
                        }
                        
                        // Render users with access
                        if (result.grants && result.grants.length > 0) {
                            let grantsHtml = '<div class="list-group">';
                            result.grants.forEach(grant => {
                                const accessLevelBadge = grant.access_level === 'edit' ? 
                                    '<span class="badge" style="background: #fbbf24; color: black;">CAN EDIT</span>' : 
                                    '<span class="badge" style="background: #3b82f6;">VIEW ONLY</span>';
                                
                                const grantedToName = grant.granted_to_name || 'User ID: ' + grant.granted_to_responder_id;
                                const grantedByName = grant.granted_by_name || 'Unknown';
                                const isActive = grant.is_active == 1;
                                
                                grantsHtml += `
                                    <div class="list-group-item">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <i class="fas fa-user-circle"></i>
                                                <strong>${escapeHtml(grantedToName)}</strong>
                                                <div>${accessLevelBadge}</div>
                                                <small>Granted by: ${escapeHtml(grantedByName)}</small>
                                            </div>
                                            ${isActive ? `<button class="btn-icon danger" onclick="revokeAccess(${grant.grant_id})" title="Revoke Access"><i class="fas fa-times"></i></button>` : '<span class="badge">Revoked</span>'}
                                        </div>
                                    </div>
                                `;
                            });
                            grantsHtml += '</div>';
                            $('#usersWithAccessList').html(grantsHtml);
                        } else {
                            $('#usersWithAccessList').html('<div class="text-center p-4">No other users have access.</div>');
                        }
                    }
                }
            });
        }
        
        function revokeAccess(grantId) {
            Swal.fire({
                title: 'Revoke Access?',
                text: 'This user will no longer be able to access this report.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Yes, Revoke'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'admin_dashboard.php',
                        method: 'POST',
                        data: { action: 'revoke_report_access', grant_id: grantId },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire('Success', 'Access revoked', 'success');
                                if (currentAccessIncidentId) {
                                    viewAccessLog(currentAccessIncidentId, $('#accessLogTrackingId').text());
                                }
                            } else {
                                Swal.fire('Error', response.message || 'Failed to revoke', 'error');
                            }
                        }
                    });
                }
            });
        }
        
        // Tab switching in access modal
        $('#tabActivityBtn').click(function() {
            $(this).addClass('active');
            $('#tabUsersBtn').removeClass('active');
            $('#activityTabPanel').show();
            $('#usersTabPanel').hide();
        });
        
        $('#tabUsersBtn').click(function() {
            $(this).addClass('active');
            $('#tabActivityBtn').removeClass('active');
            $('#usersTabPanel').show();
            $('#activityTabPanel').hide();
        });
        
        // ============================================
        // ANALYTICS
        // ============================================
        
        $('.period-btn').click(function() {
            $('.period-btn').removeClass('active');
            $(this).addClass('active');
            currentPeriod = $(this).data('period');
            loadAnalytics(currentPeriod);
        });
        
        function loadAnalytics(period) {
            $.ajax({
                url: 'admin_dashboard.php',
                method: 'POST',
                data: { action: 'get_filtered_analytics', period: period },
                dataType: 'json',
                success: function(result) {
                    if (result.success) renderAnalytics(result.analytics);
                }
            });
        }
        
        function renderAnalytics(analytics) {
            Object.values(charts).forEach(c => { if (c) c.destroy(); });
            
            const theme = document.documentElement.getAttribute('data-theme') || 'light';
            const textColor = theme === 'light' ? '#1f2937' : '#e5e5e5';
            
            const statusCtx = document.getElementById('statusChart')?.getContext('2d');
            if (statusCtx && analytics.by_status) {
                charts.status = new Chart(statusCtx, {type: 'doughnut', data: {labels: analytics.by_status.map(s => s.status + ' (' + s.percentage + '%)'), datasets: [{data: analytics.by_status.map(s => s.count), backgroundColor: ['#fbbf24', '#3b82f6', '#10b981']}]}, options: {responsive: true, maintainAspectRatio: true, plugins: {legend: {labels: {color: textColor, font: {size: 10}}}}}});
                let pctHtml = '';
                analytics.by_status.forEach(s => { pctHtml += `<div><span style="color: var(--accent-yellow);">${s.status}:</span> ${s.count} (${s.percentage}%)</div>`; });
                $('#statusPercentages').html(pctHtml);
            }
            
            const typeCtx = document.getElementById('typeChart')?.getContext('2d');
            if (typeCtx && analytics.by_type) {
                charts.type = new Chart(typeCtx, {type: 'doughnut', data: {labels: analytics.by_type.map(t => t.incident_type + ' (' + t.percentage + '%)'), datasets: [{data: analytics.by_type.map(t => t.count), backgroundColor: ['#fbbf24', '#f59e0b', '#ef4444', '#3b82f6', '#10b981', '#8b5cf6']}]}, options: {responsive: true, maintainAspectRatio: true, plugins: {legend: {labels: {color: textColor, font: {size: 10}}}}}});
                let typePctHtml = '';
                analytics.by_type.slice(0, 4).forEach(t => { typePctHtml += `<div><span style="color: var(--accent-yellow);">${t.incident_type}:</span> ${t.percentage}%</div>`; });
                $('#typePercentages').html(typePctHtml);
            }
            
            const trendCtx = document.getElementById('trendChart')?.getContext('2d');
            if (trendCtx && analytics.trend) {
                charts.trend = new Chart(trendCtx, {type: 'line', data: {labels: analytics.trend.map(t => t.date), datasets: [{label: 'Incidents', data: analytics.trend.map(t => t.count), borderColor: '#fbbf24', backgroundColor: 'rgba(251, 191, 36, 0.1)', tension: 0.3, fill: true}]}, options: {responsive: true, maintainAspectRatio: true, scales: {y: {grid: {color: theme === 'light' ? '#e5e7eb' : '#2a2a2a'}, ticks: {color: textColor, font: {size: 9}}}, x: {ticks: {color: textColor, font: {size: 9}, maxRotation: 45}}}, plugins: {legend: {labels: {color: textColor, font: {size: 10}}}}}});
            }
            
            const sevCtx = document.getElementById('severityChart')?.getContext('2d');
            if (sevCtx && analytics.by_severity) {
                charts.severity = new Chart(sevCtx, {type: 'doughnut', data: {labels: analytics.by_severity.map(s => s.severity + ' (' + s.percentage + '%)'), datasets: [{data: analytics.by_severity.map(s => s.count), backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#6b7280']}]}, options: {responsive: true, maintainAspectRatio: true, plugins: {legend: {labels: {color: textColor, font: {size: 10}}}}}});
                let sevPctHtml = '';
                analytics.by_severity.forEach(s => { sevPctHtml += `<div><span style="color: var(--accent-yellow);">${s.severity}:</span> ${s.percentage}%</div>`; });
                $('#severityPercentages').html(sevPctHtml);
            }
            
            if (analytics.summary) {
                $('#analyticsSummary').html(`<div><h4 style="color: var(--accent-yellow);">${analytics.summary.total}</h4><p>Total</p></div><div><h4 style="color: var(--success);">${analytics.summary.completed}</h4><p>Completed</p></div><div><h4 style="color: var(--info);">${analytics.summary.ongoing}</h4><p>Ongoing</p></div><div><h4 style="color: var(--accent-yellow);">${analytics.summary.completion_rate}%</h4><p>Completion</p></div>`);
            }
        }
        
        // ============================================
        // MAP FUNCTIONS
        // ============================================
        
        function initLiveMap() {
            if (liveMap) { liveMap.remove(); liveMap = null; }
            liveMap = L.map('liveMap').setView([15.6333, 121.3167], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(liveMap);
            
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(pos => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    if (userLocationMarker) liveMap.removeLayer(userLocationMarker);
                    userLocationMarker = L.marker([lat, lng], {
                        icon: L.divIcon({ html: '<i class="fas fa-user-circle" style="color: #10b981; font-size: 24px;"></i>', className: 'user-location-marker', iconSize: [24, 24] })
                    }).addTo(liveMap).bindPopup('Your Location');
                    liveMap.setView([lat, lng], 13);
                });
            }
            
            loadMapData();
            setInterval(loadMapData, 30000);
        }
        
        function loadMapData() {
            if (!liveMap) return;
            
            $.get('api/get_map_data.php', function(data) {
                const result = typeof data === 'string' ? JSON.parse(data) : data;
                
                mapMarkers.forEach(m => liveMap.removeLayer(m));
                mapMarkers = [];
                
                let incidentCount = 0;
                
                if (result.incidents) {
                    result.incidents.forEach(incident => {
                        if (incident.location_lat && incident.location_lng) {
                            incidentCount++;
                            const color = getSeverityMapColor(incident.severity);
                            const popupContent = `
                                <div class="incident-popup">
                                    <h6><i class="fas fa-clipboard-list"></i> ${escapeHtml(incident.tracking_id)}</h6>
                                    <div class="popup-detail"><i class="fas fa-tag"></i> ${escapeHtml(incident.incident_type)}</div>
                                    <div class="popup-detail"><i class="fas fa-exclamation-triangle" style="color: ${color};"></i> Severity: ${escapeHtml(incident.severity || 'Minor')}</div>
                                    <div class="popup-detail"><i class="fas fa-map-marker-alt"></i> ${escapeHtml((incident.location_address || '').substring(0, 40))}...</div>
                                    <div class="popup-detail"><i class="far fa-clock"></i> ${new Date(incident.created_at).toLocaleString()}</div>
                                    <button class="btn-primary btn-sm" style="margin-top: 8px; width: 100%;" onclick="viewIncident(${incident.incident_id})">View Details</button>
                                </div>
                            `;
                            
                            const marker = L.circleMarker([incident.location_lat, incident.location_lng], {
                                radius: 12, fillColor: color, color: '#fff', weight: 2, fillOpacity: 0.9
                            }).bindPopup(popupContent, { maxWidth: 250 }).addTo(liveMap);
                            
                            mapMarkers.push(marker);
                        }
                    });
                }
                
                $('#mapIncidentCount').text(incidentCount);
                
                if (result.responders) {
                    responderMarkers.forEach(m => liveMap.removeLayer(m));
                    responderMarkers = [];
                    result.responders.forEach(r => {
                        if (r.location_lat && r.location_lng) {
                            const marker = L.marker([r.location_lat, r.location_lng], {
                                icon: L.divIcon({ html: '<i class="fas fa-user-shield" style="color: #3b82f6; font-size: 20px;"></i>', className: 'responder-marker', iconSize: [20, 20] })
                            }).bindPopup(`<strong>${escapeHtml(r.fullname)}</strong><br>${escapeHtml(r.responder_type || 'Responder')}`).addTo(liveMap);
                            responderMarkers.push(marker);
                        }
                    });
                    $('#mapResponderCount').text(result.responders.length);
                }
            });
        }
        
        function refreshMap() { loadMapData(); showToast('Map Refreshed', 'Latest incident data loaded', 'success'); }
        
        function centerMap() {
            if (liveMap) {
                liveMap.setView([15.6333, 121.3167], 12);
                showToast('Map Centered', 'Viewing Bongabon area', 'info');
            }
        }
        
        function locateMe() {
            if (!navigator.geolocation) { showToast('Error', 'Geolocation not supported', 'danger'); return; }
            
            navigator.geolocation.getCurrentPosition(pos => {
                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                
                if (userLocationMarker) liveMap.removeLayer(userLocationMarker);
                userLocationMarker = L.marker([lat, lng], {
                    icon: L.divIcon({ html: '<i class="fas fa-user-circle" style="color: #10b981; font-size: 24px;"></i>', className: 'user-location-marker', iconSize: [24, 24] })
                }).addTo(liveMap).bindPopup('Your Location').openPopup();
                
                liveMap.setView([lat, lng], 15);
                showToast('Location Found', 'Map centered on your location', 'success');
            }, () => showToast('Error', 'Could not get location', 'danger'));
        }
        
        function viewLocationOnMap(lat, lng, trackingId) {
            switchTab('map');
            
            setTimeout(() => {
                if (!liveMap) initLiveMap();
                
                setTimeout(() => {
                    if (liveMap) {
                        liveMap.setView([lat, lng], 16);
                        
                        const tempMarker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                html: '<i class="fas fa-map-marker-alt" style="color: #fbbf24; font-size: 28px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));"></i>',
                                className: 'highlighted-marker',
                                iconSize: [28, 28]
                            })
                        }).addTo(liveMap).bindPopup(`
                            <div class="incident-popup">
                                <h6><i class="fas fa-clipboard-list"></i> ${escapeHtml(trackingId)}</h6>
                                <div class="popup-detail"><i class="fas fa-map-marker-alt"></i> Selected Location</div>
                            </div>
                        `).openPopup();
                        
                        if (!window.tempHighlightMarkers) window.tempHighlightMarkers = [];
                        window.tempHighlightMarkers.push(tempMarker);
                        
                        setTimeout(() => {
                            if (liveMap && tempMarker) {
                                liveMap.removeLayer(tempMarker);
                                window.tempHighlightMarkers = window.tempHighlightMarkers.filter(m => m !== tempMarker);
                            }
                        }, 30000);
                        
                        showToast('Location Found', `Viewing location for ${trackingId}`, 'success');
                    }
                }, 300);
            }, 100);
        }
        
        function viewResponderLocation(lat, lng, name) {
            switchTab('map');
            
            setTimeout(() => {
                if (!liveMap) initLiveMap();
                
                setTimeout(() => {
                    if (liveMap) {
                        liveMap.setView([lat, lng], 16);
                        
                        const tempMarker = L.marker([lat, lng], {
                            icon: L.divIcon({
                                html: '<i class="fas fa-user-shield" style="color: #3b82f6; font-size: 28px;"></i>',
                                className: 'responder-highlight-marker',
                                iconSize: [28, 28]
                            })
                        }).addTo(liveMap).bindPopup(`<strong>${escapeHtml(name)}</strong><br>Responder Location`).openPopup();
                        
                        if (!window.tempHighlightMarkers) window.tempHighlightMarkers = [];
                        window.tempHighlightMarkers.push(tempMarker);
                        
                        setTimeout(() => {
                            if (liveMap && tempMarker) {
                                liveMap.removeLayer(tempMarker);
                            }
                        }, 30000);
                    }
                }, 300);
            }, 100);
        }
        
        // ============================================
        // RESPONDER FUNCTIONS
        // ============================================
        
        function showCreateResponderModal() { $('#createResponderForm')[0].reset(); $('#createResponderModal').modal('show'); }
        
        function createResponder() {
            $.post('admin_dashboard.php', $('#createResponderForm').serialize() + '&action=create_responder', function(response) {
                const data = JSON.parse(response);
                if (data.success) {
                    $('#createResponderModal').modal('hide');
                    Swal.fire({title: 'Success!', text: data.message, icon: 'success'});
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire({title: 'Error', text: data.message, icon: 'error'});
                }
            });
        }
        
        function toggleResponderStatus(userId, currentStatus) {
            $.post('admin_dashboard.php', {action: 'toggle_status', user_id: userId, current_status: currentStatus}, function() { location.reload(); });
        }
        
        function resetResponderPassword(userId) {
            Swal.fire({title: 'Reset Password?', text: 'New password: responder123', icon: 'question', showCancelButton: true, confirmButtonColor: '#fbbf24'}).then((result) => {
                if (result.isConfirmed) {
                    $.post('admin_dashboard.php', {action: 'reset_password', user_id: userId}, function(response) {
                        Swal.fire({title: 'Success', text: JSON.parse(response).message, icon: 'success'});
                    });
                }
            });
        }
        
        function deleteResponder(userId, fullname) {
            Swal.fire({title: 'Delete Responder?', text: `Delete ${fullname}?`, icon: 'error', showCancelButton: true, confirmButtonColor: '#dc2626'}).then((result) => {
                if (result.isConfirmed) {
                    $.post('admin_dashboard.php', {action: 'delete_responder', user_id: userId}, function() { location.reload(); });
                }
            });
        }
        
        // ============================================
        // VIEW FUNCTIONS
        // ============================================
        
        function viewIncident(id) {
            $.post('api/get_incident_full.php', {incident_id: id}, function(incident) {
                const severityBadge = getSeverityBadgeJs(incident.severity);
                const hasLocation = incident.location_lat && incident.location_lng;
                
                let html = `
                    <div style="background: var(--bg-secondary); border-left: 4px solid var(--accent-yellow); padding: 16px; border-radius: 12px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                            <code style="color: var(--accent-yellow); font-size: 16px; font-weight: bold;">${escapeHtml(incident.tracking_id)}</code>
                            ${severityBadge}
                        </div>
                        <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">${escapeHtml(incident.incident_type)}</div>
                        <div style="color: var(--text-muted);">
                            <i class="fas fa-map-marker-alt" style="color: var(--accent-yellow);"></i> 
                            ${escapeHtml(incident.location_address || 'No address provided')}
                        </div>
                    </div>
                    
                    <table style="width:100%; margin-bottom: 16px;">
                        <tr><td style="color: var(--text-muted); padding: 4px 0;">Reported by</td><td style="padding: 4px 0;">${escapeHtml(incident.reporter_name || 'Unknown')}</td></tr>
                        <tr><td style="color: var(--text-muted); padding: 4px 0;">Contact</td><td style="padding: 4px 0;">${escapeHtml(incident.reporter_phone || 'N/A')}</td></tr>
                        <tr><td style="color: var(--text-muted); padding: 4px 0;">Reported at</td><td style="padding: 4px 0;">${new Date(incident.created_at).toLocaleString()}</td></tr>
                        <tr><td style="color: var(--text-muted); padding: 4px 0;">Status</td><td style="padding: 4px 0;"><span class="incident-badge ${incident.status}">${incident.status.toUpperCase()}</span></td></tr>
                        ${incident.taken_by_name ? `<tr><td style="color: var(--text-muted); padding: 4px 0;">Taken by</td><td style="padding: 4px 0;">${escapeHtml(incident.taken_by_name)}</td></tr>` : ''}
                        ${incident.finished_by_name ? `<tr><td style="color: var(--text-muted); padding: 4px 0;">Completed by</td><td style="padding: 4px 0;">${escapeHtml(incident.finished_by_name)}</td></tr>` : ''}
                    </table>
                    
                    <div style="margin-top: 16px; padding: 12px; background: var(--stat-icon-bg); border-radius: 8px;">
                        <strong style="color: var(--accent-yellow);">Description:</strong><br>
                        ${escapeHtml(incident.description || 'No description provided')}
                    </div>
                `;
                
                if (hasLocation) {
                    html += `
                        <div style="margin-top: 20px; text-align: center;">
                            <button class="btn-primary" style="width: 100%;" onclick="viewLocationOnMap(${incident.location_lat}, ${incident.location_lng}, '${escapeHtml(incident.tracking_id)}'); $('#incidentModal').modal('hide');">
                                <i class="fas fa-map-marked-alt"></i> View Location on Map
                            </button>
                        </div>
                    `;
                }
                
                $('#incidentModalBody').html(html);
                $('#incidentModal').modal('show');
            }, 'json').fail(() => $('#incidentModalBody').html('<p class="text-danger">Error loading incident details</p>'));
        }
        
        function viewCompletedReport(reportId) {
            $.post('admin_dashboard.php', {action: 'get_completed_report', report_id: reportId}, function(result) {
                if (result.success) {
                    const report = result.report;
                    const data = JSON.parse(report.report_data);
                    const html = generateFullReportHTML(report, data);
                    $('#completedReportBody').html(html);
                    $('#completedReportModal').modal('show');
                }
            }, 'json');
        }
        
        function generateFullReportHTML(report, data) {
            const severityBadge = getSeverityBadgeJs(report.severity);
            let html = `<div class="report-viewer" style="background:white;color:black;padding:20px;font-family:'Times New Roman',serif;">
                <div style="text-align:center;margin-bottom:20px;"><h2>INCIDENT REPORT</h2><p><strong>Tracking ID:</strong> ${escapeHtml(report.tracking_id)} | <strong>Completed by:</strong> ${escapeHtml(report.responder_name)} | <strong>Date:</strong> ${new Date(report.submitted_at).toLocaleString()}</p><p>${severityBadge}</p></div>
                <div style="display:flex;gap:20px;"><div style="flex:1;">
                <table style="width:100%;border-collapse:collapse;border:1px solid #000;margin-bottom:16px;"><tr><th colspan="2" style="background:#e67e22;color:white;padding:8px;">INCIDENT DETAILS</th></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Date:</strong></td><td style="border:1px solid #000;padding:6px;">${escapeHtml(data.incidentDate || 'N/A')}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Time of Call:</strong></td><td>${escapeHtml(data.callTime || 'N/A')}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Incident Type:</strong></td><td>${escapeHtml(data.incidentPurpose || report.incident_type)}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Location:</strong></td><td>${escapeHtml(data.placeIncident || report.location_address)}</td></tr>
                </table>
                <table style="width:100%;border-collapse:collapse;border:1px solid #000;margin-bottom:16px;"><tr><th colspan="2" style="background:#e67e22;color:white;padding:8px;">PATIENT INFORMATION</th></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Name:</strong></td><td>${escapeHtml(data.patientName || 'N/A')}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Age:</strong></td><td>${escapeHtml(data.patientAge || 'N/A')}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Gender:</strong></td><td>${escapeHtml(data.patientGender || 'N/A')}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Address:</strong></td><td>${escapeHtml(data.patientAddress || 'N/A')}</td></tr>
                </table>`;
            
            if (data.bodyImage) {
                html += `<table style="width:100%;border-collapse:collapse;border:1px solid #000;margin-bottom:16px;"><tr><th colspan="2" style="background:#e67e22;color:white;padding:8px;">INJURY MAP</th></tr>
                <tr><td colspan="2" style="border:1px solid #000;padding:10px;text-align:center;"><img src="${data.bodyImage}" style="max-width:100%;"></td></tr>
                </table>`;
            }
            
            html += `<table style="width:100%;border-collapse:collapse;border:1px solid #000;margin-bottom:16px;"><tr><th colspan="2" style="background:#e67e22;color:white;padding:8px;">CHIEF COMPLAINT</th></tr>
                <tr><td colspan="2" style="border:1px solid #000;padding:10px;">${escapeHtml(data.chiefComplaint || 'N/A')}</td></tr>
                </table>
                </div><div style="flex:1;">
                <table style="width:100%;border-collapse:collapse;border:1px solid #000;margin-bottom:16px;"><tr><th colspan="2" style="background:#e67e22;color:white;padding:8px;">VITAL SIGNS</th></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>BP:</strong></td><td>${escapeHtml(data.bp || 'N/A')}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Pulse:</strong></td><td>${escapeHtml(data.pulse || 'N/A')}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Respiratory:</strong></td><td>${escapeHtml(data.respiratory || 'N/A')}</td></tr>
                <tr><td style="border:1px solid #000;padding:6px;background:#fef9e6;"><strong>Temperature:</strong></td><td>${escapeHtml(data.temperature || 'N/A')}</td></tr>
                </table>
                <table style="width:100%;border-collapse:collapse;border:1px solid #000;margin-bottom:16px;"><tr><th colspan="2" style="background:#e67e22;color:white;padding:8px;">MANAGEMENT / INTERVENTION</th></tr>
                <tr><td colspan="2" style="border:1px solid #000;padding:10px;">${escapeHtml(data.actionsGiven || 'N/A')}</td></tr>
                </table>
                <table style="width:100%;border-collapse:collapse;border:1px solid #000;margin-bottom:16px;"><tr><th colspan="2" style="background:#e67e22;color:white;padding:8px;">PROVIDER INFORMATION</th></tr>
                <tr><td colspan="2" style="border:1px solid #000;padding:10px;">Crew 1: ${escapeHtml(data.crew1 || 'N/A')}<br>Crew 2: ${escapeHtml(data.crew2 || 'N/A')}<br>Driver: ${escapeHtml(data.driver || 'N/A')}<br>Vehicle: ${escapeHtml(data.vehicle || 'N/A')}</td></tr>
                </table>
                </div></div>`;
            
            if (data.patientSig) {
                html += `<div style="margin-top:16px;"><strong>Patient Signature:</strong> <img src="${data.patientSig}" style="max-width:200px; border:1px solid #ddd; padding:5px;"></div>`;
            }
            
            html += `</div>`;
            return html;
        }
        
        // ============================================
        // INITIALIZATION
        // ============================================
        
        $(document).ready(function() {
            updateDateTime();
            setInterval(updateDateTime, 1000);
            startAutoRefresh();
            
            // Initial data load
            refreshAllData();
            
            $(document).click(function(e) {
                if ($(window).width() <= 768) {
                    if (!$(e.target).closest('.sidebar').length && !$(e.target).closest('.menu-toggle').length) {
                        $('#sidebar').removeClass('open');
                    }
                }
            });
            
            $('#fullReportEditorModal').on('shown.bs.modal', function() {
                initSignatures();
                initInjuryMap();
                if (!editingIncidentId) {
                    const year = new Date().getFullYear();
                    $('#reportTrackingId').val(`ADMIN-${year}-${String(Math.floor(Math.random() * 1000)).padStart(3, '0')}`);
                }
            });
            
            lastIncidentCount = <?= $stats['total_reports'] ?>;
            
            // Initialize with current data
            if (allIncidentsData.length === 0) {
                $.ajax({
                    url: 'admin_dashboard.php',
                    method: 'POST',
                    data: { action: 'get_all_incidents' },
                    dataType: 'json',
                    success: function(result) {
                        if (result.success) {
                            allIncidentsData = result.incidents;
                            updateStatsAndLists(result.incidents);
                        }
                    }
                });
            }
            
            if (allCompletedReportsData.length === 0) {
                $.ajax({
                    url: 'admin_dashboard.php',
                    method: 'POST',
                    data: { action: 'get_completed_reports' },
                    dataType: 'json',
                    success: function(result) {
                        if (result.success) {
                            allCompletedReportsData = result.reports;
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>