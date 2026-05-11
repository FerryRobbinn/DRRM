<?php
// register_responder.php
// This can be used by admin to create responder accounts
session_start();
require_once 'config/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    $stmt = $conn->prepare("INSERT INTO tbl_users (username, password, fullname, email, phone, role) VALUES (?, ?, ?, ?, ?, 'responder')");
    $stmt->bind_param("sssss", $username, $password, $fullname, $email, $phone);
    
    if ($stmt->execute()) {
        $success = "Responder account created successfully!";
    } else {
        $error = "Error: " . $conn->error;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Responder - MDRRMO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4>Create Responder Account</h4>
                    </div>
                    <div class="card-body">
                        <?php if(isset($success)): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>
                        <?php if(isset($error)): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label>Full Name</label>
                                <input type="text" name="fullname" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Username</label>
                                <input type="text" name="username" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label>Phone Number</label>
                                <input type="text" name="phone" class="form-control">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Create Account</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>