<?php
session_start();
require_once 'config.php';

// Log the logout activity
if (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
    $_SESSION['timeout_message'] = 'Session locked for too long. Please login again.';
}
if (isset($_SESSION['user_id'])) {
    logActivity($conn, $_SESSION['user_id'], 'logout', 'users', $_SESSION['user_id'], 
               ['ip' => $_SERVER['REMOTE_ADDR']]);
    
    // Clear remember me token
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        
        setcookie('remember_token', '', time() - 3600, "/", "", true, true);
    }
}

// Destroy session
session_destroy();

// Redirect to login page
header("Location: login.php?logout=success");
exit();
?>