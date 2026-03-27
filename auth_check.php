<?php
// auth_check.php - Authentication and authorization only

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Check for remember me cookie
    if (isset($_COOKIE['remember_token'])) {
        require_once 'config.php';
        $token = $_COOKIE['remember_token'];
        
        $stmt = $conn->prepare("SELECT u.* FROM users u 
                                JOIN user_sessions s ON u.id = s.user_id 
                                WHERE s.session_token = ? AND s.is_active = 1 
                                AND s.expiry_time > NOW()");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            
            // Update last activity
            $update = $conn->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_token = ?");
            $update->bind_param("s", $token);
            $update->execute();
        } else {
            // Invalid token, redirect to login
            header("Location: login.php");
            exit();
        }
    } else {
        // Not logged in, redirect to login
        header("Location: login.php");
        exit();
    }
}



// Update last activity
$_SESSION['login_time'] = time();

// Role-based access control
function checkRole($allowed_roles = []) {
    if (!empty($allowed_roles) && !in_array($_SESSION['user_role'], $allowed_roles)) {
        header("HTTP/1.0 403 Forbidden");
        die("Access denied. You don't have permission to access this page.");
    }
}

// Check specific permission
function hasPermission($permission) {
    // Implement permission checking logic
    $permissions = [
        'admin' => ['all'],
        'manager' => ['view_employees', 'manage_attendance', 'view_reports'],
        'hr' => ['manage_employees', 'manage_leaves', 'view_payroll'],
        'employee' => ['view_own_profile', 'request_leave', 'view_own_payslip']
    ];
    
    return in_array($permission, $permissions[$_SESSION['user_role']] ?? []) || 
           in_array('all', $permissions[$_SESSION['user_role']] ?? []);
}
?>