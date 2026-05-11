<?php
include '../db.php';
$data = json_decode(file_get_contents('php://input'), true);

if(isset($data['incident_id'], $data['user_id'])) {
    $stmt = $conn->prepare("UPDATE incidents SET assigned_to = ?, current_status = 'dispatched' WHERE incident_id = ?");
    $stmt->bind_param("ii", $data['user_id'], $data['incident_id']);
    
    if($stmt->execute()) {
        echo json_encode(["status" => "success"]);
    } else {
        echo json_encode(["status" => "error"]);
    }
}
?>