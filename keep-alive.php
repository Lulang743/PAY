<?php
// keep-alive.php - Called by JavaScript to keep session alive
session_start();

if (isset($_SESSION['user_id']) && (!isset($_SESSION['locked']) || $_SESSION['locked'] !== true)) {
    $_SESSION['last_activity'] = time();
    echo 'OK';
} else {
    http_response_code(403);
    echo 'LOCKED';
}
?>