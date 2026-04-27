<?php
session_start();
include '../db.php';

if(!isset($_SESSION['user_id'])) {
    die(json_encode([]));
}

$my_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT i.*, t.type_name FROM incidents i JOIN incident_types t ON i.type_id = t.type_id WHERE i.assigned_to = ? AND i.current_status != 'resolved'");
$stmt->bind_param("i", $my_id);
$stmt->execute();

echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
?>