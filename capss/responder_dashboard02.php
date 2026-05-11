<?php
// responder_dashboard.php - Complete responder interface with access tracking
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    header('Location: responder_login.php');
    exit;
}

// Get pending incidents - ORDER BY created_at DESC (newest on top)
$pending = $conn->query("
    SELECT i.*, 
           (SELECT COUNT(*) FROM tbl_incident_photos WHERE incident_id = i.incident_id) as photo_count
    FROM tbl_incidents i 
    WHERE i.status = 'pending'
    ORDER BY i.created_at DESC
");

// Get my active incidents - newest on top
$stmt = $conn->prepare("
    SELECT i.*, 
           (SELECT COUNT(*) FROM tbl_incident_photos WHERE incident_id = i.incident_id) as photo_count
    FROM tbl_incidents i 
    WHERE i.taken_by_responder_id = ? AND i.status != 'completed'
    ORDER BY i.created_at DESC
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$active = $stmt->get_result();

// Get my completed incidents - newest on top
$stmt2 = $conn->prepare("
    SELECT i.*
    FROM tbl_incidents i 
    WHERE i.finished_by_responder_id = ?
    ORDER BY i.finished_at DESC LIMIT 20
");
$stmt2->bind_param("i", $_SESSION['user_id']);
$stmt2->execute();
$completed = $stmt2->get_result();

// Check for query errors
if (!$pending) {
    die("Error in pending query: " . $conn->error);
}

// Get statistics
$pending_count = $pending->num_rows;
$active_count = $active->num_rows;
$completed_count = $completed->num_rows;

// Check if drafts table exists, create if not
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_drafts'");
if ($table_check->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_incident_drafts (
        draft_id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        draft_data LONGTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_incident (incident_id)
    )");
}

// Check if notifications table exists
$notif_check = $conn->query("SHOW TABLES LIKE 'tbl_notifications'");
if ($notif_check->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        incident_id INT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        type ENUM('info', 'warning', 'success', 'danger') DEFAULT 'info',
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// Check if responder actions table exists
$actions_check = $conn->query("SHOW TABLES LIKE 'tbl_responder_actions'");
if ($actions_check->num_rows == 0) {
    $conn->query("CREATE TABLE IF NOT EXISTS tbl_responder_actions (
        action_id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        responder_id INT NOT NULL,
        action_type ENUM('viewed', 'taken', 'arrived', 'completed', 'transferred') DEFAULT 'viewed',
        location_lat DECIMAL(10,8) NULL,
        location_lng DECIMAL(11,8) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
}

// Create access tracking tables if they don't exist
$access_log_table = $conn->query("SHOW TABLES LIKE 'tbl_report_access_log'");
if ($access_log_table->num_rows == 0) {
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

// Handle AJAX request to save draft data for active incident
if (isset($_POST['action']) && $_POST['action'] === 'save_active_draft') {
    header('Content-Type: application/json');
    $incident_id = intval($_POST['incident_id']);
    $draft_data = $_POST['draft_data'];
    
    $check = $conn->prepare("SELECT draft_id FROM tbl_incident_drafts WHERE incident_id = ?");
    $check->bind_param("i", $incident_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE tbl_incident_drafts SET draft_data = ?, updated_at = NOW() WHERE incident_id = ?");
        $stmt->bind_param("si", $draft_data, $incident_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO tbl_incident_drafts (incident_id, draft_data) VALUES (?, ?)");
        $stmt->bind_param("is", $incident_id, $draft_data);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Draft saved successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving draft: ' . $conn->error]);
    }
    exit;
}

// Handle AJAX request to load draft for active incident
if (isset($_POST['action']) && $_POST['action'] === 'load_active_draft') {
    header('Content-Type: application/json');
    $incident_id = intval($_POST['incident_id']);
    
    $stmt = $conn->prepare("SELECT draft_data FROM tbl_incident_drafts WHERE incident_id = ?");
    $stmt->bind_param("i", $incident_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'draft_data' => $row['draft_data']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No draft found']);
    }
    exit;
}

// Handle AJAX request to move completed report from active list
if (isset($_POST['action']) && $_POST['action'] === 'remove_completed_report') {
    header('Content-Type: application/json');
    $incident_id = intval($_POST['incident_id']);
    $stmt = $conn->prepare("UPDATE tbl_incidents SET status = 'completed', finished_at = NOW(), finished_by_responder_id = ? WHERE incident_id = ? AND taken_by_responder_id = ?");
    $stmt->bind_param("iii", $_SESSION['user_id'], $incident_id, $_SESSION['user_id']);
    if ($stmt->execute()) {
        $deleteStmt = $conn->prepare("DELETE FROM tbl_incident_drafts WHERE incident_id = ?");
        $deleteStmt->bind_param("i", $incident_id);
        $deleteStmt->execute();
        
        // Log completion action
        $logStmt = $conn->prepare("INSERT INTO tbl_responder_actions (incident_id, responder_id, action_type) VALUES (?, ?, 'completed')");
        $logStmt->bind_param("ii", $incident_id, $_SESSION['user_id']);
        $logStmt->execute();
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

// Handle AJAX request to create a new incident report (from scratch)
if (isset($_POST['action']) && $_POST['action'] === 'create_new_incident') {
    header('Content-Type: application/json');
    
    $tracking_id = 'SELF-' . strtoupper(uniqid());
    $incident_type = $_POST['incident_type'] ?? 'Medical';
    $location_address = $_POST['location_address'] ?? '';
    $location_lat = isset($_POST['location_lat']) && $_POST['location_lat'] !== 'null' ? $_POST['location_lat'] : null;
    $location_lng = isset($_POST['location_lng']) && $_POST['location_lng'] !== 'null' ? $_POST['location_lng'] : null;
    $severity = $_POST['severity'] ?? 'low';
    $description = $_POST['description'] ?? '';
    $reporter_name = $_POST['reporter_name'] ?? $_SESSION['fullname'] ?? 'Responder';
    $reporter_phone = $_POST['reporter_phone'] ?? '';
    
    $stmt = $conn->prepare("INSERT INTO tbl_incidents (tracking_id, incident_type, location_address, location_lat, location_lng, severity, description, reporter_name, reporter_phone, status, taken_by_responder_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'dispatched', ?, NOW())");
    $stmt->bind_param("sssdsssssi", $tracking_id, $incident_type, $location_address, $location_lat, $location_lng, $severity, $description, $reporter_name, $reporter_phone, $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $incident_id = $conn->insert_id;
        echo json_encode(['success' => true, 'incident_id' => $incident_id, 'tracking_id' => $tracking_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error creating incident: ' . $conn->error]);
    }
    exit;
}

// ============================================
// SEVERITY FUNCTIONS
// ============================================

function getSeverityBadge($severity) {
    $severity = trim(strtolower($severity));
    if (empty($severity)) {
        return '<span class="severity-minor"><i class="fas fa-band-aid"></i> MINOR</span>';
    }
    
    if ($severity === 'dead' || $severity === 'deceased' || $severity === 'black') {
        return '<span class="severity-dead"><i class="fas fa-skull"></i> DEAD</span>';
    }
    if ($severity === 'high' || $severity === 'critical' || $severity === 'immediate' || $severity === 'red') {
        return '<span class="severity-immediate"><i class="fas fa-exclamation-triangle"></i> IMMEDIATE</span>';
    }
    if ($severity === 'moderate' || $severity === 'delayed' || $severity === 'yellow' || $severity === 'serious') {
        return '<span class="severity-delayed"><i class="fas fa-exclamation-circle"></i> DELAYED</span>';
    }
    if ($severity === 'low' || $severity === 'minor' || $severity === 'green') {
        return '<span class="severity-minor"><i class="fas fa-band-aid"></i> MINOR</span>';
    }
    return '<span class="severity-minor"><i class="fas fa-band-aid"></i> MINOR</span>';
}

function getSeverityBorderColor($severity) {
    $severity = trim(strtolower($severity));
    if (empty($severity)) return '#059669';
    if ($severity === 'dead' || $severity === 'deceased' || $severity === 'black') return '#1f2937';
    if ($severity === 'high' || $severity === 'critical' || $severity === 'immediate' || $severity === 'red') return '#dc2626';
    if ($severity === 'moderate' || $severity === 'delayed' || $severity === 'yellow' || $severity === 'serious') return '#d97706';
    if ($severity === 'low' || $severity === 'minor' || $severity === 'green') return '#059669';
    return '#059669';
}

function getSeverityBgColor($severity) {
    $severity = trim(strtolower($severity));
    if (empty($severity)) return '#ecfdf5';
    if ($severity === 'dead' || $severity === 'deceased' || $severity === 'black') return '#f3f4f6';
    if ($severity === 'high' || $severity === 'critical' || $severity === 'immediate' || $severity === 'red') return '#fef2f2';
    if ($severity === 'moderate' || $severity === 'delayed' || $severity === 'yellow' || $severity === 'serious') return '#fffbeb';
    if ($severity === 'low' || $severity === 'minor' || $severity === 'green') return '#ecfdf5';
    return '#ecfdf5';
}

function getSeverityClass($severity) {
    $severity = trim(strtolower($severity));
    if (empty($severity)) return 'severity-minor';
    if ($severity === 'dead' || $severity === 'deceased' || $severity === 'black') return 'severity-dead';
    if ($severity === 'high' || $severity === 'critical' || $severity === 'immediate' || $severity === 'red') return 'severity-immediate';
    if ($severity === 'moderate' || $severity === 'delayed' || $severity === 'yellow' || $severity === 'serious') return 'severity-delayed';
    if ($severity === 'low' || $severity === 'minor' || $severity === 'green') return 'severity-minor';
    return 'severity-minor';
}

function formatTrackingIdWithDateTime($tracking_id, $created_at) {
    $date = new DateTime($created_at);
    $formattedDate = $date->format('M d, Y H:i');
    return '<div class="tracking-id-wrapper"><span class="tracking-id">' . htmlspecialchars($tracking_id) . '</span><span class="tracking-datetime"><i class="far fa-calendar-alt"></i> ' . $formattedDate . '</span></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>MDRRMO Bongabon - Responder Dashboard</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: #e0e0e0;
            font-family: 'Times New Roman', 'Arial', 'Courier New', serif;
            min-height: 100vh;
            overflow-x: hidden;
        }
        .menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1001;
            background: #e67e22;
            color: white;
            border: none;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            font-size: 24px;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            display: none;
            align-items: center;
            justify-content: center;
        }
        .side-menu {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: #1e2a36;
            color: white;
            z-index: 1000;
            transform: translateX(0);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 20px rgba(0,0,0,0.3);
            overflow-y: auto;
        }
        .side-menu.closed { transform: translateX(-100%); }
        .menu-header {
            padding: 25px 20px;
            background: #0f1a24;
            text-align: center;
            border-bottom: 1px solid #2c3e50;
            position: relative;
        }
        .menu-header h2 { font-size: 18px; margin-bottom: 5px; }
        .menu-header p { font-size: 10px; opacity: 0.7; }
        .close-menu {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            display: none;
        }
        .menu-nav { flex: 1; padding: 20px 0; }
        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 20px;
            margin: 5px 10px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            color: #ecf0f1;
            font-weight: 500;
        }
        .menu-item i { width: 24px; font-size: 18px; }
        .menu-item:hover { background: #2c3e50; }
        .menu-item.active { background: #e67e22; color: white; box-shadow: 0 2px 8px rgba(230,126,34,0.3); }
        .menu-item.logout-item { color: #e74c3c; margin-top: auto; border-top: 1px solid #2c3e50; border-radius: 0; margin: 10px; border-radius: 12px; }
        .menu-item.logout-item:hover { background: #e74c3c; color: white; }
        .main-content {
            margin-left: 280px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }
        .main-content.expanded { margin-left: 0; }
        .tab-pane { display: none; animation: fadeIn 0.25s ease; }
        .tab-pane.active { display: block; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dashboard-header {
            background: linear-gradient(135deg, #1e2a36, #2c3e50);
            color: white;
            padding: 20px 25px;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .dashboard-header h1 { font-size: 24px; margin-bottom: 8px; }
        .dashboard-header p { opacity: 0.9; font-size: 13px; }
        .stats-row { display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap; }
        .stat-card { background: rgba(255,255,255,0.15); padding: 10px 18px; border-radius: 40px; font-size: 13px; backdrop-filter: blur(5px); }
        .page {
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            padding: 20px 15px;
            border-radius: 16px;
            font-size: 12px;
            line-height: 1.3;
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
        }
        .report-card {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 12px;
            transition: all 0.2s;
            cursor: pointer;
            background: white;
            border-left: 6px solid;
        }
        .report-card:hover { transform: translateX(3px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .report-card.severity-dead { border-left-color: #1f2937 !important; background: #f3f4f6 !important; }
        .report-card.severity-immediate { border-left-color: #dc2626 !important; background: #fef2f2 !important; }
        .report-card.severity-delayed { border-left-color: #d97706 !important; background: #fffbeb !important; }
        .report-card.severity-minor { border-left-color: #059669 !important; background: #ecfdf5 !important; }
        .report-card.completed { border-left-color: #27ae60; background: #f0fdf4; }
        .report-card.draft { border-left-color: #e67e22; background: #fff7ed; }
        .report-card.active { border-left-color: #17a2b8; background: #f0f9ff; }
        .report-card.trash { border-left-color: #7f8c8d; background: #f8f9fa; opacity: 0.8; }
        
        .badge-pending { background: #ffc107; color: #000; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-taken { background: #17a2b8; color: white; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-draft { background: #e67e22; color: white; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-trash { background: #7f8c8d; color: white; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        .badge-active { background: #17a2b8; color: white; padding: 3px 10px; border-radius: 20px; font-size: 11px; }
        
        .severity-minor { background: #059669 !important; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 6px; }
        .severity-delayed { background: #d97706 !important; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 6px; }
        .severity-immediate { background: #dc2626 !important; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 6px; }
        .severity-dead { background: #1f2937 !important; color: white; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-flex; align-items: center; gap: 6px; }
        
        .tracking-id-wrapper { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .tracking-id { font-weight: bold; font-size: 14px; background: #e9ecef; padding: 2px 8px; border-radius: 15px; font-family: monospace; color: #1a1a1a; }
        .tracking-datetime { font-size: 11px; color: #6c757d; }
        .tracking-datetime i { margin-right: 3px; }
        
        @media (max-width: 768px) {
            .menu-toggle { display: flex; }
            .side-menu { width: 280px; z-index: 1002; }
            .close-menu { display: block; }
            .main-content { margin-left: 0; padding: 60px 12px 20px 12px; }
        }
        .photo-thumb { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; cursor: pointer; margin: 5px; }
        .refresh-btn { background: #e67e22; color: white; border: none; padding: 8px 16px; border-radius: 30px; margin-bottom: 15px; cursor: pointer; }
        .refresh-btn:hover { background: #d35400; }
        .incident-stats { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 15px; }
        #liveMap { height: 500px; border-radius: 12px; margin-bottom: 15px; }
        .my-location-btn { background: #e67e22; color: white; border: none; padding: 10px 20px; border-radius: 30px; margin-bottom: 10px; cursor: pointer; }
        .my-location-btn:hover { background: #d35400; }
        .new-incident-alert { animation: slideDown 0.5s ease; }
        @keyframes slideDown { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .two-column-layout { display: flex; gap: 15px; flex-wrap: wrap; }
        .left-column, .right-column { flex: 1; min-width: 280px; }
        .header { text-align: center; margin-bottom: 15px; }
        .logo-row { display: flex; justify-content: center; align-items: center; gap: 10px; flex-wrap: wrap; }
        .logo-box { width: 70px; height: 70px; position: relative; display: flex; align-items: center; justify-content: center; background: white; }
        .logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .logo-upload { position: absolute; bottom: -20px; font-size: 6px; background: #3498db; color: white; padding: 2px 5px; border-radius: 3px; cursor: pointer; white-space: nowrap; }
        .report-title { font-size: 18px; font-weight: bold; text-align: center; margin: 10px 0; }
        .form-table { width: 100%; border-collapse: collapse; margin: 8px 0; font-size: 11px; background: white; }
        .form-table td, .form-table th { border: 1px solid #000000; padding: 8px 6px; vertical-align: top; background: white; }
        .label-cell { font-weight: bold; width: 40%; }
        .section-header { font-weight: bold; text-align: center; font-size: 12px; background: #f8f9fa; }
        input, textarea, select { width: 100%; border: none; background: white; font-family: inherit; font-size: 11px; padding: 4px; resize: vertical; border-bottom: 1px dotted #ccc; }
        .signature-area { border: 1px solid #ccc; background: white; margin-top: 5px; border-radius: 6px; }
        .signature-canvas { width: 100%; height: auto; min-height: 80px; border: 1px solid #ddd; background: #fff; touch-action: none; border-radius: 4px; }
        .sig-buttons { display: flex; gap: 8px; margin-top: 6px; }
        .sig-btn { font-size: 10px; padding: 5px 12px; background: #e0e0e0; border: none; border-radius: 5px; cursor: pointer; }
        .canvas-wrapper { border: 2px solid #333; background: white; display: inline-block; width: 100%; max-width: 360px; border-radius: 8px; overflow: hidden; background: #fff; }
        canvas#bodyCanvas { display: block; cursor: crosshair; background: white; width: 100%; height: auto; touch-action: none; }
        .draw-tools { display: flex; gap: 6px; justify-content: center; margin: 8px 0; flex-wrap: wrap; align-items: center; }
        .tool-btn { padding: 6px 12px; font-size: 11px; border: 1px solid #aaa; border-radius: 6px; cursor: pointer; background: #f0f0f0; }
        .tool-btn.active { background: #3498db; color: white; }
        .color-picker { width: 35px; height: 35px; border: 1px solid #ccc; cursor: pointer; border-radius: 4px; }
        .incident-images-section { margin-top: 20px; }
        .print-image-gallery { display: flex; flex-direction: column; gap: 15px; margin-top: 10px; }
        .gallery-item { position: relative; width: 100%; border: 2px solid #ddd; border-radius: 8px; overflow: hidden; background: white; }
        .gallery-item img { width: 100%; height: auto; display: block; }
        .gallery-item .remove-img { position: absolute; top: 10px; right: 10px; background: rgba(255,0,0,0.9); color: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-weight: bold; }
        .upload-images-btn, .clear-images-btn { padding: 8px 16px; border-radius: 8px; cursor: pointer; border: none; font-weight: bold; }
        .upload-images-btn { background: #27ae60; color: white; }
        .clear-images-btn { background: #e67e22; color: white; }
        .refusal-text { font-size: 10px; line-height: 1.3; text-align: justify; background: #fef9e6; padding: 8px; border-radius: 6px; }
        .fab-container { position: sticky; bottom: 20px; float: right; z-index: 100; margin-top: 20px; clear: both; }
        .fab-main { width: 56px; height: 56px; background: #e67e22; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,0.3); color: white; font-size: 24px; }
        .fab-menu { position: absolute; bottom: 70px; right: 0; background: white; border-radius: 16px; box-shadow: 0 8px 25px rgba(0,0,0,0.2); min-width: 180px; overflow: hidden; display: none; flex-direction: column; z-index: 101; }
        .fab-menu.show { display: flex; }
        .fab-menu-item { padding: 12px 18px; display: flex; align-items: center; gap: 12px; cursor: pointer; background: white; border: none; font-size: 14px; font-weight: 500; text-align: left; width: 100%; }
        .fab-menu-item:hover { background: #f5f5f5; }
        .fab-menu-item i { width: 24px; color: #e67e22; }
        .filipino-text { font-size: 9px; color: #444; font-style: italic; display: inline-block; margin-left: 4px; }
        .btn-view-location { background: #3498db; color: white; border: none; padding: 5px 12px; border-radius: 20px; font-size: 11px; cursor: pointer; margin-top: 5px; display: none !important; }
        .btn-view-location:hover { background: #2980b9; }
        .btn-complete-active { background: #27ae60; color: white; border: none; padding: 5px 12px; border-radius: 20px; font-size: 11px; cursor: pointer; margin-top: 5px; margin-left: 5px; display: none !important; }
        .btn-complete-active:hover { background: #219a52; }
        .btn-delete { background: #dc2626; color: white; border: none; padding: 5px 12px; border-radius: 20px; font-size: 11px; cursor: pointer; margin-top: 5px; margin-left: 5px; display: none !important; }
        .btn-delete:hover { background: #b91c1c; }
        .btn-restore { background: #059669; color: white; border: none; padding: 5px 12px; border-radius: 20px; font-size: 11px; cursor: pointer; margin-top: 5px; margin-left: 5px; }
        .btn-restore:hover { background: #047857; }
        .btn-access { background: #8e44ad; color: white; border: none; padding: 5px 12px; border-radius: 20px; font-size: 11px; cursor: pointer; margin-top: 5px; margin-left: 5px; display: none !important; }
        .btn-access:hover { background: #732d91; }
        .action-buttons-group { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; display: none !important; }
        .empty-state { text-align: center; padding: 40px; color: #6c757d; }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.5; }
        .draft-badge { background: #e67e22; color: white; font-size: 10px; padding: 2px 8px; border-radius: 12px; margin-left: 8px; }
        @media print { .side-menu, .menu-toggle, .fab-container, .draw-tools, .sig-buttons, .logo-upload, .action-buttons, .incident-images-tools { display: none !important; } .main-content { margin-left: 0; padding: 0; } .page { box-shadow: none; padding: 0; } .form-table td, .form-table th { border: 1px solid #000 !important; background: white !important; } }
        .trash-info { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 10px 15px; margin-bottom: 15px; border-radius: 8px; font-size: 12px; }
        .auto-save-indicator { position: fixed; bottom: 20px; left: 20px; background: #27ae60; color: white; padding: 8px 16px; border-radius: 30px; font-size: 12px; z-index: 1000; display: none; animation: fadeInOut 2s ease; }
        @keyframes fadeInOut { 0% { opacity: 0; transform: translateY(20px); } 15% { opacity: 1; transform: translateY(0); } 85% { opacity: 1; transform: translateY(0); } 100% { opacity: 0; transform: translateY(-20px); } }
        .form-loading-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.95); display: flex; align-items: center; justify-content: center; z-index: 1000; border-radius: 16px; flex-direction: column; gap: 15px; }
        .form-loading-overlay .spinner-border { width: 3rem; height: 3rem; }
        .page { position: relative; }
        
        /* Toast Notification Styles */
        .notification-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1100;
            min-width: 300px;
            max-width: 400px;
        }
        .notification-toast .toast {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .notification-toast .toast-header.warning { background: #f59e0b; color: white; }
        .notification-toast .toast-header.success { background: #10b981; color: white; }
        .notification-toast .toast-header.danger { background: #ef4444; color: white; }
        .notification-toast .toast-header.info { background: #3b82f6; color: white; }
        
        /* Access Log Timeline Styles */
        .access-log-item {
            transition: all 0.2s ease;
        }
        .access-log-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .timeline {
            max-height: 500px;
            overflow-y: auto;
        }

        .report-card.shared-report-item {
            border-left-color: #8e44ad !important;
            background: #f9f5ff !important;
        }

        .report-card.shared-report-item:hover {
            transform: translateX(3px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .badge-shared {
            background: #8e44ad;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
        }

        .share-icon {
            color: #8e44ad;
            margin-right: 5px;
        }
        
        .modal-content { border-radius: 16px; }
        .modal-header { background: #e67e22; color: white; border-radius: 16px 16px 0 0; }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        .form-section { margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 12px; }
        .form-section-title { font-weight: bold; margin-bottom: 15px; font-size: 14px; border-left: 3px solid #e67e22; padding-left: 10px; }
        .get-location-btn { background: #3498db; color: white; border: none; padding: 5px 12px; border-radius: 20px; font-size: 11px; cursor: pointer; margin-top: 5px; }
        .get-location-btn:hover { background: #2980b9; }
        .report-card-details { color: #374151; }
        .report-card-title { color: #1f2937; }
        .report-card .text-muted { color: #6b7280 !important; }
        .report-type-badge { background: #e67e22; color: white; font-size: 10px; padding: 2px 8px; border-radius: 12px; margin-left: 8px; }
        .badge-pending, .badge-active, .badge-taken { color: #000; }
        .badge-trash, .badge-draft { color: #fff; }

        /* Report Card Header Layout */
        .report-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            width: 100%;
        }

        .report-card-header-left {
            flex: 1;
            min-width: 0;
        }

        .report-card-header-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
            margin-left: 10px;
        }

        /* Action Dropdown Menu Styles */
        .action-dropdown {
            position: relative;
            display: inline-block;
            flex-shrink: 0;
        }

        .action-menu-btn {
            background: transparent;
            border: none;
            color: #6c757d;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 16px;
        }

        .action-menu-btn:hover {
            background: #e9ecef;
            color: #e67e22;
        }

        .action-menu-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            min-width: 200px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.2s ease;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }

        .action-menu-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .action-menu-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: background 0.15s ease;
            font-size: 13px;
            color: #374151;
            border-bottom: 1px solid #f1f5f9;
        }

        .action-menu-item:last-child {
            border-bottom: none;
        }

        .action-menu-item:hover {
            background: #f8fafc;
        }

        .action-menu-item i {
            width: 20px;
            color: #e67e22;
            font-size: 14px;
        }

        .action-menu-item.text-danger {
            color: #dc2626;
        }

        .action-menu-item.text-danger i {
            color: #dc2626;
        }

        .action-menu-item.text-danger:hover {
            background: #fef2f2;
        }

        .report-card .text-muted {
            white-space: nowrap;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
<button class="menu-toggle" id="menuToggleBtn"><i class="fas fa-bars"></i></button>
<div class="side-menu" id="sideMenu">
    <div class="menu-header">
        <button class="close-menu" id="closeMenuBtn"><i class="fas fa-times"></i></button>
        <h2><i class="fas fa-ambulance"></i> MDRRMO Bongabon</h2>
        <p>Responder: <?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Responder') ?></p>
    </div>
    <div class="menu-nav">
        <div class="menu-item active" data-tab="dashboard-tab"><i class="fas fa-tachometer-alt"></i> Dashboard</div>
        <div class="menu-item" data-tab="my-reports-tab"><i class="fas fa-folder-open"></i> My Reports</div>
        <div class="menu-item" data-tab="trash-tab"><i class="fas fa-trash-alt"></i> Trash</div>
        <div class="menu-item" data-tab="live-map-tab"><i class="fas fa-map"></i> Live Map</div>
        <div class="menu-item" data-tab="report-form-tab"><i class="fas fa-file-alt"></i> Incident Report Form</div>
        <hr style="margin: 15px 20px; border-color: #2c3e50;">
        <div class="menu-item logout-item" id="logoutBtn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </div>
    </div>
</div>

<div class="main-content" id="mainContent">
    <div id="autoSaveIndicator" class="auto-save-indicator">
        <i class="fas fa-save"></i> Draft auto-saved
    </div>
    
    <!-- Toast Notification Container -->
    <div class="notification-toast" id="notificationToast"></div>
    
    <div id="dashboard-tab" class="tab-pane active">
        <div class="dashboard-header">
            <h1><i class="fas fa-bell"></i> Incident Dashboard</h1>
            <p>View and respond to pending incident reports - Newest reports appear on top</p>
            <div id="statsContainer">
                <div class="incident-stats">
                    <div class="stat-card"><i class="fas fa-bell"></i> Pending: <?= $pending_count ?></div>
                    <div class="stat-card"><i class="fas fa-truck"></i> Active: <?= $active_count ?></div>
                    <div class="stat-card"><i class="fas fa-check"></i> Completed: <?= $completed_count ?></div>
                </div>
            </div>
        </div>
        <div class="page">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3><i class="fas fa-bell"></i> Pending Reports</h3>
                <div>
                    <button class="refresh-btn" onclick="loadIncidents()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <button class="refresh-btn" onclick="showCreateReportModal()" style="background: #27ae60;"><i class="fas fa-plus"></i> Create New Report</button>
                </div>
            </div>
            <div id="pendingReportsList">
                <?php if ($pending_count > 0): ?>
                    <?php 
                    $pendingIncidents = [];
                    while($row = $pending->fetch_assoc()) {
                        $pendingIncidents[] = $row;
                    }
                    foreach($pendingIncidents as $row): 
                        $severityClass = getSeverityClass($row['severity']);
                    ?>
                        <div class="report-card pending-item <?= $severityClass ?>" data-severity="<?= strtolower(trim($row['severity'])) ?>" data-tracking="<?= htmlspecialchars($row['tracking_id']) ?>" data-incident-id="<?= $row['incident_id'] ?>">
                            <div class="report-card-header">
                                <div class="report-card-header-left">
                                    <div class="report-card-title">
                                        <?= formatTrackingIdWithDateTime($row['tracking_id'], $row['created_at']) ?>
                                        <span class="badge-pending ms-2">Pending</span>
                                        <?= getSeverityBadge($row['severity']) ?>
                                    </div>
                                </div>
                                <div class="report-card-header-right">
                                    <small class="text-muted"><?= date('M d, Y H:i:s', strtotime($row['created_at'])) ?></small>
                                </div>
                            </div>
                            <div class="report-card-details mt-2">
                                <i class="fas fa-fire"></i> <?= htmlspecialchars($row['incident_type']) ?><br>
                                <i class="fas fa-map-marker-alt"></i> 📍 <?= htmlspecialchars(substr($row['location_address'] ?? 'No address provided', 0, 80)) ?>
                            </div>
                            <?php if($row['location_lat'] && $row['location_lng']): ?>
                                <button class="btn-view-location" onclick="event.stopPropagation(); viewLocationOnMap(<?= $row['location_lat'] ?>, <?= $row['location_lng'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>')"><i class="fas fa-map-marker-alt"></i> View Location</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-inbox"></i><br>No pending reports at this time.<br><button class="refresh-btn mt-3" onclick="showCreateReportModal()" style="background: #27ae60;"><i class="fas fa-plus"></i> Create New Report</button></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div id="my-reports-tab" class="tab-pane">
        <div class="page">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3><i class="fas fa-folder-open"></i> My Reports</h3>
                <div>
                    <button class="refresh-btn" onclick="loadMyReports()"><i class="fas fa-sync-alt"></i> Refresh</button>
                    <button class="refresh-btn" onclick="showCreateReportModal()" style="background: #27ae60;"><i class="fas fa-plus"></i> Create New Report</button>
                </div>
            </div>
            <div id="myReportsList">
                <?php if ($active_count > 0): ?>
                    <h5 class="mt-2"><i class="fas fa-truck"></i> My Active Incidents</h5>
                    <?php 
                    $active->data_seek(0);
                    while($row = $active->fetch_assoc()): 
                        $draft_check = $conn->prepare("SELECT COUNT(*) as has_draft FROM tbl_incident_drafts WHERE incident_id = ?");
                        $draft_check->bind_param("i", $row['incident_id']);
                        $draft_check->execute();
                        $draft_result = $draft_check->get_result();
                        $has_draft = $draft_result->fetch_assoc()['has_draft'] > 0;
                        $severityClass = getSeverityClass($row['severity']);
                        $isSelfCreated = (strpos($row['tracking_id'], 'SELF-') === 0);
                    ?>
                        <div class="report-card active my-report-item <?= $severityClass ?>" data-status="active" data-has-draft="<?= $has_draft ? 'true' : 'false' ?>" data-tracking="<?= htmlspecialchars($row['tracking_id']) ?>" data-incident-id="<?= $row['incident_id'] ?>" onclick="viewActiveIncident(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>')" style="cursor: pointer;">
                            <div class="report-card-header">
                                <div class="report-card-header-left">
                                    <div class="report-card-title">
                                        <span class="tracking-id"><?= htmlspecialchars($row['tracking_id']) ?></span>
                                        <span class="badge-active ms-2">In Progress</span>
                                        <?php if($isSelfCreated): ?>
                                            <span class="report-type-badge"><i class="fas fa-plus-circle"></i> Self-Created</span>
                                        <?php endif; ?>
                                        <?php if($has_draft): ?>
                                            <span class="draft-badge"><i class="fas fa-pen"></i> Draft Saved</span>
                                        <?php endif; ?>
                                        <?= getSeverityBadge($row['severity']) ?>
                                    </div>
                                </div>
                                <div class="report-card-header-right">
                                    <small class="text-muted"><i class="far fa-calendar-alt"></i> <?= date('M d, Y H:i', strtotime($row['created_at'])) ?></small>
                                    <div class="action-dropdown">
                                        <button class="action-menu-btn" onclick="toggleActionMenu(this); return false;" title="Actions">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <div class="action-menu-dropdown">
                                            <?php if($row['location_lat'] && $row['location_lng']): ?>
                                                <div class="action-menu-item" onclick="event.stopPropagation(); viewLocationOnMap(<?= $row['location_lat'] ?>, <?= $row['location_lng'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>'); return false;">
                                                    <i class="fas fa-map-marker-alt"></i> View Location
                                                </div>
                                            <?php endif; ?>
                                            <div class="action-menu-item" onclick="event.stopPropagation(); viewActiveIncident(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>'); return false;">
                                                <i class="fas fa-check-circle"></i> Complete Report
                                            </div>
                                            <div class="action-menu-item text-danger" onclick="event.stopPropagation(); moveToTrash(this, 'active', '<?= htmlspecialchars($row['tracking_id']) ?>', <?= $row['incident_id'] ?>); return false;">
                                                <i class="fas fa-trash"></i> Move to Trash
                                            </div>
                                            <div class="action-menu-item" onclick="event.stopPropagation(); showAccessManagement(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>'); return false;">
                                                <i class="fas fa-users"></i> Access Log
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="report-card-details mt-2">
                                <i class="fas fa-map-marker-alt"></i> Location: <?= htmlspecialchars(substr($row['location_address'] ?? 'No location', 0, 80)) ?><br>
                                <i class="fas fa-fire"></i> Type: <?= htmlspecialchars($row['incident_type']) ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-folder-open"></i><br>No active reports. Take a report from the Dashboard or create a new report!</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="trash-tab" class="tab-pane">
        <div class="page">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3><i class="fas fa-trash-alt"></i> Trash</h3>
                <button class="refresh-btn" onclick="loadTrash()" style="background: #6c757d;"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
            <div class="trash-info">
                <i class="fas fa-info-circle"></i> <strong>Trash Information:</strong> Items in trash will be automatically deleted after <strong>5 months</strong> (150 days). 
                You can restore items within this period. Permanently deleted items cannot be recovered.
            </div>
            <div id="trashList"></div>
        </div>
    </div>

    <div id="live-map-tab" class="tab-pane">
        <div class="page">
            <h3><i class="fas fa-map-marked-alt"></i> Live Incident Map</h3>
            <p>View all incidents and responder locations on the map - Colored circles represent severity levels</p>
            <div id="liveMap"></div>
            <div class="row mb-3">
                <div class="col-md-3"><div class="d-flex align-items-center"><div style="width:24px;height:24px;background-color:#059669;border-radius:50%;margin-right:8px;"></div><span>MINOR - Walking wounded</span></div></div>
                <div class="col-md-3"><div class="d-flex align-items-center"><div style="width:24px;height:24px;background-color:#d97706;border-radius:50%;margin-right:8px;"></div><span>DELAYED - Serious but stable</span></div></div>
                <div class="col-md-3"><div class="d-flex align-items-center"><div style="width:24px;height:24px;background-color:#dc2626;border-radius:50%;margin-right:8px;"></div><span>IMMEDIATE - Life-threatening</span></div></div>
                <div class="col-md-3"><div class="d-flex align-items-center"><div style="width:24px;height:24px;background-color:#1f2937;border-radius:50%;margin-right:8px;"></div><span>DEAD - Deceased</span></div></div>
            </div>
            <div class="row">
                <div class="col-md-3"><div class="d-flex align-items-center"><div style="width:16px;height:16px;background-color:#3b82f6;border-radius:50%;margin-right:8px;"></div><span>Responder Location</span></div></div>
                <div class="col-md-3"><div class="d-flex align-items-center"><div style="width:16px;height:16px;background-color:#e67e22;border-radius:50%;margin-right:8px;"></div><span>Your Location</span></div></div>
            </div>
            <div class="mt-3 d-flex gap-2 flex-wrap">
                <button id="updateMyLocation" class="my-location-btn" style="background:#2980b9;"><i class="fas fa-location-dot"></i> Update My Location</button>
                <button id="refreshMap" class="my-location-btn" style="background:#6c757d;"><i class="fas fa-sync-alt"></i> Refresh Map</button>
                <button id="centerToMyLocation" class="my-location-btn" style="background:#e67e22;"><i class="fas fa-crosshairs"></i> Center to My Location</button>
            </div>
            <div id="newIncidentAlert" style="display:none;" class="alert alert-danger mt-3 new-incident-alert"><i class="fas fa-bell"></i> <strong>New Incident Reported!</strong> Check the map for details.</div>
        </div>
    </div>

    <div id="report-form-tab" class="tab-pane">
        <div class="page" id="reportForm">
            <div class="form-loading-overlay" id="formLoadingOverlay" style="display: none;">
                <div class="spinner-border text-primary" role="status"></div>
                <p>Loading incident data...</p>
            </div>
            <div class="header">
                <div class="logo-row">
                    <div class="logo-box"><img id="logoLeftImg" src="bonga_logo.png" alt="Left Logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%23f0f0f0%22/%3E%3Ctext x=%2250%22 y=%2255%22 font-size=%2210%22 text-anchor=%22middle%22 fill=%22%23999%22%3ELogo%3C/text%3E%3C/svg%3E';"><div class="logo-upload" onclick="document.getElementById('logoLeftInput').click()">Change</div><input type="file" id="logoLeftInput" accept="image/*" style="display:none;"></div>
                    <div class="header-text"><h3>Republic of the Philippines</h3><h2>Municipality of Bongabon</h2><h4>Municipal Disaster Risk Reduction and Management Office</h4></div>
                    <div class="logo-box"><img id="logoRightImg" src="bonga_logo2.png" alt="Right Logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%23f0f0f0%22/%3E%3Ctext x=%2250%22 y=%2255%22 font-size=%2210%22 text-anchor=%22middle%22 fill=%22%23999%22%3EMDRRMO%3C/text%3E%3C/svg%3E';"><div class="logo-upload" onclick="document.getElementById('logoRightInput').click()">Change</div><input type="file" id="logoRightInput" accept="image/*" style="display:none;"></div>
                </div>
                <div class="report-title">INCIDENT REPORT</div>
                <div id="workingOnIndicator" style="display:none; background:#17a2b8; color:white; padding:5px 10px; border-radius:20px; font-size:11px; position:absolute; top:10px; right:20px;">
                    <i class="fas fa-pen"></i> Working on: <span id="workingTrackingId"></span>
                </div>
                <div id="autoSaveStatus" style="display:none; background:#27ae60; color:white; padding:5px 10px; border-radius:20px; font-size:10px; position:absolute; bottom:10px; left:10px;">
                    <i class="fas fa-check-circle"></i> Saved
                </div>
            </div>
            <div class="two-column-layout">
                <div class="left-column">
                    <table class="form-table">
                        <tr><th colspan="2" class="section-header">INCIDENT DETAILS</th></tr>
                        <tr>
                            <td class="label-cell">Date of Incident:<br><span class="filipino-text">(Petsa)</span><input type="date" id="incidentDate">
                            <td class="label-cell">Time of Call:<br><span class="filipino-text">(Oras ng Pagtawag)</span><input type="time" id="callTime">
                        </tr>
                        <tr>
                            <td class="label-cell">Time of Incident:<br><span class="filipino-text">(Oras ng Insidente)</span><input type="time" id="incidentTime">
                            <td class="label-cell">At Scene:<br><span class="filipino-text">(Oras sa Pinangyarihan)</span><input type="time" id="atScene">
                        </tr>
                        <tr>
                            <td class="label-cell">Incident/Transfer/Purpose:<br><input type="text" id="incidentPurpose" placeholder="(Insidente/Paglipat/Layunin)">
                            <td class="label-cell">Depart Scene:<br><input type="time" id="departScene"><br>At Hospital:<br><input type="time" id="atHospital">
                        </tr>
                        <tr>
                            <td class="label-cell">Place of Incident:<br><span class="filipino-text">(Lugar ng Insidente)</span><input type="text" id="placeIncident">
                            <td class="label-cell">Handover:<br><input type="time" id="handover"><br>Back to Base:<br><input type="time" id="backToBase">
                        </td>
                    </table>
                    <table class="form-table">
                        <tr><th colspan="2" class="section-header">PATIENT'S INFORMATION</th></tr>
                        <tr>
                            <td class="label-cell">Name:<br><input type="text" id="patientName" placeholder="(Pangalan)">
                            <td class="label-cell">In Case of Emergency Contact Person:<br><input type="text" id="emergencyContact" placeholder="(Contact Person)">
                        </tr>
                        <tr>
                            <td class="label-cell">Age:<br><input type="number" id="patientAge" placeholder="(Edad)">
                            <td class="label-cell">Gender:<br><select id="patientGender"><option>Male / Lalaki</option><option>Female / Babae</option></select>
                        </tr>
                        <tr>
                            <td class="label-cell">Address:<br><input type="text" id="patientAddress" placeholder="(Tirahan)">
                            <td class="label-cell">Contact Number:<br><input type="text" id="emergencyNumber" placeholder="(Numero)">
                        </tr>
                        <tr>
                            <td class="label-cell">Signature:<br><div class="signature-area"><canvas id="patientSigCanvas" class="signature-canvas" width="280" height="80"></canvas><div class="sig-buttons"><button type="button" class="sig-btn" onclick="clearSignature('patientSigCanvas')">Clear</button></div><input type="hidden" id="patientSignatureData"></div>
                            <td class="label-cell">Emergency Signature:<br><div class="signature-area"><canvas id="emergencySigCanvas" class="signature-canvas" width="280" height="80"></canvas><div class="sig-buttons"><button type="button" class="sig-btn" onclick="clearSignature('emergencySigCanvas')">Clear</button></div><input type="hidden" id="emergencySignatureData"></div>
                        </tr>
                    </table>
                    <table class="form-table">
                        <tr><th colspan="2" class="section-header">INJURY MAP - Highlight affected areas<br><span class="filipino-text">(I-highlight ang mga pinsala)</span></th></tr>
                        <tr><td colspan="2"><div class="draw-tools"><button type="button" id="drawBtn" class="tool-btn active">Draw</button><button type="button" id="eraseBtn" class="tool-btn">Erase</button><button type="button" id="undoBtn" class="tool-btn">Undo</button><button type="button" id="redoBtn" class="tool-btn">Redo</button><button type="button" id="clearCanvasBtn" class="tool-btn">Clear All</button><input type="color" id="penColor" value="#ff0000" class="color-picker"><span style="font-size:9px;">Size:</span><input type="range" id="brushSize" min="2" max="20" value="5" style="width:60px;"></div>
                         <div class="canvas-wrapper"><canvas id="bodyCanvas" width="360" height="420"></canvas></div><input type="hidden" id="bodyImageData">   </div>
                        </tr>
                    </table>
                    <table class="form-table">
                        <tr><th colspan="2" class="section-header">INJURIES / ILLNESS / CHIEF COMPLAINT<br><textarea id="chiefComplaint" rows="6" placeholder="(Mga Pinsala/Sakit/Pangunahing Daing)"></textarea></th></tr>
                    </table>
                </div>
                <div class="right-column">
                    <table class="form-table">
                        <tr><th colspan="2" class="section-header">PATIENT'S SAMPLE HISTORY AND VITAL SIGNS</th></td>
                        <tr>
                            <td class="label-cell">Signs & Symptoms:<br><textarea id="symptoms" rows="2" placeholder="(Palatandaan at Sintomas)"></textarea>
                            <td class="label-cell">Blood Pressure:<input type="text" id="bp" placeholder="___ / ___">
                        </tr>
                        <tr>
                            <td class="label-cell">Allergy:<input type="text" id="allergy" placeholder="(Alerhiya)">
                            <td class="label-cell">Pulse Rate:<input type="text" id="pulse" placeholder="___ bpm">
                        </tr>
                        <tr>
                            <td class="label-cell">Medications:<br><input type="text" id="medications" placeholder="(Medikasyon)">
                            <td class="label-cell">Respiratory Rate:<input type="text" id="respiratory" placeholder="___ breaths/min">
                        </tr>
                        <tr>
                            <td class="label-cell">Past Medical History:<br><input type="text" id="pastHistory" placeholder="(Nakaraang Medikal na Kasaysayan)">
                            <td class="label-cell">Body Temperature:<input type="text" id="temperature" placeholder="___ °C">
                        </tr>
                        <tr>
                            <td class="label-cell">Last Intake/Output:<br><input type="text" id="lastIntake" placeholder="(Huling Kinain/Nilabas)">
                            <td class="label-cell">Events Leading to Injury:<br><textarea id="events" rows="3" placeholder="(Dahilan ng Pagkakapinsala)"></textarea>
                        </tr>
                    </table>
                    <table class="form-table">
                        <tr><th colspan="2" class="section-header">MANAGEMENT / INTERVENTION<br></th></tr>
                        <tr><td class="label-cell">Actions Taken:<br><textarea id="actionsGiven" rows="8" placeholder="(Pangunang Lunas na Ginawa)"></textarea>   </div>
                        </tr>
                    </table>
                    <table class="form-table">
                        <tr><th colspan="2" class="section-header">REFUSAL OF TREATMENT AND/OR TRANSPORT<br><span class="filipino-text">(Pagtanggi sa Pangunang Lunas/Pagdala sa Pagamutan)</span></th></tr>
                        <tr><td colspan="2" class="refusal-text">Ako, na lumagda sa ibaba, ay maayos na napaliwanagan ukol sa aking kondisyon at mga serbisyong medikal na aking kailangan ngunit dahil sa aking personal na dahilan aking tinanggihan ang paglipat o paggamot sa akin. Dahil dito anuman ang maging resulta ng aking desisyon ay walang sinuman sa mga kawani ng Bongabon MDRRMO Rescue Team ang may pananagutan dahil sa aking pagtanggi.</div>
                        </tr>
                        <tr>
                            <td class="label-cell">Witness:<br><input type="text" id="refusalWitness" placeholder="(Saksi)">
                            <td class="label-cell">Date Signed:<br><input type="date" id="refusalDate">
                        </tr>
                    </table>
                    <table class="form-table">
                        <tr>
                            <th class="section-header">PROVIDER'S INFORMATION<br><span class="filipino-text">(Tagapagbigay ng Pangunang Lunas)</span></th>
                            <th class="section-header">RECEIVING FACILITIES<br><span class="filipino-text">(Pagamutang Tumanggap)</span></th>
                        </tr>
                        <tr>
                            <td class="label-cell">Crew 1:<input type="text" id="crew1" placeholder="(Crew 1)"><br>
                            Crew 2:<input type="text" id="crew2" placeholder="(Crew 2)"><br>
                            Crew 3:<input type="text" id="crew3" placeholder="(Crew 3)"><br>
                            Crew 4:<input type="text" id="crew4" placeholder="(Crew 4)"><br>
                            Crew 5:<input type="text" id="crew5" placeholder="(Crew 5)"><br>
                            Driver:<input type="text" id="driver" placeholder="(Driver)"><br>
                            Vehicle Used:<input type="text" id="vehicle" placeholder="(Vehicle Used)">
                            <td class="label-cell">Place / Hospital:<br><textarea id="receivingPlace" rows="3" placeholder="(Place / Hospital)"></textarea><br>
                            Receiving Person:<br><input type="text" id="receivingPerson" placeholder="(Receiving Person)"><br>
                            Name & Signature:<br><div class="signature-area"><canvas id="providerSigCanvas" class="signature-canvas" width="280" height="70"></canvas><div class="sig-buttons"><button type="button" class="sig-btn" onclick="clearSignature('providerSigCanvas')">Clear</button></div><input type="hidden" id="providerSignatureData"><br><input type="text" id="receivingSignName" placeholder="(Pangalan at Lagda)"></div>
                        </tr>
                    </table>
                </div>
            </div>
            <div class="incident-images-section">
                <div class="incident-images-header" style="font-size:14px; font-weight:bold; text-align:center; padding:10px; border:1px solid #000; margin:15px 0 10px 0;"><i class="fas fa-camera"></i> INCIDENT PHOTOGRAPHS <span class="filipino-text">(Mga Larawan ng Insidente)</span></div>
                <div class="incident-images-tools"><label class="upload-images-btn"><i class="fas fa-plus"></i> Add Images<input type="file" id="incidentImagesInput" accept="image/*" multiple style="display:none;"></label><button type="button" id="clearAllImagesBtn" class="clear-images-btn"><i class="fas fa-trash"></i> Clear All</button><span class="image-count" id="imageCount">0 image(s)</span></div>
                <div class="print-image-gallery" id="incidentImageGallery"></div>
                <div class="image-note" style="font-size:10px; color:#666; font-style:italic; text-align:center; margin-top:8px;">* Images are displayed at full size for clear documentation of incident details</div>
                <input type="hidden" id="incidentImagesData" value="">
            </div>
            <div class="fab-container">
                <div class="fab-main" id="fabMain"><i class="fas fa-ellipsis-v"></i></div>
                <div class="fab-menu" id="fabMenu">
                    <button class="fab-menu-item" id="fabSaveDraft"><i class="fas fa-save"></i> Save Progress</button>
                    <button class="fab-menu-item" id="fabClearForm"><i class="fas fa-eraser"></i> Clear Form</button>
                    <button class="fab-menu-item" id="fabCompleteReport"><i class="fas fa-check-circle"></i> Complete Report</button>
                    <button class="fab-menu-item" id="fabPrint"><i class="fas fa-print"></i> Print Report</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create New Report Modal -->
<div class="modal fade" id="createReportModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Create New Incident Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-info-circle"></i> Basic Incident Information</div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Incident Type *</label>
                            <select id="newIncidentType" class="form-select form-select-sm">
                                <option value="Medical">Medical Emergency</option>
                                <option value="Trauma">Trauma / Injury</option>
                                <option value="Fire">Fire Incident</option>
                                <option value="Flood">Flood / Water Rescue</option>
                                <option value="Earthquake">Earthquake</option>
                                <option value="Vehicular Accident">Vehicular Accident</option>
                                <option value="Cardiac Arrest">Cardiac Arrest</option>
                                <option value="Stroke">Stroke</option>
                                <option value="Difficulty Breathing">Difficulty Breathing</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Severity *</label>
                            <select id="newSeverity" class="form-select form-select-sm">
                                <option value="low">MINOR - Walking wounded</option>
                                <option value="moderate">DELAYED - Serious but stable</option>
                                <option value="high">IMMEDIATE - Life-threatening</option>
                                <option value="dead">DEAD - Deceased</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Location Address *</label>
                            <input type="text" id="newLocationAddress" class="form-control form-control-sm" placeholder="Enter the incident location">
                            <button type="button" id="getNewLocationBtn" class="get-location-btn mt-2"><i class="fas fa-location-dot"></i> Get Current Location</button>
                            <small class="text-muted d-block mt-1">Or click the button to use your current GPS location</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reporter Name</label>
                            <input type="text" id="newReporterName" class="form-control form-control-sm" placeholder="Your name or caller name" value="<?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Responder') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Reporter Phone</label>
                            <input type="text" id="newReporterPhone" class="form-control form-control-sm" placeholder="Contact number">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea id="newDescription" class="form-control form-control-sm" rows="3" placeholder="Brief description of the incident..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="createNewIncident()"><i class="fas fa-plus"></i> Create Report</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Access Management Modal -->
<div class="modal fade" id="accessManagementModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: #e67e22; color: white;">
                <h5 class="modal-title"><i class="fas fa-users"></i> Report Access & Activity Log</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" id="accessTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab">Activity Log</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="users-tab" data-bs-toggle="tab" data-bs-target="#users" type="button" role="tab">User Access</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="grant-tab" data-bs-toggle="tab" data-bs-target="#grant" type="button" role="tab">Grant Access</button>
                    </li>
                </ul>
                <div class="tab-content mt-3">
                    <div class="tab-pane fade show active" id="activity" role="tabpanel">
                        <div id="accessLogsList">
                            <div class="text-center p-4">Loading activity logs...</div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="users" role="tabpanel">
                        <div id="usersWithAccess">
                            <div class="text-center p-4">Loading user access data...</div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="grant" role="tabpanel">
                        <div class="form-section">
                            <div class="form-section-title">Grant Access to Another Responder</div>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> <strong>Access Control:</strong> You can grant other responders access to this report. All access and edits will be logged.
                            </div>
                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Select Responder</label>
                                    <select id="grantToUserId" class="form-select">
                                        <option value="">Loading responders...</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Access Level</label>
                                    <select id="grantAccessLevel" class="form-select">
                                        <option value="view">View Only</option>
                                        <option value="edit">Edit Report</option>
                                    </select>
                                </div>
                            </div>
                            <button class="btn btn-primary" onclick="grantReportAccess()"><i class="fas fa-user-plus"></i> Grant Access</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="incidentModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header" style="background: #e67e22; color: white;">
                <h5 class="modal-title"><i class="fas fa-file-alt"></i> Incident Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="incidentModalBody">Loading...</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="takeActionBtn">Take Action</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables
let incidents = [];
let liveMap = null;
let liveMapMarkers = [];
let lastIncidentCount = 0;
let currentLocation = { lat: 15.6333, lng: 121.3167 };
let currentWorkingIncidentId = null;
let currentWorkingTrackingId = null;
let incidentImages = [];
let autoSaveTimer = null;
let lastFormData = '';
let notificationInterval = null;
let notificationAudio = null;
const sigPads = {};
const STORAGE_TRASH = 'mdrrmo_responder_trash';
const TRASH_RETENTION_DAYS = 150;
let newIncidentLat = null;
let newIncidentLng = null;
let currentAccessIncidentId = null;

// Helper functions
function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function formatTrackingIdWithDateTimeJs(trackingId, createdAt) {
    const date = new Date(createdAt);
    const formattedDate = date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    return '<div class="tracking-id-wrapper"><span class="tracking-id">' + escapeHtml(trackingId) + '</span><span class="tracking-datetime"><i class="far fa-calendar-alt"></i> ' + formattedDate + '</span></div>';
}

function getSeverityBadgeJs(severity) {
    if (!severity) return '<span class="severity-minor"><i class="fas fa-band-aid"></i> MINOR</span>';
    const sev = String(severity).toLowerCase().trim();
    if (sev === 'dead' || sev === 'deceased' || sev === 'black') {
        return '<span class="severity-dead"><i class="fas fa-skull"></i> DEAD</span>';
    }
    if (sev === 'high' || sev === 'critical' || sev === 'immediate' || sev === 'red') {
        return '<span class="severity-immediate"><i class="fas fa-exclamation-triangle"></i> IMMEDIATE</span>';
    }
    if (sev === 'moderate' || sev === 'delayed' || sev === 'yellow' || sev === 'serious') {
        return '<span class="severity-delayed"><i class="fas fa-exclamation-circle"></i> DELAYED</span>';
    }
    if (sev === 'low' || sev === 'minor' || sev === 'green') {
        return '<span class="severity-minor"><i class="fas fa-band-aid"></i> MINOR</span>';
    }
    return '<span class="severity-minor"><i class="fas fa-band-aid"></i> MINOR</span>';
}

function getSeverityClassJs(severity) {
    if (!severity) return 'severity-minor';
    const sev = String(severity).toLowerCase().trim();
    if (sev === 'dead' || sev === 'deceased' || sev === 'black') return 'severity-dead';
    if (sev === 'high' || sev === 'critical' || sev === 'immediate' || sev === 'red') return 'severity-immediate';
    if (sev === 'moderate' || sev === 'delayed' || sev === 'yellow' || sev === 'serious') return 'severity-delayed';
    if (sev === 'low' || sev === 'minor' || sev === 'green') return 'severity-minor';
    return 'severity-minor';
}

function getSeverityMapColor(severity) {
    if (!severity) return '#059669';
    const sev = String(severity).toLowerCase().trim();
    if (sev === 'dead' || sev === 'deceased' || sev === 'black') return '#1f2937';
    if (sev === 'high' || sev === 'critical' || sev === 'immediate' || sev === 'red') return '#dc2626';
    if (sev === 'moderate' || sev === 'delayed' || sev === 'yellow' || sev === 'serious') return '#d97706';
    if (sev === 'low' || sev === 'minor' || sev === 'green') return '#059669';
    return '#059669';
}

// Toggle action menu dropdown
function toggleActionMenu(btn) {
    // Stop event from bubbling up
    event.stopPropagation();
    
    // Close all other open dropdowns first
    document.querySelectorAll('.action-menu-dropdown.show').forEach(function(menu) {
        if (menu !== btn.nextElementSibling) {
            menu.classList.remove('show');
        }
    });
    
    // Toggle current dropdown
    const dropdown = btn.nextElementSibling;
    dropdown.classList.toggle('show');
}

// ============================================
// REPORT ACCESS MANAGEMENT FUNCTIONS
// ============================================

function logReportAccess(incidentId, actionType, fieldChanged, oldValue, newValue) {
    $.ajax({
        url: 'api/log_report_access.php',
        method: 'POST',
        data: {
            incident_id: incidentId,
            action_type: actionType,
            action_details: newValue || '',
            field_changed: fieldChanged || null,
            new_value: newValue || null
        },
        dataType: 'json',
        success: function(result) {
            if (!result.success) {
                console.log('Failed to log access:', result.message);
            }
        },
        error: function() {
            console.log('Error logging access');
        }
    });
}

function showAccessManagement(incidentId, trackingId) {
    currentAccessIncidentId = incidentId;
    $('#accessManagementModal').modal('show');
    loadAccessLogs(incidentId);
    loadUsersWithAccess(incidentId);
    loadRespondersForGrant();
}

function loadAccessLogs(incidentId) {
    $('#accessLogsList').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Loading activity logs...</div>');
    
    $.ajax({
        url: 'api/get_report_access_logs.php',
        method: 'GET',
        data: { incident_id: incidentId },
        dataType: 'json',
        success: function(result) {
            if (result.success && result.logs) {
                if (result.logs.length === 0) {
                    $('#accessLogsList').html('<div class="text-center p-4 text-muted"><i class="fas fa-clock"></i><br>No activity recorded yet.</div>');
                    return;
                }
                
                let html = '<div class="timeline" style="max-height: 500px; overflow-y: auto;">';
                result.logs.forEach(function(log) {
                    let actionIcon = {
                        'view': 'eye',
                        'viewed': 'eye',
                        'edit': 'edit',
                        'edited': 'edit',
                        'manual_save': 'save',
                        'saved_draft': 'save',
                        'completed': 'check-circle',
                        'print': 'print',
                        'printed': 'print',
                        'grant_access': 'user-plus',
                        'granted_access': 'user-plus',
                        'revoke_access': 'user-minus',
                        'revoked_access': 'user-minus',
                        'auto_share': 'share-alt',
                        'clear_form': 'eraser'
                    }[log.action_type] || 'info-circle';
                    
                    let actionColor = {
                        'view': '#3498db',
                        'viewed': '#3498db',
                        'edit': '#e67e22',
                        'edited': '#e67e22',
                        'manual_save': '#27ae60',
                        'saved_draft': '#27ae60',
                        'completed': '#2ecc71',
                        'print': '#9b59b6',
                        'printed': '#9b59b6',
                        'grant_access': '#f39c12',
                        'granted_access': '#f39c12',
                        'revoke_access': '#e74c3c',
                        'revoked_access': '#e74c3c',
                        'auto_share': '#8e44ad',
                        'clear_form': '#e74c3c'
                    }[log.action_type] || '#6c757d';
                    
                    let displayName = log.responder_name || 'Responder ID: ' + log.responder_id;
                    let actionDisplay = (log.action_type || 'view').replace(/_/g, ' ').toUpperCase();
                    
                    html += `
                        <div class="access-log-item" style="border-left: 3px solid ${actionColor}; padding: 10px 15px; margin-bottom: 12px; background: #f8f9fa; border-radius: 8px;">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <i class="fas fa-${actionIcon}" style="color: ${actionColor};"></i>
                                    <strong>${escapeHtml(displayName)}</strong>
                                    <span class="badge" style="background: ${actionColor}; color: white;">${actionDisplay}</span>
                                </div>
                                <small class="text-muted">${log.formatted_time || new Date(log.created_at).toLocaleString()}</small>
                            </div>
                            ${log.action_details ? `<div class="mt-1"><small><strong>Details:</strong> ${escapeHtml(log.action_details)}</small></div>` : ''}
                            ${log.field_changed ? `<div class="mt-1"><small><strong>Field:</strong> ${escapeHtml(log.field_changed)}</small></div>` : ''}
                            ${log.new_value ? `<div class="mt-1"><small><strong>Change:</strong> ${escapeHtml(log.new_value)}</small></div>` : ''}
                            <div class="mt-1"><small class="text-muted"><i class="fas fa-map-marker-alt"></i> IP: ${escapeHtml(log.ip_address || 'N/A')}</small></div>
                        </div>
                    `;
                });
                html += '</div>';
                $('#accessLogsList').html(html);
            } else {
                $('#accessLogsList').html('<div class="alert alert-warning">Failed to load activity logs. ' + (result.message || '') + '</div>');
            }
        },
        error: function(xhr, status, error) {
            $('#accessLogsList').html('<div class="alert alert-danger">Error loading activity logs: ' + error + '</div>');
        }
    });
}

function loadUsersWithAccess(incidentId) {
    $('#usersWithAccess').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Loading user access...</div>');
    
    $.ajax({
        url: 'api/get_report_access_logs.php',
        method: 'GET',
        data: { incident_id: incidentId },
        dataType: 'json',
        success: function(result) {
            if (result.success && result.grants) {
                if (result.grants.length === 0) {
                    $('#usersWithAccess').html('<div class="text-center p-4 text-muted"><i class="fas fa-users"></i><br>No other users have access to this report.</div>');
                    return;
                }
                
                let html = '<div class="list-group">';
                result.grants.forEach(function(grant) {
                    html += `
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-user-circle fa-2x me-2"></i>
                                    <strong>${escapeHtml(grant.granted_to_name)}</strong><br>
                                    <small class="text-muted">Access Level: <span class="badge ${grant.access_level === 'edit' ? 'bg-warning' : 'bg-info'}">${(grant.access_level || 'view').toUpperCase()}</span></small>
                                    <br><small>Granted by: ${escapeHtml(grant.granted_by_name)}</small>
                                    <br><small>Granted: ${new Date(grant.granted_at).toLocaleString()}</small>
                                </div>
                                <button class="btn btn-sm btn-danger" onclick="revokeReportAccess(${grant.grant_id})"><i class="fas fa-trash"></i> Revoke</button>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                $('#usersWithAccess').html(html);
            } else {
                $('#usersWithAccess').html('<div class="alert alert-warning">No access grants found.</div>');
            }
        },
        error: function() {
            $('#usersWithAccess').html('<div class="alert alert-danger">Error loading user access.</div>');
        }
    });
}

function loadRespondersForGrant() {
    $.ajax({
        url: 'api/get_responders_list.php',
        method: 'GET',
        dataType: 'json',
        success: function(result) {
            if (result.success && result.responders) {
                let options = '<option value="">Select Responder</option>';
                result.responders.forEach(function(responder) {
                    if (responder.user_id != <?= $_SESSION['user_id'] ?>) {
                        options += `<option value="${responder.user_id}" data-name="${escapeHtml(responder.fullname)}">${escapeHtml(responder.fullname)} (${escapeHtml(responder.username)})</option>`;
                    }
                });
                $('#grantToUserId').html(options);
            } else {
                $('#grantToUserId').html('<option value="">No other responders found</option>');
            }
        },
        error: function() {
            $('#grantToUserId').html('<option value="">Error loading responders</option>');
        }
    });
}

function grantReportAccess() {
    const userId = $('#grantToUserId').val();
    const accessLevel = $('#grantAccessLevel').val();
    const userName = $('#grantToUserId option:selected').data('name') || 'User';
    
    if (!userId) {
        Swal.fire('Error', 'Please select a responder to grant access to', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Grant Access?',
        text: `Grant ${accessLevel.toUpperCase()} access to ${userName}? This action will be logged.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#27ae60',
        confirmButtonText: 'Yes, Grant Access'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/grant_report_access.php',
                method: 'POST',
                data: {
                    incident_id: currentAccessIncidentId,
                    user_id: userId,
                    access_level: accessLevel,
                    granted_to_name: userName
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Success', response.message, 'success');
                        loadUsersWithAccess(currentAccessIncidentId);
                        loadAccessLogs(currentAccessIncidentId);
                        $('#grantToUserId').val('');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to grant access', 'error');
                }
            });
        }
    });
}

function revokeReportAccess(grantId) {
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
                url: 'api/revoke_report_access.php',
                method: 'POST',
                data: { grant_id: grantId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Success', response.message, 'success');
                        loadUsersWithAccess(currentAccessIncidentId);
                        loadAccessLogs(currentAccessIncidentId);
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Failed to revoke access', 'error');
                }
            });
        }
    });
}

// ============================================
// FORM CLEAR FUNCTION - Clear all form fields at once
// ============================================

function clearAllFormData() {
    Swal.fire({
        title: 'Clear All Form Data?',
        text: 'This will erase ALL data in the current form including patient info, vital signs, injury map, and uploaded images. This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Yes, Clear Everything',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Clear all form inputs
            clearForm();
            
            // Log the clear action
            if (currentWorkingIncidentId) {
                logReportAccess(currentWorkingIncidentId, 'clear_form', 'all_fields', null, 'Form cleared by user');
            }
            
            Swal.fire('Cleared!', 'All form data has been cleared.', 'success');
        }
    });
}

// ============================================
// NOTIFICATION SYSTEM
// ============================================

function initNotifications() {
    notificationAudio = new Audio();
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (AudioContext) {
            const ctx = new AudioContext();
            const beep = () => {
                const oscillator = ctx.createOscillator();
                const gain = ctx.createGain();
                oscillator.connect(gain);
                gain.connect(ctx.destination);
                oscillator.frequency.value = 800;
                gain.gain.value = 0.3;
                oscillator.start();
                gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + 0.5);
                oscillator.stop(ctx.currentTime + 0.5);
            };
            notificationAudio.beep = beep;
        }
    } catch(e) { console.log('Audio not supported'); }
    
    notificationInterval = setInterval(checkNotifications, 15000);
}

function checkNotifications() {
    $.ajax({
        url: 'api/get_notifications.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success && response.notifications && response.notifications.length > 0) {
                response.notifications.forEach(function(notif) {
                    showNotificationToast(notif);
                });
            }
        },
        error: function() {
            console.log('Error checking notifications');
        }
    });
}

function showNotificationToast(notification) {
    if (notificationAudio && notificationAudio.beep) {
        notificationAudio.beep();
    }
    
    const iconMap = {
        'warning': 'exclamation-triangle',
        'success': 'check-circle',
        'danger': 'times-circle',
        'info': 'info-circle'
    };
    const icon = iconMap[notification.type] || 'bell';
    
    const toastHtml = `
        <div class="toast show" role="alert" data-bs-autohide="true" data-bs-delay="8000">
            <div class="toast-header ${notification.type}">
                <i class="fas fa-${icon} me-2"></i>
                <strong class="me-auto">${escapeHtml(notification.title)}</strong>
                <small>Just now</small>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body">
                ${escapeHtml(notification.message)}
                ${notification.incident_id ? `<br><a href="#" onclick="viewIncident(${notification.incident_id}); $('.notification-toast').empty();" class="btn btn-sm btn-primary mt-2">View Incident</a>` : ''}
            </div>
        </div>
    `;
    
    $('#notificationToast').append(toastHtml);
    
    setTimeout(function() {
        $('#notificationToast .toast:first-child').remove();
    }, 8000);
}

// ============================================
// INCIDENT MANAGEMENT FUNCTIONS
// ============================================

function showCreateReportModal() {
    document.getElementById('newIncidentType').value = 'Medical';
    document.getElementById('newSeverity').value = 'low';
    document.getElementById('newLocationAddress').value = '';
    document.getElementById('newReporterName').value = '<?= htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Responder') ?>';
    document.getElementById('newReporterPhone').value = '';
    document.getElementById('newDescription').value = '';
    newIncidentLat = null;
    newIncidentLng = null;
    $('#createReportModal').modal('show');
}

document.getElementById('getNewLocationBtn')?.addEventListener('click', function() {
    if (navigator.geolocation) {
        Swal.fire({ title: 'Getting Location', text: 'Please wait...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
        navigator.geolocation.getCurrentPosition(function(pos) {
            newIncidentLat = pos.coords.latitude;
            newIncidentLng = pos.coords.longitude;
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${newIncidentLat}&lon=${newIncidentLng}&zoom=18&addressdetails=1`)
                .then(response => response.json())
                .then(data => {
                    const address = data.display_name || `${newIncidentLat}, ${newIncidentLng}`;
                    document.getElementById('newLocationAddress').value = address;
                    Swal.fire('Success', 'Location captured!', 'success');
                })
                .catch(() => {
                    document.getElementById('newLocationAddress').value = `${newIncidentLat}, ${newIncidentLng}`;
                    Swal.fire('Success', 'Location coordinates captured!', 'success');
                });
        }, function(err) {
            Swal.fire('Error', 'Could not get your location. Please enable GPS.', 'error');
        }, { enableHighAccuracy: true, timeout: 10000 });
    } else {
        Swal.fire('Error', 'Geolocation is not supported by your browser', 'error');
    }
});

function createNewIncident() {
    const incidentType = document.getElementById('newIncidentType').value;
    const severity = document.getElementById('newSeverity').value;
    const locationAddress = document.getElementById('newLocationAddress').value;
    const reporterName = document.getElementById('newReporterName').value;
    const reporterPhone = document.getElementById('newReporterPhone').value;
    const description = document.getElementById('newDescription').value;
    
    if (!locationAddress) {
        Swal.fire('Error', 'Please enter the incident location', 'error');
        return;
    }
    
    Swal.fire({ title: 'Creating Report...', text: 'Please wait...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    
    $.ajax({
        url: 'responder_dashboard.php',
        method: 'POST',
        data: {
            action: 'create_new_incident',
            incident_type: incidentType,
            severity: severity,
            location_address: locationAddress,
            location_lat: newIncidentLat,
            location_lng: newIncidentLng,
            reporter_name: reporterName,
            reporter_phone: reporterPhone,
            description: description
        },
        dataType: 'json',
        success: function(result) {
            if (result.success) {
                $('#createReportModal').modal('hide');
                Swal.fire('Success!', `Incident report created successfully!\nTracking ID: ${result.tracking_id}`, 'success');
                viewActiveIncident(result.incident_id, result.tracking_id);
                loadIncidents();
                loadMyReports();
            } else {
                Swal.fire('Error', result.message || 'Failed to create incident report', 'error');
            }
        },
        error: function(xhr, status, error) {
            Swal.fire('Error', 'Could not connect to server: ' + error, 'error');
        }
    });
}

function loadIncidents() {
    $.ajax({
        url: 'api/get_responder_incidents.php',
        method: 'GET',
        dataType: 'json',
        success: function(result) {
            incidents = result.pending || [];
            renderPendingLists(result);
            const statsHtml = '<div class="incident-stats"><div class="stat-card"><i class="fas fa-bell"></i> Pending: ' + (result.pending ? result.pending.length : 0) + '</div><div class="stat-card"><i class="fas fa-truck"></i> Active: ' + (result.active ? result.active.length : 0) + '</div><div class="stat-card"><i class="fas fa-check"></i> Completed: ' + (result.completed ? result.completed.length : 0) + '</div></div>';
            document.getElementById('statsContainer').innerHTML = statsHtml;
        },
        error: function() {
            document.getElementById('pendingReportsList').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><br>Unable to load incidents. Please check your connection.</div>';
        }
    });
}

function renderPendingLists(data) {
    let pendingHtml = '';
    const pendingIncidents = data.pending || [];
    if (pendingIncidents.length === 0) {
        pendingHtml = '<div class="empty-state"><i class="fas fa-inbox"></i><br>No pending reports at this time.<br><button class="refresh-btn mt-3" onclick="showCreateReportModal()" style="background: #27ae60;"><i class="fas fa-plus"></i> Create New Report</button></div>';
    } else {
        pendingIncidents.forEach(function(incident) {
            let severityClass = getSeverityClassJs(incident.severity);
            pendingHtml += `
                <div class="report-card pending-item ${severityClass}" data-severity="${(incident.severity || '').toLowerCase()}" data-tracking="${escapeHtml(incident.tracking_id)}" data-incident-id="${incident.incident_id}" onclick="viewIncident(${incident.incident_id})">
                    <div class="report-card-header">
                        <div class="report-card-header-left">
                            <div class="report-card-title">
                                ${formatTrackingIdWithDateTimeJs(incident.tracking_id, incident.created_at)}
                                <span class="badge-pending ms-2">Pending</span>
                                ${getSeverityBadgeJs(incident.severity)}
                            </div>
                        </div>
                        <div class="report-card-header-right">
                            <small class="text-muted">${new Date(incident.created_at).toLocaleString()}</small>
                        </div>
                    </div>
                    <div class="report-card-details mt-2">
                        <i class="fas fa-fire"></i> ${escapeHtml(incident.incident_type)}<br>
                        <i class="fas fa-map-marker-alt"></i> 📍 ${escapeHtml((incident.location_address || 'No address provided').substring(0, 80))}${incident.location_address && incident.location_address.length > 80 ? '...' : ''}
                    </div>
                    ${incident.location_lat && incident.location_lng ? `<button class="btn-view-location" onclick="event.stopPropagation(); viewLocationOnMap(${incident.location_lat}, ${incident.location_lng}, '${incident.tracking_id}')"><i class="fas fa-map-marker-alt"></i> View Location</button>` : ''}
                </div>
            `;
        });
    }
    document.getElementById('pendingReportsList').innerHTML = pendingHtml;
}

function loadMyReports() {
    cleanOldTrash();
    $.ajax({
        url: 'api/get_responder_incidents.php',
        method: 'GET',
        dataType: 'json',
        success: function(result) {
            renderMyReports(result.active || [], result.shared || []);
        },
        error: function() {
            renderMyReports([], []);
        }
    });
}

function viewSharedIncident(incidentId, trackingId, accessLevel) {
    if (accessLevel === 'view') {
        Swal.fire({
            title: 'View Only Access',
            text: 'You have view-only access to this report. You can view but not edit.',
            icon: 'info',
            confirmButtonText: 'View Report'
        }).then(() => {
            viewIncident(incidentId);
        });
    } else {
        viewActiveIncident(incidentId, trackingId);
    }
}

function renderMyReports(activeIncidents, sharedIncidents) {
    const container = document.getElementById('myReportsList');
    let html = '';
    
    // My Active Incidents
    if (activeIncidents && activeIncidents.length > 0) {
        html += '<h5 class="mt-2"><i class="fas fa-truck"></i> My Active Incidents</h5>';
        activeIncidents.forEach(function(incident) {
            const hasDraft = incident.has_draft || false;
            let severityClass = getSeverityClassJs(incident.severity);
            const isSelfCreated = incident.tracking_id && incident.tracking_id.indexOf('SELF-') === 0;
            
            html += `
                <div class="report-card active my-report-item ${severityClass}" data-status="active" data-has-draft="${hasDraft ? 'true' : 'false'}" data-tracking="${escapeHtml(incident.tracking_id)}" data-incident-id="${incident.incident_id}" onclick="viewActiveIncident(${incident.incident_id}, '${incident.tracking_id}')" style="cursor: pointer;">
                    <div class="report-card-header">
                        <div class="report-card-header-left">
                            <div class="report-card-title">
                                <span class="tracking-id">${escapeHtml(incident.tracking_id)}</span>
                                <span class="badge-active ms-2">In Progress</span>
                                ${isSelfCreated ? '<span class="report-type-badge"><i class="fas fa-plus-circle"></i> Self-Created</span>' : ''}
                                ${hasDraft ? '<span class="draft-badge"><i class="fas fa-pen"></i> Draft Saved</span>' : ''}
                                ${getSeverityBadgeJs(incident.severity)}
                            </div>
                        </div>
                        <div class="report-card-header-right">
                            <small class="text-muted"><i class="far fa-calendar-alt"></i> ${new Date(incident.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</small>
                            <div class="action-dropdown">
                                <button class="action-menu-btn" onclick="toggleActionMenu(this); return false;" title="Actions">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="action-menu-dropdown">
                                    ${incident.location_lat && incident.location_lng ? `
                                        <div class="action-menu-item" onclick="event.stopPropagation(); viewLocationOnMap(${incident.location_lat}, ${incident.location_lng}, '${incident.tracking_id}'); return false;">
                                            <i class="fas fa-map-marker-alt"></i> View Location
                                        </div>
                                    ` : ''}
                                    <div class="action-menu-item" onclick="event.stopPropagation(); viewActiveIncident(${incident.incident_id}, '${incident.tracking_id}'); return false;">
                                        <i class="fas fa-check-circle"></i> Complete Report
                                    </div>
                                    <div class="action-menu-item text-danger" onclick="event.stopPropagation(); moveToTrash(this, 'active', '${incident.tracking_id}', ${incident.incident_id}); return false;">
                                        <i class="fas fa-trash"></i> Move to Trash
                                    </div>
                                    <div class="action-menu-item" onclick="event.stopPropagation(); showAccessManagement(${incident.incident_id}, '${incident.tracking_id}'); return false;">
                                        <i class="fas fa-users"></i> Access Log
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="report-card-details mt-2">
                        <i class="fas fa-map-marker-alt"></i> Location: ${escapeHtml((incident.location_address || 'No location').substring(0, 80))}<br>
                        <i class="fas fa-fire"></i> Type: ${escapeHtml(incident.incident_type)}
                    </div>
                </div>
            `;
        });
    }
    
    // Shared Incidents (from other responders)
    if (sharedIncidents && sharedIncidents.length > 0) {
        html += '<h5 class="mt-3"><i class="fas fa-share-alt" style="color:#8e44ad;"></i> Shared With Me</h5>';
        sharedIncidents.forEach(function(incident) {
            const hasDraft = incident.has_draft || false;
            let severityClass = getSeverityClassJs(incident.severity);
            
            html += `
                <div class="report-card shared-report-item ${severityClass}" style="border-left-color: #8e44ad !important; background: #f9f5ff !important;" data-status="shared" data-has-draft="${hasDraft ? 'true' : 'false'}" data-tracking="${escapeHtml(incident.tracking_id)}" data-incident-id="${incident.incident_id}" onclick="viewSharedIncident(${incident.incident_id}, '${incident.tracking_id}', '${incident.access_level || 'view'}')" style="cursor: pointer;">
                    <div class="report-card-header">
                        <div class="report-card-header-left">
                            <div class="report-card-title">
                                <span class="tracking-id">${escapeHtml(incident.tracking_id)}</span>
                                <span class="badge" style="background: #8e44ad; color: white;" class="ms-2"><i class="fas fa-share-alt"></i> Shared by: ${escapeHtml(incident.shared_by_name || 'Another Responder')}</span>
                                <span class="badge-active ms-2">In Progress</span>
                                ${hasDraft ? '<span class="draft-badge"><i class="fas fa-pen"></i> Draft Saved</span>' : ''}
                                ${getSeverityBadgeJs(incident.severity)}
                            </div>
                        </div>
                        <div class="report-card-header-right">
                            <small class="text-muted"><i class="far fa-calendar-alt"></i> ${new Date(incident.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</small>
                            <div class="action-dropdown">
                                <button class="action-menu-btn" onclick="toggleActionMenu(this); return false;" title="Actions">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <div class="action-menu-dropdown">
                                    ${incident.location_lat && incident.location_lng ? `
                                        <div class="action-menu-item" onclick="event.stopPropagation(); viewLocationOnMap(${incident.location_lat}, ${incident.location_lng}, '${incident.tracking_id}'); return false;">
                                            <i class="fas fa-map-marker-alt"></i> View Location
                                        </div>
                                    ` : ''}
                                    <div class="action-menu-item" onclick="event.stopPropagation(); viewSharedIncident(${incident.incident_id}, '${incident.tracking_id}', '${incident.access_level || 'view'}'); return false;">
                                        <i class="fas fa-eye"></i> View Report
                                    </div>
                                    <div class="action-menu-item" onclick="event.stopPropagation(); showAccessManagement(${incident.incident_id}, '${incident.tracking_id}'); return false;">
                                        <i class="fas fa-users"></i> Access Log
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="report-card-details mt-2">
                        <i class="fas fa-map-marker-alt"></i> Location: ${escapeHtml((incident.location_address || 'No location').substring(0, 80))}<br>
                        <i class="fas fa-fire"></i> Type: ${escapeHtml(incident.incident_type)}
                        <br><small class="text-muted"><i class="fas fa-user-shield"></i> Access Level: <strong>${(incident.access_level || 'view').toUpperCase()}</strong></small>
                    </div>
                </div>
            `;
        });
    }
    
    if ((!activeIncidents || activeIncidents.length === 0) && (!sharedIncidents || sharedIncidents.length === 0)) {
        html = '<div class="empty-state"><i class="fas fa-folder-open"></i><br>No active reports. Take a report from the Dashboard or create a new report!<br><small class="text-muted">Reports shared with you will appear here automatically.</small></div>';
    }
    
    container.innerHTML = html;
}

function cleanOldTrash() {
    let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
    const fiveMonthsAgo = new Date();
    fiveMonthsAgo.setMonth(fiveMonthsAgo.getMonth() - 5);
    const newTrash = trash.filter(function(item) {
        return new Date(item.deletedAt) > fiveMonthsAgo;
    });
    if (newTrash.length !== trash.length) {
        localStorage.setItem(STORAGE_TRASH, JSON.stringify(newTrash));
    }
}

function moveToTrash(btn, type, trackingId, reportId) {
    let displayName = trackingId || 'This report';
    Swal.fire({
        title: 'Move to Trash?',
        text: 'Are you sure you want to move "' + displayName + '" to trash? It will be automatically deleted after 5 months.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Yes, Move to Trash',
        cancelButtonText: 'Cancel'
    }).then(function(result) {
        if (result.isConfirmed) {
            let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
            if (type === 'active') {
                const deletedItem = { id: 'trash_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6), incidentId: reportId, trackingId: trackingId, originalType: 'active', deletedAt: new Date().toISOString(), reportName: trackingId };
                trash.unshift(deletedItem);
                $.ajax({ url: 'api/cancel_incident.php', method: 'POST', data: { incident_id: reportId } }).fail(function() { console.log('Failed to cancel incident'); });
                Swal.fire('Moved to Trash', '"' + displayName + '" has been moved to trash.', 'success');
            }
            localStorage.setItem(STORAGE_TRASH, JSON.stringify(trash));
            loadMyReports();
            loadTrash();
        }
    });
}

function loadTrash() {
    cleanOldTrash();
    let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
    const container = document.getElementById('trashList');
    if (trash.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-trash-alt"></i><br>Trash is empty.</div>';
        return;
    }
    let html = '';
    trash.forEach(function(item) {
        const deletedDate = new Date(item.deletedAt);
        const deleteDate = new Date(deletedDate.getTime() + (TRASH_RETENTION_DAYS * 24 * 60 * 60 * 1000));
        const daysUntilDelete = Math.ceil((deleteDate - new Date()) / (1000 * 60 * 60 * 24));
        const willDeleteIn = daysUntilDelete > 0 ? 'Will be deleted in ' + daysUntilDelete + ' days' : 'Will be deleted soon';
        html += '<div class="report-card trash"><div class="d-flex justify-content-between align-items-start"><div class="report-card-title"><strong><i class="fas fa-trash-alt"></i> ' + escapeHtml(item.reportName || item.trackingId || 'Unknown') + '</strong><span class="badge-trash ms-2">Trash</span></div><small class="text-muted">Deleted: ' + deletedDate.toLocaleString() + '</small></div><div class="report-card-details mt-2"><small class="text-muted"><i class="fas fa-clock"></i> ' + willDeleteIn + '</small></div><div class="action-buttons-group"><button class="btn-restore" onclick="restoreFromTrash(\'' + item.id + '\')"><i class="fas fa-trash-restore"></i> Restore</button><button class="btn-delete" onclick="permanentDelete(\'' + item.id + '\')"><i class="fas fa-trash-alt"></i> Permanently Delete</button></div></div>';
    });
    container.innerHTML = html;
}

function restoreFromTrash(itemId) {
    Swal.fire({
        title: 'Restore Item?',
        text: 'Are you sure you want to restore this item?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        confirmButtonText: 'Yes, Restore'
    }).then(function(result) {
        if (result.isConfirmed) {
            let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
            const newTrash = trash.filter(function(t) { return t.id !== itemId; });
            localStorage.setItem(STORAGE_TRASH, JSON.stringify(newTrash));
            Swal.fire('Restored', 'Item has been restored.', 'success');
            loadMyReports();
            loadTrash();
        }
    });
}

function permanentDelete(itemId) {
    Swal.fire({
        title: 'Permanently Delete?',
        text: 'This action cannot be undone!',
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Yes, Permanently Delete'
    }).then(function(result) {
        if (result.isConfirmed) {
            let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
            const newTrash = trash.filter(function(t) { return t.id !== itemId; });
            localStorage.setItem(STORAGE_TRASH, JSON.stringify(newTrash));
            Swal.fire('Deleted', 'Item has been permanently deleted.', 'success');
            loadTrash();
        }
    });
}

function viewLocationOnMap(lat, lng, trackingId) {
    Swal.fire({
        title: 'Incident Location: ' + trackingId,
        html: '<div id="locationMap" style="height: 400px; width: 100%; border-radius: 8px;"></div><p class="mt-2"><strong>Coordinates:</strong> ' + lat + ', ' + lng + '</p>',
        showConfirmButton: true,
        confirmButtonText: 'Close',
        didOpen: function() {
            const map = L.map('locationMap').setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);
            L.marker([lat, lng]).addTo(map).bindPopup('<strong>' + trackingId + '</strong><br>Incident Location').openPopup();
        }
    });
}

function viewIncident(id) {
    logReportAccess(id, 'viewed', null, null, null);
    
    $('#incidentModalBody').html('<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading incident details...</div>');
    
    $.ajax({
        url: 'api/get_incident_full.php',
        method: 'POST',
        data: { incident_id: id },
        dataType: 'json',
        success: function(incident) {
            if (!incident || incident.error) {
                $('#incidentModalBody').html('<div class="alert alert-danger">Error loading incident details. Please try again.</div>');
                return;
            }
            
            let photosHtml = '';
            if (incident.photos && incident.photos.length > 0) {
                photosHtml = '<h6 class="mt-3"><i class="fas fa-images"></i> Incident Photos:</h6><div class="row">';
                incident.photos.forEach(function(photo) {
                    photosHtml += '<div class="col-md-2 col-4 mb-2"><img src="uploads/incidents/' + photo + '" class="photo-thumb w-100" style="cursor:pointer; border-radius:8px;" onclick="window.open(\'uploads/incidents/' + photo + '\')"></div>';
                });
                photosHtml += '</div>';
            } else {
                photosHtml = '<p class="text-muted mt-3"><i class="fas fa-camera"></i> No photos available for this incident.</p>';
            }
            
            let severityColor, severityText;
            switch((incident.severity || '').toLowerCase()) {
                case 'dead': severityColor = '#1f2937'; severityText = 'DEAD - Deceased'; break;
                case 'high': case 'immediate': case 'critical': severityColor = '#dc2626'; severityText = 'IMMEDIATE - Life-threatening'; break;
                case 'moderate': case 'delayed': case 'serious': severityColor = '#d97706'; severityText = 'DELAYED - Serious but stable'; break;
                default: severityColor = '#059669'; severityText = 'MINOR - Walking wounded';
            }
            
            const locationButton = incident.location_lat && incident.location_lng ? 
                '<button class="btn btn-sm btn-info mt-2" onclick="viewLocationOnMap(' + incident.location_lat + ', ' + incident.location_lng + ', \'' + incident.tracking_id + '\')"><i class="fas fa-map-marker-alt"></i> View on Map</button>' : '';
            
            const trackingWithDate = formatTrackingIdWithDateTimeJs(incident.tracking_id, incident.created_at);
            
            const html = `
                <div class="row">
                    <div class="col-md-12">
                        <div class="mb-3 p-3 rounded" style="background: ${severityColor}15; border-left: 5px solid ${severityColor};">
                            <div class="d-flex justify-content-between align-items-center flex-wrap">
                                <div>
                                    ${trackingWithDate}
                                    <div class="mt-2">
                                        <span class="badge" style="background: ${severityColor};">${severityText}</span>
                                    </div>
                                </div>
                                <span class="badge ${incident.status === 'pending' ? 'bg-warning text-dark' : (incident.status === 'dispatched' ? 'bg-info' : 'bg-success')} p-2">
                                    ${(incident.status || 'pending').toUpperCase()}
                                </span>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <h6><i class="fas fa-info-circle"></i> Incident Details</h6>
                                <table class="table table-sm table-bordered">
                                    <tr><th style="width: 40%;">Incident Type:</th><td>${escapeHtml(incident.incident_type || 'N/A')}</td></tr>
                                    <tr><th>Location:</th><td>${escapeHtml(incident.location_address || 'No address provided')} ${locationButton}</td></tr>
                                    <tr><th>Reported by:</th><td>${escapeHtml(incident.reporter_name || 'Unknown')}</td></tr>
                                    <tr><th>Contact Number:</th><td>${escapeHtml(incident.reporter_phone || 'N/A')}</td></tr>
                                    <tr><th>Reported at:</th><td>${new Date(incident.created_at).toLocaleString()}</td></tr>
                                </table>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6><i class="fas fa-stethoscope"></i> Additional Information</h6>
                                <table class="table table-sm table-bordered">
                                    <tr><th style="width: 30%;">Description:</th><td>${escapeHtml(incident.description || 'No description provided')}</td></tr>
                                    ${incident.responder_name ? `<tr><th>Assigned Responder:</th><td>${escapeHtml(incident.responder_name)}</td>` : ''}
                                </table>
                            </div>
                        </div>
                        
                        ${photosHtml}
                    </div>
                </div>
            `;
            
            $('#incidentModalBody').html(html);
            $('#incidentModal').modal('show');
            
            const actionBtn = $('#takeActionBtn');
            if (incident.status === 'pending') {
                actionBtn.html('<i class="fas fa-hand-paper"></i> Take & Fill Report').show();
                actionBtn.off('click').on('click', function() { takeIncident(id, incident); });
            } else if (incident.status === 'dispatched') {
                actionBtn.html('<i class="fas fa-check-circle"></i> Complete Report').show();
                actionBtn.off('click').on('click', function() { completeIncident(id, incident); });
            } else {
                actionBtn.hide();
            }
        },
        error: function(xhr, status, error) {
            $('#incidentModalBody').html('<div class="alert alert-danger">Unable to load incident details. Please check your connection.<br><small>Error: ' + error + '</small></div>');
        }
    });
}

function takeIncident(id, incident) {
    Swal.fire({
        title: 'Take Report & Fill Form?',
        text: 'You are about to take responsibility for ' + incident.tracking_id + '. This will also be shared with other responders.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Take It',
        confirmButtonColor: '#e67e22'
    }).then(function(result) {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Processing...',
                text: 'Taking the report...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            
            $.ajax({
                url: 'api/take_incident.php',
                method: 'POST',
                data: { incident_id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            title: 'Success!',
                            text: response.message,
                            icon: 'success',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            loadIncidents();
                            loadMyReports();
                            $('#incidentModal').modal('hide');
                            viewActiveIncident(id, incident.tracking_id);
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error, xhr.responseText);
                    let errorMsg = 'Could not connect to server: ' + error;
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.message) errorMsg = response.message;
                    } catch(e) {}
                    Swal.fire('Error', errorMsg, 'error');
                }
            });
        }
    });
}

function viewActiveIncident(incidentId, trackingId) {
    const loadingOverlay = document.getElementById('formLoadingOverlay');
    if (loadingOverlay) loadingOverlay.style.display = 'flex';
    
    currentWorkingIncidentId = incidentId;
    currentWorkingTrackingId = trackingId;
    
    document.getElementById('workingOnIndicator').style.display = 'block';
    document.getElementById('workingTrackingId').innerText = trackingId;
    
    switchTab('report-form-tab');
    clearForm();
    
    $.ajax({
        url: 'responder_dashboard.php',
        method: 'POST',
        data: { action: 'load_active_draft', incident_id: incidentId },
        dataType: 'json',
        complete: function() { if (loadingOverlay) loadingOverlay.style.display = 'none'; },
        success: function(result) {
            if (result.success && result.draft_data) {
                try {
                    const draftData = JSON.parse(result.draft_data);
                    restoreFormData(draftData);
                    Swal.fire('Draft Loaded', 'Previously saved progress has been loaded.', 'success', { timer: 2000, showConfirmButton: false });
                } catch(e) { console.log('Error loading draft:', e); }
            } else {
                $.ajax({
                    url: 'api/get_incident_full.php',
                    method: 'POST',
                    data: { incident_id: incidentId },
                    dataType: 'json',
                    success: function(incident) {
                        if (incident.location_address) document.getElementById('placeIncident').value = incident.location_address;
                        if (incident.created_at) {
                            const date = new Date(incident.created_at);
                            document.getElementById('incidentDate').value = date.toISOString().split('T')[0];
                        }
                        if (incident.incident_type) document.getElementById('incidentPurpose').value = incident.incident_type;
                    }
                });
            }
        }
    });
}

function captureFormData() {
    let fields = ['incidentDate','callTime','incidentTime','atScene','incidentPurpose','departScene','atHospital','placeIncident','handover','backToBase','patientName','emergencyContact','patientAge','patientGender','patientAddress','emergencyNumber','symptoms','bp','allergy','pulse','medications','respiratory','pastHistory','temperature','lastIntake','events','chiefComplaint','actionsGiven','refusalWitness','refusalDate','crew1','crew2','crew3','crew4','crew5','driver','vehicle','receivingPlace','receivingPerson','receivingSignName'];
    let data = {};
    fields.forEach(function(f) { var el = document.getElementById(f); data[f] = el ? el.value : ''; });
    data.patientSig = sigPads['patientSigCanvas'] && !sigPads['patientSigCanvas'].isEmpty() ? sigPads['patientSigCanvas'].toDataURL() : '';
    data.emergencySig = sigPads['emergencySigCanvas'] && !sigPads['emergencySigCanvas'].isEmpty() ? sigPads['emergencySigCanvas'].toDataURL() : '';
    data.providerSig = sigPads['providerSigCanvas'] && !sigPads['providerSigCanvas'].isEmpty() ? sigPads['providerSigCanvas'].toDataURL() : '';
    data.bodyImage = document.getElementById('bodyImageData')?.value || '';
    data.incidentImages = document.getElementById('incidentImagesData')?.value || '[]';
    data.logoLeft = document.getElementById('logoLeftImg')?.src || '';
    data.logoRight = document.getElementById('logoRightImg')?.src || '';
    return data;
}

function restoreFormData(data) {
    for (var key in data) {
        if (document.getElementById(key) && !['patientSig','emergencySig','providerSig','bodyImage','incidentImages','logoLeft','logoRight'].includes(key)) {
            document.getElementById(key).value = data[key] || '';
        }
    }
    if (data.patientSig && sigPads['patientSigCanvas']) sigPads['patientSigCanvas'].fromDataURL(data.patientSig);
    if (data.emergencySig && sigPads['emergencySigCanvas']) sigPads['emergencySigCanvas'].fromDataURL(data.emergencySig);
    if (data.providerSig && sigPads['providerSigCanvas']) sigPads['providerSigCanvas'].fromDataURL(data.providerSig);
    if (data.bodyImage && window.restoreBodyDrawing) window.restoreBodyDrawing(data.bodyImage);
    if (data.incidentImages) { try { incidentImages = JSON.parse(data.incidentImages); updateImageGallery(); } catch(e) { incidentImages = []; } }
    if (data.logoLeft) document.getElementById('logoLeftImg').src = data.logoLeft;
    if (data.logoRight) document.getElementById('logoRightImg').src = data.logoRight;
}

function saveProgress() {
    if (!currentWorkingIncidentId) {
        Swal.fire('No Active Incident', 'Please select an incident from "My Reports" to work on.', 'warning');
        return;
    }
    Swal.fire({ title: 'Saving Progress...', text: 'Please wait...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    let formData = captureFormData();
    let dataString = JSON.stringify(formData);
    
    logReportAccess(currentWorkingIncidentId, 'manual_save', 'report_data', null, 'Manually saved draft');
    
    $.ajax({
        url: 'responder_dashboard.php',
        method: 'POST',
        data: { action: 'save_active_draft', incident_id: currentWorkingIncidentId, draft_data: dataString },
        dataType: 'json',
        success: function(result) {
            if (result.success) { 
                lastFormData = dataString; 
                Swal.fire('Success!', 'Your progress has been saved successfully!', 'success'); 
                loadMyReports();
                
                $('.report-card[data-incident-id="' + currentWorkingIncidentId + '"] .report-card-title').each(function() {
                    if (!$(this).find('.draft-badge').length) {
                        $(this).append('<span class="draft-badge"><i class="fas fa-pen"></i> Draft Saved</span>');
                    }
                });
            } else { 
                Swal.fire('Error', result.message || 'Failed to save progress.', 'error'); 
            }
        },
        error: function() { Swal.fire('Error', 'Could not connect to server.', 'error'); }
    });
}

function completeIncident(id, incident) {
    Swal.fire({
        title: 'Complete Report',
        text: 'You are about to complete ' + incident.tracking_id + '.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Continue',
        confirmButtonColor: '#27ae60'
    }).then(function(result) {
        if (result.isConfirmed) viewActiveIncident(id, incident.tracking_id);
    });
}

function completeReportSubmission() {
    if (!currentWorkingIncidentId) {
        Swal.fire('No Active Incident', 'Please select an incident from "My Reports" to work on.', 'warning');
        return;
    }
    Swal.fire({ title: 'Submitting Report...', text: 'Please wait...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
    let formData = captureFormData();
    
    logReportAccess(currentWorkingIncidentId, 'completed', 'report', null, 'Report completed');
    
    $.ajax({
        url: 'api/complete_incident.php',
        method: 'POST',
        data: { incident_id: currentWorkingIncidentId, report_data: JSON.stringify(formData), report_name: document.getElementById('patientName').value || 'Incident Report' },
        dataType: 'json',
        success: function(result) {
            if (result.success) {
                $.post('responder_dashboard.php', { action: 'remove_completed_report', incident_id: currentWorkingIncidentId }, function(removeResult) {
                    const removeData = JSON.parse(removeResult);
                    if (removeData.success) {
                        if (autoSaveTimer) { clearInterval(autoSaveTimer); autoSaveTimer = null; }
                        clearForm();
                        currentWorkingIncidentId = null;
                        currentWorkingTrackingId = null;
                        document.getElementById('workingOnIndicator').style.display = 'none';
                        lastFormData = '';
                        Swal.fire({ title: 'Success!', text: 'Report has been completed and sent to admin!', icon: 'success', confirmButtonText: 'OK' }).then(() => {
                            loadIncidents(); loadMyReports(); switchTab('dashboard-tab');
                        });
                    }
                });
            } else { Swal.fire('Error', result.message || 'Failed to complete incident', 'error'); }
        },
        error: function() { Swal.fire('Error', 'Could not connect to server', 'error'); }
    });
}

function clearForm() {
    document.querySelectorAll('#report-form-tab input, #report-form-tab textarea, #report-form-tab select').forEach(function(el) {
        if (el.id && !el.id.includes('Sig') && !el.id.includes('logo') && !el.id.includes('Date')) { 
            el.value = ''; 
        }
    });
    Object.keys(sigPads).forEach(function(k) { if (sigPads[k]) sigPads[k].clear(); });
    if (window.clearDrawing) window.clearDrawing();
    incidentImages = [];
    updateImageGallery();
    document.getElementById('incidentDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('refusalDate').value = new Date().toISOString().split('T')[0];
}

function initSignatures() {
    ['patientSigCanvas','emergencySigCanvas','providerSigCanvas'].forEach(function(id) {
        var c = document.getElementById(id);
        if (c) { c.width = c.clientWidth || 280; c.height = 80; sigPads[id] = new SignaturePad(c, { backgroundColor: 'white', penColor: 'black' }); }
    });
}
window.clearSignature = function(cid) { if (sigPads[cid]) sigPads[cid].clear(); };

// Injury Map Implementation
(function() {
    var canvas = document.getElementById('bodyCanvas');
    if (!canvas) return;
    canvas.width = 360; canvas.height = 420;
    var ctx = canvas.getContext('2d');
    var drawingLayer = document.createElement('canvas');
    drawingLayer.width = 360; drawingLayer.height = 420;
    var drawCtx = drawingLayer.getContext('2d');
    var historyStack = [], redoStack = [];
    var backgroundImage = null;
    function drawWithBackground() {
        ctx.clearRect(0, 0, 360, 420);
        if (backgroundImage) { ctx.drawImage(backgroundImage, 0, 0, 360, 420); } else {
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
        var bodyImageData = document.getElementById('bodyImageData');
        if (bodyImageData) bodyImageData.value = canvas.toDataURL();
    }
    function composite() { drawWithBackground(); }
    function saveState() { var state = drawingLayer.toDataURL(); historyStack.push(state); if (historyStack.length > 30) historyStack.shift(); redoStack = []; }
    function restoreDrawing(dataURL) { var img = new Image(); img.onload = function() { drawCtx.clearRect(0, 0, 360, 420); drawCtx.drawImage(img, 0, 0); composite(); }; img.src = dataURL; }
    window.restoreBodyDrawing = function(dataURL) { restoreDrawing(dataURL); };
    window.clearDrawing = function() { drawCtx.clearRect(0, 0, 360, 420); composite(); saveState(); };
    var drawing = false, currentMode = 'draw', currentColor = '#ff0000', brushSize = 5, lastX, lastY;
    function getCoords(e) {
        var rect = canvas.getBoundingClientRect(), scaleX = canvas.width / rect.width, scaleY = canvas.height / rect.height;
        var cx, cy;
        if (e.touches) { cx = e.touches[0].clientX; cy = e.touches[0].clientY; } else { cx = e.clientX; cy = e.clientY; }
        var x = (cx - rect.left) * scaleX, y = (cy - rect.top) * scaleY;
        return { x: Math.min(Math.max(0, x), canvas.width), y: Math.min(Math.max(0, y), canvas.height) };
    }
    function drawOnLayer(x, y) {
        drawCtx.globalCompositeOperation = currentMode === 'draw' ? 'source-over' : 'destination-out';
        drawCtx.strokeStyle = currentColor; drawCtx.fillStyle = currentColor; drawCtx.lineWidth = brushSize; drawCtx.lineCap = 'round';
        drawCtx.beginPath(); drawCtx.moveTo(lastX, lastY); drawCtx.lineTo(x, y); drawCtx.stroke();
        drawCtx.beginPath(); drawCtx.arc(x, y, brushSize / 2, 0, Math.PI * 2); drawCtx.fill();
        lastX = x; lastY = y; composite();
    }
    function startDraw(e) { drawing = true; var coords = getCoords(e); lastX = coords.x; lastY = coords.y; saveState(); drawOnLayer(lastX, lastY); e.preventDefault(); }
    function drawMove(e) { if (!drawing) return; var coords = getCoords(e); drawOnLayer(coords.x, coords.y); e.preventDefault(); }
    canvas.addEventListener('mousedown', startDraw); canvas.addEventListener('mousemove', drawMove); canvas.addEventListener('mouseup', function() { drawing = false; }); canvas.addEventListener('mouseleave', function() { drawing = false; });
    canvas.addEventListener('touchstart', startDraw); canvas.addEventListener('touchmove', drawMove); canvas.addEventListener('touchend', function() { drawing = false; });
    var drawBtn = document.getElementById('drawBtn'); if (drawBtn) drawBtn.addEventListener('click', function() { currentMode = 'draw'; drawBtn.classList.add('active'); document.getElementById('eraseBtn').classList.remove('active'); });
    var eraseBtn = document.getElementById('eraseBtn'); if (eraseBtn) eraseBtn.addEventListener('click', function() { currentMode = 'erase'; eraseBtn.classList.add('active'); document.getElementById('drawBtn').classList.remove('active'); });
    var penColor = document.getElementById('penColor'); if (penColor) penColor.addEventListener('change', function(e) { currentColor = e.target.value; });
    var brushSizeInput = document.getElementById('brushSize'); if (brushSizeInput) brushSizeInput.addEventListener('input', function(e) { brushSize = parseInt(e.target.value); });
    var clearCanvasBtn = document.getElementById('clearCanvasBtn'); if (clearCanvasBtn) clearCanvasBtn.addEventListener('click', function() { drawCtx.clearRect(0, 0, 360, 420); composite(); saveState(); });
    var undoBtn = document.getElementById('undoBtn'); if (undoBtn) undoBtn.addEventListener('click', function() { if (historyStack.length > 1) { redoStack.push(historyStack.pop()); restoreDrawing(historyStack[historyStack.length - 1]); } });
    var redoBtn = document.getElementById('redoBtn'); if (redoBtn) redoBtn.addEventListener('click', function() { if (redoStack.length) { var state = redoStack.pop(); historyStack.push(state); restoreDrawing(state); } });
    var bgImg = new Image();
    bgImg.onload = function() { backgroundImage = bgImg; composite(); saveState(); };
    bgImg.onerror = function() { backgroundImage = null; composite(); saveState(); };
    bgImg.src = 'images/image.png';
})();

function updateImageGallery() {
    var gallery = document.getElementById('incidentImageGallery');
    var countSpan = document.getElementById('imageCount');
    if (!gallery) return;
    gallery.innerHTML = '';
    incidentImages.forEach(function(img, idx) {
        var div = document.createElement('div'); div.className = 'gallery-item';
        var imgEl = document.createElement('img'); imgEl.src = img;
        var rm = document.createElement('div'); rm.className = 'remove-img'; rm.innerHTML = '×';
        rm.onclick = function() { incidentImages.splice(idx, 1); updateImageGallery(); document.getElementById('incidentImagesData').value = JSON.stringify(incidentImages); };
        div.appendChild(imgEl); div.appendChild(rm); gallery.appendChild(div);
    });
    if (countSpan) countSpan.innerText = incidentImages.length + ' image(s)';
    document.getElementById('incidentImagesData').value = JSON.stringify(incidentImages);
}

var imagesInput = document.getElementById('incidentImagesInput');
if (imagesInput) {
    imagesInput.addEventListener('change', function(e) {
        Array.from(e.target.files).forEach(function(f) {
            if (f.type.startsWith('image/')) {
                var r = new FileReader();
                r.onload = function(ev) { incidentImages.push(ev.target.result); updateImageGallery(); };
                r.readAsDataURL(f);
            }
        });
        e.target.value = '';
    });
}
var clearAllBtn = document.getElementById('clearAllImagesBtn');
if (clearAllBtn) clearAllBtn.addEventListener('click', function() { if (confirm('Clear all images?')) { incidentImages = []; updateImageGallery(); } });

function setupLogo(imgId, inputId) {
    var img = document.getElementById(imgId);
    var input = document.getElementById(inputId);
    if (input) input.addEventListener('change', function(e) { if (e.target.files && e.target.files[0]) { var reader = new FileReader(); reader.onload = function(ev) { img.src = ev.target.result; }; reader.readAsDataURL(e.target.files[0]); } });
}
setupLogo('logoLeftImg', 'logoLeftInput');
setupLogo('logoRightImg', 'logoRightInput');

var fabMain = document.getElementById('fabMain'), fabMenu = document.getElementById('fabMenu');
if (fabMain) {
    fabMain.addEventListener('click', function(e) { e.stopPropagation(); fabMenu.classList.toggle('show'); });
    document.addEventListener('click', function() { if (fabMenu) fabMenu.classList.remove('show'); });
    if (fabMenu) fabMenu.addEventListener('click', function(e) { e.stopPropagation(); });
}
var saveDraftBtn = document.getElementById('fabSaveDraft'); if (saveDraftBtn) saveDraftBtn.addEventListener('click', saveProgress);
var clearFormBtn = document.getElementById('fabClearForm'); if (clearFormBtn) clearFormBtn.addEventListener('click', clearAllFormData);
var completeReportBtn = document.getElementById('fabCompleteReport'); if (completeReportBtn) completeReportBtn.addEventListener('click', completeReportSubmission);
var printBtn = document.getElementById('fabPrint'); if (printBtn) printBtn.addEventListener('click', function() { 
    if (currentWorkingIncidentId) {
        logReportAccess(currentWorkingIncidentId, 'printed', 'report', null, 'Report printed');
    }
    window.print(); 
});

// Live Map Functions
function initLiveMap() {
    if (typeof L === 'undefined') { console.error('Leaflet not loaded'); return; }
    liveMap = L.map('liveMap').setView([15.6333, 121.3167], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(liveMap);
    if (navigator.geolocation) navigator.geolocation.getCurrentPosition(function(pos) { currentLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude }; liveMap.setView([currentLocation.lat, currentLocation.lng], 13); }, function(err) { console.log('Geolocation error:', err); });
    loadLiveMapData();
    setInterval(loadLiveMapData, 30000);
}

function loadLiveMapData() {
    $.ajax({
        url: 'api/get_map_data.php',
        method: 'GET',
        dataType: 'json',
        success: function(result) {
            if (!liveMap) return;
            if (lastIncidentCount > 0 && result.incidents.length > lastIncidentCount) $('#newIncidentAlert').fadeIn().delay(5000).fadeOut();
            lastIncidentCount = result.incidents.length;
            liveMapMarkers.forEach(function(marker) { liveMap.removeLayer(marker); });
            liveMapMarkers = [];
            
            result.incidents.forEach(function(incident) {
                if (incident.location_lat && incident.location_lng) {
                    const severityColor = getSeverityMapColor(incident.severity);
                    var circleMarker = L.circleMarker([incident.location_lat, incident.location_lng], { radius: 14, fillColor: severityColor, color: '#ffffff', weight: 3, opacity: 1, fillOpacity: 0.9 });
                    circleMarker.bindPopup(`<div style="min-width: 200px;"><strong style="color: ${severityColor};">${escapeHtml(incident.tracking_id)}</strong><hr class="my-1"><i class="fas fa-tag"></i> ${escapeHtml(incident.incident_type)}<br><i class="fas fa-exclamation-triangle"></i> Severity: <strong style="color: ${severityColor};">${(incident.severity || 'MINOR').toUpperCase()}</strong><br><i class="fas fa-clock"></i> Reported: ${new Date(incident.created_at).toLocaleString()}<br><button class="btn btn-sm btn-primary mt-2" onclick="viewIncident(${incident.incident_id})"><i class="fas fa-eye"></i> View Details</button></div>`);
                    circleMarker.addTo(liveMap);
                    liveMapMarkers.push(circleMarker);
                }
            });
            
            if (result.responders) {
                result.responders.forEach(function(responder) {
                    if (responder.current_lat && responder.current_lng) {
                        var responderMarker = L.circleMarker([responder.current_lat, responder.current_lng], { radius: 8, fillColor: '#3b82f6', color: '#ffffff', weight: 2, opacity: 1, fillOpacity: 0.9 });
                        responderMarker.bindPopup(`<strong><i class="fas fa-user-md"></i> Responder: ${escapeHtml(responder.fullname)}</strong><br><i class="fas fa-clock"></i> Last updated: ${new Date(responder.last_location_update).toLocaleTimeString()}`);
                        responderMarker.addTo(liveMap);
                        liveMapMarkers.push(responderMarker);
                    }
                });
            }
            
            if (currentLocation.lat && currentLocation.lng) {
                var userMarker = L.circleMarker([currentLocation.lat, currentLocation.lng], { radius: 10, fillColor: '#e67e22', color: '#ffffff', weight: 3, opacity: 1, fillOpacity: 0.9 });
                userMarker.bindPopup('<strong><i class="fas fa-user"></i> Your Location</strong>');
                userMarker.addTo(liveMap);
                liveMapMarkers.push(userMarker);
            }
        },
        error: function(xhr, status, error) { console.log('Error loading map data:', error); }
    });
}

function centerToMyLocation() {
    if (navigator.geolocation) {
        Swal.fire({ title: 'Getting Your Location', text: 'Please wait...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
        navigator.geolocation.getCurrentPosition(function(pos) {
            currentLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            liveMap.setView([currentLocation.lat, currentLocation.lng], 15);
            Swal.fire({ title: 'Location Found', text: 'Map centered to your location', icon: 'success', timer: 1500, showConfirmButton: false });
        }, function(err) { Swal.fire('Error', 'Could not get your location. Please enable GPS.', 'error'); }, { enableHighAccuracy: true, timeout: 10000 });
    } else { Swal.fire('Error', 'Geolocation is not supported by your browser', 'error'); }
}

function updateMyLocation() {
    if (navigator.geolocation) {
        Swal.fire({ title: 'Getting Location', text: 'Please allow location access...', allowOutsideClick: false, didOpen: function() { Swal.showLoading(); } });
        navigator.geolocation.getCurrentPosition(function(pos) {
            currentLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            $.post('api/save_location.php', { lat: pos.coords.latitude, lng: pos.coords.longitude }, function(response) {
                try { var data = JSON.parse(response); if (data.success) { Swal.fire('Success', 'Your location has been updated!', 'success'); loadLiveMapData(); if (liveMap) liveMap.setView([currentLocation.lat, currentLocation.lng], 14); } else { Swal.fire('Error', data.message, 'error'); } } catch(e) { Swal.fire('Success', 'Location saved locally', 'success'); }
            }).fail(function() { Swal.fire('Info', 'Location saved locally', 'info'); });
        }, function(err) { Swal.fire('Error', 'Could not get your location. Please enable GPS.', 'error'); }, { enableHighAccuracy: true, timeout: 10000 });
    } else { Swal.fire('Error', 'Geolocation is not supported by your browser', 'error'); }
}

function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(function(p) { p.classList.remove('active'); });
    var targetTab = document.getElementById(tabId); if (targetTab) targetTab.classList.add('active');
    document.querySelectorAll('.menu-item').forEach(function(i) {
        if (i.dataset.tab === tabId) i.classList.add('active');
        else i.classList.remove('active');
    });
    if (tabId === 'dashboard-tab') loadIncidents();
    if (tabId === 'my-reports-tab') loadMyReports();
    if (tabId === 'trash-tab') loadTrash();
    if (tabId === 'live-map-tab') { if (!liveMap) initLiveMap(); else { loadLiveMapData(); setTimeout(function() { if (liveMap) liveMap.invalidateSize(); }, 100); } }
}

function toggleMenu() { document.getElementById('sideMenu').classList.toggle('closed'); document.getElementById('mainContent').classList.toggle('expanded'); }
function closeMenu() { if (window.innerWidth <= 768) { document.getElementById('sideMenu').classList.add('closed'); document.getElementById('mainContent').classList.add('expanded'); } }

// Logout function
document.getElementById('logoutBtn')?.addEventListener('click', function() {
    Swal.fire({
        title: 'Logout?',
        text: 'Are you sure you want to logout?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, Logout',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'responder_logout.php';
        }
    });
});

document.getElementById('menuToggleBtn')?.addEventListener('click', toggleMenu);
document.getElementById('closeMenuBtn')?.addEventListener('click', closeMenu);
document.querySelectorAll('.menu-item').forEach(function(btn) {
    btn.addEventListener('click', function() { switchTab(btn.dataset.tab); if (window.innerWidth <= 768) closeMenu(); });
});

var centerBtn = document.getElementById('centerToMyLocation'); if (centerBtn) centerBtn.addEventListener('click', centerToMyLocation);
var updateBtn = document.getElementById('updateMyLocation'); if (updateBtn) updateBtn.addEventListener('click', updateMyLocation);
var refreshBtn = document.getElementById('refreshMap'); if (refreshBtn) refreshBtn.addEventListener('click', function() { loadLiveMapData(); Swal.fire('Refreshed', 'Map data has been updated', 'success'); });
var newAlert = document.getElementById('newIncidentAlert'); if (newAlert) newAlert.addEventListener('click', function() { loadLiveMapData(); $(this).fadeOut(); });

$(document).ready(function() {
    loadIncidents();
    if (window.innerWidth <= 768) closeMenu();
    setInterval(loadIncidents, 30000);
    initSignatures();
    initNotifications();
    var today = new Date().toISOString().split('T')[0];
    var incidentDateInput = document.getElementById('incidentDate');
    var refusalDateInput = document.getElementById('refusalDate');
    if (incidentDateInput) incidentDateInput.value = today;
    if (refusalDateInput) refusalDateInput.value = today;
    
    // Close action dropdowns when clicking elsewhere
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.action-dropdown')) {
            document.querySelectorAll('.action-menu-dropdown.show').forEach(function(menu) {
                menu.classList.remove('show');
            });
        }
    });
});
</script>
</body>
</html>