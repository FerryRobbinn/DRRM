<?php
// fix_admin.php - Create or fix admin account
require_once 'config/db_connect.php';

// Admin credentials
$username = 'admin';
$password = 'admin123';
$fullname = 'MDRRMO Administrator';
$email = 'admin@mdrrmo.gov.ph';
$phone = '09123456789';

// Hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Fixing Admin Account</h2>";

// Check if admin exists
$check = $conn->prepare("SELECT user_id, password FROM tbl_users WHERE username = ? OR role = 'admin'");
$check->bind_param("s", $username);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    $admin = $result->fetch_assoc();
    
    // Update existing admin
    $stmt = $conn->prepare("UPDATE tbl_users SET password = ?, fullname = ?, email = ?, phone = ?, is_active = 1, role = 'admin' WHERE user_id = ?");
    $stmt->bind_param("ssssi", $hashed_password, $fullname, $email, $phone, $admin['user_id']);
    
    if ($stmt->execute()) {
        echo "<p style='color:green'>✅ Admin account UPDATED successfully!</p>";
    } else {
        echo "<p style='color:red'>❌ Error updating: " . $conn->error . "</p>";
    }
} else {
    // Create new admin
    $stmt = $conn->prepare("INSERT INTO tbl_users (username, password, fullname, role, email, phone, is_active) VALUES (?, ?, ?, 'admin', ?, ?, 1)");
    $stmt->bind_param("sssss", $username, $hashed_password, $fullname, $email, $phone);
    
    if ($stmt->execute()) {
        echo "<p style='color:green'>✅ Admin account CREATED successfully!</p>";
    } else {
        echo "<p style='color:red'>❌ Error creating: " . $conn->error . "</p>";
    }
}

echo "<hr>";
echo "<h3>Login Credentials:</h3>";
echo "<ul>";
echo "<li><strong>Username:</strong> admin</li>";
echo "<li><strong>Password:</strong> admin123</li>";
echo "</ul>";

echo "<br><a href='login.php' class='btn btn-primary'>Go to Login Page</a>";

$conn->close();
?>