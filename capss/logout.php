<?php
// logout.php - For admin users
session_start();
session_destroy();
header('Location: login.php');
exit;
?>