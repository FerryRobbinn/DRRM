<?php
// api/get_incidents_map.php
session_start();
require_once '../config/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'responder') {
    echo json_encode([]);
    exit;
}

$incidents = $conn->query("
    SELECT incident_id, tracking_id, incident_type, severity, 
           location_lat, location_lng, location_address
    FROM tbl_incidents 
    WHERE assigned_responder_id = {$_SESSION['user_id']}
    AND status IN ('pending', 'dispatched', 'on_scene')
    AND location_lat IS NOT NULL
");

$data = [];
while ($row = $incidents->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
?>