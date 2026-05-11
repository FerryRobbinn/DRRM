<?php
include '../db.php';

$phone = $_POST['phone'];
$type_id = $_POST['type_id']; // This should match the ID in incident_types table
$address = $_POST['address'];
$lat = $_POST['lat'];
$lng = $_POST['lng'];

$stmt = $conn->prepare("INSERT INTO incidents (reporter_phone, type_id, address_text, lat, lng) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sisdd", $phone, $type_id, $address, $lat, $lng);

if($stmt->execute()) {
    echo json_encode(["status" => "success", "id" => $conn->insert_id]);
} else {
    echo json_encode(["status" => "error"]);
}
?>