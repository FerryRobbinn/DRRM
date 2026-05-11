<?php
// api/get_incident_full.php - Get full incident details including photos
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized - Please login again']);
    exit;
}

// Get incident_id from POST or GET
$incident_id = 0;
if (isset($_POST['incident_id'])) {
    $incident_id = intval($_POST['incident_id']);
} elseif (isset($_GET['incident_id'])) {
    $incident_id = intval($_GET['incident_id']);
}

if (!$incident_id) {
    echo json_encode(['error' => 'Incident ID required']);
    exit;
}

// Get incident details
$stmt = $conn->prepare("
    SELECT i.*, 
           r1.fullname as taken_by_name,
           r2.fullname as finished_by_name
    FROM tbl_incidents i 
    LEFT JOIN tbl_users r1 ON i.taken_by_responder_id = r1.user_id
    LEFT JOIN tbl_users r2 ON i.finished_by_responder_id = r2.user_id
    WHERE i.incident_id = ?
");
$stmt->bind_param("i", $incident_id);
$stmt->execute();
$result = $stmt->get_result();
$incident = $result->fetch_assoc();

if (!$incident) {
    echo json_encode(['error' => 'Incident not found']);
    exit;
}

// Get photos
$photos = [];
$photo_stmt = $conn->prepare("SELECT photo_id, photo_path, uploaded_at FROM tbl_incident_photos WHERE incident_id = ? ORDER BY uploaded_at DESC");
$photo_stmt->bind_param("i", $incident_id);
$photo_stmt->execute();
$photo_result = $photo_stmt->get_result();

while ($photo = $photo_result->fetch_assoc()) {
    $photos[] = [
        'id' => $photo['photo_id'],
        'url' => $photo['photo_path'],
        'photo_path' => $photo['photo_path'],
        'uploaded_at' => $photo['uploaded_at']
    ];
}
$incident['photos'] = $photos;

// Also check the old photo_path column for backward compatibility
if (empty($photos) && !empty($incident['photo_path'])) {
    $photos[] = [
        'id' => 0,
        'url' => $incident['photo_path'],
        'photo_path' => $incident['photo_path']
    ];
    $incident['photos'] = $photos;
}

// Return the data
echo json_encode($incident);
?>