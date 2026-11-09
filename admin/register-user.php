<?php
require '../includes/db.php';
// Renamed to add-user.php (now supports students too). Redirect any old links.
header('Location: ' . BASE_URL . '/admin/add-user.php');
exit;
