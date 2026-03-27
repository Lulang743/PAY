<?php
require_once 'config.php';

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

// Check if user_sessions table exists, create if not
function ensureSessionTableExists($conn) {
    $check_table = $conn->query("SHOW TABLES LIKE 'user_sessions'");
    if ($check_table->num_rows == 0) {
        // Create user_sessions table
        $sql = "CREATE TABLE IF NOT EXISTS user_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            session_token VARCHAR(255),
            ip_address VARCHAR(45),
            user_agent TEXT,
            login_time DATETIME,
            last_activity DATETIME,
            expiry_time DATETIME,
            status ENUM('active', 'expired', 'terminated', 'failed') DEFAULT 'active',
            device_type VARCHAR(50),
            browser VARCHAR(50),
            operating_system VARCHAR(50),
            location VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_token (session_token),
            INDEX idx_status (status)
        )";
        
        if (!$conn->query($sql)) {
            error_log("Failed to create user_sessions table: " . $conn->error);
        }
    }
}

// Check if user_session_logs table exists, create if not
function ensureLogTableExists($conn) {
    $check_table = $conn->query("SHOW TABLES LIKE 'user_session_logs'");
    if ($check_table->num_rows == 0) {
        // Create user_session_logs table
        $sql = "CREATE TABLE IF NOT EXISTS user_session_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            session_id INT,
            status VARCHAR(50),
            ip_address VARCHAR(45),
            user_agent TEXT,
            notes TEXT,
            created_at DATETIME,
            INDEX idx_user_id (user_id),
            INDEX idx_session_id (session_id)
        )";
        
        if (!$conn->query($sql)) {
            error_log("Failed to create user_session_logs table: " . $conn->error);
        }
    }
}

