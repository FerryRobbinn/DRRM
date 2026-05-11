<?php
// api/get_nearby_incidents.php - Get incidents near responder with distance
session_start();
require_once '../config/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? floatval($_GET['radius']) : 10; // Default 10km

if (!$lat || !$lng) {
    echo json_encode(['success' => false, 'message' => 'Location coordinates required']);
    exit;
}

// Get pending incidents with distance calculation
$query = "
    SELECT i.*, 
           (6371 * acos(cos(radians(?)) * cos(radians(i.location_lat)) * cos(radians(i.location_lng) - radians(?)) + sin(radians(?)) * sin(radians(i.location_lat)))) AS distance,
           (SELECT COUNT(*) FROM tbl_incident_photos WHERE incident_id = i.incident_id) as photo_count
    FROM tbl_incidents i 
    WHERE i.status = 'pending'
    AND i.location_lat IS NOT NULL 
    AND i.location_lng IS NOT NULL
    HAVING distance < ?
    ORDER BY distance ASC, i.created_at DESC
    LIMIT 50
";

$stmt = $conn->prepare($query);
$stmt->bind_param("dddd", $lat, $lng, $lat, $radius);
$stmt->execute();
$result = $stmt->get_result();

$incidents = [];
while ($row = $result->fetch_assoc()) {
    $incidents[] = $row;
}

echo json_encode([
    'success' => true,
    'incidents' => $incidents,
    'count' => count($incidents),
    'search_radius' => $radius
]);
?>