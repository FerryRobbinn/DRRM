<?php
// api/get_map_data.php - Get data for live map (enhanced)
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

$response = [
    'incidents' => [],
    'responders' => []
];

// Get all incidents with location data
$incidents = $conn->query("
    SELECT incident_id, tracking_id, incident_type, severity, 
           location_lat, location_lng, location_address, created_at, status
    FROM tbl_incidents 
    WHERE location_lat IS NOT NULL AND location_lng IS NOT NULL
    AND status IN ('pending', 'dispatched')
    ORDER BY 
        CASE severity 
            WHEN 'Dead' THEN 1
            WHEN 'Immediate' THEN 2
            WHEN 'high' THEN 2
            WHEN 'Delayed' THEN 3
            WHEN 'moderate' THEN 3
            ELSE 4
        END,
        created_at DESC
");

while ($row = $incidents->fetch_assoc()) {
    $response['incidents'][] = $row;
}

// Get responder locations (updated in last 30 minutes)
$responder_table_check = $conn->query("SHOW TABLES LIKE 'tbl_responder_locations'");
if ($responder_table_check->num_rows > 0) {
    $responders = $conn->query("
        SELECT u.user_id, u.fullname, 
               rl.latitude as current_lat, 
               rl.longitude as current_lng, 
               rl.updated_at as last_location_update
        FROM tbl_users u
        INNER JOIN tbl_responder_locations rl ON u.user_id = rl.responder_id
        WHERE u.role = 'responder' 
        AND rl.updated_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
    ");
    
    while ($row = $responders->fetch_assoc()) {
        $response['responders'][] = $row;
    }
}

echo json_encode($response);
?>