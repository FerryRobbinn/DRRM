<?php
include '../db.php';
// Role 2 is 'responder' based on our SQL seed
$sql = "SELECT user_id, fullname, unit_identifier FROM users WHERE role_id = 2";
$result = $conn->query($sql);
echo json_encode($result->fetch_all(MYSQLI_ASSOC));
?>