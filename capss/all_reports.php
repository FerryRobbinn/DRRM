<?php
// all_reports.php
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Get all reports with filters
$where = "1=1";
$params = [];
$types = "";

if (isset($_GET['status']) && $_GET['status'] != '') {
    $where .= " AND i.status = ?";
    $params[] = $_GET['status'];
    $types .= "s";
}

if (isset($_GET['type']) && $_GET['type'] != '') {
    $where .= " AND i.incident_type = ?";
    $params[] = $_GET['type'];
    $types .= "s";
}

if (isset($_GET['search']) && $_GET['search'] != '') {
    $where .= " AND (i.report_number LIKE ? OR i.location_address LIKE ? OR i.caller_name LIKE ?)";
    $search = "%{$_GET['search']}%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= "sss";
}

$query = "SELECT i.*, u.fullname as responder_name 
          FROM tbl_incidents i 
          LEFT JOIN tbl_users u ON i.responder_id = u.user_id 
          WHERE $where 
          ORDER BY i.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reports = $stmt->get_result();

// Get unique incident types for filter
$types_list = $conn->query("SELECT DISTINCT incident_type FROM tbl_incidents WHERE incident_type IS NOT NULL");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Reports - MDRRMO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body {
            background: #f4f6f9;
            font-family: 'Poppins', sans-serif;
        }
        .sidebar {
            background: #1e2a36;
            min-height: 100vh;
            color: white;
        }
        .sidebar a {
            color: #ecf0f1;
            text-decoration: none;
            padding: 12px 20px;
            display: block;
            transition: 0.2s;
        }
        .sidebar a:hover, .sidebar a.active {
            background: #e67e22;
            color: white;
        }
        .sidebar i {
            margin-right: 10px;
            width: 20px;
        }
        .main-content {
            padding: 20px;
        }
        .filter-bar {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-2 p-0 sidebar">
                <div class="p-4 text-center border-bottom border-secondary">
                    <i class="fas fa-ambulance fa-3x mb-2"></i>
                    <h5>MDRRMO Bongabon</h5>
                    <small class="text-muted">Admin Panel</small>
                </div>
                <nav class="mt-3">
                    <a href="admin_dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="manage_responders.php">
                        <i class="fas fa-users"></i> Manage Responders
                    </a>
                    <a href="all_reports.php" class="active">
                        <i class="fas fa-clipboard-list"></i> All Reports
                    </a>
                    <a href="reports_analytics.php">
                        <i class="fas fa-chart-line"></i> Analytics
                    </a>
                    <a href="settings.php">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                    <a href="logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </nav>
            </div>
            
            <div class="col-md-10 main-content">
                <h3 class="mb-4"><i class="fas fa-clipboard-list me-2"></i> All Incident Reports</h3>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All</option>
                                <option value="pending" <?= isset($_GET['status']) && $_GET['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="dispatched" <?= isset($_GET['status']) && $_GET['status'] == 'dispatched' ? 'selected' : '' ?>>Dispatched</option>
                                <option value="on_scene" <?= isset($_GET['status']) && $_GET['status'] == 'on_scene' ? 'selected' : '' ?>>On Scene</option>
                                <option value="resolved" <?= isset($_GET['status']) && $_GET['status'] == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                <option value="cancelled" <?= isset($_GET['status']) && $_GET['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Incident Type</label>
                            <select name="type" class="form-select">
                                <option value="">All</option>
                                <?php while($type = $types_list->fetch_assoc()): ?>
                                    <option value="<?= $type['incident_type'] ?>" <?= isset($_GET['type']) && $_GET['type'] == $type['incident_type'] ? 'selected' : '' ?>>
                                        <?= $type['incident_type'] ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Report #, Location, Caller..." value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-mdrrmo w-100">
                                <i class="fas fa-search me-2"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Reports Table -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="reportsTable">
                                <thead>
                                    <tr>
                                        <th>Report #</th>
                                        <th>Type</th>
                                        <th>Severity</th>
                                        <th>Location</th>
                                        <th>Caller</th>
                                        <th>Responder</th>
                                        <th>Status</th>
                                        <th>Date/Time</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = $reports->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?= $row['report_number'] ?></strong></td>
                                        <td><?= $row['incident_type'] ?></td>
                                        <td>
                                            <span class="badge bg-<?= $row['severity'] == 'Critical' ? 'danger' : ($row['severity'] == 'High' ? 'warning' : 'info') ?>">
                                                <?= $row['severity'] ?>
                                            </span>
                                        </td>
                                        <td><?= substr($row['location_address'], 0, 40) ?>...</td>
                                        <td><?= $row['caller_name'] ?><br><small><?= $row['caller_phone'] ?></small></td>
                                        <td><?= $row['responder_name'] ?? 'Unassigned' ?></td>
                                        <td>
                                            <span class="badge bg-<?= $row['status'] == 'resolved' ? 'success' : ($row['status'] == 'pending' ? 'warning' : 'primary') ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y H:i', strtotime($row['created_at'])) ?></td>
                                        <td>
                                            <a href="view_report.php?id=<?= $row['incident_id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#reportsTable').DataTable({
                pageLength: 25,
                order: [[7, 'desc']],
                responsive: true
            });
        });
    </script>
</body>
</html>