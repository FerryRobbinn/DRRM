<?php
// api/get_incident_details.php
session_start();
require_once '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$incident_id = $_POST['incident_id'];

$stmt = $conn->prepare("SELECT * FROM tbl_incidents WHERE incident_id = ?");
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$incident = $stmt->get_result()->fetch_assoc();

// Get photos
$photos = $conn->query("SELECT photo_path FROM tbl_incident_photos WHERE incident_id = $incident_id");
$photo_paths = [];
while ($photo = $photos->fetch_assoc()) {
    $photo_paths[] = $photo['photo_path'];
}

$incident['photos'] = $photo_paths;
echo json_encode($incident);
?>