// Ensure tables exist
ensureSessionTableExists($conn);
ensureLogTableExists($conn);

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['login'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $remember = isset($_POST['remember']) ? true : false;
        
        // Validate input
        if (empty($username) || empty($password)) {
            $error = "Please enter username and password";
        } else {
            // Check login attempts
            $ip = $_SERVER['REMOTE_ADDR'];
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            $lockout_time = date('Y-m-d H:i:s', strtotime('-15 minutes'));
            
            // Get user by username or email - only from users table
            $stmt = $conn->prepare("SELECT id, username, password, first_name, last_name, 
                                   email, role, avatar, status, login_attempts, lock_until 
                                   FROM users 
                                   WHERE (username = ? OR email = ?) AND status = 'active'");
            
            if ($stmt) {
                $stmt->bind_param("ss", $username, $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    
                    // Check if account is locked
                    if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
                        $error = "Account is locked. Please try again later.";
                        
                        // Log locked account attempt
                        logSession($conn, $user['id'], 'locked', $ip, $user_agent, null, 'Account locked');
                        
                    } else {
                        // Verify password
                        if (password_verify($password, $user['password'])) {
                            // Successful login
                            
                            // Regenerate session ID for security
                            session_regenerate_id(true);
                            
                            // Set session variables
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['username'] = $user['username'];
                            $_SESSION['user_role'] = $user['role'];
                            $_SESSION['role'] = $user['role']; // Added for compatibility
                            $_SESSION['first_name'] = $user['first_name'];
                            $_SESSION['last_name'] = $user['last_name'];
                            $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['login_time'] = time();
                            $_SESSION['ip_address'] = $ip;
                            $_SESSION['user_agent'] = $user_agent;
                            
                            // Update last login in users table
                            $update = $conn->prepare("UPDATE users SET 
                                                      last_login = NOW(), 
                                                      last_login_ip = ?, 
                                                      login_attempts = 0,
                                                      lock_until = NULL 
                                                      WHERE id = ?");
                            if ($update) {
                                $update->bind_param("si", $ip, $user['id']);
                                $update->execute();
                                $update->close();
                            }
                            
                            // Generate session token
                            $session_token = bin2hex(random_bytes(32));
                            $expiry_time = date('Y-m-d H:i:s', strtotime('+24 hours'));
                            
                            // For "Remember Me" - longer expiry
                            if ($remember) {
                                $expiry_time = date('Y-m-d H:i:s', strtotime('+30 days'));
                            }
                            
                            // Get device info
                            $device_info = getDeviceInfo($user_agent);
                            $device_type = $device_info['device_type'];
                            $browser = $device_info['browser'];
                            $os = $device_info['os'];
                            $location = getLocationFromIP($ip);
                            
                            // Insert into user_sessions table
                            $session = $conn->prepare("INSERT INTO user_sessions 
                                                      (user_id, session_token, ip_address, user_agent, 
                                                      login_time, last_activity, expiry_time, status,
                                                      device_type, browser, operating_system, location)
                                                      VALUES (?, ?, ?, ?, NOW(), NOW(), ?, 'active', ?, ?, ?, ?)");
                            
                            if ($session) {
                                $session->bind_param("issssssss", 
                                    $user['id'], 
                                    $session_token, 
                                    $ip, 
                                    $user_agent,
                                    $expiry_time,
                                    $device_type,
                                    $browser,
                                    $os,
                                    $location
                                );
                                
                                if ($session->execute()) {
                                    $session_id = $conn->insert_id;
                                    
                                    // Store session info in PHP session
                                    $_SESSION['session_token'] = $session_token;
                                    $_SESSION['session_id'] = $session_id;
                                    
                                    // Set cookie for "Remember Me"
                                    if ($remember) {
                                        setcookie('remember_token', $session_token, time() + (86400 * 30), "/", "", true, true);
                                    }
                                    
                                    // Log successful login
                                    logSession($conn, $user['id'], 'success', $ip, $user_agent, $session_id, 'Login successful');
                                    
                                    $session->close();
                                    
                                    // Make sure no output has been sent before redirect
                                    if (!headers_sent()) {
                                        // Redirect to dashboard
                                        header("Location: dashboard.php");
                                        exit();
                                    } else {
                                        // If headers already sent, use JavaScript redirect
                                        echo "<script>window.location.href = 'dashboard.php';</script>";
                                        exit();
                                    }
                                } else {
                                    $error = "Error creating session: " . $conn->error;
                                }
                            } else {
                                // Session table might not exist, but login is still successful
                                // Log successful login without session tracking
                                logSession($conn, $user['id'], 'success', $ip, $user_agent, null, 'Login successful (no session table)');
                                
                                // Make sure no output has been sent before redirect
                                if (!headers_sent()) {
                                    header("Location: dashboard.php");
                                    exit();
                                } else {
                                    echo "<script>window.location.href = 'dashboard.php';</script>";
                                    exit();
                                }
                            }
                        } else {
                            // Failed password
                            $new_attempts = ($user['login_attempts'] ?? 0) + 1;
                            
                            $failed_attempt = $conn->prepare("UPDATE users SET login_attempts = ? WHERE id = ?");
                            if ($failed_attempt) {
                                $failed_attempt->bind_param("ii", $new_attempts, $user['id']);
                                $failed_attempt->execute();
                                $failed_attempt->close();
                            }
                            
                            // Lock account after 5 failed attempts
                            if ($new_attempts >= 5) {
                                $lock_time = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                                $lock = $conn->prepare("UPDATE users SET lock_until = ? WHERE id = ?");
                                if ($lock) {
                                    $lock->bind_param("si", $lock_time, $user['id']);
                                    $lock->execute();
                                    $lock->close();
                                }
                                
                                $error = "Account locked due to too many failed attempts. Try again after 30 minutes.";
                                
                                // Log account lock
                                logSession($conn, $user['id'], 'locked', $ip, $user_agent, null, 
                                          "Account locked after $new_attempts failed attempts");
                            } else {
                                $remaining = 5 - $new_attempts;
                                $error = "Invalid password. Attempts remaining: " . $remaining;
                                
                                // Log failed attempt
                                logSession($conn, $user['id'], 'failed', $ip, $user_agent, null, 
                                          "Failed login attempt #$new_attempts");
                            }
                        }
                    }
                } else {
                    $error = "User not found or inactive";
                    
                    // Log failed attempt for non-existent user
                    logSession($conn, 0, 'failed', $ip, $user_agent, null, 
                              "Login attempt for non-existent user: $username");
                }
                $stmt->close();
            } else {
                $error = "Database error: " . $conn->error;
            }
        }
    }
    
    // Handle forgot password
    if (isset($_POST['forgot_password'])) {
        $email = trim($_POST['email']);
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        
        $stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND status = 'active'");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                $token = bin2hex(random_bytes(32));
                $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                if ($update) {
                    $update->bind_param("ssi", $token, $expiry, $user['id']);
                    $update->execute();
                    $update->close();
                }
                
                // Log password reset request
                logSession($conn, $user['id'], 'password_reset', $ip, $user_agent, null, 
                          "Password reset requested");
                
                // In a real application, send email here
                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/reset_password.php?token=" . $token;
                $success = "Password reset link has been sent to your email. (Demo: $reset_link)";
            } else {
                $error = "Email not found in our system";
                
                // Log failed reset attempt
                logSession($conn, 0, 'password_reset_failed', $ip, $user_agent, null, 
                          "Password reset attempt for non-existent email: $email");
            }
            $stmt->close();
        } else {
            $error = "Database error: " . $conn->error;
        }
    }
}

