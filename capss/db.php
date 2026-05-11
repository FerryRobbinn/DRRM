<?php
$conn = new mysqli("localhost", "root", "", 
"bongabon_drrm");
if ($conn->connect_error) die("Database Connection Failed");
header('Content-Type: application/json');
?>