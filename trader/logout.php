<?php
// Logout trader.
session_start();
session_destroy();
header('Location: login.php');
exit;
?>
