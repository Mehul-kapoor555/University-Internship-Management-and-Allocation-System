<?php
require 'includes/db.php';
require 'includes/auth.php';
session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;