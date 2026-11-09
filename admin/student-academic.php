<?php
require '../includes/db.php';
// Student academics are now edited on each student's profile page.
header('Location: ' . BASE_URL . '/admin/users.php?role=student');
exit;
