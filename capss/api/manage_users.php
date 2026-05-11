<?php
session_start();
include '../db.php';

// SECURITY: If not admin, block the request
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die(json_encode(["status" => "error", "message" => "Unauthorized access"]));
}

$action = $_GET['action'] ?? '';

// --- ACTION: ADD NEW RESPONDER ---
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $unit = $_POST['unit'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role_id = 2; // ID for 'responder' in our SQL

    $stmt = $conn->prepare("INSERT INTO users (fullname, email, password_hash, role_id, unit_identifier) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssis", $fullname, $email, $password, $role_id, $unit);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Email already exists"]);
    }
}

// --- ACTION: DELETE RESPONDER ---
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = $data['user_id'];

    // Prevent Admin from deleting themselves
    if ($user_id == $_SESSION['user_id']) {
        die(json_encode(["status" => "error", "message" => "Cannot delete your own account"]));
    }

    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role_id = 2");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error"]);
    }
}
?>