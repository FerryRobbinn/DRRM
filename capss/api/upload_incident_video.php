<?php
// api/upload_incident_video.php - Upload videos for incidents
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$incident_id = intval($_POST['incident_id'] ?? 0);

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Incident ID required']);
    exit;
}

// Check if video file was uploaded
if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No video file uploaded or upload error']);
    exit;
}

$file = $_FILES['video'];
$max_size = 50 * 1024 * 1024; // 50MB max

if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'message' => 'Video file too large (max 50MB)']);
    exit;
}

// Check file type
$allowed_types = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid video format. Allowed: MP4, WebM, MOV, AVI']);
    exit;
}

// Create upload directory if not exists
$upload_dir = '../uploads/videos/' . date('Y/m/');
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'incident_' . $incident_id . '_' . time() . '_' . uniqid() . '.' . $extension;
$filepath = $upload_dir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save video file']);
    exit;
}

$video_url = 'uploads/videos/' . date('Y/m/') . $filename;

// Create video table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS tbl_incident_videos (
    video_id INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NOT NULL,
    video_url TEXT NOT NULL,
    thumbnail_url TEXT NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_incident (incident_id)
)");

// Save to database
$stmt = $conn->prepare("INSERT INTO tbl_incident_videos (incident_id, video_url, uploaded_by) VALUES (?, ?, ?)");
$stmt->bind_param("isi", $incident_id, $video_url, $_SESSION['user_id']);

if ($stmt->execute()) {
    // Log the upload
    $responder_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Unknown';
    $log_stmt = $conn->prepare("INSERT INTO tbl_report_access_log (incident_id, responder_id, responder_name, action_type, action_details) VALUES (?, ?, ?, 'upload_video', ?)");
    $details = "Uploaded video: " . $filename;
    $log_stmt->bind_param("iiss", $incident_id, $_SESSION['user_id'], $responder_name, $details);
    $log_stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Video uploaded successfully',
        'video_id' => $conn->insert_id,
        'video_url' => $video_url
    ]);
} else {
    // Delete file if database insert fails
    unlink($filepath);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>