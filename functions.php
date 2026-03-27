<?php
// functions.php - Add this file and include it in your pages

/**
 * Log activity to audit_log table
 * 
 * @param mysqli $conn Database connection
 * @param int|null $user_id User ID (can be null for non-logged users)
 * @param string $action Action performed
 * @param string $table Table name affected
 * @param int|null $record_id Record ID affected
 * @param mixed $details Additional details (will be JSON encoded)
 * @return bool Success or failure
 */
function logActivity($conn, $user_id, $action, $table, $record_id = null, $details = null) {
    // Check if audit_log table exists, if not create it
    $checkTable = $conn->query("SHOW TABLES LIKE 'audit_log'");
    if ($checkTable->num_rows == 0) {
        createAuditLogTable($conn);
    }
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Convert details to JSON if it's an array
    $details_json = is_array($details) ? json_encode($details) : $details;
    
    $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare audit log statement: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("ississs", 
        $user_id, 
        $action, 
        $table, 
        $record_id, 
        $details_json, 
        $ip_address, 
        $user_agent
    );
    
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Create audit_log table if it doesn't exist
 */
function createAuditLogTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS audit_log (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        table_name VARCHAR(50),
        record_id INT NULL,
        details TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        created_at DATETIME,
        INDEX idx_user_id (user_id),
        INDEX idx_action (action),
        INDEX idx_created_at (created_at)
    )";
    
    return $conn->query($sql);
}

/**
 * Get user by ID or username
 */
function getUserById($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Get user by username or email
 */
function getUserByUsername($conn, $username) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Validate password strength
 */
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    if (!preg_match("/[A-Z]/", $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    if (!preg_match("/[a-z]/", $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    if (!preg_match("/[0-9]/", $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return $errors;
}

/**
 * Check login attempts for brute force protection
 */
function checkLoginAttempts($conn, $ip_address) {
    $lockout_time = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM user_sessions 
                            WHERE ip_address = ? AND login_time > ? AND is_active = 0");
    $stmt->bind_param("ss", $ip_address, $lockout_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    return $data['attempts'];
}

/**
 * Create user session for "Remember Me"
 */
function createUserSession($conn, $user_id, $remember = false) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $token = null;
    
    if ($remember) {
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $stmt = $conn->prepare("INSERT INTO user_sessions 
                                (user_id, session_token, ip_address, user_agent, login_time, last_activity, expiry_time) 
                                VALUES (?, ?, ?, ?, NOW(), NOW(), ?)");
        $stmt->bind_param("issss", $user_id, $token, $ip, $_SERVER['HTTP_USER_AGENT'], $expiry);
        $stmt->execute();
        
        // Set secure cookie
        setcookie('remember_token', $token, time() + (86400 * 30), "/", "", true, true);
    }
    
    return $token;
}

/**
 * Update last login information
 */
function updateLastLogin($conn, $user_id, $ip) {
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW(), last_login_ip = ?, login_attempts = 0 WHERE id = ?");
    $stmt->bind_param("si", $ip, $user_id);
    return $stmt->execute();
}

/**
 * Increment login attempts and lock account if needed
 */
function incrementLoginAttempts($conn, $user_id) {
    // Get current attempts
    $stmt = $conn->prepare("SELECT login_attempts FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    $attempts = $user['login_attempts'] + 1;
    
    // Update attempts
    $update = $conn->prepare("UPDATE users SET login_attempts = ? WHERE id = ?");
    $update->bind_param("ii", $attempts, $user_id);
    $update->execute();
    
    // Lock account after 5 failed attempts
    if ($attempts >= 5) {
        $lock_time = date('Y-m-d H:i:s', strtotime('+30 minutes'));
        $lock = $conn->prepare("UPDATE users SET lock_until = ? WHERE id = ?");
        $lock->bind_param("si", $lock_time, $user_id);
        $lock->execute();
        return 'locked';
    }
    
    return $attempts;
}