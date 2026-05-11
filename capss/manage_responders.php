<?php
// manage_responders.php
session_start();
require_once 'config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// Handle Add Responder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_responder'])) {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    $stmt = $conn->prepare("INSERT INTO tbl_users (username, password, fullname, email, phone, role) VALUES (?, ?, ?, ?, ?, 'responder')");
    $stmt->bind_param("sssss", $username, $password, $fullname, $email, $phone);
    
    if ($stmt->execute()) {
        $success = "Responder added successfully!";
    } else {
        $error = "Error adding responder: " . $conn->error;
    }
}

// Handle Toggle Status
if (isset($_POST['toggle_status'])) {
    $user_id = $_POST['user_id'];
    $current = $_POST['current_status'];
    $new_status = $current == 1 ? 0 : 1;
    
    $stmt = $conn->prepare("UPDATE tbl_users SET is_active = ? WHERE user_id = ?");
    $stmt->bind_param("ii", $new_status, $user_id);
    $stmt->execute();
    echo "success";
    exit;
}

// Handle Delete
if (isset($_POST['delete_responder'])) {
    $user_id = $_POST['user_id'];
    $stmt = $conn->prepare("DELETE FROM tbl_users WHERE user_id = ? AND role = 'responder'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    echo "success";
    exit;
}

// Get all responders
$responders = $conn->query("SELECT * FROM tbl_users WHERE role = 'responder' ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Responders - MDRRMO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        .btn-mdrrmo {
            background: #e67e22;
            color: white;
            border: none;
        }
        .btn-mdrrmo:hover {
            background: #d35400;
            color: white;
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
                    <a href="manage_responders.php" class="active">
                        <i class="fas fa-users"></i> Manage Responders
                    </a>
                    <a href="all_reports.php">
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
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3><i class="fas fa-users me-2"></i> Manage Responders</h3>
                    <button class="btn btn-mdrrmo" data-bs-toggle="modal" data-bs-target="#addResponderModal">
                        <i class="fas fa-plus me-2"></i> Add New Responder
                    </button>
                </div>
                
                <?php if(isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= $success ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0">Responder List</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="responderTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Full Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($row = $responders->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?= str_pad($row['user_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                        <td><?= htmlspecialchars($row['fullname']) ?></td>
                                        <td><?= htmlspecialchars($row['username']) ?></td>
                                        <td><?= htmlspecialchars($row['email']) ?></td>
                                        <td><?= htmlspecialchars($row['phone']) ?></td>
                                        <td>
                                            <button class="btn btn-sm <?= $row['is_active'] ? 'btn-success' : 'btn-secondary' ?> toggle-status" 
                                                    data-id="<?= $row['user_id'] ?>" 
                                                    data-status="<?= $row['is_active'] ?>">
                                                <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
                                            </button>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-danger delete-responder" data-id="<?= $row['user_id'] ?>" data-name="<?= htmlspecialchars($row['fullname']) ?>">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
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
    
    <!-- Add Responder Modal -->
    <div class="modal fade" id="addResponderModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-mdrrmo text-white">
                    <h5 class="modal-title">Add New Responder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="fullname" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_responder" class="btn btn-mdrrmo">Add Responder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Status
        $(document).on('click', '.toggle-status', function() {
            const btn = $(this);
            const id = btn.data('id');
            const currentStatus = btn.data('status');
            
            Swal.fire({
                title: 'Change Status?',
                text: `Are you sure you want to ${currentStatus ? 'deactivate' : 'activate'} this responder?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, proceed'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('manage_responders.php', {
                        toggle_status: 1,
                        user_id: id,
                        current_status: currentStatus
                    }, function(res) {
                        if (res.trim() === 'success') {
                            Swal.fire('Success', 'Status updated!', 'success').then(() => {
                                location.reload();
                            });
                        }
                    });
                }
            });
        });
        
        // Delete Responder
        $(document).on('click', '.delete-responder', function() {
            const id = $(this).data('id');
            const name = $(this).data('name');
            
            Swal.fire({
                title: 'Delete Responder?',
                html: `Are you sure you want to delete <strong>${name}</strong>?<br>This action cannot be undone.`,
                icon: 'error',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete permanently'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('manage_responders.php', {
                        delete_responder: 1,
                        user_id: id
                    }, function(res) {
                        if (res.trim() === 'success') {
                            Swal.fire('Deleted!', 'Responder has been removed.', 'success').then(() => {
                                location.reload();
                            });
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>