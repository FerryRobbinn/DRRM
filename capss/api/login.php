<?php
session_start();
include '../db.php'; // Ensure db.php is in your main folder

$email = $_POST['email'] ?? '';
$pass = $_POST['password'] ?? '';

// 1. Look for the user and join with roles to see if they are 'admin' or 'responder'
$stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// 2. Verify the password hash
if ($user && password_verify($pass, $user['password_hash'])) {
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['role'] = $user['role_name'];
    $_SESSION['fullname'] = $user['fullname'];
    
    echo json_encode(["status" => "success", "role" => $user['role_name']]);
} else {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid credentials"]);
}
?>