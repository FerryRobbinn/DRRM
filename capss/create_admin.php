<?php
// create_admin.php
require_once 'config/db_connect.php';

// Admin credentials
$username = 'admin';
$password = 'admin123'; // Change to your preferred password
$fullname = 'System Administrator';
$email = 'admin@mdrrmo.gov.ph';
$phone = '09123456789';

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Check if users table exists
$table_check = $conn->query("SHOW TABLES LIKE 'tbl_users'");
if ($table_check->num_rows == 0) {
    echo "❌ Table 'tbl_users' doesn't exist! Please run the database setup first.<br>";
    exit;
}

// Check if admin exists
$result = $conn->query("SELECT * FROM tbl_users WHERE username = '$username' OR role = 'admin'");

if ($result->num_rows > 0) {
    // Update existing admin
    $stmt = $conn->prepare("UPDATE tbl_users SET password = ?, fullname = ?, email = ?, phone = ?, is_active = 1 WHERE username = ? OR role = 'admin'");
    $stmt->bind_param("sssss", $hashed_password, $fullname, $email, $phone, $username);
    
    if ($stmt->execute()) {
        echo "✅ Admin account updated successfully!<br>";
        echo "==============================<br>";
        echo "Username: admin<br>";
        echo "Password: $password<br>";
        echo "==============================<br>";
        echo "You can now login at login.php";
    } else {
        echo "❌ Error: " . $conn->error;
    }
} else {
    // Create new admin
    $stmt = $conn->prepare("INSERT INTO tbl_users (username, password, fullname, role, email, phone, is_active) VALUES (?, ?, ?, 'admin', ?, ?, 1)");
    $stmt->bind_param("sssss", $username, $hashed_password, $fullname, $email, $phone);
    
    if ($stmt->execute()) {
        echo "✅ Admin account created successfully!<br>";
        echo "==============================<br>";
        echo "Username: admin<br>";
        echo "Password: $password<br>";
        echo "==============================<br>";
        echo "You can now login at login.php";
    } else {
        echo "❌ Error: " . $conn->error;
    }
}

$conn->close();
?>