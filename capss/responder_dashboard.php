<?php
// responder_dashboard.php - Complete responder interface with access tracking
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    header('Location: responder_login.php');
    exit;
}

// Check if videos table exists
$video_table_exists = $conn->query("SHOW TABLES LIKE 'tbl_incident_videos'")->num_rows > 0;

// Build video count SQL conditionally
$video_count_sql = $video_table_exists 
    ? "(SELECT COUNT(*) FROM tbl_incident_videos WHERE incident_id = i.incident_id) as video_count"
    : "0 as video_count";

// Get pending incidents - ORDER BY created_at DESC (newest on top)
$pending = $conn->query("
    SELECT i.*, 
           (SELECT COUNT(*) FROM tbl_incident_photos WHERE incident_id = i.incident_id) as photo_count,
           $video_count_sql
    FROM tbl_incidents i 
    WHERE i.status = 'pending'
    ORDER BY i.created_at DESC
");

// Get my active incidents - newest on top
$stmt = $conn->prepare("
    SELECT i.*, 
           (SELECT COUNT(*) FROM tbl_incident_photos WHERE incident_id = i.incident_id) as photo_count,
           $video_count_sql
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

// Get shared incidents (granted to this responder by others)
$shared_query = "
    SELECT i.incident_id, i.tracking_id, i.incident_type, i.severity, i.location_lat, i.location_lng, 
           i.location_address, i.description, i.reporter_name, i.reporter_phone, i.created_at, i.status,
           g.access_level, g.granted_by_responder_id, u.fullname as shared_by_name,
           (SELECT COUNT(*) FROM tbl_incident_drafts WHERE incident_id = i.incident_id) as has_draft
    FROM tbl_report_access_grants g
    JOIN tbl_incidents i ON g.incident_id = i.incident_id
    JOIN tbl_users u ON g.granted_by_responder_id = u.user_id
    WHERE g.granted_to_responder_id = {$_SESSION['user_id']} 
    AND g.is_active = 1
    AND i.status = 'dispatched'
    AND (i.taken_by_responder_id != {$_SESSION['user_id']} OR i.taken_by_responder_id IS NULL)
    ORDER BY g.granted_at DESC
";
$shared = $conn->query($shared_query);
$shared_count = $shared ? $shared->num_rows : 0;

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
        action_type ENUM('viewed', 'taken', 'arrived', 'completed', 'transferred', 'navigating') DEFAULT 'viewed',
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

// Create responder locations table for tracking
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

// Handle AJAX request to update responder location
if (isset($_POST['action']) && $_POST['action'] === 'update_location') {
    header('Content-Type: application/json');
    $lat = floatval($_POST['lat']);
    $lng = floatval($_POST['lng']);
    
    $stmt = $conn->prepare("INSERT INTO tbl_responder_locations (responder_id, latitude, longitude) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE latitude = ?, longitude = ?, updated_at = NOW()");
    $stmt->bind_param("idddd", $_SESSION['user_id'], $lat, $lng, $lat, $lng);
    
    if ($stmt->execute()) {
        $_SESSION['current_lat'] = $lat;
        $_SESSION['current_lng'] = $lng;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Handle AJAX request to start navigation
if (isset($_POST['action']) && $_POST['action'] === 'start_navigation') {
    header('Content-Type: application/json');
    $incident_id = intval($_POST['incident_id']);
    
    $stmt = $conn->prepare("INSERT INTO tbl_responder_actions (incident_id, responder_id, action_type, notes) VALUES (?, ?, 'navigating', 'Started navigation to incident location')");
    $stmt->bind_param("ii", $incident_id, $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
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

function getSeverityClass($severity) {
    $severity = trim(strtolower($severity));
    if (empty($severity)) return 'severity-minor';
    if ($severity === 'dead' || $severity === 'deceased' || $severity === 'black') return 'severity-dead';
    if ($severity === 'high' || $severity === 'critical' || $severity === 'immediate' || $severity === 'red') return 'severity-immediate';
    if ($severity === 'moderate' || $severity === 'delayed' || $severity === 'yellow' || $severity === 'serious') return 'severity-delayed';
    if ($severity === 'low' || $severity === 'minor' || $severity === 'green') return 'severity-minor';
    return 'severity-minor';
}

// Get theme preference from cookie - DEFAULT TO LIGHT MODE
$theme = isset($_COOKIE['responder_theme']) ? $_COOKIE['responder_theme'] : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>MDRRMO Bongabon - Responder</title>
    
    <!-- Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <link rel="icon" type="image/png" href="images/bonga_logo.png">
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
    
    <style>
        /* ============================================ */
        /* THEME VARIABLES - LIGHT MODE DEFAULT */
        /* ============================================ */
        
        :root {
            /* Light Mode (Default) */
            --primary-black: #f4f6f9;
            --secondary-black: #ffffff;
            --card-black: #ffffff;
            --border-black: #e5e7eb;
            --text-light: #1f2937;
            --text-muted: #6b7280;
            --primary-yellow: #e67e22;
            --secondary-yellow: #d35400;
            --dark-yellow: #b84306;
            --danger: #dc2626;
            --success: #059669;
            --info: #2563eb;
            --purple: #7c3aed;
            --shared-purple: #8e44ad;
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
            /* Dark Mode */
            --primary-black: #0a0a0a;
            --secondary-black: #1a1a1a;
            --card-black: #1e1e1e;
            --border-black: #2a2a2a;
            --text-light: #e5e5e5;
            --text-muted: #9ca3af;
            --primary-yellow: #fbbf24;
            --secondary-yellow: #f59e0b;
            --dark-yellow: #d97706;
            --danger: #ef4444;
            --success: #10b981;
            --info: #3b82f6;
            --purple: #8b5cf6;
            --shared-purple: #a78bfa;
            --modal-bg: #1e1e1e;
            --input-bg: #0a0a0a;
            --table-header-bg: #1a1a1a;
            --chart-text: #e5e5e5;
            --stat-icon-bg: rgba(251, 191, 36, 0.1);
            --incident-item-bg: #1a1a1a;
            --incident-item-hover: #1e1e1e;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--primary-black);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            min-height: 100vh;
            color: var(--text-light);
            padding-bottom: 70px;
        }
        
        /* ============================================ */
        /* CONNECTION STATUS */
        /* ============================================ */
        
        .connection-status {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--danger);
            color: white;
            text-align: center;
            padding: 4px;
            font-size: 12px;
            z-index: 2000;
            transform: translateY(-100%);
            transition: transform 0.3s;
        }
        
        .connection-status.offline {
            transform: translateY(0);
        }
        
        /* ============================================ */
        /* BOTTOM NAVIGATION */
        /* ============================================ */
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--secondary-black);
            display: flex;
            justify-content: space-around;
            align-items: center;
            padding: 8px 4px;
            border-top: 2px solid var(--primary-yellow);
            z-index: 1000;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.5);
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 11px;
            padding: 6px 10px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 60px;
        }
        
        .nav-item i {
            font-size: 22px;
            margin-bottom: 3px;
        }
        
        .nav-item.active {
            color: var(--primary-black);
            background: var(--primary-yellow);
            font-weight: 600;
        }
        
        /* ============================================ */
        /* HEADER */
        /* ============================================ */
        
        .app-header {
            background: linear-gradient(135deg, var(--secondary-black) 0%, var(--primary-black) 100%);
            padding: 16px 16px 12px;
            border-bottom: 3px solid var(--primary-yellow);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .app-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .app-title i {
            color: var(--primary-yellow);
            font-size: 24px;
        }
        
        .app-title h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: var(--text-light);
        }
        
        .app-title span {
            font-size: 11px;
            color: var(--text-muted);
            display: block;
        }
        
        .header-actions {
            display: flex;
            gap: 8px;
        }
        
        .header-btn {
            background: var(--card-black);
            border: 1px solid var(--border-black);
            color: var(--primary-yellow);
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            transition: all 0.2s;
        }
        
        .header-btn:active {
            background: var(--primary-yellow);
            color: var(--primary-black);
        }
        
        /* Theme Toggle Button */
        .theme-toggle {
            background: var(--card-black);
            border: 1px solid var(--border-black);
            color: var(--primary-yellow);
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .theme-toggle:hover {
            background: var(--primary-yellow);
            color: var(--primary-black);
        }
        
        /* Stats Cards */
        .stats-container {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 4px;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        
        .stats-container::-webkit-scrollbar {
            display: none;
        }
        
        .stat-card {
            background: var(--card-black);
            border: 1px solid var(--border-black);
            border-radius: 16px;
            padding: 12px 16px;
            min-width: 100px;
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stat-card i {
            font-size: 24px;
            color: var(--primary-yellow);
        }
        
        .stat-info h3 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: var(--primary-yellow);
            transition: all 0.3s ease;
        }
        
        .stat-info p {
            font-size: 11px;
            margin: 0;
            color: var(--text-muted);
        }
        
        /* Number change animation */
        .stat-info h3.number-changed {
            animation: numberPulse 0.5s ease;
        }
        
        @keyframes numberPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.3); color: var(--primary-yellow); }
            100% { transform: scale(1); }
        }
        
        /* ============================================ */
        /* MAIN CONTENT */
        /* ============================================ */
        
        .main-content {
            padding: 16px;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .tab-pane {
            display: none;
        }
        
        .tab-pane.active {
            display: block;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .section-header h2 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .section-header h2 i {
            color: var(--primary-yellow);
        }
        
        .action-btn {
            background: var(--card-black);
            border: 1px solid var(--border-black);
            color: var(--primary-yellow);
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .action-btn.primary {
            background: var(--primary-yellow);
            color: var(--primary-black);
            border: none;
        }
        
        /* Loading state */
        #pendingReportsList.loading,
        #myReportsList.loading {
            position: relative;
            min-height: 200px;
            opacity: 0.6;
            pointer-events: none;
        }
        
        #pendingReportsList.loading::after,
        #myReportsList.loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 40px;
            height: 40px;
            margin: -20px 0 0 -20px;
            border: 3px solid var(--border-black);
            border-top-color: var(--primary-yellow);
            border-radius: 50%;
            animation: spinner 0.8s linear infinite;
        }
        
        @keyframes spinner {
            to { transform: rotate(360deg); }
        }
        
        /* ============================================ */
        /* INCIDENT CARDS */
        /* ============================================ */
        
        .incident-card {
            background: var(--card-black);
            border-left: 5px solid var(--primary-yellow);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .incident-card:active {
            transform: scale(0.98);
        }
        
        .incident-card.severity-immediate {
            border-left-color: var(--danger);
            background: linear-gradient(90deg, rgba(239,68,68,0.1) 0%, var(--card-black) 100%);
        }
        
        .incident-card.severity-delayed {
            border-left-color: var(--secondary-yellow);
            background: linear-gradient(90deg, rgba(245,158,11,0.1) 0%, var(--card-black) 100%);
        }
        
        .incident-card.shared-card {
            border-left-color: var(--shared-purple) !important;
            background: linear-gradient(90deg, rgba(142, 68, 173, 0.1) 0%, var(--card-black) 100%);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .location-badge {
            background: var(--secondary-black);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-light);
            border: 1px solid var(--border-black);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .location-badge i {
            color: var(--primary-yellow);
        }
        
        .status-badge {
            font-size: 10px;
            padding: 3px 10px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .status-badge.pending {
            background: rgba(251,191,36,0.2);
            color: var(--primary-yellow);
        }
        
        .card-body {
            margin-bottom: 12px;
        }
        
        .incident-type {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .incident-location {
            font-size: 13px;
            color: var(--text-muted);
            display: flex;
            align-items: flex-start;
            gap: 6px;
        }
        
        .incident-location i {
            color: var(--primary-yellow);
            margin-top: 3px;
        }
        
        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 10px;
            border-top: 1px solid var(--border-black);
        }
        
        .incident-time {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .distance-badge {
            background: rgba(59,130,246,0.1);
            color: var(--info);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
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
        
        .severity-dot.minor { background: var(--primary-yellow); }
        .severity-dot.delayed { background: var(--secondary-yellow); }
        .severity-dot.immediate { background: var(--danger); }
        .severity-dot.dead { background: var(--text-muted); }
        
        /* Card Action Menu */
        .card-menu {
            position: relative;
        }
        
        .menu-trigger {
            background: none;
            border: none;
            color: var(--text-muted);
            padding: 6px;
            cursor: pointer;
            font-size: 18px;
        }
        
        .menu-dropdown {
            position: absolute;
            right: 0;
            top: 100%;
            background: var(--secondary-black);
            border: 1px solid var(--border-black);
            border-radius: 12px;
            min-width: 180px;
            z-index: 100;
            display: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            overflow: hidden;
        }
        
        .menu-dropdown.show {
            display: block;
        }
        
        .menu-item-btn {
            width: 100%;
            padding: 12px 16px;
            background: none;
            border: none;
            color: var(--text-light);
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid var(--border-black);
        }
        
        .menu-item-btn:last-child {
            border-bottom: none;
        }
        
        .menu-item-btn i {
            width: 20px;
            color: var(--primary-yellow);
        }
        
        .menu-item-btn:active {
            background: var(--card-black);
        }
        
        .menu-item-btn.danger {
            color: var(--danger);
        }
        
        .menu-item-btn.danger i {
            color: var(--danger);
        }
        
        .menu-item-btn.share {
            color: var(--shared-purple);
        }
        
        .menu-item-btn.share i {
            color: var(--shared-purple);
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
            color: var(--primary-yellow);
        }
        
        /* FAB Button */
        .fab-button {
            position: fixed;
            bottom: 80px;
            right: 20px;
            width: 56px;
            height: 56px;
            border-radius: 28px;
            background: var(--primary-yellow);
            border: none;
            color: var(--primary-black);
            font-size: 24px;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(251,191,36,0.4);
            z-index: 99;
        }
        
        .fab-button.visible {
            display: flex;
        }
        
        .fab-menu {
            position: fixed;
            bottom: 150px;
            right: 20px;
            background: var(--secondary-black);
            border: 1px solid var(--border-black);
            border-radius: 16px;
            overflow: hidden;
            display: none;
            z-index: 100;
        }
        
        .fab-menu.show {
            display: block;
        }
        
        .fab-menu-item {
            padding: 14px 20px;
            background: none;
            border: none;
            color: var(--text-light);
            width: 100%;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            font-size: 14px;
            border-bottom: 1px solid var(--border-black);
        }
        
        .fab-menu-item i {
            width: 20px;
            color: var(--primary-yellow);
        }
        
        /* Map Styles */
        .map-container {
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 16px;
            border: 2px solid var(--border-black);
        }
        
        #liveMap {
            height: 400px;
            width: 100%;
        }
        
        .navigation-map-container {
            height: 500px;
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .nav-info-panel {
            background: var(--secondary-black);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }
        
        .nav-info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-black);
        }
        
        .nav-info-row:last-child {
            border-bottom: none;
        }
        
        .nav-info-label {
            color: var(--text-muted);
            font-size: 13px;
        }
        
        .nav-info-value {
            font-weight: 600;
            color: var(--primary-yellow);
        }
        
        .map-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 12px 0;
            padding: 12px;
            background: var(--card-black);
            border-radius: 16px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
        }
        
        .legend-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid white;
        }
        
        .map-controls {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .map-btn {
            flex: 1;
            min-width: 100px;
            padding: 12px;
            background: var(--card-black);
            border: 1px solid var(--border-black);
            border-radius: 12px;
            color: var(--text-light);
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .map-btn i {
            color: var(--primary-yellow);
        }
        
        .map-btn.navigate {
            background: var(--primary-yellow);
            color: var(--primary-black);
        }
        
        .map-btn.navigate i {
            color: var(--primary-black);
        }
        
        /* Media Gallery */
        .media-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .media-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--border-black);
            cursor: pointer;
        }
        
        .media-item img,
        .media-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Form Styles */
        .form-page {
            background: var(--card-black);
            border-radius: 0;
            padding: 16px;
            margin: -16px;
            margin-bottom: 0;
        }
        
        .form-logo-section {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 10px;
            background: var(--secondary-black);
            border-radius: 12px;
        }
        
        .logo-box {
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
        }
        
        .logo-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .logo-upload-label {
            position: absolute;
            bottom: -8px;
            font-size: 8px;
            background: var(--primary-yellow);
            color: black;
            padding: 2px 6px;
            border-radius: 10px;
            white-space: nowrap;
        }
        
        .form-title-section {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .form-title-section h4 {
            font-size: 14px;
            margin: 0;
        }
        
        .form-title-section h3 {
            font-size: 16px;
            margin: 4px 0;
            color: var(--primary-yellow);
        }
        
        .form-title-section h2 {
            font-size: 18px;
            margin: 0;
        }
        
        .two-column-layout {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        @media (min-width: 768px) {
            .two-column-layout {
                flex-direction: row;
            }
            .left-column, .right-column {
                flex: 1;
            }
        }
        
        .form-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            background: var(--secondary-black);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .form-table th {
            background: var(--primary-yellow);
            color: var(--primary-black);
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 600;
            text-align: left;
        }
        
        .form-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-black);
            vertical-align: top;
        }
        
        .form-table tr:last-child td {
            border-bottom: none;
        }
        
        .label-cell {
            font-weight: 600;
            color: var(--primary-yellow);
            width: 40%;
            font-size: 12px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            background: var(--input-bg);
            border: 1px solid var(--border-black);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 13px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-yellow);
        }
        
        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }
        
        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23fbbf24' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
        }
        
        /* Injury Map Styles */
        .injury-map-container {
            background: white;
            border-radius: 12px;
            padding: 12px;
            margin-top: 10px;
        }
        
        .canvas-wrapper {
            border: 2px solid #333;
            background: white;
            display: inline-block;
            width: 100%;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        
        #bodyCanvas {
            width: 100%;
            height: auto;
            background: white;
            border-radius: 8px;
            touch-action: none;
            cursor: crosshair;
        }
        
        .draw-tools {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 10px;
            align-items: center;
        }
        
        .tool-btn {
            padding: 8px 12px;
            background: var(--secondary-black);
            border: 1px solid var(--border-black);
            border-radius: 8px;
            color: var(--text-light);
            font-size: 11px;
            cursor: pointer;
            flex: 1;
        }
        
        .tool-btn.active {
            background: var(--primary-yellow);
            color: var(--primary-black);
        }
        
        .color-picker {
            width: 35px;
            height: 35px;
            border: 1px solid #ccc;
            cursor: pointer;
            border-radius: 4px;
        }
        
        /* Signature Styles */
        .signature-container {
            background: white;
            border-radius: 8px;
            padding: 8px;
            margin-top: 5px;
        }
        
        .signature-area {
            border: 1px solid #ccc;
            background: white;
            margin-top: 5px;
            border-radius: 6px;
            padding: 8px;
        }
        
        .signature-canvas {
            width: 100%;
            height: 80px;
            border: 1px solid #ddd;
            background: #fff;
            touch-action: none;
            border-radius: 4px;
        }
        
        .sig-buttons {
            display: flex;
            gap: 6px;
            margin-top: 6px;
        }
        
        .sig-btn {
            padding: 6px 12px;
            background: #e0e0e0;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            cursor: pointer;
        }
        
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 12px;
        }
        
        .photo-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--border-black);
        }
        
        .photo-item img,
        .photo-item video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .photo-remove {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 28px;
            height: 28px;
            border-radius: 14px;
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
            padding: 14px;
            background: var(--secondary-black);
            border: 2px dashed var(--border-black);
            border-radius: 12px;
            color: var(--primary-yellow);
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .refusal-text {
            font-size: 11px;
            line-height: 1.4;
            background: rgba(251,191,36,0.1);
            padding: 12px;
            border-radius: 8px;
            border-left: 3px solid var(--primary-yellow);
        }
        
        .filipino-text {
            font-size: 10px;
            color: var(--text-muted);
            font-style: italic;
            display: block;
            margin-top: 2px;
        }
        
        /* Toast Notifications */
        .notification-toast {
            position: fixed;
            top: 80px;
            right: 16px;
            left: 16px;
            z-index: 1100;
        }
        
        .toast-notification {
            background: var(--secondary-black);
            border-left: 4px solid var(--primary-yellow);
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .toast-notification i {
            color: var(--primary-yellow);
            font-size: 20px;
        }
        
        .toast-content {
            flex: 1;
        }
        
        .toast-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .toast-message {
            font-size: 13px;
            color: var(--text-muted);
        }
        
        .toast-close {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 16px;
        }
        
        /* New incident alert */
        .new-incident-alert {
            background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 100%);
            color: #000;
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 20px rgba(251, 191, 36, 0.4);
            animation: slideInAlert 0.3s ease;
        }
        
        .new-incident-alert i {
            font-size: 24px;
            animation: bellRing 0.5s ease infinite;
        }
        
        .new-incident-alert span {
            flex: 1;
            font-weight: 600;
        }
        
        .new-incident-alert button {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            opacity: 0.7;
        }
        
        @keyframes slideInAlert {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes bellRing {
            0%, 100% { transform: rotate(0); }
            25% { transform: rotate(-15deg); }
            75% { transform: rotate(15deg); }
        }
        
        /* Auto-save indicator */
        .auto-save-indicator {
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--success);
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 12px;
            z-index: 1000;
            display: none;
        }
        
        /* Modal Styles */
        .modal-content {
            background: var(--modal-bg);
            color: var(--text-light);
        }
        
        .modal-header {
            border-bottom: 1px solid var(--border-black);
            background: var(--primary-black);
        }
        
        .modal-header .modal-title {
            color: var(--primary-yellow);
        }
        
        .modal-header .btn-close {
            filter: invert(1);
        }
        
        [data-theme="light"] .modal-header .btn-close {
            filter: none;
        }
        
        .modal-footer {
            border-top: 1px solid var(--border-black);
        }
        
        .btn-primary {
            background: var(--primary-yellow);
            color: var(--primary-black);
            border: none;
        }
        
        .btn-secondary {
            background: var(--card-black);
            color: var(--text-light);
            border: 1px solid var(--border-black);
        }
        
        /* Utility */
        .d-none { display: none !important; }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-warning {
            background: rgba(251,191,36,0.2);
            color: var(--primary-yellow);
        }
        .badge-info {
            background: rgba(59,130,246,0.2);
            color: var(--info);
        }
        .badge-success {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }
        .badge-shared {
            background: rgba(142, 68, 173, 0.2);
            color: var(--shared-purple);
        }
        
        /* Tab Buttons */
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
            color: var(--primary-yellow);
            background: rgba(251,191,36,0.1);
        }
        
        .tab-btn.active {
            color: var(--primary-yellow);
            border-bottom: 2px solid var(--primary-yellow);
        }
        
        .tab-btn i {
            margin-right: 8px;
        }
        
        /* Access Log Styles */
        .access-log-item {
            transition: all 0.2s ease;
            border-radius: 8px;
            margin-bottom: 12px;
            padding: 12px;
            background: var(--card-black);
            border-left: 3px solid var(--primary-yellow);
        }
        
        .access-log-item:hover {
            transform: translateX(5px);
        }
        
        .timeline {
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Video badge */
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
            color: var(--primary-yellow);
        }
        
        .media-badge.video {
            background: rgba(37, 99, 235, 0.2);
            color: var(--info);
        }
        
        /* Proximity Alert Styles */
        .proximity-alert {
            position: fixed;
            top: 80px;
            right: 16px;
            left: auto;
            transform: none;
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            z-index: 2000;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 8px 30px rgba(220, 38, 38, 0.5);
            animation: slideInRight 0.3s ease, pulseAlert 2s infinite;
            cursor: pointer;
            max-width: 320px;
            width: auto;
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .proximity-alert.high-severity {
            background: linear-gradient(135deg, #7c2d12 0%, #ea580c 100%);
            animation: slideInRight 0.3s ease, pulseHighSeverity 1.5s infinite;
        }
        
        .proximity-alert.critical {
            background: linear-gradient(135deg, #991b1b 0%, #dc2626 100%);
            animation: slideInRight 0.3s ease, pulseCritical 1s infinite;
        }
        
        @keyframes pulseAlert {
            0%, 100% { box-shadow: 0 8px 30px rgba(220, 38, 38, 0.5); }
            50% { box-shadow: 0 8px 40px rgba(220, 38, 38, 0.8); }
        }
        
        @keyframes pulseHighSeverity {
            0%, 100% { box-shadow: 0 8px 30px rgba(234, 88, 12, 0.5); }
            50% { box-shadow: 0 8px 45px rgba(234, 88, 12, 0.9); }
        }
        
        @keyframes pulseCritical {
            0%, 100% { box-shadow: 0 8px 30px rgba(220, 38, 38, 0.6); }
            50% { box-shadow: 0 8px 50px rgba(220, 38, 38, 1); }
        }
        
        .proximity-alert i {
            font-size: 24px;
            animation: bellShake 0.5s ease infinite;
        }
        
        @keyframes bellShake {
            0%, 100% { transform: rotate(0); }
            20% { transform: rotate(-15deg); }
            40% { transform: rotate(15deg); }
            60% { transform: rotate(-10deg); }
            80% { transform: rotate(10deg); }
        }
        
        .proximity-alert .alert-content {
            flex: 1;
        }
        
        .proximity-alert .alert-title {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 2px;
        }
        
        .proximity-alert .alert-details {
            font-size: 12px;
            opacity: 0.9;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .proximity-alert .alert-distance {
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .proximity-alert .close-alert {
            background: none;
            border: none;
            color: white;
            font-size: 18px;
            cursor: pointer;
            opacity: 0.7;
            padding: 5px;
        }
        
        /* Sound Settings Button */
        .sound-settings {
            position: fixed;
            bottom: 140px;
            left: 20px;
            background: var(--card-black);
            border: 1px solid var(--border-black);
            border-radius: 30px;
            padding: 8px 12px;
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 98;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.3s;
        }
        
        .sound-settings:hover {
            opacity: 1;
        }
        
        .sound-settings i {
            color: var(--primary-yellow);
        }
        
        .sound-settings.muted i {
            color: var(--text-muted);
        }
        
        /* Proximity Range Slider */
        .proximity-settings {
            margin-top: 10px;
            padding: 10px;
            background: var(--card-black);
            border-radius: 8px;
        }
        
        .proximity-settings label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-muted);
            font-size: 12px;
        }
        
        .proximity-settings input[type="range"] {
            width: 100%;
            accent-color: var(--primary-yellow);
        }
        
        /* Last updated indicator */
        .last-updated {
            font-size: 10px;
            color: var(--text-muted);
            text-align: right;
            margin-top: 4px;
        }
        
        .last-updated i {
            margin-right: 4px;
        }
    </style>
</head>
<body>

<!-- Connection Status Indicator -->
<div class="connection-status" id="connectionStatus">
    <i class="fas fa-wifi-slash"></i> No Internet Connection - Working Offline
</div>

<!-- Sound Settings Button -->
<div class="sound-settings" id="soundSettingsBtn" onclick="toggleSoundSettings()">
    <i class="fas fa-volume-up" id="soundIcon"></i>
    <span id="soundStatus">Alerts On</span>
    <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
</div>

<!-- Proximity Alert Container -->
<div id="proximityAlertContainer" style="position: fixed; top: 80px; right: 16px; z-index: 1999; pointer-events: none; display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
</div>

<!-- App Header -->
<div class="app-header">
    <div class="header-top">
        <div class="app-title">
            <i class="fas fa-shield-hal"></i>
            <div>
                <h1>MDRRMO Bongabon</h1>
                <span><?= htmlspecialchars($_SESSION['fullname'] ?? 'Responder') ?></span>
            </div>
        </div>
        <div class="header-actions">
            <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()">
                <i class="fas fa-moon"></i>
            </button>
            <button class="header-btn" onclick="showCreateReportModal()" title="New Report">
                <i class="fas fa-plus"></i>
            </button>
            <button class="header-btn" onclick="confirmLogout()" title="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </button>
        </div>
    </div>
    
    <!-- Stats Container -->
    <div class="stats-container" id="statsContainer">
        <div class="stat-card">
            <i class="fas fa-bell"></i>
            <div class="stat-info">
                <h3 id="pendingCount"><?= $pending_count ?></h3>
                <p>Pending</p>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-truck"></i>
            <div class="stat-info">
                <h3 id="activeCount"><?= $active_count ?></h3>
                <p>Active</p>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <div class="stat-info">
                <h3 id="completedCount"><?= $completed_count ?></h3>
                <p>Completed</p>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Area -->
<div class="main-content">
    
    <!-- Dashboard Tab -->
    <div id="dashboard-tab" class="tab-pane active">
        <div class="section-header">
            <h2><i class="fas fa-bell"></i> Pending Reports</h2>
        </div>
        <div id="pendingReportsList">
            <?php if ($pending_count > 0): ?>
                <?php 
                $pending->data_seek(0);
                while($row = $pending->fetch_assoc()): 
                    $severityClass = getSeverityClass($row['severity']);
                    $severityDot = strtolower(trim($row['severity']));
                    if (!in_array($severityDot, ['minor', 'delayed', 'immediate', 'dead'])) $severityDot = 'minor';
                    $locationParts = explode(',', $row['location_address'] ?? '');
                    $shortLocation = trim($locationParts[0]) ?: 'Unknown Location';
                    $reporterDisplay = !empty($row['reporter_name']) ? htmlspecialchars($row['reporter_name']) : 'No name provided';
                    $timestamp = strtotime($row['created_at']);
                ?>
                <div class="incident-card <?= $severityClass ?>" 
                     onclick="viewIncident(<?= $row['incident_id'] ?>); return false;"
                     data-lat="<?= $row['location_lat'] ?>"
                     data-lng="<?= $row['location_lng'] ?>"
                     data-timestamp="<?= $timestamp ?>">
                    <div class="card-header">
                        <span class="location-badge">
                            <i class="fas fa-map-pin"></i>
                            <?= htmlspecialchars($shortLocation) ?>
                        </span>
                        <span class="status-badge pending">PENDING</span>
                    </div>
                    <div class="card-body">
                        <div class="incident-type"><?= htmlspecialchars($row['incident_type']) ?></div>
                        <div class="incident-location">
                            <i class="fas fa-user"></i>
                            <span><strong>Reported by:</strong> <?= $reporterDisplay ?></span>
                        </div>
                        <div class="incident-location time-ago">
                            <i class="fas fa-clock"></i>
                            <span class="time-ago-text" data-timestamp="<?= $timestamp ?>"><?= date('M d, Y h:i A', $timestamp) ?></span>
                        </div>
                        <?php if ($row['photo_count'] > 0 || ($video_table_exists && $row['video_count'] > 0)): ?>
                        <div style="margin-top: 8px; display: flex; gap: 8px;">
                            <?php if ($row['photo_count'] > 0): ?>
                            <span class="media-badge photo"><i class="fas fa-image"></i> <?= $row['photo_count'] ?></span>
                            <?php endif; ?>
                            <?php if ($video_table_exists && $row['video_count'] > 0): ?>
                            <span class="media-badge video"><i class="fas fa-video"></i> <?= $row['video_count'] ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer">
                        <div class="severity-indicator">
                            <span class="severity-dot <?= $severityDot ?>"></span>
                            <span><?= strtoupper($row['severity'] ?? 'MINOR') ?></span>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Pending Reports</h3>
                    <p>All clear! New incidents will appear here.</p>
                    <button class="action-btn primary mt-3" onclick="showCreateReportModal()">
                        <i class="fas fa-plus"></i> Create New Report
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <div class="last-updated" id="lastUpdated">
            <i class="far fa-clock"></i> Updated: <span><?= date('H:i:s') ?></span>
        </div>
    </div>
    
    <!-- My Reports Tab -->
    <div id="my-reports-tab" class="tab-pane">
        <div class="section-header">
            <h2><i class="fas fa-folder-open"></i> My Reports</h2>
        </div>
        <div id="myReportsList">
            <?php if ($active_count > 0): ?>
                <?php 
                $active->data_seek(0);
                while($row = $active->fetch_assoc()): 
                    $draft_check = $conn->prepare("SELECT COUNT(*) as has_draft FROM tbl_incident_drafts WHERE incident_id = ?");
                    $draft_check->bind_param("i", $row['incident_id']);
                    $draft_check->execute();
                    $draft_result = $draft_check->get_result();
                    $has_draft = $draft_result->fetch_assoc()['has_draft'] > 0;
                    $severityClass = getSeverityClass($row['severity']);
                    $severityDot = strtolower(trim($row['severity']));
                    if (!in_array($severityDot, ['minor', 'delayed', 'immediate', 'dead'])) $severityDot = 'minor';
                    $isSelfCreated = (strpos($row['tracking_id'], 'SELF-') === 0);
                    $locationParts = explode(',', $row['location_address'] ?? '');
                    $shortLocation = trim($locationParts[0]) ?: 'Unknown Location';
                    $reporterDisplay = !empty($row['reporter_name']) ? htmlspecialchars($row['reporter_name']) : 'No name provided';
                    $timestamp = strtotime($row['created_at']);
                ?>
                <div class="incident-card <?= $severityClass ?>" onclick="viewActiveIncident(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>')">
                    <div class="card-header">
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <span class="location-badge">
                                <i class="fas fa-map-pin"></i>
                                <?= htmlspecialchars($shortLocation) ?>
                            </span>
                            <?php if($has_draft): ?>
                                <span class="badge badge-warning"><i class="fas fa-pen"></i> Draft</span>
                            <?php endif; ?>
                            <?php if($isSelfCreated): ?>
                                <span class="badge badge-info"><i class="fas fa-user-plus"></i> Self</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-menu">
                            <button class="menu-trigger" onclick="event.stopPropagation(); toggleCardMenu(event, this)">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="menu-dropdown">
                                <?php if($row['location_lat'] && $row['location_lng']): ?>
                                <button class="menu-item-btn" onclick="event.stopPropagation(); startNavigation(<?= $row['incident_id'] ?>, <?= $row['location_lat'] ?>, <?= $row['location_lng'] ?>, '<?= htmlspecialchars($shortLocation) ?>')">
                                    <i class="fas fa-directions"></i> Navigate
                                </button>
                                <?php endif; ?>
                                <button class="menu-item-btn" onclick="event.stopPropagation(); viewActiveIncident(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>')">
                                    <i class="fas fa-check-circle"></i> Complete Report
                                </button>
                                <button class="menu-item-btn share" onclick="event.stopPropagation(); showShareModal(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>')">
                                    <i class="fas fa-share-alt"></i> Share Report
                                </button>
                                <button class="menu-item-btn" onclick="event.stopPropagation(); showAccessManagement(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>')">
                                    <i class="fas fa-users"></i> Access Log
                                </button>
                                <button class="menu-item-btn danger" onclick="event.stopPropagation(); moveToTrash(this, 'active', '<?= htmlspecialchars($row['tracking_id']) ?>', <?= $row['incident_id'] ?>)">
                                    <i class="fas fa-trash"></i> Move to Trash
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="incident-type"><?= htmlspecialchars($row['incident_type']) ?></div>
                        <div class="incident-location">
                            <i class="fas fa-user"></i>
                            <span><strong>Reported by:</strong> <?= $reporterDisplay ?></span>
                        </div>
                        <div class="incident-location time-ago">
                            <i class="fas fa-clock"></i>
                            <span class="time-ago-text" data-timestamp="<?= $timestamp ?>"><?= date('M d, Y h:i A', $timestamp) ?></span>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="severity-indicator">
                            <span class="severity-dot <?= $severityDot ?>"></span>
                            <span><?= strtoupper($row['severity'] ?? 'MINOR') ?></span>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>No Active Reports</h3>
                    <p>Take a report from the Dashboard or create a new one!</p>
                </div>
            <?php endif; ?>
            
            <!-- Shared Reports Section -->
            <?php if ($shared_count > 0): ?>
                <h4 style="margin: 20px 0 12px; color: var(--shared-purple);">
                    <i class="fas fa-share-alt"></i> Shared With Me
                </h4>
                <?php 
                $shared->data_seek(0);
                while($row = $shared->fetch_assoc()): 
                    $severityClass = getSeverityClass($row['severity']);
                    $severityDot = strtolower(trim($row['severity']));
                    if (!in_array($severityDot, ['minor', 'delayed', 'immediate', 'dead'])) $severityDot = 'minor';
                    $locationParts = explode(',', $row['location_address'] ?? '');
                    $shortLocation = trim($locationParts[0]) ?: 'Unknown Location';
                    $reporterDisplay = !empty($row['reporter_name']) ? htmlspecialchars($row['reporter_name']) : 'No name provided';
                    $timestamp = strtotime($row['created_at']);
                ?>
                <div class="incident-card shared-card <?= $severityClass ?>" onclick="viewSharedIncident(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>', '<?= $row['access_level'] ?>')">
                    <div class="card-header">
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <span class="location-badge">
                                <i class="fas fa-map-pin"></i>
                                <?= htmlspecialchars($shortLocation) ?>
                            </span>
                            <span class="badge badge-shared">
                                <i class="fas fa-share-alt"></i> Shared by <?= htmlspecialchars($row['shared_by_name'] ?? 'Responder') ?>
                            </span>
                        </div>
                        <div class="card-menu">
                            <button class="menu-trigger" onclick="event.stopPropagation(); toggleCardMenu(event, this)">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <div class="menu-dropdown">
                                <?php if($row['location_lat'] && $row['location_lng']): ?>
                                <button class="menu-item-btn" onclick="event.stopPropagation(); startNavigation(<?= $row['incident_id'] ?>, <?= $row['location_lat'] ?>, <?= $row['location_lng'] ?>, '<?= htmlspecialchars($shortLocation) ?>')">
                                    <i class="fas fa-directions"></i> Navigate
                                </button>
                                <?php endif; ?>
                                <button class="menu-item-btn" onclick="event.stopPropagation(); showAccessManagement(<?= $row['incident_id'] ?>, '<?= htmlspecialchars($row['tracking_id']) ?>')">
                                    <i class="fas fa-users"></i> Access Log
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="incident-type"><?= htmlspecialchars($row['incident_type']) ?></div>
                        <div class="incident-location">
                            <i class="fas fa-user"></i>
                            <span><strong>Reported by:</strong> <?= $reporterDisplay ?></span>
                        </div>
                        <div class="incident-location time-ago">
                            <i class="fas fa-clock"></i>
                            <span class="time-ago-text" data-timestamp="<?= $timestamp ?>"><?= date('M d, Y h:i A', $timestamp) ?></span>
                        </div>
                        <div style="font-size: 11px; color: var(--shared-purple); margin-top: 5px;">
                            <i class="fas fa-<?= $row['access_level'] === 'edit' ? 'edit' : 'eye' ?>"></i>
                            Access: <?= $row['access_level'] === 'edit' ? 'Can Edit' : 'View Only' ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="severity-indicator">
                            <span class="severity-dot <?= $severityDot ?>"></span>
                            <span><?= strtoupper($row['severity'] ?? 'MINOR') ?></span>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
            
            <?php if ($active_count == 0 && $shared_count == 0): ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h3>No Reports</h3>
                    <p>Take a report from the Dashboard or create a new one!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Trash Tab -->
    <div id="trash-tab" class="tab-pane">
        <div class="section-header">
            <h2><i class="fas fa-trash-alt"></i> Trash</h2>
        </div>
        <div style="background: rgba(251,191,36,0.1); border-radius: 12px; padding: 12px; margin-bottom: 16px; font-size: 12px;">
            <i class="fas fa-info-circle" style="color: var(--primary-yellow);"></i>
            Items auto-delete after 5 months
        </div>
        <div id="trashList"></div>
    </div>
    
    <!-- Live Map Tab -->
    <div id="live-map-tab" class="tab-pane">
        <div class="section-header">
            <h2><i class="fas fa-map-marked-alt"></i> Live Map</h2>
        </div>
        
        <div class="map-container">
            <div id="liveMap"></div>
        </div>
        
        <div class="map-legend">
            <div class="legend-item"><span class="legend-dot" style="background: #fbbf24;"></span> Minor</div>
            <div class="legend-item"><span class="legend-dot" style="background: #f59e0b;"></span> Delayed</div>
            <div class="legend-item"><span class="legend-dot" style="background: #ef4444;"></span> Immediate</div>
            <div class="legend-item"><span class="legend-dot" style="background: #6b7280;"></span> Dead</div>
            <div class="legend-item"><span class="legend-dot" style="background: #3b82f6;"></span> Responder</div>
        </div>
        
        <div class="map-controls">
            <button class="map-btn" onclick="updateMyLocation()">
                <i class="fas fa-location-dot"></i> Update Location
            </button>
            <button class="map-btn" onclick="centerToMyLocation()">
                <i class="fas fa-crosshairs"></i> Center Map
            </button>
        </div>
    </div>
    
    <!-- Report Form Tab - COMPLETE VERSION -->
    <div id="report-form-tab" class="tab-pane">
        <div id="workingOnIndicator" style="display:none; background: var(--card-black); border: 1px solid var(--primary-yellow); border-radius: 20px; padding: 8px 16px; margin-bottom: 16px;">
            <i class="fas fa-pen" style="color: var(--primary-yellow);"></i>
            Working on: <span id="workingTrackingId"></span>
        </div>
        
        <div class="form-page">
            <!-- Logo Section -->
            <div class="form-logo-section">
                <div class="logo-box" onclick="document.getElementById('logoLeftInput').click()">
                    <img id="logoLeftImg" src="images/bonga_logo.png" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/%3E%3Ctext x=%2250%22 y=%2255%22 text-anchor=%22middle%22 font-size=%2210%22%3ELogo%3C/text%3E%3C/svg%3E'">
                    <span class="logo-upload-label">Change</span>
                </div>
                <div class="form-title-section">
                    <h4>Republic of the Philippines</h4>
                    <h3>Municipality of Bongabon</h3>
                    <h4>Municipal Disaster Risk Reduction and Management Office</h4>
                    <h2>INCIDENT REPORT</h2>
                </div>
                <div class="logo-box" onclick="document.getElementById('logoRightInput').click()">
                    <img id="logoRightImg" src="images/bonga_logo2.png" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect fill=%22%23f0f0f0%22 width=%22100%22 height=%22100%22/%3E%3Ctext x=%2250%22 y=%2255%22 text-anchor=%22middle%22 font-size=%2210%22%3EMDRRMO%3C/text%3E%3C/svg%3E'">
                    <span class="logo-upload-label">Change</span>
                </div>
            </div>
            <input type="file" id="logoLeftInput" accept="image/*" style="display:none;">
            <input type="file" id="logoRightInput" accept="image/*" style="display:none;">
            
            <div class="two-column-layout">
                <!-- LEFT COLUMN -->
                <div class="left-column">
                    <!-- INCIDENT DETAILS -->
                    <table class="form-table">
                        <tr><th colspan="2">INCIDENT DETAILS <span class="filipino-text">(Mga Detalye ng Insidente)</span></th></tr>
                        <tr>
                            <td class="label-cell">Date of Incident:<br><span class="filipino-text">(Petsa)</span></td>
                            <td><input type="date" id="incidentDate" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Time of Call:<br><span class="filipino-text">(Oras ng Pagtawag)</span></td>
                            <td><input type="time" id="callTime" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Time of Incident:<br><span class="filipino-text">(Oras ng Insidente)</span></td>
                            <td><input type="time" id="incidentTime" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">At Scene:<br><span class="filipino-text">(Oras sa Pinangyarihan)</span></td>
                            <td><input type="time" id="atScene" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Incident/Transfer/Purpose:</td>
                            <td><input type="text" id="incidentPurpose" class="form-control" placeholder="(Insidente/Paglipat/Layunin)"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Depart Scene:</td>
                            <td><input type="time" id="departScene" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">At Hospital:</td>
                            <td><input type="time" id="atHospital" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Place of Incident:<br><span class="filipino-text">(Lugar ng Insidente)</span></td>
                            <td><input type="text" id="placeIncident" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Handover:</td>
                            <td><input type="time" id="handover" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Back to Base:</td>
                            <td><input type="time" id="backToBase" class="form-control"></td>
                        </tr>
                    </table>
                    
                    <!-- PATIENT INFORMATION -->
                    <table class="form-table">
                        <tr><th colspan="2">PATIENT'S INFORMATION <span class="filipino-text">(Impormasyon ng Pasyente)</span></th></tr>
                        <tr>
                            <td class="label-cell">Name:<br><span class="filipino-text">(Pangalan)</span></td>
                            <td><input type="text" id="patientName" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Emergency Contact Person:</td>
                            <td><input type="text" id="emergencyContact" class="form-control" placeholder="(Contact Person)"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Age:<br><span class="filipino-text">(Edad)</span></td>
                            <td><input type="number" id="patientAge" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Gender:</td>
                            <td>
                                <select id="patientGender" class="form-control">
                                    <option>Male / Lalaki</option>
                                    <option>Female / Babae</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td class="label-cell">Address:<br><span class="filipino-text">(Tirahan)</span></td>
                            <td><input type="text" id="patientAddress" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Contact Number:</td>
                            <td><input type="text" id="emergencyNumber" class="form-control" placeholder="(Numero)"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Patient Signature:</td>
                            <td>
                                <div class="signature-area">
                                    <canvas id="patientSigCanvas" class="signature-canvas" width="280" height="80"></canvas>
                                    <div class="sig-buttons">
                                        <button type="button" class="sig-btn" onclick="clearSignature('patientSigCanvas')">Clear</button>
                                    </div>
                                    <input type="hidden" id="patientSignatureData">
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td class="label-cell">Emergency Contact Signature:</td>
                            <td>
                                <div class="signature-area">
                                    <canvas id="emergencySigCanvas" class="signature-canvas" width="280" height="80"></canvas>
                                    <div class="sig-buttons">
                                        <button type="button" class="sig-btn" onclick="clearSignature('emergencySigCanvas')">Clear</button>
                                    </div>
                                    <input type="hidden" id="emergencySignatureData">
                                </div>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- INJURY MAP -->
                    <table class="form-table">
                        <tr><th colspan="2">INJURY MAP - Highlight affected areas<br><span class="filipino-text">(I-highlight ang mga pinsala)</span></th></tr>
                        <tr>
                            <td colspan="2">
                                <div class="draw-tools">
                                    <button type="button" id="drawBtn" class="tool-btn active"><i class="fas fa-pencil-alt"></i> Draw</button>
                                    <button type="button" id="eraseBtn" class="tool-btn"><i class="fas fa-eraser"></i> Erase</button>
                                    <button type="button" id="undoBtn" class="tool-btn"><i class="fas fa-undo"></i> Undo</button>
                                    <button type="button" id="redoBtn" class="tool-btn"><i class="fas fa-redo"></i> Redo</button>
                                    <button type="button" id="clearCanvasBtn" class="tool-btn"><i class="fas fa-trash"></i> Clear All</button>
                                    <input type="color" id="penColor" value="#ff0000" class="color-picker" title="Color">
                                    <span style="font-size:11px; margin-left:5px;">Size:</span>
                                    <input type="range" id="brushSize" min="2" max="20" value="5" style="width:60px;">
                                </div>
                                <div class="canvas-wrapper">
                                    <canvas id="bodyCanvas" width="360" height="420"></canvas>
                                </div>
                                <input type="hidden" id="bodyImageData">
                            </td>
                        </tr>
                    </table>
                    
                    <!-- CHIEF COMPLAINT -->
                    <table class="form-table">
                        <tr><th colspan="2">INJURIES / ILLNESS / CHIEF COMPLAINT<br><span class="filipino-text">(Mga Pinsala/Sakit/Pangunahing Daing)</span></th></tr>
                        <tr><td colspan="2"><textarea id="chiefComplaint" class="form-control" rows="6"></textarea></td></tr>
                    </table>
                </div>
                
                <!-- RIGHT COLUMN -->
                <div class="right-column">
                    <!-- VITAL SIGNS & SAMPLE HISTORY -->
                    <table class="form-table">
                        <tr><th colspan="2">PATIENT'S SAMPLE HISTORY AND VITAL SIGNS</th></tr>
                        <tr>
                            <td class="label-cell">Signs & Symptoms:<br><span class="filipino-text">(Palatandaan at Sintomas)</span></td>
                            <td><textarea id="symptoms" class="form-control" rows="2"></textarea></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Blood Pressure:</td>
                            <td><input type="text" id="bp" class="form-control" placeholder="___ / ___"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Allergy:<br><span class="filipino-text">(Alerhiya)</span></td>
                            <td><input type="text" id="allergy" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Pulse Rate:</td>
                            <td><input type="text" id="pulse" class="form-control" placeholder="___ bpm"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Medications:<br><span class="filipino-text">(Medikasyon)</span></td>
                            <td><input type="text" id="medications" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Respiratory Rate:</td>
                            <td><input type="text" id="respiratory" class="form-control" placeholder="___ breaths/min"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Past Medical History:<br><span class="filipino-text">(Nakaraang Medikal na Kasaysayan)</span></td>
                            <td><input type="text" id="pastHistory" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Body Temperature:</td>
                            <td><input type="text" id="temperature" class="form-control" placeholder="___ °C"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Last Intake/Output:<br><span class="filipino-text">(Huling Kinain/Nilabas)</span></td>
                            <td><input type="text" id="lastIntake" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Events Leading to Injury:<br><span class="filipino-text">(Dahilan ng Pagkakapinsala)</span></td>
                            <td><textarea id="events" class="form-control" rows="3"></textarea></td>
                        </tr>
                    </table>
                    
                    <!-- MANAGEMENT / INTERVENTION -->
                    <table class="form-table">
                        <tr><th colspan="2">MANAGEMENT / INTERVENTION<br><span class="filipino-text">(Pangunang Lunas na Ginawa)</span></th></tr>
                        <tr><td colspan="2"><textarea id="actionsGiven" class="form-control" rows="8"></textarea></td></tr>
                    </table>
                    
                    <!-- REFUSAL OF TREATMENT -->
                    <table class="form-table">
                        <tr><th colspan="2">REFUSAL OF TREATMENT AND/OR TRANSPORT<br><span class="filipino-text">(Pagtanggi sa Pangunang Lunas/Pagdala sa Pagamutan)</span></th></tr>
                        <tr>
                            <td colspan="2" class="refusal-text">
                                Ako, na lumagda sa ibaba, ay maayos na napaliwanagan ukol sa aking kondisyon at mga serbisyong medikal na aking kailangan ngunit dahil sa aking personal na dahilan aking tinanggihan ang paglipat o paggamot sa akin. Dahil dito anuman ang maging resulta ng aking desisyon ay walang sinuman sa mga kawani ng Bongabon MDRRMO Rescue Team ang may pananagutan dahil sa aking pagtanggi.
                            </td>
                        </tr>
                        <tr>
                            <td class="label-cell">Witness:<br><span class="filipino-text">(Saksi)</span></td>
                            <td><input type="text" id="refusalWitness" class="form-control"></td>
                        </tr>
                        <tr>
                            <td class="label-cell">Date Signed:</td>
                            <td><input type="date" id="refusalDate" class="form-control"></td>
                        </tr>
                    </table>
                    
                    <!-- PROVIDER & RECEIVING FACILITY -->
                    <table class="form-table">
                        <tr>
                            <th>PROVIDER'S INFORMATION<br><span class="filipino-text">(Tagapagbigay ng Pangunang Lunas)</span></th>
                            <th>RECEIVING FACILITIES<br><span class="filipino-text">(Pagamutang Tumanggap)</span></th>
                        </tr>
                        <tr>
                            <td>
                                Crew 1: <input type="text" id="crew1" class="form-control form-control-sm" style="margin-bottom:5px;">
                                Crew 2: <input type="text" id="crew2" class="form-control form-control-sm" style="margin-bottom:5px;">
                                Crew 3: <input type="text" id="crew3" class="form-control form-control-sm" style="margin-bottom:5px;">
                                Crew 4: <input type="text" id="crew4" class="form-control form-control-sm" style="margin-bottom:5px;">
                                Crew 5: <input type="text" id="crew5" class="form-control form-control-sm" style="margin-bottom:5px;">
                                Driver: <input type="text" id="driver" class="form-control form-control-sm" style="margin-bottom:5px;">
                                Vehicle Used: <input type="text" id="vehicle" class="form-control form-control-sm">
                            </td>
                            <td>
                                Place / Hospital:<br>
                                <textarea id="receivingPlace" class="form-control" rows="3"></textarea><br>
                                Receiving Person:<br>
                                <input type="text" id="receivingPerson" class="form-control"><br>
                                Name & Signature:<br>
                                <div class="signature-area">
                                    <canvas id="providerSigCanvas" class="signature-canvas" width="280" height="70"></canvas>
                                    <div class="sig-buttons">
                                        <button type="button" class="sig-btn" onclick="clearSignature('providerSigCanvas')">Clear</button>
                                    </div>
                                    <input type="hidden" id="providerSignatureData"><br>
                                    <input type="text" id="receivingSignName" class="form-control" placeholder="(Pangalan at Lagda)">
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- PHOTO & VIDEO SECTION -->
            <div style="margin-top: 20px;">
                <div style="background: var(--primary-yellow); color: black; padding: 10px; border-radius: 8px; text-align: center; font-weight: bold; margin-bottom: 15px;">
                    <i class="fas fa-camera"></i> INCIDENT PHOTOGRAPHS & VIDEOS <span class="filipino-text">(Mga Larawan at Video ng Insidente)</span>
                </div>
                <div class="photo-grid" id="incidentImageGallery"></div>
                <button class="add-photo-btn" onclick="document.getElementById('incidentImagesInput').click()">
                    <i class="fas fa-plus"></i> Add Photos/Videos
                </button>
                <button class="add-photo-btn" onclick="clearAllMedia()" style="background: #dc2626; color: white; margin-top: 10px;">
                    <i class="fas fa-trash"></i> Clear All Media
                </button>
                <input type="file" id="incidentImagesInput" accept="image/*,video/*" multiple style="display:none;">
                <input type="hidden" id="incidentImagesData" value="[]">
                <div style="text-align: center; margin-top: 8px; font-size: 11px; color: var(--text-muted);">
                    <span id="imageCount">0 item(s)</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- FAB Button -->
<button class="fab-button" id="fabMain">
    <i class="fas fa-plus"></i>
</button>

<div class="fab-menu" id="fabMenu">
    <button class="fab-menu-item" id="fabSaveDraft">
        <i class="fas fa-save"></i> Save Progress
    </button>
    <button class="fab-menu-item" id="fabClearForm">
        <i class="fas fa-eraser"></i> Clear Form
    </button>
    <button class="fab-menu-item" id="fabCompleteReport">
        <i class="fas fa-check-circle"></i> Complete Report
    </button>
    <button class="fab-menu-item" id="fabPrint">
        <i class="fas fa-print"></i> Print Report
    </button>
</div>

<!-- Bottom Navigation -->
<div class="bottom-nav">
    <button class="nav-item active" data-tab="dashboard-tab">
        <i class="fas fa-home"></i>
        <span>Home</span>
    </button>
    <button class="nav-item" data-tab="my-reports-tab">
        <i class="fas fa-folder"></i>
        <span>Reports</span>
    </button>
    <button class="nav-item" data-tab="trash-tab">
        <i class="fas fa-trash"></i>
        <span>Trash</span>
    </button>
    <button class="nav-item" data-tab="live-map-tab">
        <i class="fas fa-map"></i>
        <span>Map</span>
    </button>
    <button class="nav-item" data-tab="report-form-tab">
        <i class="fas fa-file-alt"></i>
        <span>Form</span>
    </button>
</div>

<!-- Auto-save Indicator -->
<div id="autoSaveIndicator" class="auto-save-indicator">
    <i class="fas fa-check-circle"></i> Draft saved
</div>

<!-- Toast Container -->
<div class="notification-toast" id="notificationToast"></div>

<!-- Create Report Modal -->
<div class="modal fade" id="createReportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> New Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 15px;">
                    <label>Incident Type *</label>
                    <select id="newIncidentType" class="form-control">
                        <option value="Medical">Medical Emergency</option>
                        <option value="Trauma">Trauma / Injury</option>
                        <option value="Fire">Fire Incident</option>
                        <option value="Flood">Flood Rescue</option>
                        <option value="Vehicular Accident">Vehicular Accident</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Severity *</label>
                    <select id="newSeverity" class="form-control">
                        <option value="low">MINOR</option>
                        <option value="moderate">DELAYED</option>
                        <option value="high">IMMEDIATE</option>
                        <option value="dead">DEAD</option>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Location *</label>
                    <input type="text" id="newLocationAddress" class="form-control" placeholder="Enter location">
                    <button type="button" class="map-btn" style="margin-top: 8px; width: 100%;" onclick="getCurrentLocationForNew()">
                        <i class="fas fa-location-dot"></i> Use Current Location
                    </button>
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Reporter Name</label>
                    <input type="text" id="newReporterName" class="form-control" value="<?= htmlspecialchars($_SESSION['fullname'] ?? '') ?>">
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Reporter Phone</label>
                    <input type="text" id="newReporterPhone" class="form-control">
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Description</label>
                    <textarea id="newDescription" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createNewIncident()">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Share Report Modal -->
<div class="modal fade" id="shareModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-share-alt"></i> Share Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="shareIncidentId">
                <div style="margin-bottom: 15px;">
                    <label>Select Responder</label>
                    <select id="shareToUserId" class="form-control">
                        <option value="">Loading...</option>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Access Level</label>
                    <select id="shareAccessLevel" class="form-control">
                        <option value="view">View Only</option>
                        <option value="edit">Can Edit</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="shareReport()">Share</button>
            </div>
        </div>
    </div>
</div>

<!-- Access Management Modal -->
<div class="modal fade" id="accessManagementModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-users"></i> Access & Activity Log</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="display: flex; gap: 10px; border-bottom: 1px solid var(--border-black); margin-bottom: 20px;">
                    <button type="button" id="tabActivityBtn" class="tab-btn active">
                        <i class="fas fa-history"></i> Activity Log
                    </button>
                    <button type="button" id="tabUsersBtn" class="tab-btn">
                        <i class="fas fa-users"></i> Users with Access
                    </button>
                </div>
                <div id="activityTabPanel" style="display: block;">
                    <div id="accessLogsList" style="max-height: 400px; overflow-y: auto;">
                        <div class="text-center p-4">Loading activity logs...</div>
                    </div>
                </div>
                <div id="usersTabPanel" style="display: none;">
                    <div id="usersWithAccessList" style="max-height: 400px; overflow-y: auto;">
                        <div class="text-center p-4">Loading users with access...</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="takeActionBtn">Take Action</button>
            </div>
        </div>
    </div>
</div>

<!-- Navigation Modal -->
<div class="modal fade" id="navigationModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-directions"></i> Navigate to Incident</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="navigationMap" class="navigation-map-container"></div>
                
                <div class="nav-info-panel">
                    <div class="nav-info-row">
                        <span class="nav-info-label">Destination:</span>
                        <span class="nav-info-value" id="navDestination"></span>
                    </div>
                    <div class="nav-info-row">
                        <span class="nav-info-label">Distance:</span>
                        <span class="nav-info-value" id="navDistance">Calculating...</span>
                    </div>
                    <div class="nav-info-row">
                        <span class="nav-info-label">Estimated Time:</span>
                        <span class="nav-info-value" id="navDuration">Calculating...</span>
                    </div>
                </div>
                
                <div class="navigation-controls">
                    <button class="map-btn" onclick="centerNavigationMap()">
                        <i class="fas fa-crosshairs"></i> Re-center
                    </button>
                    <button class="map-btn navigate" onclick="openInGoogleMaps()">
                        <i class="fas fa-external-link-alt"></i> Open in Maps
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" onclick="arrivedAtScene()">
                    <i class="fas fa-flag-checkered"></i> Arrived at Scene
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Audio Elements for Alerts -->
<audio id="alertSoundMinor" preload="auto"></audio>
<audio id="alertSoundModerate" preload="auto"></audio>
<audio id="alertSoundHigh" preload="auto"></audio>
<audio id="alertSoundCritical" preload="auto"></audio>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================
// GLOBAL VARIABLES
// ============================================

let currentWorkingIncidentId = null;
let currentWorkingTrackingId = null;
let incidentImages = [];
let incidentVideos = [];
let liveMap = null;
let navigationMap = null;
let routingControl = null;
let navigationIncidentId = null;
let navigationDestination = { lat: null, lng: null, name: '' };
let liveMapMarkers = [];
let currentLocation = { lat: null, lng: null };
let newIncidentLat = null;
let newIncidentLng = null;
let currentAccessIncidentId = null;
let autoSaveTimer = null;
let lastFormData = '';
let sigPads = {};
let locationWatchId = null;
let autoRefreshInterval = null;
let isRefreshing = false;
let lastPendingCount = <?= $pending_count ?>;
let lastActiveCount = <?= $active_count ?>;
let lastCompletedCount = <?= $completed_count ?>;

// Alert settings
let soundEnabled = true;
let proximityRadius = 5000; // 5km default
let lastAlertIds = [];
let audioContext = null;

// Body Canvas Variables
let bodyCanvas, bodyCtx, drawingLayer, drawCtx;
let drawing = false, currentMode = 'draw', currentColor = '#ff0000', brushSize = 5;
let lastX, lastY;
let historyStack = [], redoStack = [];
let backgroundImage = null;

const STORAGE_TRASH = 'mdrrmo_responder_trash';
const TRASH_RETENTION_DAYS = 150;
const REFRESH_INTERVAL = 30000; // 30 seconds

// ============================================
// TIME AGO FUNCTION
// ============================================

function formatTimeAgo(timestamp) {
    const now = Math.floor(Date.now() / 1000);
    const diff = now - timestamp;
    
    if (diff < 0) return 'Just now';
    
    const seconds = diff;
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);
    const weeks = Math.floor(days / 7);
    const months = Math.floor(days / 30);
    const years = Math.floor(days / 365);
    
    if (seconds < 5) return 'Just now';
    if (seconds < 60) return `${seconds} second${seconds !== 1 ? 's' : ''} ago`;
    if (minutes < 60) return `${minutes} minute${minutes !== 1 ? 's' : ''} ago`;
    if (hours < 24) return `${hours} hour${hours !== 1 ? 's' : ''} ago`;
    if (days < 7) return `${days} day${days !== 1 ? 's' : ''} ago`;
    if (weeks < 4) return `${weeks} week${weeks !== 1 ? 's' : ''} ago`;
    if (months < 12) return `${months} month${months !== 1 ? 's' : ''} ago`;
    return `${years} year${years !== 1 ? 's' : ''} ago`;
}

function updateAllTimeAgo() {
    $('.time-ago-text').each(function() {
        const $el = $(this);
        const timestamp = parseInt($el.data('timestamp'));
        if (timestamp && !isNaN(timestamp)) {
            $el.text(formatTimeAgo(timestamp));
        }
    });
}

// ============================================
// UTILITY FUNCTIONS
// ============================================

function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;'})[m]);
}

function showToast(title, message, type = 'info') {
    const toast = $(`
        <div class="toast-notification">
            <i class="fas fa-${type === 'danger' ? 'times-circle' : (type === 'success' ? 'check-circle' : 'info-circle')}"></i>
            <div class="toast-content">
                <div class="toast-title">${escapeHtml(title)}</div>
                <div class="toast-message">${escapeHtml(message)}</div>
            </div>
            <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
    `);
    $('#notificationToast').append(toast);
    setTimeout(() => toast.remove(), 5000);
}

function toggleCardMenu(event, btn) {
    event.stopPropagation();
    $('.menu-dropdown.show').not(btn.nextElementSibling).removeClass('show');
    $(btn.nextElementSibling).toggleClass('show');
}

$(document).click(() => $('.menu-dropdown.show').removeClass('show'));

// ============================================
// DISTANCE CALCULATION
// ============================================

function calculateDistance(lat1, lon1, lat2, lon2) {
    if (!lat1 || !lon1 || !lat2 || !lon2) return null;
    
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = 
        Math.sin(dLat/2) * Math.sin(dLat/2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
        Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    const distanceKm = R * c;
    
    return {
        km: distanceKm,
        meters: distanceKm * 1000,
        display: distanceKm < 1 
            ? Math.round(distanceKm * 1000) + 'm'
            : distanceKm.toFixed(1) + 'km'
    };
}

function updatePendingCardsWithDistance() {
    if (!currentLocation.lat || !currentLocation.lng) return;
    
    $('#pendingReportsList .incident-card').each(function() {
        const $card = $(this);
        const lat = parseFloat($card.attr('data-lat'));
        const lng = parseFloat($card.attr('data-lng'));
        
        if (lat && lng) {
            const distance = calculateDistance(
                currentLocation.lat, currentLocation.lng,
                lat, lng
            );
            
            if (distance) {
                let $footer = $card.find('.card-footer');
                let $distanceBadge = $footer.find('.distance-badge');
                
                if ($distanceBadge.length === 0) {
                    $distanceBadge = $(`
                        <span class="distance-badge">
                            <i class="fas fa-location-arrow"></i>
                            <span class="distance-value">${distance.display}</span>
                        </span>
                    `);
                    
                    const $severity = $footer.find('.severity-indicator');
                    if ($severity.length) {
                        $severity.before($distanceBadge);
                    } else {
                        $footer.append($distanceBadge);
                    }
                } else {
                    $distanceBadge.find('.distance-value').text(distance.display);
                }
                
                if (distance.km < 1) {
                    $distanceBadge.css({
                        'background': 'rgba(239, 68, 68, 0.15)',
                        'color': '#ef4444'
                    });
                } else if (distance.km < 3) {
                    $distanceBadge.css({
                        'background': 'rgba(245, 158, 11, 0.15)',
                        'color': '#f59e0b'
                    });
                } else {
                    $distanceBadge.css({
                        'background': 'rgba(59, 130, 246, 0.15)',
                        'color': '#3b82f6'
                    });
                }
            }
        }
    });
}

// ============================================
// THEME TOGGLE
// ============================================

function toggleTheme() {
    const html = document.documentElement;
    const currentTheme = html.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    html.setAttribute('data-theme', newTheme);
    document.cookie = `responder_theme=${newTheme}; path=/; max-age=31536000`;
    
    const icon = document.querySelector('#themeToggle i');
    icon.className = newTheme === 'light' ? 'fas fa-moon' : 'fas fa-sun';
}

// ============================================
// GET SEVERITY MAP COLOR
// ============================================

function getSeverityMapColor(severity) {
    const s = String(severity || '').toLowerCase();
    if (s === 'dead') return '#6b7280';
    if (s === 'high' || s === 'immediate' || s === 'critical') return '#ef4444';
    if (s === 'moderate' || s === 'delayed') return '#f59e0b';
    return '#fbbf24';
}

function getSeverityDot(severity) {
    const s = String(severity || '').toLowerCase();
    if (s === 'dead') return 'dead';
    if (s === 'high' || s === 'critical' || s === 'immediate') return 'immediate';
    if (s === 'moderate' || s === 'delayed') return 'delayed';
    return 'minor';
}

// ============================================
// AUTO-REFRESH FUNCTIONS
// ============================================

function startAutoRefresh() {
    if (autoRefreshInterval) clearInterval(autoRefreshInterval);
    
    autoRefreshInterval = setInterval(() => {
        if ($('#dashboard-tab').hasClass('active') && !isRefreshing) {
            refreshDashboardData();
        }
    }, REFRESH_INTERVAL);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

function refreshDashboardData() {
    if (isRefreshing) return;
    isRefreshing = true;
    
    $.ajax({
        url: 'api/get_dashboard_stats.php',
        method: 'GET',
        dataType: 'json',
        timeout: 10000,
        success: function(data) {
            if (data.success) {
                updateStatWithAnimation('#pendingCount', data.pending_count);
                updateStatWithAnimation('#activeCount', data.active_count);
                updateStatWithAnimation('#completedCount', data.completed_count);
                
                if (data.pending_count > lastPendingCount) {
                    const newCount = data.pending_count - lastPendingCount;
                    showToast('New Incident!', `${newCount} new incident(s) received`, 'warning');
                    refreshPendingList();
                }
                
                lastPendingCount = data.pending_count;
                lastActiveCount = data.active_count;
                lastCompletedCount = data.completed_count;
                
                const now = new Date();
                $('#lastUpdated span').text(now.toLocaleTimeString());
            }
        },
        complete: function() {
            isRefreshing = false;
        }
    });
}

function updateStatWithAnimation(selector, newValue) {
    const $el = $(selector);
    const oldValue = parseInt($el.text()) || 0;
    
    if (oldValue !== newValue) {
        $el.text(newValue).addClass('number-changed');
        setTimeout(() => $el.removeClass('number-changed'), 500);
    }
}

function refreshPendingList() {
    $('#pendingReportsList').addClass('loading');
    
    $.ajax({
        url: 'api/get_pending_reports.php',
        method: 'GET',
        success: function(html) {
            $('#pendingReportsList').html(html).removeClass('loading');
            setTimeout(() => updatePendingCardsWithDistance(), 500);
            setTimeout(() => updateAllTimeAgo(), 100);
        },
        error: function() {
            $('#pendingReportsList').removeClass('loading');
        }
    });
}

// ============================================
// SOUND & PROXIMITY ALERTS
// ============================================

function initAudio() {
    try {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
    } catch(e) {
        console.log('Web Audio API not supported');
    }
}

function playAlertSound(severity) {
    if (!soundEnabled) return;
    
    if (audioContext) {
        try {
            if (audioContext.state === 'suspended') {
                audioContext.resume();
            }
            
            const now = audioContext.currentTime;
            const osc = audioContext.createOscillator();
            const gain = audioContext.createGain();
            
            osc.connect(gain);
            gain.connect(audioContext.destination);
            
            let frequency = 800;
            let duration = 0.3;
            let repeat = 2;
            let volume = 0.3;
            
            switch(severity.toLowerCase()) {
                case 'critical':
                case 'dead':
                    frequency = 1200;
                    duration = 0.5;
                    repeat = 4;
                    volume = 0.5;
                    break;
                case 'high':
                case 'immediate':
                    frequency = 1000;
                    duration = 0.4;
                    repeat = 3;
                    volume = 0.4;
                    break;
                case 'moderate':
                case 'delayed':
                    frequency = 800;
                    duration = 0.3;
                    repeat = 2;
                    volume = 0.3;
                    break;
                default:
                    frequency = 600;
                    duration = 0.2;
                    repeat = 2;
                    volume = 0.2;
            }
            
            osc.type = 'sine';
            gain.gain.setValueAtTime(0, now);
            
            for (let i = 0; i < repeat; i++) {
                const startTime = now + (i * duration * 1.5);
                gain.gain.setValueAtTime(volume, startTime);
                gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
                osc.frequency.setValueAtTime(frequency, startTime);
                if (i % 2 === 0) {
                    osc.frequency.setValueAtTime(frequency * 1.2, startTime + duration * 0.3);
                }
            }
            
            osc.start();
            osc.stop(now + repeat * duration * 1.5);
            
        } catch(e) {
            console.log('Web Audio failed');
        }
    }
}

function toggleSoundSettings() {
    Swal.fire({
        title: 'Alert Settings',
        html: `
            <div style="text-align: left;">
                <div style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="soundEnabledCheck" ${soundEnabled ? 'checked' : ''} style="width: 20px; height: 20px;">
                        <span><i class="fas fa-volume-up"></i> Enable Alert Sounds</span>
                    </label>
                </div>
                
                <div class="proximity-settings">
                    <label><i class="fas fa-map-marker-alt"></i> Proximity Alert Radius</label>
                    <input type="range" id="proximityRadiusSlider" min="1000" max="20000" step="500" value="${proximityRadius}">
                    <div style="display: flex; justify-content: space-between; margin-top: 5px;">
                        <span>1 km</span>
                        <span id="radiusValue">${(proximityRadius / 1000).toFixed(1)} km</span>
                        <span>20 km</span>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 10px; background: var(--card-black); border-radius: 8px;">
                    <p style="margin: 0; font-size: 13px; color: var(--text-muted);">
                        <i class="fas fa-info-circle"></i> 
                        You'll only receive alerts for incidents within your set radius.
                    </p>
                </div>
                
                <div style="margin-top: 15px;">
                    <button type="button" class="action-btn" onclick="testAlertSound()" style="width: 100%;">
                        <i class="fas fa-volume-up"></i> Test Alert Sound
                    </button>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Save Settings',
        didOpen: () => {
            $('#proximityRadiusSlider').on('input', function() {
                $('#radiusValue').text((this.value / 1000).toFixed(1) + ' km');
            });
        },
        preConfirm: () => {
            soundEnabled = $('#soundEnabledCheck').is(':checked');
            proximityRadius = parseInt($('#proximityRadiusSlider').val());
            
            localStorage.setItem('alertSettings', JSON.stringify({
                soundEnabled,
                proximityRadius
            }));
            
            updateSoundIcon();
        }
    });
}

function testAlertSound() {
    playAlertSound('moderate');
    showToast('Test Alert', 'Playing test alert sound...', 'info');
}

function updateSoundIcon() {
    const icon = $('#soundIcon');
    const status = $('#soundStatus');
    
    if (soundEnabled) {
        icon.removeClass('fa-volume-mute').addClass('fa-volume-up');
        status.text('Alerts On');
        $('.sound-settings').removeClass('muted');
    } else {
        icon.removeClass('fa-volume-up').addClass('fa-volume-mute');
        status.text('Alerts Muted');
        $('.sound-settings').addClass('muted');
    }
}

function showProximityAlert(incident) {
    if (lastAlertIds.includes(incident.incident_id)) return;
    if (!currentLocation.lat || !currentLocation.lng) return;
    if (!incident.location_lat || !incident.location_lng) return;
    
    const distance = calculateDistance(
        currentLocation.lat, currentLocation.lng,
        parseFloat(incident.location_lat), parseFloat(incident.location_lng)
    );
    
    if (!distance || distance.meters > proximityRadius) return;
    
    lastAlertIds.push(incident.incident_id);
    if (lastAlertIds.length > 50) lastAlertIds.shift();
    
    playAlertSound(incident.severity);
    
    let severityClass = '';
    if (incident.severity === 'dead' || incident.severity === 'critical') {
        severityClass = 'critical';
    } else if (incident.severity === 'high' || incident.severity === 'immediate') {
        severityClass = 'high-severity';
    }
    
    const locationParts = (incident.location_address || 'Unknown').split(',');
    const shortLocation = locationParts[0].trim();
    const reporterName = incident.reporter_name || 'Unknown';
    
    const alertHtml = `
        <div class="proximity-alert ${severityClass}" onclick="viewIncident(${incident.incident_id})" style="pointer-events: auto;">
            <i class="fas fa-bell"></i>
            <div class="alert-content">
                <div class="alert-title">
                    ${incident.severity === 'critical' || incident.severity === 'dead' ? '🚨 CRITICAL INCIDENT' : '⚠️ New Incident Nearby'}
                </div>
                <div class="alert-details">
                    <span>${escapeHtml(incident.incident_type)}</span>
                    <span>Reported by: ${escapeHtml(reporterName)}</span>
                    <span>${escapeHtml(shortLocation)}</span>
                    <span class="alert-distance">
                        <i class="fas fa-location-dot"></i> ${distance.display}
                    </span>
                </div>
            </div>
            <button class="close-alert" onclick="event.stopPropagation(); this.parentElement.remove();">
                &times;
            </button>
        </div>
    `;
    
    $('#proximityAlertContainer').append(alertHtml);
    
    setTimeout(() => {
        $(`.proximity-alert:contains("${incident.incident_type}")`).fadeOut(300, function() {
            $(this).remove();
        });
    }, 15000);
}

function checkProximityIncidents() {
    if (!currentLocation.lat || !currentLocation.lng) return;
    
    $.ajax({
        url: 'api/get_nearby_incidents.php',
        method: 'GET',
        data: {
            lat: currentLocation.lat,
            lng: currentLocation.lng,
            radius: proximityRadius / 1000
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.incidents) {
                response.incidents.forEach(incident => {
                    showProximityAlert(incident);
                });
            }
        }
    });
}

function loadAlertSettings() {
    const saved = localStorage.getItem('alertSettings');
    if (saved) {
        try {
            const settings = JSON.parse(saved);
            soundEnabled = settings.soundEnabled ?? true;
            proximityRadius = settings.proximityRadius ?? 5000;
            updateSoundIcon();
        } catch(e) {}
    }
}

// ============================================
// LOCATION TRACKING
// ============================================

function startLocationTracking() {
    if (!navigator.geolocation) return;
    
    navigator.geolocation.getCurrentPosition(pos => {
        currentLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        updatePendingCardsWithDistance();
    });
    
    if (locationWatchId) {
        navigator.geolocation.clearWatch(locationWatchId);
    }
    
    locationWatchId = navigator.geolocation.watchPosition(
        pos => {
            const newLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            const distanceMoved = currentLocation.lat 
                ? calculateDistance(currentLocation.lat, currentLocation.lng, newLocation.lat, newLocation.lng)?.meters || 0
                : 0;
            
            currentLocation = newLocation;
            
            if (distanceMoved > 100) {
                updatePendingCardsWithDistance();
                
                $.post('responder_dashboard.php', {
                    action: 'update_location',
                    lat: currentLocation.lat,
                    lng: currentLocation.lng
                });
            }
        },
        error => console.log('Location watch error:', error),
        { enableHighAccuracy: true, maximumAge: 30000, timeout: 10000 }
    );
}

// ============================================
// VIEW INCIDENT
// ============================================

function viewIncident(id) {
    if (!id || id === 0) {
        showToast('Error', 'Invalid incident ID', 'danger');
        return;
    }
    
    $('#incidentModalBody').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    $('#incidentModal').modal('show');
    
    $.ajax({
        url: 'api/get_incident_full.php',
        method: 'POST',
        data: { incident_id: id },
        dataType: 'json',
        timeout: 10000,
        success: function(incident) {
            if (incident.error) {
                $('#incidentModalBody').html('<p class="text-danger">Error: ' + escapeHtml(incident.error) + '</p>');
                $('#takeActionBtn').hide();
                return;
            }
            
            const severityColor = getSeverityMapColor(incident.severity);
            const hasLocation = incident.location_lat && incident.location_lng;
            
            let shortLocation = 'Location not specified';
            if (incident.location_address) {
                const locationParts = incident.location_address.split(',');
                shortLocation = locationParts[0].trim();
            }
            
            const createdDate = incident.created_at ? new Date(incident.created_at).toLocaleString() : 'N/A';
            const reporterDisplay = incident.reporter_name || incident.reporter_phone || 'Unknown';
            
            let html = `
                <div style="background: ${severityColor}15; border-left: 4px solid ${severityColor}; padding: 16px; border-radius: 12px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span class="location-badge">
                            <i class="fas fa-map-pin"></i> ${escapeHtml(shortLocation)}
                        </span>
                        <span class="badge badge-warning">${(incident.status || 'pending').toUpperCase()}</span>
                    </div>
                    <div style="font-size:18px;font-weight:600;">${escapeHtml(incident.incident_type || 'Unknown')}</div>
                    <div style="margin-top: 8px;"><i class="fas fa-user"></i> <strong>Reported by:</strong> ${escapeHtml(reporterDisplay)}</div>
                    <div style="margin-top: 4px;"><i class="fas fa-calendar-alt"></i> ${createdDate}</div>
                    <div style="margin-top: 4px;"><i class="fas fa-hashtag"></i> Tracking: ${escapeHtml(incident.tracking_id || 'N/A')}</div>
                </div>
                
                <table style="width:100%;">
                    <tr><td style="color:var(--text-muted); padding:8px 0;">Severity</td><td style="padding:8px 0;"><span class="severity-indicator"><span class="severity-dot ${getSeverityDot(incident.severity)}"></span> ${(incident.severity || 'MINOR').toUpperCase()}</span></td></tr>
                    <tr><td style="color:var(--text-muted); padding:8px 0;">Contact</td><td style="padding:8px 0;">${escapeHtml(incident.reporter_phone || 'N/A')}</td></tr>`;
            
            if (incident.description) {
                html += `<tr><td style="color:var(--text-muted); padding:8px 0;">Description</td><td style="padding:8px 0;">${escapeHtml(incident.description)}</td></tr>`;
            }
            
            if (currentLocation.lat && currentLocation.lng && incident.location_lat && incident.location_lng) {
                const distance = calculateDistance(
                    currentLocation.lat, currentLocation.lng,
                    parseFloat(incident.location_lat), parseFloat(incident.location_lng)
                );
                
                if (distance) {
                    html += `<tr><td style="color:var(--text-muted); padding:8px 0;">Distance</td><td style="padding:8px 0;"><span class="distance-badge" style="display: inline-flex;"><i class="fas fa-location-arrow"></i> ${distance.display}</span></td></tr>`;
                }
            }
            
            html += `</table>`;
            
            // Photos
            if (incident.photos && incident.photos.length > 0) {
                html += `<div style="margin-top:16px;"><strong><i class="fas fa-image"></i> Photos</strong></div><div class="media-grid">`;
                incident.photos.forEach(photo => {
                    let photoPath = typeof photo === 'object' ? (photo.photo_path || photo.url) : photo;
                    const filename = photoPath.split('/').pop().split('\\').pop();
                    const photoUrl = window.location.pathname.split('/').slice(0, -1).join('/') + '/uploads/incidents/' + filename;
                    html += `<div class="media-item" onclick="viewFullMedia('${photoUrl}', 'image')"><img src="${photoUrl}" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22100%22 height=%22100%22%3E%3Crect fill=%22%23ddd%22 width=%22100%22 height=%22100%22/%3E%3C/svg%3E'"></div>`;
                });
                html += `</div>`;
            }
            
            // Videos
            if (incident.videos && incident.videos.length > 0) {
                html += `<div style="margin-top:16px;"><strong><i class="fas fa-video"></i> Videos</strong></div><div class="media-grid">`;
                incident.videos.forEach(video => {
                    let videoPath = typeof video === 'object' ? (video.video_path || video.url) : video;
                    const filename = videoPath.split('/').pop().split('\\').pop();
                    const videoUrl = window.location.pathname.split('/').slice(0, -1).join('/') + '/uploads/incidents/' + filename;
                    html += `<div class="media-item" onclick="viewFullMedia('${videoUrl}', 'video')"><video src="${videoUrl}"></video><div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:white;"><i class="fas fa-play-circle" style="font-size:40px;"></i></div></div>`;
                });
                html += `</div>`;
            }
            
            html += `<div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">`;
            if (hasLocation) {
                html += `<button class="action-btn primary" style="flex:1;" onclick="startNavigation(${incident.incident_id}, ${incident.location_lat}, ${incident.location_lng}, '${escapeHtml(shortLocation)}'); $('#incidentModal').modal('hide');"><i class="fas fa-directions"></i> Navigate</button>`;
            }
            html += `</div>`;
            
            $('#incidentModalBody').html(html);
            
            const actionBtn = $('#takeActionBtn');
            if (incident.status === 'pending') {
                actionBtn.html('<i class="fas fa-hand-paper"></i> Take Report').show();
                actionBtn.off('click').on('click', () => takeIncident(id, incident));
            } else {
                actionBtn.hide();
            }
            
            logReportAccess(id, 'viewed', null, null, 'Viewed incident details');
        },
        error: function() {
            $('#incidentModalBody').html('<div class="text-danger p-4"><p><strong>Error loading incident.</strong></p></div>');
            $('#takeActionBtn').hide();
        }
    });
}

