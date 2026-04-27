// api/generate_office_access.php - Create temporary access for office use
<?php
session_start();
require_once '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = intval($_POST['incident_id']);
$expires_hours = intval($_POST['expires_hours'] ?? 24);

// Generate unique access code
$access_code = strtoupper(substr(md5(uniqid() . $incident_id), 0, 8));
$session_id = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_hours} hours"));

$stmt = $conn->prepare("INSERT INTO tbl_shared_report_sessions (session_id, incident_id, created_by, access_code, expires_at) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("siiss", $session_id, $incident_id, $_SESSION['user_id'], $access_code, $expires_at);

if ($stmt->execute()) {
    $access_url = "https://yourdomain.com/view_shared_report.php?code=" . $access_code;
    echo json_encode([
        'success' => true, 
        'access_code' => $access_code,
        'access_url' => $access_url,
        'expires_at' => $expires_at
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to generate access']);
}
?>