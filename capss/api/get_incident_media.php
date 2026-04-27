<?php
// api/get_incident_media.php - Get photos and videos for an incident
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$incident_id = isset($_GET['incident_id']) ? intval($_GET['incident_id']) : 0;

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Incident ID required']);
    exit;
}

// Get photos
$photos = [];
$photo_query = $conn->prepare("SELECT photo_id, photo_path, created_at FROM tbl_incident_photos WHERE incident_id = ? ORDER BY created_at DESC");
$photo_query->bind_param("i", $incident_id);
$photo_query->execute();
$photo_result = $photo_query->get_result();

while ($photo = $photo_result->fetch_assoc()) {
    $photos[] = [
        'id' => $photo['photo_id'],
        'url' => $photo['photo_path'],
        'type' => 'photo',
        'created_at' => $photo['created_at']
    ];
}

// Check if videos table exists
$video_table_check = $conn->query("SHOW TABLES LIKE 'tbl_incident_videos'");
$videos = [];
if ($video_table_check->num_rows > 0) {
    $video_query = $conn->prepare("SELECT video_id, video_url, thumbnail_url, created_at FROM tbl_incident_videos WHERE incident_id = ? ORDER BY created_at DESC");
    $video_query->bind_param("i", $incident_id);
    $video_query->execute();
    $video_result = $video_query->get_result();
    
    while ($video = $video_result->fetch_assoc()) {
        $videos[] = [
            'id' => $video['video_id'],
            'url' => $video['video_url'],
            'thumbnail' => $video['thumbnail_url'],
            'type' => 'video',
            'created_at' => $video['created_at']
        ];
    }
}

echo json_encode([
    'success' => true,
    'photos' => $photos,
    'videos' => $videos,
    'total_photos' => count($photos),
    'total_videos' => count($videos)
]);
?>