function viewSharedIncident(id, trackingId, accessLevel) {
    logReportAccess(id, 'viewed', null, null, 'Viewed shared report');
    
    if (accessLevel === 'view') {
        Swal.fire({
            title: 'View Only',
            text: 'You have view-only access to this report.',
            icon: 'info',
            confirmButtonText: 'View Details'
        }).then(() => viewIncident(id));
    } else {
        viewActiveIncident(id, trackingId);
    }
}

function viewFullMedia(url, type) {
    if (type === 'image') {
        Swal.fire({ imageUrl: url, showConfirmButton: true, width: '90%' });
    } else {
        Swal.fire({ html: `<video src="${url}" controls style="width:100%;"></video>`, showConfirmButton: true, width: '90%' });
    }
}

function takeIncident(id, incident) {
    Swal.fire({
        title: 'Take Report?',
        text: `You'll be responsible for this incident.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#fbbf24',
        confirmButtonText: 'Yes'
    }).then(r => {
        if (r.isConfirmed) {
            logReportAccess(id, 'taken', null, null, 'Took responsibility for report');
            
            $.post('api/take_incident.php', { incident_id: id }, response => {
                if (response.success) {
                    $('#incidentModal').modal('hide');
                    
                    Swal.fire({
                        title: 'What would you like to do?',
                        html: `
                            <div style="display: flex; gap: 20px; justify-content: center;">
                                <div style="text-align: center; cursor: pointer;" onclick="window.choiceMade='form'; Swal.clickConfirm()">
                                    <i class="fas fa-file-alt" style="font-size: 48px; color: #fbbf24;"></i>
                                    <p>Fill Report Form</p>
                                </div>
                                <div style="text-align: center; cursor: pointer;" onclick="window.choiceMade='navigate'; Swal.clickConfirm()">
                                    <i class="fas fa-directions" style="font-size: 48px; color: #3b82f6;"></i>
                                    <p>Navigate First</p>
                                </div>
                            </div>
                        `,
                        showConfirmButton: true,
                        preConfirm: () => window.choiceMade || 'form'
                    }).then((result) => {
                        const choice = result.value || 'form';
                        
                        if (choice === 'form') {
                            currentWorkingIncidentId = id;
                            currentWorkingTrackingId = incident.tracking_id;
                            $('#workingOnIndicator').show();
                            $('#workingTrackingId').text(incident.tracking_id);
                            clearForm();
                            
                            if (incident.location_address) $('#placeIncident').val(incident.location_address);
                            if (incident.incident_type) $('#incidentPurpose').val(incident.incident_type);
                            if (incident.reporter_name) $('#patientName').val(incident.reporter_name);
                            if (incident.reporter_phone) $('#emergencyNumber').val(incident.reporter_phone);
                            if (incident.description) $('#chiefComplaint').val(incident.description);
                            
                            switchTab('report-form-tab');
                            showToast('Report Taken', 'Start filling out the report form', 'success');
                        } else {
                            if (incident.location_lat && incident.location_lng) {
                                startNavigation(id, incident.location_lat, incident.location_lng, 
                                    (incident.location_address || 'Location').split(',')[0].trim());
                                currentWorkingIncidentId = id;
                                currentWorkingTrackingId = incident.tracking_id;
                                showToast('Navigation Started', 'You can fill the report after arriving', 'info');
                            }
                        }
                    });
                    
                    setTimeout(() => refreshDashboardData(), 1000);
                } else {
                    Swal.fire('Error', response.message || 'Failed', 'error');
                }
            }, 'json');
        }
    });
}

function loadIncidentMediaToForm(incident) {
    if (!incident) return;
    
    const basePath = window.location.pathname.split('/').slice(0, -1).join('/');
    
    const addMedia = (url, type) => {
        return fetch(url)
            .then(r => r.blob())
            .then(blob => new Promise(resolve => {
                const reader = new FileReader();
                reader.onloadend = () => {
                    if (type === 'image') incidentImages.push(reader.result);
                    else incidentVideos.push(reader.result);
                    updateImageGallery();
                    resolve();
                };
                reader.readAsDataURL(blob);
            }))
            .catch(() => {
                if (type === 'image') incidentImages.push(url);
                else incidentVideos.push(url);
                updateImageGallery();
            });
    };
    
    const promises = [];
    
    if (incident.photos) {
        incident.photos.forEach(p => {
            const path = typeof p === 'object' ? (p.photo_path || p.url) : p;
            if (path) promises.push(addMedia(basePath + '/uploads/incidents/' + path.split('/').pop(), 'image'));
        });
    }
    
    if (incident.videos) {
        incident.videos.forEach(v => {
            const path = typeof v === 'object' ? (v.video_path || v.url) : v;
            if (path) promises.push(addMedia(basePath + '/uploads/incidents/' + path.split('/').pop(), 'video'));
        });
    }
    
    Promise.all(promises).then(() => {
        updateImageGallery();
        $('#imageCount').text(`${incidentImages.length} photo(s), ${incidentVideos.length} video(s)`);
    });
}

// ============================================
// LOGOUT
// ============================================

function confirmLogout() {
    Swal.fire({
        title: 'Logout?',
        text: 'Are you sure?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#fbbf24',
        confirmButtonText: 'Logout'
    }).then(r => { if (r.isConfirmed) window.location.href = 'responder_logout.php'; });
}

// ============================================
// NAVIGATION FUNCTIONS
// ============================================

function startNavigation(incidentId, destLat, destLng, destName) {
    navigationIncidentId = incidentId;
    navigationDestination = { lat: destLat, lng: destLng, name: destName };
    
    $('#navDestination').text(destName);
    $('#navigationModal').modal('show');
    
    $.post('responder_dashboard.php', {
        action: 'start_navigation',
        incident_id: incidentId
    });
    
    setTimeout(() => initNavigationMap(), 300);
}

function initNavigationMap() {
    if (navigationMap) navigationMap.remove();
    
    navigationMap = L.map('navigationMap').setView([navigationDestination.lat, navigationDestination.lng], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(navigationMap);
    
    L.marker([navigationDestination.lat, navigationDestination.lng])
        .bindPopup(`<b>Incident Location</b><br>${navigationDestination.name}`)
        .addTo(navigationMap)
        .openPopup();
    
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            const startLat = pos.coords.latitude;
            const startLng = pos.coords.longitude;
            
            L.marker([startLat, startLng], {
                icon: L.divIcon({ className: 'current-location-marker', html: '<div style="background:#3b82f6;width:16px;height:16px;border-radius:50%;border:3px solid white;"></div>', iconSize: [16,16] })
            }).bindPopup('Your Location').addTo(navigationMap);
            
            if (routingControl) navigationMap.removeControl(routingControl);
            
            routingControl = L.Routing.control({
                waypoints: [L.latLng(startLat, startLng), L.latLng(navigationDestination.lat, navigationDestination.lng)],
                routeWhileDragging: false,
                lineOptions: { styles: [{color: '#fbbf24', weight: 6}] }
            }).addTo(navigationMap);
            
            routingControl.on('routesfound', e => {
                const summary = e.routes[0].summary;
                $('#navDistance').text((summary.totalDistance / 1000).toFixed(1) + ' km');
                $('#navDuration').text(Math.round(summary.totalTime / 60) + ' mins');
            });
            
            navigationMap.fitBounds([[startLat, startLng], [navigationDestination.lat, navigationDestination.lng]], { padding: [50, 50] });
        });
    }
}

function centerNavigationMap() {
    if (navigationMap && currentLocation.lat) {
        navigationMap.setView([currentLocation.lat, currentLocation.lng], 15);
    }
}

function openInGoogleMaps() {
    window.open(`https://www.google.com/maps/dir/?api=1&destination=${navigationDestination.lat},${navigationDestination.lng}`, '_blank');
}

function arrivedAtScene() {
    Swal.fire({
        title: 'Arrived at Scene?',
        text: 'Confirm that you have arrived.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        confirmButtonText: 'Yes'
    }).then(r => {
        if (r.isConfirmed) {
            $('#navigationModal').modal('hide');
            Swal.fire('Arrived!', 'You have arrived at the scene', 'success');
            if (navigationIncidentId) viewActiveIncident(navigationIncidentId, '');
        }
    });
}

// ============================================
// CREATE NEW INCIDENT
// ============================================

function showCreateReportModal() {
    $('#newIncidentType').val('Medical');
    $('#newSeverity').val('low');
    $('#newLocationAddress').val('');
    $('#newReporterName').val('<?= htmlspecialchars($_SESSION['fullname'] ?? '') ?>');
    $('#newReporterPhone').val('');
    $('#newDescription').val('');
    newIncidentLat = newIncidentLng = null;
    $('#createReportModal').modal('show');
}

function getCurrentLocationForNew() {
    if (!navigator.geolocation) { Swal.fire('Error', 'Geolocation not supported', 'error'); return; }
    
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
    
    if (!data.location_address) { Swal.fire('Error', 'Please enter location', 'error'); return; }
    
    Swal.fire({ title: 'Creating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    
    $.post('responder_dashboard.php', data, result => {
        if (result.success) {
            $('#createReportModal').modal('hide');
            Swal.fire('Success!', `Report created: ${result.tracking_id}`, 'success');
            viewActiveIncident(result.incident_id, result.tracking_id);
            setTimeout(() => refreshDashboardData(), 1000);
        } else {
            Swal.fire('Error', result.message || 'Failed', 'error');
        }
    }, 'json');
}

// ============================================
// LOG REPORT ACCESS
// ============================================

function logReportAccess(incidentId, actionType, fieldChanged, oldValue, newValue) {
    $.ajax({
        url: 'api/log_report_access.php',
        method: 'POST',
        data: {
            incident_id: incidentId,
            action_type: actionType,
            action_details: newValue || null,
            field_changed: fieldChanged || null,
            new_value: newValue || null
        }
    });
}

// ============================================
// SHARE REPORT
// ============================================

function showShareModal(incidentId, trackingId) {
    $('#shareIncidentId').val(incidentId);
    
    $.get('api/get_responders_list.php', result => {
        let options = '<option value="">Select Responder</option>';
        if (result.success && result.responders) {
            result.responders.forEach(r => {
                if (r.user_id != <?= $_SESSION['user_id'] ?>) {
                    options += `<option value="${r.user_id}">${escapeHtml(r.fullname)} (${escapeHtml(r.username)})</option>`;
                }
            });
        }
        $('#shareToUserId').html(options);
    });
    
    $('#shareModal').modal('show');
}

function shareReport() {
    const userId = $('#shareToUserId').val();
    const accessLevel = $('#shareAccessLevel').val();
    const incidentId = $('#shareIncidentId').val();
    const userName = $('#shareToUserId option:selected').text();
    
    if (!userId) { Swal.fire('Error', 'Please select a responder', 'error'); return; }
    
    $.post('api/grant_report_access.php', {
        incident_id: incidentId,
        user_id: userId,
        access_level: accessLevel,
        granted_to_name: userName
    }, response => {
        if (response.success) {
            $('#shareModal').modal('hide');
            Swal.fire('Success', 'Report shared successfully!', 'success');
            logReportAccess(incidentId, 'granted_access', null, null, `Granted ${accessLevel} access to ${userName}`);
        } else {
            Swal.fire('Error', response.message || 'Failed to share', 'error');
        }
    }, 'json');
}

// ============================================
// ACCESS MANAGEMENT
// ============================================

function showAccessManagement(incidentId, trackingId) {
    currentAccessIncidentId = incidentId;
    
    $('#tabActivityBtn').addClass('active');
    $('#tabUsersBtn').removeClass('active');
    $('#activityTabPanel').show();
    $('#usersTabPanel').hide();
    
    $('#accessManagementModal').modal('show');
    
    setTimeout(() => {
        loadAccessLogs(incidentId);
        loadUsersWithAccessList(incidentId);
    }, 100);
}

function loadAccessLogs(id) {
    $('#accessLogsList').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    
    $.ajax({
        url: 'api/get_report_access_logs.php',
        method: 'GET',
        data: { incident_id: id },
        dataType: 'json',
        success: function(result) {
            if (result.success && result.logs && result.logs.length > 0) {
                let html = '<div class="timeline">';
                
                result.logs.forEach(log => {
                    const actionType = log.action_type || 'view';
                    let actionIcon = 'info-circle', actionColor = '#6b7280';
                    
                    switch(actionType) {
                        case 'view': case 'viewed': actionIcon = 'eye'; actionColor = '#3b82f6'; break;
                        case 'edit': case 'edited': actionIcon = 'edit'; actionColor = '#fbbf24'; break;
                        case 'saved_draft': actionIcon = 'save'; actionColor = '#10b981'; break;
                        case 'printed': actionIcon = 'print'; actionColor = '#8b5cf6'; break;
                        case 'granted_access': actionIcon = 'user-plus'; actionColor = '#f59e0b'; break;
                        case 'completed': actionIcon = 'check-circle'; actionColor = '#10b981'; break;
                        case 'taken': actionIcon = 'hand-paper'; actionColor = '#fbbf24'; break;
                    }
                    
                    html += `
                        <div class="access-log-item" style="border-left: 3px solid ${actionColor};">
                            <div style="display: flex; justify-content: space-between;">
                                <div>
                                    <i class="fas fa-${actionIcon}" style="color: ${actionColor};"></i>
                                    <strong>${escapeHtml(log.responder_name || 'Responder')}</strong>
                                    <span class="badge" style="background: ${actionColor};">${actionType.toUpperCase()}</span>
                                </div>
                                <small>${log.formatted_time || new Date(log.created_at).toLocaleString()}</small>
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                $('#accessLogsList').html(html);
            } else {
                $('#accessLogsList').html('<div class="text-center p-4">No activity recorded yet.</div>');
            }
        }
    });
}

function loadUsersWithAccessList(id) {
    $('#usersWithAccessList').html('<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');
    
    $.ajax({
        url: 'api/get_report_access_logs.php',
        method: 'GET',
        data: { incident_id: id },
        dataType: 'json',
        success: function(result) {
            if (result.success && result.grants && result.grants.length > 0) {
                let html = '<div class="list-group">';
                
                result.grants.forEach(grant => {
                    const badge = grant.access_level === 'edit' ? 
                        '<span class="badge" style="background:#fbbf24;color:black;">CAN EDIT</span>' : 
                        '<span class="badge" style="background:#3b82f6;">VIEW ONLY</span>';
                    
                    html += `
                        <div class="list-group-item" style="background: var(--card-black); margin-bottom: 10px; border-radius: 8px; padding: 15px;">
                            <div style="display: flex; justify-content: space-between;">
                                <div>
                                    <i class="fas fa-user-circle"></i>
                                    <strong>${escapeHtml(grant.granted_to_name || 'User')}</strong>
                                    <div>${badge}</div>
                                    <small>Granted by: ${escapeHtml(grant.granted_by_name || 'Unknown')}</small>
                                </div>
                                ${grant.is_active == 1 ? `<button class="btn btn-sm btn-danger" onclick="revokeReportAccess(${grant.grant_id})">Revoke</button>` : ''}
                            </div>
                        </div>
                    `;
                });
                
                html += '</div>';
                $('#usersWithAccessList').html(html);
            } else {
                $('#usersWithAccessList').html('<div class="text-center p-4">No other users have access.</div>');
            }
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
    }).then(r => {
        if (r.isConfirmed) {
            $.post('api/revoke_report_access.php', { grant_id: grantId }, response => {
                if (response.success) {
                    Swal.fire('Success', response.message, 'success');
                    if (currentAccessIncidentId) {
                        loadUsersWithAccessList(currentAccessIncidentId);
                        loadAccessLogs(currentAccessIncidentId);
                    }
                }
            }, 'json');
        }
    });
}

// ============================================
// TRASH FUNCTIONS
// ============================================

function cleanOldTrash() {
    let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
    const cutoff = new Date();
    cutoff.setMonth(cutoff.getMonth() - 5);
    trash = trash.filter(i => new Date(i.deletedAt) > cutoff);
    localStorage.setItem(STORAGE_TRASH, JSON.stringify(trash));
}

function moveToTrash(btn, type, trackingId, reportId) {
    Swal.fire({
        title: 'Move to Trash?',
        text: `"${trackingId}" will be deleted after 5 months.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Yes'
    }).then(r => {
        if (r.isConfirmed) {
            let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
            trash.unshift({
                id: 'trash_' + Date.now(),
                incidentId: reportId,
                trackingId: trackingId,
                deletedAt: new Date().toISOString()
            });
            localStorage.setItem(STORAGE_TRASH, JSON.stringify(trash));
            
            if (type === 'active') {
                $.post('api/cancel_incident.php', { incident_id: reportId });
            }
            
            Swal.fire('Moved', 'Item moved to trash', 'success');
            setTimeout(() => location.reload(), 500);
        }
    });
}

function loadTrash() {
    cleanOldTrash();
    const trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
    
    if (!trash.length) {
        $('#trashList').html('<div class="empty-state"><i class="fas fa-trash-alt"></i><h3>Trash Empty</h3></div>');
        return;
    }
    
    let html = '';
    trash.forEach(item => {
        const deleteDate = new Date(new Date(item.deletedAt).getTime() + TRASH_RETENTION_DAYS * 86400000);
        const daysLeft = Math.ceil((deleteDate - new Date()) / 86400000);
        
        html += `
            <div class="incident-card" style="opacity: 0.7;">
                <div class="card-header">
                    <span class="location-badge">${escapeHtml(item.trackingId)}</span>
                    <span class="status-badge" style="background: #6b7280;">TRASH</span>
                </div>
                <div class="card-body">
                    <div class="incident-time">Deleted: ${new Date(item.deletedAt).toLocaleDateString()}</div>
                    <div class="incident-time">${daysLeft > 0 ? `Deletes in ${daysLeft} days` : 'Deleting soon'}</div>
                </div>
                <div class="card-footer" style="gap: 8px;">
                    <button class="action-btn" style="flex:1;" onclick="restoreFromTrash('${item.id}')"><i class="fas fa-trash-restore"></i> Restore</button>
                    <button class="action-btn danger" style="flex:1;" onclick="permanentDelete('${item.id}')"><i class="fas fa-trash-alt"></i> Delete</button>
                </div>
            </div>
        `;
    });
    $('#trashList').html(html);
}

function restoreFromTrash(id) {
    let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
    trash = trash.filter(i => i.id !== id);
    localStorage.setItem(STORAGE_TRASH, JSON.stringify(trash));
    Swal.fire('Restored', 'Item restored', 'success');
    loadTrash();
    switchTab('my-reports-tab');
}

function permanentDelete(id) {
    Swal.fire({
        title: 'Permanently Delete?',
        text: 'This cannot be undone!',
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Delete'
    }).then(r => {
        if (r.isConfirmed) {
            let trash = JSON.parse(localStorage.getItem(STORAGE_TRASH) || '[]');
            trash = trash.filter(i => i.id !== id);
            localStorage.setItem(STORAGE_TRASH, JSON.stringify(trash));
            Swal.fire('Deleted', 'Item deleted', 'success');
            loadTrash();
        }
    });
}

// ============================================
// ACTIVE INCIDENT FORM
// ============================================

function viewActiveIncident(id, trackingId) {
    currentWorkingIncidentId = id;
    currentWorkingTrackingId = trackingId;
    
    $('#workingOnIndicator').show();
    $('#workingTrackingId').text(trackingId);
    
    switchTab('report-form-tab');
    clearForm();
    
    $.post('responder_dashboard.php', { action: 'load_active_draft', incident_id: id }, result => {
        if (result.success && result.draft_data) {
            try { 
                restoreFormData(JSON.parse(result.draft_data)); 
                showToast('Draft Loaded', 'Progress restored', 'success'); 
            } catch(e) {}
        } else {
            $.post('api/get_incident_full.php', { incident_id: id }, incident => {
                if (incident.location_address) $('#placeIncident').val(incident.location_address);
                if (incident.incident_type) $('#incidentPurpose').val(incident.incident_type);
                if (incident.reporter_name) $('#patientName').val(incident.reporter_name);
                if (incident.reporter_phone) $('#emergencyNumber').val(incident.reporter_phone);
                if (incident.description) $('#chiefComplaint').val(incident.description);
                if (incident.created_at) $('#incidentDate').val(incident.created_at.split('T')[0]);
                
                if (incident.photos || incident.videos) {
                    loadIncidentMediaToForm(incident);
                }
            }, 'json');
        }
    }, 'json');
}

function captureFormData() {
    const fields = [
        'incidentDate', 'callTime', 'incidentTime', 'atScene', 'incidentPurpose',
        'departScene', 'atHospital', 'placeIncident', 'handover', 'backToBase',
        'patientName', 'emergencyContact', 'patientAge', 'patientGender', 'patientAddress',
        'emergencyNumber', 'symptoms', 'bp', 'allergy', 'pulse', 'medications',
        'respiratory', 'pastHistory', 'temperature', 'lastIntake', 'events',
        'chiefComplaint', 'actionsGiven', 'refusalWitness', 'refusalDate',
        'crew1', 'crew2', 'crew3', 'crew4', 'crew5', 'driver', 'vehicle',
        'receivingPlace', 'receivingPerson', 'receivingSignName'
    ];
    
    let data = {};
    fields.forEach(f => { data[f] = $('#' + f).val() || ''; });
    
    data.patientSig = sigPads['patientSigCanvas'] && !sigPads['patientSigCanvas'].isEmpty() ? sigPads['patientSigCanvas'].toDataURL() : '';
    data.emergencySig = sigPads['emergencySigCanvas'] && !sigPads['emergencySigCanvas'].isEmpty() ? sigPads['emergencySigCanvas'].toDataURL() : '';
    data.providerSig = sigPads['providerSigCanvas'] && !sigPads['providerSigCanvas'].isEmpty() ? sigPads['providerSigCanvas'].toDataURL() : '';
    
    data.bodyImage = $('#bodyImageData').val() || '';
    
    data.incidentImages = JSON.stringify(incidentImages);
    data.incidentVideos = JSON.stringify(incidentVideos);
    
    data.logoLeft = $('#logoLeftImg').attr('src') || '';
    data.logoRight = $('#logoRightImg').attr('src') || '';
    
    return data;
}

function restoreFormData(data) {
    for (let key in data) {
        if ($('#' + key).length && !['patientSig', 'emergencySig', 'providerSig', 
                                       'bodyImage', 'incidentImages', 'incidentVideos',
                                       'logoLeft', 'logoRight'].includes(key)) {
            $('#' + key).val(data[key] || '');
        }
    }
    
    if (data.patientSig && sigPads['patientSigCanvas']) sigPads['patientSigCanvas'].fromDataURL(data.patientSig);
    if (data.emergencySig && sigPads['emergencySigCanvas']) sigPads['emergencySigCanvas'].fromDataURL(data.emergencySig);
    if (data.providerSig && sigPads['providerSigCanvas']) sigPads['providerSigCanvas'].fromDataURL(data.providerSig);
    
    if (data.bodyImage && window.restoreBodyDrawing) restoreBodyDrawing(data.bodyImage);
    
    if (data.incidentImages) { try { incidentImages = JSON.parse(data.incidentImages); } catch(e) {} }
    if (data.incidentVideos) { try { incidentVideos = JSON.parse(data.incidentVideos); } catch(e) {} }
    updateImageGallery();
    
    if (data.logoLeft) $('#logoLeftImg').attr('src', data.logoLeft);
    if (data.logoRight) $('#logoRightImg').attr('src', data.logoRight);
}

function saveProgress() {
    if (!currentWorkingIncidentId) { 
        Swal.fire('No Active Report', 'Select a report first', 'warning'); 
        return; 
    }
    
    const data = captureFormData();
    lastFormData = JSON.stringify(data);
    
    $.post('responder_dashboard.php', {
        action: 'save_active_draft',
        incident_id: currentWorkingIncidentId,
        draft_data: lastFormData
    }, result => {
        if (result.success) {
            $('#autoSaveIndicator').fadeIn(200).delay(1500).fadeOut(200);
            logReportAccess(currentWorkingIncidentId, 'saved_draft', null, null, 'Saved draft');
        }
    }, 'json');
}

function completeReportSubmission() {
    if (!currentWorkingIncidentId) { 
        Swal.fire('No Active Report', 'Select a report first', 'warning'); 
        return; 
    }
    
    Swal.fire({
        title: 'Submit Report?',
        text: 'This will complete the incident.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        confirmButtonText: 'Submit'
    }).then(r => {
        if (r.isConfirmed) {
            const data = captureFormData();
            
            $.post('api/complete_incident.php', {
                incident_id: currentWorkingIncidentId,
                report_data: JSON.stringify(data)
            }, response => {
                if (response.success) {
                    logReportAccess(currentWorkingIncidentId, 'completed', null, null, 'Report completed');
                    
                    $.post('responder_dashboard.php', {
                        action: 'remove_completed_report',
                        incident_id: currentWorkingIncidentId
                    }, () => {
                        clearForm();
                        currentWorkingIncidentId = null;
                        $('#workingOnIndicator').hide();
                        Swal.fire('Success!', 'Report completed', 'success').then(() => {
                            switchTab('dashboard-tab');
                            setTimeout(() => location.reload(), 500);
                        });
                    });
                }
            }, 'json');
        }
    });
}

function clearForm() {
    $('#report-form-tab input, #report-form-tab textarea, #report-form-tab select')
        .not('[type=hidden]').not('#penColor').not('#brushSize').val('');
    
    Object.values(sigPads).forEach(pad => pad?.clear());
    
    if (window.clearBodyDrawing) clearBodyDrawing();
    
    incidentImages = [];
    incidentVideos = [];
    updateImageGallery();
    
    const today = new Date().toISOString().split('T')[0];
    $('#incidentDate').val(today);
    $('#refusalDate').val(today);
}

function clearAllFormData() {
    Swal.fire({
        title: 'Clear All?',
        text: 'This will erase all form data!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Clear'
    }).then(r => { 
        if (r.isConfirmed) { 
            clearForm(); 
            if (currentWorkingIncidentId) {
                logReportAccess(currentWorkingIncidentId, 'clear_form', 'all_fields', null, 'Cleared form');
            }
            Swal.fire('Cleared', '', 'success'); 
        } 
    });
}

function clearAllMedia() {
    Swal.fire({
        title: 'Clear Media?',
        text: 'Remove all photos and videos?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Clear'
    }).then(r => {
        if (r.isConfirmed) {
            incidentImages = [];
            incidentVideos = [];
            updateImageGallery();
            Swal.fire('Cleared', 'All media removed', 'success');
        }
    });
}

function updateImageGallery() {
    const gallery = $('#incidentImageGallery');
    gallery.empty();
    
    incidentImages.forEach((img, idx) => {
        gallery.append(`
            <div class="photo-item">
                <img src="${img}">
                <button class="photo-remove" onclick="removeImage(${idx})">&times;</button>
            </div>
        `);
    });
    
    incidentVideos.forEach((vid, idx) => {
        gallery.append(`
            <div class="photo-item">
                <video src="${vid}" style="width:100%;height:100%;object-fit:cover;"></video>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; pointer-events: none;">
                    <i class="fas fa-play-circle" style="font-size: 30px; opacity: 0.8;"></i>
                </div>
                <button class="photo-remove" onclick="removeVideo(${idx})">&times;</button>
            </div>
        `);
    });
    
    $('#imageCount').text(`${incidentImages.length} photo(s), ${incidentVideos.length} video(s)`);
    $('#incidentImagesData').val(JSON.stringify({ images: incidentImages, videos: incidentVideos }));
}

function removeImage(idx) { incidentImages.splice(idx, 1); updateImageGallery(); }
function removeVideo(idx) { incidentVideos.splice(idx, 1); updateImageGallery(); }

// ============================================
// SIGNATURE PAD FUNCTIONS
// ============================================

function initSignatures() {
    ['patientSigCanvas', 'emergencySigCanvas', 'providerSigCanvas'].forEach(id => {
        const canvas = document.getElementById(id);
        if (canvas) {
            canvas.width = canvas.clientWidth || 280;
            canvas.height = id === 'providerSigCanvas' ? 70 : 80;
            sigPads[id] = new SignaturePad(canvas, {
                backgroundColor: 'white',
                penColor: 'black'
            });
        }
    });
}

function clearSignature(canvasId) {
    if (sigPads[canvasId]) sigPads[canvasId].clear();
}

// ============================================
// INJURY MAP (BODY CANVAS) FUNCTIONS
// ============================================

function initBodyCanvas() {
    bodyCanvas = document.getElementById('bodyCanvas');
    if (!bodyCanvas) return;
    
    bodyCanvas.width = 360;
    bodyCanvas.height = 420;
    bodyCtx = bodyCanvas.getContext('2d');
    
    drawingLayer = document.createElement('canvas');
    drawingLayer.width = 360;
    drawingLayer.height = 420;
    drawCtx = drawingLayer.getContext('2d');
    
    const bgImg = new Image();
    bgImg.onload = function() {
        backgroundImage = bgImg;
        compositeBodyCanvas();
        saveBodyState();
    };
    bgImg.onerror = function() {
        backgroundImage = null;
        compositeBodyCanvas();
        saveBodyState();
    };
    bgImg.src = 'images/image.png';
    
    setupBodyCanvasEvents();
    setupDrawingTools();
}

function compositeBodyCanvas() {
    bodyCtx.clearRect(0, 0, 360, 420);
    
    if (backgroundImage) {
        bodyCtx.drawImage(backgroundImage, 0, 0, 360, 420);
    } else {
        bodyCtx.fillStyle = '#fff';
        bodyCtx.fillRect(0, 0, 360, 420);
        bodyCtx.strokeStyle = '#333';
        bodyCtx.lineWidth = 2;
        
        bodyCtx.beginPath();
        bodyCtx.arc(180, 65, 32, 0, Math.PI * 2);
        bodyCtx.stroke();
        
        bodyCtx.beginPath();
        bodyCtx.moveTo(148, 95);
        bodyCtx.lineTo(148, 175);
        bodyCtx.lineTo(212, 175);
        bodyCtx.lineTo(212, 95);
        bodyCtx.stroke();
        
        bodyCtx.beginPath();
        bodyCtx.moveTo(148, 115);
        bodyCtx.lineTo(105, 160);
        bodyCtx.stroke();
        bodyCtx.beginPath();
        bodyCtx.moveTo(212, 115);
        bodyCtx.lineTo(255, 160);
        bodyCtx.stroke();
        
        bodyCtx.beginPath();
        bodyCtx.moveTo(148, 175);
        bodyCtx.lineTo(125, 260);
        bodyCtx.stroke();
        bodyCtx.beginPath();
        bodyCtx.moveTo(212, 175);
        bodyCtx.lineTo(235, 260);
        bodyCtx.stroke();
        bodyCtx.beginPath();
        bodyCtx.moveTo(148, 175);
        bodyCtx.lineTo(148, 320);
        bodyCtx.stroke();
        bodyCtx.beginPath();
        bodyCtx.moveTo(212, 175);
        bodyCtx.lineTo(212, 320);
        bodyCtx.stroke();
    }
    
    bodyCtx.drawImage(drawingLayer, 0, 0);
    $('#bodyImageData').val(bodyCanvas.toDataURL());
}

function saveBodyState() {
    const state = drawingLayer.toDataURL();
    historyStack.push(state);
    if (historyStack.length > 30) historyStack.shift();
    redoStack = [];
}

function restoreBodyDrawing(dataURL) {
    const img = new Image();
    img.onload = function() {
        drawCtx.clearRect(0, 0, 360, 420);
        drawCtx.drawImage(img, 0, 0);
        compositeBodyCanvas();
    };
    img.src = dataURL;
}

function clearBodyDrawing() {
    drawCtx.clearRect(0, 0, 360, 420);
    compositeBodyCanvas();
    saveBodyState();
}

function setupBodyCanvasEvents() {
    function getCoords(e) {
        const rect = bodyCanvas.getBoundingClientRect();
        const scaleX = bodyCanvas.width / rect.width;
        const scaleY = bodyCanvas.height / rect.height;
        
        let cx, cy;
        if (e.touches) {
            cx = e.touches[0].clientX;
            cy = e.touches[0].clientY;
        } else {
            cx = e.clientX;
            cy = e.clientY;
        }
        
        let x = (cx - rect.left) * scaleX;
        let y = (cy - rect.top) * scaleY;
        
        return {
            x: Math.min(Math.max(0, x), bodyCanvas.width),
            y: Math.min(Math.max(0, y), bodyCanvas.height)
        };
    }
    
    function drawOnLayer(x, y) {
        drawCtx.globalCompositeOperation = currentMode === 'draw' ? 'source-over' : 'destination-out';
        drawCtx.strokeStyle = currentColor;
        drawCtx.fillStyle = currentColor;
        drawCtx.lineWidth = brushSize;
        drawCtx.lineCap = 'round';
        
        drawCtx.beginPath();
        drawCtx.moveTo(lastX, lastY);
        drawCtx.lineTo(x, y);
        drawCtx.stroke();
        
        drawCtx.beginPath();
        drawCtx.arc(x, y, brushSize / 2, 0, Math.PI * 2);
        drawCtx.fill();
        
        lastX = x;
        lastY = y;
        compositeBodyCanvas();
    }
    
    function startDraw(e) {
        drawing = true;
        const coords = getCoords(e);
        lastX = coords.x;
        lastY = coords.y;
        saveBodyState();
        drawOnLayer(lastX, lastY);
        e.preventDefault();
    }
    
    function drawMove(e) {
        if (!drawing) return;
        const coords = getCoords(e);
        drawOnLayer(coords.x, coords.y);
        e.preventDefault();
    }
    
    bodyCanvas.addEventListener('mousedown', startDraw);
    bodyCanvas.addEventListener('mousemove', drawMove);
    bodyCanvas.addEventListener('mouseup', () => drawing = false);
    bodyCanvas.addEventListener('mouseleave', () => drawing = false);
    
    bodyCanvas.addEventListener('touchstart', startDraw);
    bodyCanvas.addEventListener('touchmove', drawMove);
    bodyCanvas.addEventListener('touchend', () => drawing = false);
}

function setupDrawingTools() {
    $('#drawBtn').click(function() {
        currentMode = 'draw';
        $(this).addClass('active');
        $('#eraseBtn').removeClass('active');
    });
    
    $('#eraseBtn').click(function() {
        currentMode = 'erase';
        $(this).addClass('active');
        $('#drawBtn').removeClass('active');
    });
    
    $('#penColor').on('change', function(e) { currentColor = e.target.value; });
    $('#brushSize').on('input', function(e) { brushSize = parseInt(e.target.value); });
    
    $('#clearCanvasBtn').click(function() {
        drawCtx.clearRect(0, 0, 360, 420);
        compositeBodyCanvas();
        saveBodyState();
    });
    
    $('#undoBtn').click(function() {
        if (historyStack.length > 1) {
            redoStack.push(historyStack.pop());
            restoreBodyDrawing(historyStack[historyStack.length - 1]);
        }
    });
    
    $('#redoBtn').click(function() {
        if (redoStack.length) {
            const state = redoStack.pop();
            historyStack.push(state);
            restoreBodyDrawing(state);
        }
    });
}

// ============================================
// NAVIGATION
// ============================================

function switchTab(tabId) {
    $('.tab-pane').removeClass('active');
    $('#' + tabId).addClass('active');
    
    $('.nav-item').removeClass('active');
    $(`.nav-item[data-tab="${tabId}"]`).addClass('active');
    
    if (tabId === 'report-form-tab') {
        $('#fabMain').addClass('visible');
        stopAutoRefresh();
    } else {
        $('#fabMain').removeClass('visible');
        $('#fabMenu').removeClass('show');
        if (tabId === 'dashboard-tab') {
            startAutoRefresh();
        } else {
            stopAutoRefresh();
        }
    }
    
    if (tabId === 'trash-tab') loadTrash();
    if (tabId === 'live-map-tab' && !liveMap) initLiveMap();
}

$('.nav-item').click(function() { switchTab($(this).data('tab')); });

// ============================================
// LIVE MAP
// ============================================

function initLiveMap() {
    if (!L) return;
    liveMap = L.map('liveMap').setView([15.6333, 121.3167], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(liveMap);
    
    navigator.geolocation?.getCurrentPosition(pos => {
        currentLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        liveMap.setView([currentLocation.lat, currentLocation.lng], 14);
    });
}

function updateMyLocation() {
    navigator.geolocation?.getCurrentPosition(pos => {
        currentLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        $.post('responder_dashboard.php', {
            action: 'update_location',
            lat: pos.coords.latitude,
            lng: pos.coords.longitude
        });
        updatePendingCardsWithDistance();
        showToast('Location Updated', 'Your location has been shared', 'success');
    });
}

function centerToMyLocation() {
    navigator.geolocation?.getCurrentPosition(pos => {
        currentLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
        liveMap?.setView([currentLocation.lat, currentLocation.lng], 15);
    });
}

// ============================================
// FAB MENU
// ============================================

$('#fabMain').click((e) => {
    e.stopPropagation();
    $('#fabMenu').toggleClass('show');
});

$(document).click((e) => {
    if (!$(e.target).closest('.fab-button, .fab-menu').length) {
        $('#fabMenu').removeClass('show');
    }
});

// ============================================
// PHOTO & VIDEO UPLOAD
// ============================================

$('#incidentImagesInput').on('change', function(e) {
    Array.from(e.target.files).forEach(f => {
        const reader = new FileReader();
        reader.onload = ev => { 
            if (f.type.startsWith('image/')) {
                incidentImages.push(ev.target.result); 
            } else if (f.type.startsWith('video/')) {
                incidentVideos.push(ev.target.result);
            }
            updateImageGallery(); 
        };
        reader.readAsDataURL(f);
    });
    e.target.value = '';
});

// ============================================
// LOGO UPLOAD
// ============================================

$('#logoLeftInput').on('change', function(e) {
    if (e.target.files[0]) {
        const reader = new FileReader();
        reader.onload = ev => $('#logoLeftImg').attr('src', ev.target.result);
        reader.readAsDataURL(e.target.files[0]);
    }
});

$('#logoRightInput').on('change', function(e) {
    if (e.target.files[0]) {
        const reader = new FileReader();
        reader.onload = ev => $('#logoRightImg').attr('src', ev.target.result);
        reader.readAsDataURL(e.target.files[0]);
    }
});

// ============================================
// INITIALIZATION
// ============================================

$(document).ready(() => {
    const theme = document.documentElement.getAttribute('data-theme') || 'light';
    $('#themeToggle i').attr('class', theme === 'light' ? 'fas fa-moon' : 'fas fa-sun');
    
    const today = new Date().toISOString().split('T')[0];
    $('#incidentDate').val(today);
    $('#refusalDate').val(today);
    
    updateAllTimeAgo();
    setInterval(updateAllTimeAgo, 60000);
    
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
    
    $('#fabSaveDraft').click(() => { saveProgress(); $('#fabMenu').removeClass('show'); });
    $('#fabClearForm').click(() => { clearAllFormData(); $('#fabMenu').removeClass('show'); });
    $('#fabCompleteReport').click(() => { completeReportSubmission(); $('#fabMenu').removeClass('show'); });
    $('#fabPrint').click(() => { 
        if (currentWorkingIncidentId) logReportAccess(currentWorkingIncidentId, 'printed', null, null, 'Report printed');
        window.print(); 
        $('#fabMenu').removeClass('show'); 
    });
    
    initSignatures();
    initBodyCanvas();
    initAudio();
    loadAlertSettings();
    startLocationTracking();
    startAutoRefresh();
    
    setInterval(() => {
        if ($('#dashboard-tab').hasClass('active') && currentLocation.lat) {
            updatePendingCardsWithDistance();
        }
    }, 10000);
    
    setInterval(() => {
        if (soundEnabled && currentLocation.lat && $('#dashboard-tab').hasClass('active')) {
            checkProximityIncidents();
        }
    }, 15000);
    
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            if ($('#dashboard-tab').hasClass('active')) {
                startAutoRefresh();
                refreshDashboardData();
                updatePendingCardsWithDistance();
                updateAllTimeAgo();
            }
        }
    });
    
    window.addEventListener('online', () => {
        $('#connectionStatus').removeClass('offline');
        showToast('Online', 'Connection restored', 'success');
        refreshDashboardData();
    });
    
    window.addEventListener('offline', () => {
        $('#connectionStatus').addClass('offline');
        showToast('Offline', 'Working in offline mode', 'warning');
    });
});

</script>
</body>
</html>