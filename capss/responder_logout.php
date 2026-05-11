<?php
// responder_logout.php - For responder users
session_start();
session_destroy();
header('Location: responder_login.php');
exit;
?>