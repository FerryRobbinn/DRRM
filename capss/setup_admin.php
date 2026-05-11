<?php
// setup_admin.php - Run this once to create admin account
require_once 'config/db_connect.php';

// Your desired admin credentials
$username = 'admin';
$password = 'admin123'; // Change this to your desired password
$fullname = 'Administrator';
$email = 'admin@mdrrmo.gov.ph';
$phone = '09123456789';

// Hash the password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Check if admin already exists
$check = $conn->query("SELECT * FROM tbl_users WHERE username = '$username'");
if ($check->num_rows > 0) {
    // Update existing admin
    $stmt = $conn->prepare("UPDATE tbl_users SET password = ?, fullname = ?, email = ?, phone = ?, is_active = 1 WHERE username = ?");
    $stmt->bind_param("sssss", $hashed_password, $fullname, $email, $phone, $username);
    
    if ($stmt->execute()) {
        echo "✅ Admin password updated successfully!<br>";
        echo "Username: admin<br>";
        echo "Password: $password<br>";
    } else {
        echo "❌ Error updating admin: " . $conn->error;
    }
} else {
    // Create new admin
    $stmt = $conn->prepare("INSERT INTO tbl_users (username, password, fullname, role, email, phone, is_active) VALUES (?, ?, ?, 'admin', ?, ?, 1)");
    $stmt->bind_param("sssss", $username, $hashed_password, $fullname, $email, $phone);
    
    if ($stmt->execute()) {
        echo "✅ Admin account created successfully!<br>";
        echo "Username: admin<br>";
        echo "Password: $password<br>";
    } else {
        echo "❌ Error creating admin: " . $conn->error;
    }
}

$conn->close();
?>