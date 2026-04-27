<?php
// admin_dashboard.php - Complete admin dashboard with completed reports styled like incident form
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Handle AJAX requests for responder management
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
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
        $result = $check->get_result();
        
        if ($result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit;
        }
        
        $stmt = $conn->prepare("INSERT INTO tbl_users (username, password, fullname, role, email, phone, responder_type, badge_number, is_active) VALUES (?, ?, ?, 'responder', ?, ?, ?, ?, 1)");
        $stmt->bind_param("sssssss", $username, $password, $fullname, $email, $phone, $responder_type, $badge_number);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Responder created successfully. Password: responder123']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $conn->error]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'toggle_status') {
        $user_id = $_POST['user_id'];
        $current_status = $_POST['current_status'];
        $new_status = $current_status == 1 ? 0 : 1;
        
        $stmt = $conn->prepare("UPDATE tbl_users SET is_active = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $new_status, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Status updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error updating status']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'delete_responder') {
        $user_id = $_POST['user_id'];
        
        $stmt = $conn->prepare("DELETE FROM tbl_users WHERE user_id = ? AND role = 'responder'");
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Responder deleted']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting responder']);
        }
        exit;
    }
    
    if ($_POST['action'] === 'reset_password') {
        $user_id = $_POST['user_id'];
        $new_password = password_hash('responder123', PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE tbl_users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $new_password, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Password reset to: responder123']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error resetting password']);
        }
        exit;
    }
    
    // Handle AJAX request to get completed report
    if ($_POST['action'] === 'get_completed_report') {
        $report_id = intval($_POST['report_id']);
        
        $stmt = $conn->prepare("
            SELECT cr.*, i.tracking_id, i.incident_type, i.severity, i.location_address, u.fullname as responder_name
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
}

// Get statistics
$stats = [];
$stats['total_reports'] = $conn->query("SELECT COUNT(*) as total FROM tbl_incidents")->fetch_assoc()['total'];
$stats['pending'] = $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE status = 'pending'")->fetch_assoc()['total'];
$stats['dispatched'] = $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE status = 'dispatched'")->fetch_assoc()['total'];
$stats['completed'] = $conn->query("SELECT COUNT(*) as total FROM tbl_incidents WHERE status = 'completed'")->fetch_assoc()['total'];
$stats['total_responders'] = $conn->query("SELECT COUNT(*) as total FROM tbl_users WHERE role = 'responder'")->fetch_assoc()['total'];
$stats['active_responders'] = $conn->query("SELECT COUNT(*) as total FROM tbl_users WHERE role = 'responder' AND is_active = 1")->fetch_assoc()['total'];

// Get completed reports count
$stats['completed_reports'] = $conn->query("SELECT COUNT(*) as total FROM tbl_completed_reports")->fetch_assoc()['total'];

// Get all incidents for list
$incidents = $conn->query("
    SELECT i.*, 
           r1.fullname as taken_by_name,
           r2.fullname as finished_by_name
    FROM tbl_incidents i 
    LEFT JOIN tbl_users r1 ON i.taken_by_responder_id = r1.user_id
    LEFT JOIN tbl_users r2 ON i.finished_by_responder_id = r2.user_id
    ORDER BY i.created_at DESC
");

// Get all responders
$responders = $conn->query("
    SELECT user_id, username, fullname, email, phone, responder_type, badge_number, is_active, created_at 
    FROM tbl_users 
    WHERE role = 'responder' 
    ORDER BY created_at DESC
");

// Get completed reports with full data
$completed_reports = $conn->query("
    SELECT cr.*, i.tracking_id, i.incident_type, i.severity, i.location_address, u.fullname as responder_name
    FROM tbl_completed_reports cr
    LEFT JOIN tbl_incidents i ON cr.incident_id = i.incident_id
    LEFT JOIN tbl_users u ON cr.responder_id = u.user_id
    ORDER BY cr.submitted_at DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MDRRMO Admin - Command Center</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --primary: #e67e22; --primary-dark: #d35400; --secondary: #1e2a36; }
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .sidebar { background: var(--secondary); min-height: 100vh; color: white; position: sticky; top: 0; }
        .sidebar a { color: #ecf0f1; text-decoration: none; padding: 12px 20px; display: block; transition: 0.2s; }
        .sidebar a:hover, .sidebar a.active { background: var(--primary); color: white; }
        .sidebar i { margin-right: 10px; width: 20px; }
        .main-content { padding: 20px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid var(--primary); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 2rem; font-weight: bold; color: var(--secondary); }
        .btn-mdrrmo { background: var(--primary); color: white; border: none; }
        .btn-mdrrmo:hover { background: var(--primary-dark); color: white; }
        .nav-tabs .nav-link { color: var(--secondary); font-weight: 500; }
        .nav-tabs .nav-link.active { color: var(--primary); border-bottom: 2px solid var(--primary); }
        .incident-card { background: white; border-radius: 12px; padding: 15px; margin-bottom: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); cursor: pointer; transition: all 0.3s; }
        .incident-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .status-pending { border-left: 4px solid #ffc107; }
        .status-taken { border-left: 4px solid #17a2b8; }
        .status-finished { border-left: 4px solid #28a745; }
        #map { height: 500px; border-radius: 12px; margin-bottom: 20px; }
        .photo-thumb { width: 100px; height: 100px; object-fit: cover; border-radius: 8px; cursor: pointer; margin: 5px; }
        .chain-timeline { position: relative; padding-left: 30px; }
        .chain-timeline:before { content: ''; position: absolute; left: 10px; top: 0; bottom: 0; width: 2px; background: #ddd; }
        .chain-item { position: relative; margin-bottom: 20px; }
        .chain-item:before { content: ''; position: absolute; left: -26px; top: 5px; width: 12px; height: 12px; border-radius: 50%; background: var(--primary); }
        
        /* Report Viewer Styles - Exactly matching responder dashboard */
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
        .report-viewer {
            background: white;
            font-family: 'Times New Roman', 'Arial', serif;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
        }
        .logo-row {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        .logo-box {
            width: 70px;
            height: 70px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
        }
        .logo-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .header-text h3, .header-text h2, .header-text h4 {
            margin: 2px 0;
        }
        .header-text h3 { font-size: 14px; }
        .header-text h2 { font-size: 18px; font-weight: bold; }
        .header-text h4 { font-size: 12px; color: #e67e22; }
        .report-title {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            margin: 10px 0;
        }
        .form-table {
            width: 100%;
            border-collapse: collapse;
            margin: 8px 0;
            font-size: 11px;
            background: white;
        }
        .form-table td, .form-table th {
            border: 1px solid #000000;
            padding: 8px 6px;
            vertical-align: top;
            background: white;
        }
        .form-table th {
            background: #f8f9fa;
            font-weight: bold;
            text-align: center;
        }
        .label-cell {
            font-weight: bold;
            width: 40%;
            background: #fef9e6;
        }
        .section-header {
            background: #e67e22;
            color: white;
            font-weight: bold;
            text-align: center;
            font-size: 12px;
        }
        .filipino-text {
            font-size: 9px;
            color: #444;
            font-style: italic;
            display: inline-block;
            margin-left: 4px;
        }
        .signature-preview {
            max-width: 250px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            background: white;
            margin-top: 5px;
        }
        .signature-preview img {
            max-width: 100%;
            height: auto;
        }
        .injury-map-preview {
            max-width: 360px;
            border: 2px solid #333;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 10px;
        }
        .injury-map-preview img {
            width: 100%;
            height: auto;
        }
        .report-images-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .report-image-item {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .report-image-item img {
            width: 100%;
            height: auto;
            cursor: pointer;
        }
        .report-image-item .image-caption {
            padding: 8px;
            font-size: 11px;
            background: #f8f9fa;
            text-align: center;
            color: #666;
        }
        .two-column-layout {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .left-column, .right-column {
            flex: 1;
            min-width: 280px;
        }
        .refusal-text {
            font-size: 10px;
            line-height: 1.3;
            text-align: justify;
            background: #fef9e6;
            padding: 8px;
            border-radius: 6px;
        }
        
        /* Print Styles - Exactly matching responder dashboard */
        @media print {
            .sidebar, .menu-toggle, .fab-container, .draw-tools, .sig-buttons, .logo-upload, .action-buttons, .incident-images-tools,
            .btn, .btn-mdrrmo, .refresh-btn, .my-location-btn, .fab-menu, .fab-main, .modal-footer button, .modal-header button,
            .nav-tabs, .dashboard-header .stats-row, .stat-card, .incident-stats, .card-header .btn, #printCompletedReportsBtn,
            .action-buttons-group, .btn-view-location, .btn-complete-active, .btn-delete, .btn-view-report, .btn-restore,
            .fab-container, .draw-tools, .incident-images-tools, .close-menu, .menu-toggle, .logo-upload {
                display: none !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            
            .page, .report-viewer, .modal-content {
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                background: white !important;
                max-width: 100% !important;
            }
            
            .modal {
                position: relative !important;
                display: block !important;
                background: white !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
                transform: none !important;
            }
            
            .modal-dialog {
                max-width: 100% !important;
                margin: 0 !important;
                transform: none !important;
            }
            
            .modal-content {
                box-shadow: none !important;
                border: none !important;
            }
            
            .modal-header {
                background: white !important;
                color: black !important;
                border-bottom: 1px solid #000 !important;
                padding: 10px !important;
            }
            
            .modal-body {
                padding: 15px !important;
                overflow: visible !important;
            }
            
            .form-table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin: 5px 0 !important;
                page-break-inside: avoid !important;
            }
            
            .form-table td, .form-table th {
                border: 1px solid #000 !important;
                padding: 6px 4px !important;
                font-size: 10pt !important;
                background: white !important;
            }
            
            .two-column-layout {
                display: flex !important;
                gap: 15px !important;
                flex-wrap: wrap !important;
            }
            
            .left-column, .right-column {
                flex: 1 !important;
                min-width: 45% !important;
            }
            
            .report-images-gallery {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 10px !important;
            }
            
            .report-image-item {
                width: 200px !important;
                page-break-inside: avoid !important;
            }
            
            .logo-row {
                display: flex !important;
                justify-content: center !important;
                gap: 20px !important;
            }
            
            .logo-box {
                width: 60px !important;
                height: 60px !important;
            }
            
            .report-title {
                font-size: 14pt !important;
            }
            
            .section-header {
                background: #e67e22 !important;
                color: white !important;
            }
            
            .label-cell {
                background: #fef9e6 !important;
            }
            
            @page {
                size: A4;
                margin: 1.5cm;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 p-0 sidebar">
                <div class="p-4 text-center border-bottom border-secondary">
                    <i class="fas fa-ambulance fa-3x mb-2"></i>
                    <h5>MDRRMO Bongabon</h5>
                    <small class="text-muted">Admin Command Center</small>
                </div>
                <nav class="mt-3">
                    <a href="admin_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    <a href="#" id="respondersTabLink"><i class="fas fa-users"></i> Manage Responders</a>
                    <a href="#" id="mapTabLink"><i class="fas fa-map"></i> Live Map</a>
                    <a href="#" id="incidentsTabLink"><i class="fas fa-clipboard-list"></i> All Incidents</a>
                    <a href="#" id="completedReportsTabLink"><i class="fas fa-check-circle"></i> Completed Reports</a>
                    <a href="logout.php" class="text-danger"><i class="fas fa-sign-out-alt"></i> Logout</a>
                </nav>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-10 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3>Welcome, <?= htmlspecialchars($_SESSION['fullname']) ?>!</h3>
                    <div><span class="badge bg-success">Admin Online</span> <span class="ms-2 text-muted"><?= date('F d, Y h:i A') ?></span></div>
                </div>
                
                <!-- Tabs -->
                <ul class="nav nav-tabs mb-4" id="mainTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard"><i class="fas fa-chart-line"></i> Dashboard</button></li>
                    <li class="nav-item"><button class="nav-link" id="responders-tab" data-bs-toggle="tab" data-bs-target="#responders"><i class="fas fa-users"></i> Manage Responders</button></li>
                    <li class="nav-item"><button class="nav-link" id="map-tab" data-bs-toggle="tab" data-bs-target="#mapTab"><i class="fas fa-map"></i> Live Map</button></li>
                    <li class="nav-item"><button class="nav-link" id="incidents-tab" data-bs-toggle="tab" data-bs-target="#incidents"><i class="fas fa-clipboard-list"></i> All Incidents</button></li>
                    <li class="nav-item"><button class="nav-link" id="completed-reports-tab" data-bs-toggle="tab" data-bs-target="#completedReports"><i class="fas fa-check-circle"></i> Completed Reports <span class="badge bg-success ms-1"><?= $stats['completed_reports'] ?></span></button></li>
                </ul>
                
                <div class="tab-content">
                    <!-- DASHBOARD TAB -->
                    <div class="tab-pane fade show active" id="dashboard">
                        <div class="row mb-4">
                            <div class="col-md-3"><div class="stat-card"><div class="stat-value"><?= $stats['total_reports'] ?></div><div class="text-muted">Total Reports</div></div></div>
                            <div class="col-md-3"><div class="stat-card"><div class="stat-value text-warning"><?= $stats['pending'] ?></div><div class="text-muted">Pending</div></div></div>
                            <div class="col-md-3"><div class="stat-card"><div class="stat-value text-info"><?= $stats['dispatched'] ?></div><div class="text-muted">In Progress</div></div></div>
                            <div class="col-md-3"><div class="stat-card"><div class="stat-value text-success"><?= $stats['completed'] ?></div><div class="text-muted">Completed</div></div></div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-value"><?= $stats['total_responders'] ?></div><div class="text-muted">Total Responders</div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-value text-success"><?= $stats['active_responders'] ?></div><div class="text-muted">Active Responders</div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-value text-primary"><?= $stats['completed_reports'] ?></div><div class="text-muted">Submitted Reports</div></div></div>
                        </div>
                        <div class="card"><div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-bolt me-2"></i> Quick Actions</h5></div>
                            <div class="card-body"><div class="row g-3"><div class="col-md-3"><button class="btn btn-danger w-100 py-3" data-bs-toggle="modal" data-bs-target="#createResponderModal"><i class="fas fa-user-plus me-2"></i> Add Responder</button></div>
                            <div class="col-md-3"><button class="btn btn-info w-100 py-3" onclick="window.location.reload()"><i class="fas fa-sync-alt me-2"></i> Refresh</button></div>
                            <div class="col-md-3"><button class="btn btn-success w-100 py-3" onclick="switchToCompletedReports()"><i class="fas fa-file-alt me-2"></i> View Reports</button></div></div></div></div>
                    </div>
                    
                    <!-- MANAGE RESPONDERS TAB -->
                    <div class="tab-pane fade" id="responders">
                        <div class="card">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i> Responder Accounts</h5>
                                <button class="btn btn-mdrrmo btn-sm" data-bs-toggle="modal" data-bs-target="#createResponderModal"><i class="fas fa-plus me-1"></i> Add New Responder</button>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr><th>ID</th><th>Username</th><th>Full Name</th><th>Badge #</th><th>Type</th><th>Status</th><th>Joined</th><th>Actions</th> </thead>
                                        <tbody>
                                            <?php while($row = $responders->fetch_assoc()): ?>
                                             ox
                                                <td>#<?= str_pad($row['user_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                                <td><strong><?= htmlspecialchars($row['username']) ?></strong></td>
                                                <td><?= htmlspecialchars($row['fullname']) ?></td>
                                                <td><code><?= htmlspecialchars($row['badge_number'] ?? 'N/A') ?></code></td>
                                                <td><span class="badge bg-<?= $row['responder_type'] == 'medic' ? 'info' : ($row['responder_type'] == 'firefighter' ? 'danger' : 'secondary') ?>"><?= ucfirst($row['responder_type'] ?? 'Responder') ?></span></td>
                                                <td><button class="btn btn-sm <?= $row['is_active'] ? 'btn-success' : 'btn-secondary' ?> toggle-status" data-id="<?= $row['user_id'] ?>" data-status="<?= $row['is_active'] ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></button></td>
                                                <td><small><?= date('M d, Y', strtotime($row['created_at'])) ?></small></td>
                                                <td><button class="btn btn-sm btn-warning reset-password" data-id="<?= $row['user_id'] ?>"><i class="fas fa-key"></i></button>
                                                    <button class="btn btn-sm btn-danger delete-responder" data-id="<?= $row['user_id'] ?>"><i class="fas fa-trash"></i></button></td>
                                             </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                     </table>
                                </div>
                                <div class="alert alert-info mt-3"><i class="fas fa-info-circle me-2"></i><strong>Default Password:</strong> <code>responder123</code></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- LIVE MAP TAB -->
                    <div class="tab-pane fade" id="mapTab">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-map-marked-alt me-2"></i> Live Incident Map</h5>
                                <div id="newIncidentNotification" style="display: none;" class="alert alert-danger mt-2 new-incident-notification">
                                    <i class="fas fa-bell"></i> <strong>New Incident Reported!</strong> Click to refresh map.
                                </div>
                            </div>
                            <div class="card-body">
                                <div id="map" style="height: 550px; border-radius: 12px;"></div>
                                <div class="mt-3">
                                    <div class="row">
                                        <div class="col-md-3"><div class="d-flex align-items-center"><div style="width: 20px; height: 20px; background-color: #10b981; border-radius: 50%; margin-right: 8px;"></div><span>Minor - Green</span></div></div>
                                        <div class="col-md-3"><div class="d-flex align-items-center"><div style="width: 20px; height: 20px; background-color: #f59e0b; border-radius: 50%; margin-right: 8px;"></div><span>Delayed - Yellow</span></div></div>
                                        <div class="col-md-3"><div class="d-flex align-items-center"><div style="width: 20px; height: 20px; background-color: #ef4444; border-radius: 50%; margin-right: 8px;"></div><span>Immediate - Red</span></div></div>
                                        <div class="col-md-3"><div class="d-flex align-items-center"><div style="width: 20px; height: 20px; background-color: #1f2937; border-radius: 50%; margin-right: 8px;"></div><span>Dead - Black</span></div></div>
                                    </div>
                                    <div class="mt-2 text-muted small"><i class="fas fa-bell"></i> Map auto-refreshes every 30 seconds. New incidents will be highlighted.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ALL INCIDENTS TAB -->
                    <div class="tab-pane fade" id="incidents">
                        <div class="card"><div class="card-header bg-white"><h5 class="mb-0"><i class="fas fa-list me-2"></i> All Incident Reports</h5></div>
                            <div class="card-body"><div id="incidentList">
                                <?php 
                                $incidents->data_seek(0);
                                while($incident = $incidents->fetch_assoc()): 
                                    $statusClass = $incident['status'] == 'pending' ? 'status-pending' : ($incident['status'] == 'dispatched' ? 'status-taken' : 'status-finished');
                                ?>
                                <div class="incident-card <?= $statusClass ?>" onclick="viewIncident(<?= $incident['incident_id'] ?>)">
                                    <div class="row align-items-center">
                                        <div class="col-md-3"><strong><?= htmlspecialchars($incident['tracking_id']) ?></strong><br><small class="text-muted"><?= date('M d, Y H:i', strtotime($incident['created_at'])) ?></small></div>
                                        <div class="col-md-2"><span class="badge bg-<?= $incident['severity'] == 'Dead' ? 'dark' : ($incident['severity'] == 'Immediate' ? 'danger' : ($incident['severity'] == 'Delayed' ? 'warning' : 'info')) ?>"><?= $incident['severity'] ?></span><br><small><?= $incident['incident_type'] ?></small></div>
                                        <div class="col-md-3"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars(substr($incident['location_address'], 0, 40)) ?></div>
                                        <div class="col-md-2"><span class="badge bg-<?= $incident['status'] == 'pending' ? 'warning' : ($incident['status'] == 'dispatched' ? 'info' : 'success') ?>"><?= ucfirst($incident['status']) ?></span></div>
                                        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" onclick="event.stopPropagation(); viewIncident(<?= $incident['incident_id'] ?>)"><i class="fas fa-eye"></i> View</button></div>
                                    </div>
                                </div>
                                <?php endwhile; ?>
                            </div></div>
                        </div>
                    </div>
                    
                    <!-- COMPLETED REPORTS TAB -->
                    <div class="tab-pane fade" id="completedReports">
                        <div class="card">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i> Completed Incident Reports</h5>
                                <button class="btn btn-secondary btn-sm" id="printCompletedReportsBtn"><i class="fas fa-print me-1"></i> Print Summary</button>
                            </div>
                            <div class="card-body">
                                <div id="completedReportsList">
                                    <?php if ($completed_reports && $completed_reports->num_rows > 0): ?>
                                        <?php while($report = $completed_reports->fetch_assoc()): ?>
                                            <div class="incident-card status-finished" onclick="viewCompletedReport(<?= $report['report_id'] ?>)">
                                                <div class="row align-items-center">
                                                    <div class="col-md-3">
                                                        <strong><i class="fas fa-hashtag"></i> <?= htmlspecialchars($report['tracking_id']) ?></strong>
                                                        <br><small class="text-muted"><i class="fas fa-calendar-check"></i> Completed: <?= date('M d, Y H:i', strtotime($report['submitted_at'])) ?></small>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <span class="badge bg-<?= $report['severity'] == 'Dead' ? 'dark' : ($report['severity'] == 'Immediate' ? 'danger' : ($report['severity'] == 'Delayed' ? 'warning' : 'info')) ?>">
                                                            <?= $report['severity'] ?>
                                                        </span>
                                                        <br><small><?= $report['incident_type'] ?></small>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <i class="fas fa-user-md text-info"></i> <?= htmlspecialchars($report['responder_name']) ?>
                                                        <br><i class="fas fa-map-marker-alt text-danger"></i> <?= htmlspecialchars(substr($report['location_address'] ?? 'N/A', 0, 40)) ?>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Completed</span>
                                                        <br><small><i class="fas fa-file-alt"></i> <?= htmlspecialchars(substr($report['report_name'], 0, 20)) ?></small>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <button class="btn btn-sm btn-primary w-100" onclick="event.stopPropagation(); viewCompletedReport(<?= $report['report_id'] ?>)">
                                                            <i class="fas fa-file-alt"></i> View Full Report
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <div class="empty-state text-center py-5">
                                            <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                                            <h5 class="text-muted">No Completed Reports Yet</h5>
                                            <p class="text-muted">When responders complete incident reports, they will appear here.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create Responder Modal -->
    <div class="modal fade" id="createResponderModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="fas fa-user-plus me-2"></i> Add New Responder</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><form id="createResponderForm">
                <div class="mb-3"><label>Username *</label><input type="text" name="username" class="form-control" required></div>
                <div class="mb-3"><label>Full Name *</label><input type="text" name="fullname" class="form-control" required></div>
                <div class="row"><div class="col-md-6 mb-3"><label>Responder Type *</label><select name="responder_type" class="form-control" required><option value="medic">Medic / EMT</option><option value="firefighter">Firefighter</option><option value="rescuer">Rescuer / Diver</option><option value="driver">Driver</option></select></div>
                <div class="col-md-6 mb-3"><label>Badge Number *</label><input type="text" name="badge_number" class="form-control" placeholder="MDR-XXX" required></div></div>
                <div class="row"><div class="col-md-6 mb-3"><label>Email</label><input type="email" name="email" class="form-control"></div>
                <div class="col-md-6 mb-3"><label>Phone Number</label><input type="text" name="phone" class="form-control"></div></div>
                <div class="mb-3"><label>Default Password</label><input type="text" class="form-control" value="responder123" readonly><small class="text-muted">User can change after first login</small></div>
                <input type="hidden" name="password" value="responder123"></form></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="saveResponderBtn">Create Responder</button></div>
        </div></div>
    </div>
    
    <!-- Incident Modal - Styled exactly like responder dashboard -->
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
                    <button type="button" class="btn btn-success" id="printReportBtn" onclick="printReport()" style="display:none;"><i class="fas fa-print"></i> Print Report</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let map;
        let mapMarkers = [];
        let lastIncidentCount = 0;
        let currentReportHTML = '';
        
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        // Initialize Live Map
        function initLiveMap() {
            map = L.map('map').setView([15.6333, 121.3167], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { 
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);
            loadMapData();
            setInterval(loadMapData, 30000);
        }
        
        function loadMapData() {
            $.ajax({
                url: 'api/get_map_data.php',
                method: 'GET',
                success: function(data) {
                    const result = JSON.parse(data);
                    
                    if (lastIncidentCount > 0 && result.incidents.length > lastIncidentCount) {
                        $('#newIncidentNotification').fadeIn().delay(5000).fadeOut();
                    }
                    lastIncidentCount = result.incidents.length;
                    
                    mapMarkers.forEach(marker => map.removeLayer(marker));
                    mapMarkers = [];
                    
                    result.incidents.forEach(incident => {
                        if (incident.location_lat && incident.location_lng) {
                            let markerColor, markerIcon;
                            switch(incident.severity) {
                                case 'Dead': markerColor = '#1f2937'; markerIcon = 'skull'; break;
                                case 'Immediate': markerColor = '#ef4444'; markerIcon = 'exclamation-triangle'; break;
                                case 'Delayed': markerColor = '#f59e0b'; markerIcon = 'exclamation-circle'; break;
                                default: markerColor = '#10b981'; markerIcon = 'info-circle';
                            }
                            
                            const customIcon = L.divIcon({
                                html: `<div style="background-color: ${markerColor}; width: 18px; height: 18px; border-radius: 50%; border: 3px solid white; box-shadow: 0 0 6px rgba(0,0,0,0.5); position: relative;">
                                            <i class="fas fa-${markerIcon}" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 8px;"></i>
                                        </div>`,
                                iconSize: [18, 18],
                                className: 'custom-marker'
                            });
                            
                            const incidentTime = new Date(incident.created_at);
                            const now = new Date();
                            const minutesAgo = (now - incidentTime) / 60000;
                            const isNew = minutesAgo < 5;
                            
                            const marker = L.marker([incident.location_lat, incident.location_lng], { icon: customIcon })
                                .bindPopup(`
                                    <div style="min-width: 200px;">
                                        <strong style="color: ${markerColor};">${incident.tracking_id}</strong>
                                        ${isNew ? '<span class="badge bg-danger ms-2">NEW!</span>' : ''}
                                        <hr class="my-1">
                                        <i class="fas fa-tag"></i> ${incident.incident_type}<br>
                                        <i class="fas fa-exclamation-triangle"></i> Severity: <strong style="color: ${markerColor};">${incident.severity}</strong><br>
                                        <i class="fas fa-map-marker-alt"></i> ${(incident.location_address || 'Location provided').substring(0, 50)}<br>
                                        <i class="fas fa-clock"></i> Reported: ${new Date(incident.created_at).toLocaleString()}<br>
                                        <button class="btn btn-sm btn-primary mt-2" onclick="viewIncident(${incident.incident_id})">
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                    </div>
                                `);
                            
                            marker.addTo(map);
                            mapMarkers.push(marker);
                        }
                    });
                },
                error: function() {
                    console.log('Error loading map data');
                }
            });
        }
        
        function viewIncident(id) {
            $.ajax({
                url: 'api/get_incident_full.php',
                method: 'POST',
                data: { incident_id: id },
                success: function(data) {
                    const incident = JSON.parse(data);
                    let photosHtml = '';
                    if (incident.photos && incident.photos.length > 0) {
                        photosHtml = '<h6><i class="fas fa-camera"></i> Incident Photos:</h6><div class="row">';
                        incident.photos.forEach(photo => {
                            photosHtml += `<div class="col-md-2"><img src="uploads/incidents/${photo}" class="photo-thumb" onclick="window.open('uploads/incidents/${photo}')"></div>`;
                        });
                        photosHtml += '</div>';
                    }
                    
                    let chainHtml = `<div class="chain-timeline"><div class="chain-item"><strong>📋 Report Created</strong><br>By: ${incident.reporter_name || 'Anonymous'} (${incident.reporter_phone})<br>Time: ${new Date(incident.created_at).toLocaleString()}</div>`;
                    if (incident.taken_by_name) {
                        chainHtml += `<div class="chain-item"><strong>🚑 Taken by Responder</strong><br>By: ${incident.taken_by_name}<br>Time: ${new Date(incident.taken_at).toLocaleString()}</div>`;
                    }
                    if (incident.finished_by_name) {
                        chainHtml += `<div class="chain-item"><strong>✅ Completed by Responder</strong><br>By: ${incident.finished_by_name}<br>Time: ${new Date(incident.finished_at).toLocaleString()}</div>`;
                    }
                    chainHtml += '</div>';
                    
                    const html = `<div class="row"><div class="col-md-6"><h6><i class="fas fa-info-circle"></i> Incident Information</h6><p><strong>Tracking ID:</strong> ${incident.tracking_id}<br><strong>Type:</strong> ${incident.incident_type}<br><strong>Severity:</strong> <span class="badge bg-${incident.severity === 'Dead' ? 'dark' : (incident.severity === 'Immediate' ? 'danger' : (incident.severity === 'Delayed' ? 'warning' : 'info'))}">${incident.severity}</span><br><strong>Location:</strong> ${incident.location_address || 'N/A'}<br><strong>Description:</strong> ${incident.description}</p></div><div class="col-md-6"><div id="modalMap" style="height: 300px; border-radius: 10px;"></div></div></div><div class="row mt-3"><div class="col-12"><h6><i class="fas fa-link"></i> Chain of Custody</h6>${chainHtml}</div></div>${photosHtml}`;
                    $('#incidentModalBody').html(html);
                    $('#incidentModal').modal('show');
                    $('#printReportBtn').hide();
                    
                    if (incident.location_lat && incident.location_lng) {
                        setTimeout(() => {
                            const modalMap = L.map('modalMap').setView([incident.location_lat, incident.location_lng], 15);
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(modalMap);
                            L.marker([incident.location_lat, incident.location_lng]).addTo(modalMap).bindPopup('Incident Location').openPopup();
                        }, 100);
                    }
                }
            });
        }
        
        function viewCompletedReport(reportId) {
            Swal.fire({
                title: 'Loading Report...',
                text: 'Please wait while we load the complete incident report.',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
            
            $.ajax({
                url: 'admin_dashboard.php',
                method: 'POST',
                data: { action: 'get_completed_report', report_id: reportId },
                dataType: 'json',
                success: function(result) {
                    Swal.close();
                    if (result.success) {
                        try {
                            const report = result.report;
                            const reportData = JSON.parse(report.report_data);
                            displayCompletedReport(report, reportData);
                        } catch(e) {
                            console.error('Error parsing report data:', e);
                            Swal.fire('Error', 'Could not parse report data', 'error');
                        }
                    } else {
                        Swal.fire('Error', result.message || 'Could not load report details', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Could not connect to server', 'error');
                }
            });
        }
        
        function generateReportHTML(report, data) {
            // Build the report display HTML exactly like responder dashboard
            let html = `
                <div class="report-viewer">
                    <div class="header">
                        <div class="logo-row">
                            <div class="logo-box">
                                <img src="${data.logoLeft || 'bonga_logo.png'}" alt="Logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%23f0f0f0%22/%3E%3Ctext x=%2250%22 y=%2255%22 font-size=%2210%22 text-anchor=%22middle%22 fill=%22%23999%22%3EMDRRMO%3C/text%3E%3C/svg%3E'">
                            </div>
                            <div class="header-text">
                                <h3>Republic of the Philippines</h3>
                                <h2>Municipality of Bongabon</h2>
                                <h4>Municipal Disaster Risk Reduction and Management Office</h4>
                            </div>
                            <div class="logo-box">
                                <img src="${data.logoRight || 'bonga_logo2.png'}" alt="Logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22%3E%3Crect width=%22100%22 height=%22100%22 fill=%22%23f0f0f0%22/%3E%3Ctext x=%2250%22 y=%2255%22 font-size=%2210%22 text-anchor=%22middle%22 fill=%22%23999%22%3EMDRRMO%3C/text%3E%3C/svg%3E'">
                            </div>
                        </div>
                        <div class="report-title">INCIDENT REPORT</div>
                        <div style="background: #f8f9fa; padding: 10px; border-radius: 8px; margin-top: 10px;">
                            <strong>Tracking ID:</strong> ${escapeHtml(report.tracking_id)} &nbsp;|&nbsp;
                            <strong>Completed by:</strong> ${escapeHtml(report.responder_name)} &nbsp;|&nbsp;
                            <strong>Date:</strong> ${new Date(report.submitted_at).toLocaleString()}
                        </div>
                    </div>
                    
                    <div class="two-column-layout">
                        <div class="left-column">`;
            
            // INCIDENT DETAILS TABLE
            html += `
                <table class="form-table">
                     <th colspan="2" class="section-header">INCIDENT DETAILS</th>
                     <tr>
                        <td class="label-cell">Date of Incident:<br><span class="filipino-text">(Petsa)</span></td>
                        <td>${escapeHtml(data.incidentDate || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Time of Call:<br><span class="filipino-text">(Oras ng Pagtawag)</span></td>
                        <td>${escapeHtml(data.callTime || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Time of Incident:<br><span class="filipino-text">(Oras ng Insidente)</span></td>
                        <td>${escapeHtml(data.incidentTime || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">At Scene:<br><span class="filipino-text">(Oras sa Pinangyarihan)</span></td>
                        <td>${escapeHtml(data.atScene || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Incident/Transfer/Purpose:<br><span class="filipino-text">(Insidente/Paglipat/Layunin)</span></td>
                        <td>${escapeHtml(data.incidentPurpose || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Depart Scene:<br><span class="filipino-text">(Oras ng Pag-alis)</span></td>
                        <td>${escapeHtml(data.departScene || 'N/A')}<br><strong>At Hospital:</strong> ${escapeHtml(data.atHospital || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Place of Incident:<br><span class="filipino-text">(Lugar ng Insidente)</span></td>
                        <td>${escapeHtml(data.placeIncident || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Handover:<br><span class="filipino-text">(Pagsalin)</span></td>
                        <td>${escapeHtml(data.handover || 'N/A')}<br><strong>Back to Base:</strong> ${escapeHtml(data.backToBase || 'N/A')}</td>
                     </tr>
                  </table>`;
            
            // PATIENT'S INFORMATION TABLE
            html += `
                <table class="form-table">
                     <th colspan="2" class="section-header">PATIENT'S INFORMATION</th>
                     <tr>
                        <td class="label-cell">Name:<br><span class="filipino-text">(Pangalan)</span></td>
                        <td>${escapeHtml(data.patientName || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">In Case of Emergency Contact Person:<br><span class="filipino-text">(Contact Person)</span></td>
                        <td>${escapeHtml(data.emergencyContact || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Age:<br><span class="filipino-text">(Edad)</span></td>
                        <td>${escapeHtml(data.patientAge || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Gender:<br><span class="filipino-text">(Kasarian)</span></td>
                        <td>${escapeHtml(data.patientGender || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Address:<br><span class="filipino-text">(Tirahan)</span></td>
                        <td>${escapeHtml(data.patientAddress || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Contact Number:<br><span class="filipino-text">(Numero)</span></td>
                        <td>${escapeHtml(data.emergencyNumber || 'N/A')}</td>
                     </tr>`;
            
            // Signatures
            if (data.patientSig && data.patientSig !== '') {
                html += `<tr><td class="label-cell">Signature:</td><td><div class="signature-preview"><img src="${data.patientSig}" alt="Patient Signature"></div></td></tr>`;
            }
            if (data.emergencySig && data.emergencySig !== '') {
                html += `<tr><td class="label-cell">Emergency Signature:</td><td><div class="signature-preview"><img src="${data.emergencySig}" alt="Emergency Signature"></div></td></tr>`;
            }
            html += `</table>`;
            
            // INJURY MAP
            if (data.bodyImage && data.bodyImage !== '') {
                html += `
                    <table class="form-table">
                         <th colspan="2" class="section-header">INJURY MAP - Highlight affected areas<br><span class="filipino-text">(I-highlight ang mga pinsala)</span></th>
                         <tr><td colspan="2"><div class="injury-map-preview"><img src="${data.bodyImage}" alt="Injury Map"></div></td></tr>
                    </table>`;
            }
            
            // CHIEF COMPLAINT
            html += `
                <table class="form-table">
                     <th colspan="2" class="section-header">INJURIES / ILLNESS / CHIEF COMPLAINT<br><span class="filipino-text">(Mga Pinsala/Sakit/Pangunahing Daing)</span></th>
                     <tr><td colspan="2">${escapeHtml(data.chiefComplaint || 'N/A')}</td></tr>
                </table>`;
            
            html += `</div><div class="right-column">`;
            
            // VITAL SIGNS TABLE
            html += `
                <table class="form-table">
                     <th colspan="2" class="section-header">PATIENT'S SAMPLE HISTORY AND VITAL SIGNS</th>
                     <tr>
                        <td class="label-cell">Signs & Symptoms:<br><span class="filipino-text">(Palatandaan at Sintomas)</span></td>
                        <td>${escapeHtml(data.symptoms || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Blood Pressure:</td>
                        <td>${escapeHtml(data.bp || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Allergy:<br><span class="filipino-text">(Alerhiya)</span></td>
                        <td>${escapeHtml(data.allergy || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Pulse Rate:</td>
                        <td>${escapeHtml(data.pulse || 'N/A')} bpm</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Medications:<br><span class="filipino-text">(Medikasyon)</span></td>
                        <td>${escapeHtml(data.medications || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Respiratory Rate:</td>
                        <td>${escapeHtml(data.respiratory || 'N/A')} breaths/min</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Past Medical History:<br><span class="filipino-text">(Nakaraang Medikal na Kasaysayan)</span></td>
                        <td>${escapeHtml(data.pastHistory || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Body Temperature:</td>
                        <td>${escapeHtml(data.temperature || 'N/A')} °C</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Last Intake/Output:<br><span class="filipino-text">(Huling Kinain/Nilabas)</span></td>
                        <td>${escapeHtml(data.lastIntake || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Events Leading to Injury:<br><span class="filipino-text">(Dahilan ng Pagkakapinsala)</span></td>
                        <td>${escapeHtml(data.events || 'N/A')}</td>
                     </tr>
                </table>`;
            
            // MANAGEMENT / INTERVENTION
            html += `
                <table class="form-table">
                     <th colspan="2" class="section-header">MANAGEMENT / INTERVENTION</th>
                     <tr><td class="label-cell">Actions Taken:<br><span class="filipino-text">(Pangunang Lunas na Ginawa)</span></td><td>${escapeHtml(data.actionsGiven || 'N/A')}</td></tr>
                </table>`;
            
            // REFUSAL SECTION
            html += `
                <table class="form-table">
                     <th colspan="2" class="section-header">REFUSAL OF TREATMENT AND/OR TRANSPORT<br><span class="filipino-text">(Pagtanggi sa Pangunang Lunas/Pagdala sa Pagamutan)</span></th>
                     <tr><td colspan="2" class="refusal-text">Ako, na lumagda sa ibaba, ay maayos na napaliwanagan ukol sa aking kondisyon at mga serbisyong medikal na aking kailangan ngunit dahil sa aking personal na dahilan aking tinanggihan ang paglipat o paggamot sa akin. Dahil dito anuman ang maging resulta ng aking desisyon ay walang sinuman sa mga kawani ng Bongabon MDRRMO Rescue Team ang may pananagutan dahil sa aking pagtanggi.</td></tr>
                     <tr>
                        <td class="label-cell">Witness:<br><span class="filipino-text">(Saksi)</span></td>
                        <td>${escapeHtml(data.refusalWitness || 'N/A')}</td>
                     </tr>
                     <tr>
                        <td class="label-cell">Date Signed:<br><span class="filipino-text">(Petsa)</span></td>
                        <td>${escapeHtml(data.refusalDate || 'N/A')}</td>
                     </tr>
                </table>`;
            
            // PROVIDER AND RECEIVING FACILITIES
            html += `
                <table class="form-table">
                     <tr>
                        <th class="section-header">PROVIDER'S INFORMATION<br><span class="filipino-text">(Tagapagbigay ng Pangunang Lunas)</span></th>
                        <th class="section-header">RECEIVING FACILITIES<br><span class="filipino-text">(Pagamutang Tumanggap)</span></th>
                     </tr>
                     <tr>
                        <td class="label-cell">
                            Crew 1: ${escapeHtml(data.crew1 || 'N/A')}<br>
                            Crew 2: ${escapeHtml(data.crew2 || 'N/A')}<br>
                            Crew 3: ${escapeHtml(data.crew3 || 'N/A')}<br>
                            Crew 4: ${escapeHtml(data.crew4 || 'N/A')}<br>
                            Crew 5: ${escapeHtml(data.crew5 || 'N/A')}<br>
                            Driver: ${escapeHtml(data.driver || 'N/A')}<br>
                            Vehicle Used: ${escapeHtml(data.vehicle || 'N/A')}
                        </td>
                        <td class="label-cell">
                            Place / Hospital:<br>${escapeHtml(data.receivingPlace || 'N/A')}<br>
                            Receiving Person:<br>${escapeHtml(data.receivingPerson || 'N/A')}<br>
                            Name & Signature:<br>
                            ${escapeHtml(data.receivingSignName || 'N/A')}
                            ${data.providerSig && data.providerSig !== '' ? `<div class="signature-preview"><img src="${data.providerSig}" alt="Provider Signature"></div>` : ''}
                        </td>
                     </tr>
                </table>`;
            
            html += `</div></div>`;
            
            // INCIDENT PHOTOGRAPHS
            if (data.incidentImages && data.incidentImages !== '[]') {
                try {
                    const images = JSON.parse(data.incidentImages);
                    if (images.length > 0) {
                        html += `
                            <div style="margin-top: 20px;">
                                <div style="font-size:14px; font-weight:bold; text-align:center; padding:10px; border:1px solid #000; margin:15px 0 10px 0;">
                                    <i class="fas fa-camera"></i> INCIDENT PHOTOGRAPHS <span class="filipino-text">(Mga Larawan ng Insidente)</span>
                                </div>
                                <div class="report-images-gallery">`;
                        images.forEach((img, idx) => {
                            html += `
                                <div class="report-image-item">
                                    <img src="${img}" alt="Incident Photo ${idx + 1}" onclick="window.open('${img}')">
                                    <div class="image-caption">Photo ${idx + 1}</div>
                                </div>`;
                        });
                        html += `</div></div>`;
                    }
                } catch(e) {}
            }
            
            html += `</div>`;
            
            return html;
        }
        
        function displayCompletedReport(report, data) {
            // Generate the report HTML and display in modal
            currentReportHTML = generateReportHTML(report, data);
            $('#incidentModalBody').html(currentReportHTML);
            $('#incidentModal').modal('show');
            $('#printReportBtn').show();
        }
        
        function printReport() {
            if (!currentReportHTML) {
                Swal.fire('Error', 'No report to print', 'error');
                return;
            }
            
            // Create a new window for printing
            const printWindow = window.open('', '_blank', 'width=1000,height=800,toolbar=yes,scrollbars=yes,resizable=yes');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Incident Report - MDRRMO Bongabon</title>
                    <meta charset="UTF-8">
                    <style>
                        * {
                            margin: 0;
                            padding: 0;
                            box-sizing: border-box;
                        }
                        body {
                            font-family: 'Times New Roman', 'Arial', serif;
                            background: white;
                            padding: 20px;
                        }
                        .report-viewer {
                            max-width: 1200px;
                            margin: 0 auto;
                            background: white;
                        }
                        .header {
                            text-align: center;
                            margin-bottom: 15px;
                        }
                        .logo-row {
                            display: flex;
                            justify-content: center;
                            align-items: center;
                            gap: 10px;
                            flex-wrap: wrap;
                            margin-bottom: 15px;
                        }
                        .logo-box {
                            width: 70px;
                            height: 70px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        }
                        .logo-box img {
                            max-width: 100%;
                            max-height: 100%;
                            object-fit: contain;
                        }
                        .header-text h3, .header-text h2, .header-text h4 {
                            margin: 2px 0;
                        }
                        .header-text h3 { font-size: 14px; }
                        .header-text h2 { font-size: 18px; font-weight: bold; }
                        .header-text h4 { font-size: 12px; color: #e67e22; }
                        .report-title {
                            font-size: 18px;
                            font-weight: bold;
                            text-align: center;
                            margin: 10px 0;
                        }
                        .form-table {
                            width: 100%;
                            border-collapse: collapse;
                            margin: 8px 0;
                            font-size: 11px;
                        }
                        .form-table td, .form-table th {
                            border: 1px solid #000;
                            padding: 8px 6px;
                            vertical-align: top;
                        }
                        .form-table th {
                            background: #f8f9fa;
                            font-weight: bold;
                            text-align: center;
                        }
                        .label-cell {
                            font-weight: bold;
                            width: 40%;
                            background: #fef9e6;
                        }
                        .section-header {
                            background: #e67e22;
                            color: white;
                            font-weight: bold;
                            text-align: center;
                            font-size: 12px;
                        }
                        .filipino-text {
                            font-size: 9px;
                            color: #444;
                            font-style: italic;
                            display: inline-block;
                            margin-left: 4px;
                        }
                        .signature-preview {
                            max-width: 250px;
                            border: 1px solid #ddd;
                            border-radius: 8px;
                            padding: 10px;
                            margin-top: 5px;
                        }
                        .signature-preview img {
                            max-width: 100%;
                            height: auto;
                        }
                        .injury-map-preview {
                            max-width: 360px;
                            border: 2px solid #333;
                            border-radius: 8px;
                            overflow: hidden;
                            margin-top: 10px;
                        }
                        .injury-map-preview img {
                            width: 100%;
                            height: auto;
                        }
                        .report-images-gallery {
                            display: flex;
                            flex-wrap: wrap;
                            gap: 15px;
                            margin-top: 15px;
                        }
                        .report-image-item {
                            width: 250px;
                            border: 1px solid #ddd;
                            border-radius: 8px;
                            overflow: hidden;
                        }
                        .report-image-item img {
                            width: 100%;
                            height: auto;
                        }
                        .report-image-item .image-caption {
                            padding: 8px;
                            font-size: 11px;
                            background: #f8f9fa;
                            text-align: center;
                        }
                        .two-column-layout {
                            display: flex;
                            gap: 15px;
                            flex-wrap: wrap;
                        }
                        .left-column, .right-column {
                            flex: 1;
                            min-width: 280px;
                        }
                        .refusal-text {
                            font-size: 10px;
                            line-height: 1.3;
                            text-align: justify;
                            background: #fef9e6;
                            padding: 8px;
                            border-radius: 6px;
                        }
                        @media print {
                            body {
                                padding: 0;
                                margin: 0;
                            }
                            @page {
                                size: A4;
                                margin: 1.5cm;
                            }
                            .form-table {
                                page-break-inside: avoid;
                            }
                            .report-image-item {
                                page-break-inside: avoid;
                            }
                        }
                    </style>
                </head>
                <body>
                    ${currentReportHTML}
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(function() {
                                window.close();
                            }, 500);
                        };
                    <\/script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }
        
        function switchToCompletedReports() {
            $('#completed-reports-tab').tab('show');
        }
        
        // Tab navigation
        $('#respondersTabLink').click(function(e) { e.preventDefault(); $('#responders-tab').tab('show'); });
        $('#mapTabLink').click(function(e) { e.preventDefault(); $('#map-tab').tab('show'); setTimeout(() => map?.invalidateSize(), 200); });
        $('#incidentsTabLink').click(function(e) { e.preventDefault(); $('#incidents-tab').tab('show'); });
        $('#completedReportsTabLink').click(function(e) { e.preventDefault(); $('#completed-reports-tab').tab('show'); });
        
        // Print completed reports summary
        $('#printCompletedReportsBtn').click(function() {
            window.print();
        });
        
        // Responder management
        $('#saveResponderBtn').click(function() {
            const formData = $('#createResponderForm').serialize() + '&action=create_responder';
            $.post('admin_dashboard.php', formData, function(response) {
                const data = JSON.parse(response);
                if (data.success) Swal.fire('Success', data.message, 'success').then(() => location.reload());
                else Swal.fire('Error', data.message, 'error');
            });
        });
        
        $(document).on('click', '.toggle-status', function() {
            const id = $(this).data('id'), status = $(this).data('status');
            Swal.fire({ title: 'Change Status?', text: `Are you sure you want to ${status ? 'deactivate' : 'activate'} this responder?`, icon: 'warning', showCancelButton: true }).then((result) => {
                if (result.isConfirmed) $.post('admin_dashboard.php', { action: 'toggle_status', user_id: id, current_status: status }, function() { location.reload(); });
            });
        });
        
        $(document).on('click', '.reset-password', function() {
            const id = $(this).data('id');
            Swal.fire({ title: 'Reset Password?', text: 'New password will be: responder123', icon: 'question', showCancelButton: true }).then((result) => {
                if (result.isConfirmed) $.post('admin_dashboard.php', { action: 'reset_password', user_id: id }, function() { Swal.fire('Success', 'Password reset to responder123', 'success'); });
            });
        });
        
        $(document).on('click', '.delete-responder', function() {
            const id = $(this).data('id');
            Swal.fire({ title: 'Delete Responder?', text: 'This action cannot be undone', icon: 'error', showCancelButton: true }).then((result) => {
                if (result.isConfirmed) $.post('admin_dashboard.php', { action: 'delete_responder', user_id: id }, function() { location.reload(); });
            });
        });
        
        $('#newIncidentNotification').click(function() {
            loadMapData();
            $(this).fadeOut();
        });
        
        $(document).ready(function() { 
            initLiveMap();
        });
    </script>
</body>
</html>