// Rest of the functions (getDeviceInfo, getLocationFromIP, logSession) remain the same...
// [Keep all the function definitions from your original code]

/**
 * Get device information from user agent
 */
function getDeviceInfo($user_agent) {
    $device_info = [
        'device_type' => 'Unknown',
        'browser' => 'Unknown',
        'os' => 'Unknown'
    ];
    
    // Detect browser
    if (strpos($user_agent, 'Chrome') !== false && strpos($user_agent, 'Edge') === false) {
        $device_info['browser'] = 'Chrome';
    } elseif (strpos($user_agent, 'Firefox') !== false) {
        $device_info['browser'] = 'Firefox';
    } elseif (strpos($user_agent, 'Safari') !== false && strpos($user_agent, 'Chrome') === false) {
        $device_info['browser'] = 'Safari';
    } elseif (strpos($user_agent, 'Edge') !== false) {
        $device_info['browser'] = 'Edge';
    } elseif (strpos($user_agent, 'MSIE') !== false || strpos($user_agent, 'Trident') !== false) {
        $device_info['browser'] = 'Internet Explorer';
    }
    
    // Detect OS
    if (strpos($user_agent, 'Windows NT') !== false) {
        $device_info['os'] = 'Windows';
    } elseif (strpos($user_agent, 'Mac OS X') !== false) {
        $device_info['os'] = 'macOS';
    } elseif (strpos($user_agent, 'Linux') !== false && strpos($user_agent, 'Android') === false) {
        $device_info['os'] = 'Linux';
    } elseif (strpos($user_agent, 'Android') !== false) {
        $device_info['os'] = 'Android';
    } elseif (strpos($user_agent, 'iOS') !== false || strpos($user_agent, 'iPhone') !== false || strpos($user_agent, 'iPad') !== false) {
        $device_info['os'] = 'iOS';
    }
    
    // Detect device type
    if (preg_match('/(tablet|ipad|playbook)|(android(?!.*mobile))/i', $user_agent)) {
        $device_info['device_type'] = 'Tablet';
    } elseif (preg_match('/(mobile|iphone|ipod|android|blackberry|opera mini|opera mobi|skyfire|windows phone)/i', $user_agent)) {
        $device_info['device_type'] = 'Mobile';
    } else {
        $device_info['device_type'] = 'Desktop';
    }
    
    return $device_info;
}

/**
 * Get location from IP (simplified)
 */
function getLocationFromIP($ip) {
    // Skip local IPs
    if ($ip == '127.0.0.1' || $ip == '::1') {
        return 'Localhost';
    }
    
    // You can integrate with a service like ipapi.co or ipinfo.io
    // For now, return a placeholder
    return 'Unknown';
}

/**
 * Log session activity
 */
