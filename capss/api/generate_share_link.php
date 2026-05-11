<?php
// api/generate_share_link.php
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$incident_id = isset($_POST['incident_id']) ? intval($_POST['incident_id']) : 0;
$expires_hours = isset($_POST['expires_hours']) ? intval($_POST['expires_hours']) : 168;
$require_code = isset($_POST['require_code']) && $_POST['require_code'] === 'yes';
$permission = isset($_POST['permission']) ? $_POST['permission'] : 'view';

if (!$incident_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid incident ID']);
    exit;
}

// Generate unique share code
$share_code = bin2hex(random_bytes(16));
$access_code = $require_code ? strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)) : null;
$expires_at = date('Y-m-d H:i:s', strtotime("+$expires_hours hours"));

// Check if share_links table exists
$conn->query("CREATE TABLE IF NOT EXISTS tbl_share_links (
    link_id INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NOT NULL,
    share_code VARCHAR(64) UNIQUE NOT NULL,
    access_code VARCHAR(20) NULL,
    permission ENUM('view', 'edit', 'full') DEFAULT 'view',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    click_count INT DEFAULT 0,
    INDEX idx_share_code (share_code)
)");

$stmt = $conn->prepare("INSERT INTO tbl_share_links (incident_id, share_code, access_code, permission, created_by, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("isssis", $incident_id, $share_code, $access_code, $permission, $_SESSION['user_id'], $expires_at);

if ($stmt->execute()) {
    $share_url = "https://" . $_SERVER['HTTP_HOST'] . "/view_shared_report.php?code=" . $share_code;
    echo json_encode([
        'success' => true,
        'share_url' => $share_url,
        'access_code' => $access_code,
        'expires_at' => $expires_at
    ]);
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>