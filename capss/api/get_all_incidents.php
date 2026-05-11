<?php
// api/get_all_incidents.php - Get all incidents with filtering
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'incidents' => []]);
    exit;
}

$filter = $_GET['filter'] ?? 'all';

$sql = "
    SELECT i.*, 
           r1.fullname as taken_by_name,
           r2.fullname as finished_by_name
    FROM tbl_incidents i 
    LEFT JOIN tbl_users r1 ON i.taken_by_responder_id = r1.user_id
    LEFT JOIN tbl_users r2 ON i.finished_by_responder_id = r2.user_id
";

if ($filter !== 'all') {
    $sql .= " WHERE i.status = '$filter'";
}

$sql .= " ORDER BY i.created_at DESC";

$result = $conn->query($sql);

$incidents = [];
while ($row = $result->fetch_assoc()) {
    $incidents[] = $row;
}

echo json_encode(['success' => true, 'incidents' => $incidents]);
?>