function logSession($conn, $user_id, $status, $ip, $user_agent, $session_id = null, $notes = '') {
    try {
        // Check if table exists
        $check = $conn->query("SHOW TABLES LIKE 'user_session_logs'");
        if ($check->num_rows == 0) {
            return; // Table doesn't exist, skip logging
        }
        
        $query = "INSERT INTO user_session_logs 
                  (user_id, session_id, status, ip_address, user_agent, notes, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("iissss", $user_id, $session_id, $status, $ip, $user_agent, $notes);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Session logging error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Alaki Payroll</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* [Keep all your existing CSS styles here - they remain exactly the same] */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            display: flex;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        /* Left Panel - Branding */
        .brand-panel {
            flex: 1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 50px;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 30s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .brand-content {
            position: relative;
            z-index: 1;
        }

        .brand-icon {
            font-size: 60px;
            margin-bottom: 30px;
        }

        .brand-title {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .brand-description {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 40px;
            line-height: 1.6;
        }

        .features {
            list-style: none;
        }

        .features li {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .features li i {
            margin-right: 10px;
            font-size: 20px;
        }

        /* Right Panel - Login Form */
        .login-panel {
            flex: 1;
            padding: 50px;
            background: white;
        }

        .login-header {
            margin-bottom: 40px;
            text-align: center;
        }

        .login-header h2 {
            font-size: 32px;
            color: #333;
            margin-bottom: 10px;
        }

        .login-header p {
            color: #666;
            font-size: 14px;
        }

        .login-header p a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .login-header p a:hover {
            text-decoration: underline;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }

        .alert-success {
            background: #efe;
            color: #3c3;
            border-left: 4px solid #3c3;
        }

        .alert i {
            margin-right: 10px;
            font-size: 20px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
            font-size: 14px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            color: #999;
            font-size: 18px;
            transition: color 0.3s;
        }

        .input-group input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f9f9f9;
        }

        .input-group input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .input-group input:focus + i {
            color: #667eea;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            color: #999;
            cursor: pointer;
            z-index: 2;
        }

        .toggle-password:hover {
            color: #667eea;
        }

        /* Form Options */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .remember-me {
            display: flex;
            align-items: center;
        }

        .remember-me input {
            margin-right: 8px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .remember-me label {
            color: #666;
            font-size: 14px;
            cursor: pointer;
        }

        .forgot-link {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn i {
            margin-right: 8px;
        }

        /* Loading Spinner */
        .spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .spinner.active {
            display: flex;
        }

        .spinner-circle {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Device Info */
        .device-info {
            margin-top: 20px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            font-size: 12px;
            color: #666;
            text-align: center;
        }

        .device-info i {
            margin: 0 5px;
            color: #667eea;
        }

        /* Responsive Design */
        @media (max-width: 968px) {
            .container {
                flex-direction: column;
                max-width: 500px;
            }
            
            .brand-panel {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="spinner" id="spinner">
        <div class="spinner-circle"></div>
    </div>

    <div class="container">
        <!-- Left Panel - Branding -->
        <div class="brand-panel">
            <div class="brand-content">
                <div class="brand-icon">
                    <i class="fas fa-calculator"></i>
                </div>
                <h1 class="brand-title">Alaki Payroll</h1>
                <p class="brand-description">
                    Complete solution for managing your company's payroll, employees, and financial operations efficiently.
                </p>
                <ul class="features">
                    <li>
                        <i class="fas fa-check-circle"></i>
                        Automated payroll processing
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        Employee self-service portal
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        Tax calculations & compliance
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        Detailed financial reports
                    </li>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        Secure & encrypted data
                    </li>
                </ul>
            </div>
        </div>

        <!-- Right Panel - Login Form -->
        <div class="login-panel">
            <div class="login-header">
                <h2>Welcome Back!</h2>
                <p>Don't have an account? <a href="#" onclick="showRegister()">Contact Administrator</a></p>
            </div>

            <!-- Error/Success Messages -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" id="loginForm" onsubmit="showSpinner(); return true;">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" 
                               placeholder="Enter your username or email" required 
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="Enter your password" required>
                        <i class="fas fa-eye toggle-password" onclick="togglePassword()"></i>
                    </div>
                </div>

                <div class="form-options">
                    <div class="remember-me">
                        <input type="checkbox" name="remember" id="remember" <?php echo isset($_POST['remember']) ? 'checked' : ''; ?>>
                        <label for="remember">Remember me for 30 days</label>
                    </div>
                    <a href="register.php" class="forgot-link">Forgot Password?</a>
                </div>

                <button type="submit" name="login" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    Sign In
                </button>
                
                
                <!-- Device Info -->
                <div class="device-info">
                    <i class="fas fa-laptop"></i>
                    <span id="deviceDisplay"></span>
                </div>
            </form>

            <!-- Forgot Password Form (Hidden by default) -->
            <form method="POST" action="" id="forgotForm" style="display: none;" onsubmit="showSpinner(); return true;">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-group">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" 
                               placeholder="Enter your registered email" required>
                    </div>
                </div>
                <p style="color: #666; font-size: 13px; margin-bottom: 20px;">
                    We'll send you a link to reset your password.
                </p>
                <button type="submit" name="forgot_password" class="login-btn">
                    <i class="fas fa-paper-plane"></i>
                    Send Reset Link
                </button>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="#" class="forgot-link" onclick="showLogin(); return false;">Back to Login</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Show/Hide Spinner
        function showSpinner() {
            document.getElementById('spinner').classList.add('active');
        }

        function hideSpinner() {
            document.getElementById('spinner').classList.remove('active');
        }

        // Toggle Password Visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.toggle-password');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Show Login Form
        function showLogin() {
            document.getElementById('loginForm').style.display = 'block';
            document.getElementById('forgotForm').style.display = 'none';
        }

        // Show Forgot Password Form
        function showForgotPassword() {
            document.getElementById('loginForm').style.display = 'none';
            document.getElementById('forgotForm').style.display = 'block';
        }

        // Show Register Info
        function showRegister() {
            alert('Please contact your system administrator to create an account.');
        }

        // Form Validation
        document.getElementById('loginForm')?.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (username === '' || password === '') {
                e.preventDefault();
                alert('Please fill in all fields');
                hideSpinner();
                return false;
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);

        // Display device info
        function getBrowserInfo() {
            const ua = navigator.userAgent;
            let browser = "Unknown";
            let os = "Unknown";
            let device = "Desktop";

            // Detect browser
            if (ua.indexOf("Chrome") > -1 && ua.indexOf("Edge") === -1) browser = "Chrome";
            else if (ua.indexOf("Firefox") > -1) browser = "Firefox";
            else if (ua.indexOf("Safari") > -1 && ua.indexOf("Chrome") === -1) browser = "Safari";
            else if (ua.indexOf("Edge") > -1) browser = "Edge";
            
            // Detect OS
            if (ua.indexOf("Windows") > -1) os = "Windows";
            else if (ua.indexOf("Mac") > -1) os = "macOS";
            else if (ua.indexOf("Linux") > -1 && ua.indexOf("Android") === -1) os = "Linux";
            else if (ua.indexOf("Android") > -1) os = "Android";
            else if (ua.indexOf("iOS") > -1 || ua.indexOf("iPhone") > -1 || ua.indexOf("iPad") > -1) os = "iOS";
            
            // Detect device
            if (/(tablet|ipad|playbook|android(?!.*mobile))/i.test(ua)) device = "Tablet";
            else if (/mobile|iphone|ipod|android|blackberry|opera mini|opera mobi|skyfire|windows phone/i.test(ua)) device = "Mobile";
            
            return { browser, os, device };
        }

        const info = getBrowserInfo();
        const deviceDisplay = document.getElementById('deviceDisplay');
        if (deviceDisplay) {
            deviceDisplay.innerHTML = `${info.device} | ${info.browser} | ${info.os}`;
        }

        // Add keyboard shortcut (Ctrl+Shift+D) for demo
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                e.preventDefault();
                document.getElementById('username').value = 'admin';
                document.getElementById('password').value = 'Admin@123';
            }
        });

        // Remember last username
        window.onload = function() {
            const savedUsername = localStorage.getItem('last_username');
            if (savedUsername && !document.getElementById('username').value) {
                document.getElementById('username').value = savedUsername;
                document.getElementById('remember').checked = true;
            }
        }

        // Prevent double form submission
        let formSubmitted = false;
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                if (formSubmitted) {
                    e.preventDefault();
                    return false;
                }
                formSubmitted = true;
            });
        });
    </script>
</body>
</html>