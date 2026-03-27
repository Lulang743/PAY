<?php
session_start();

require_once 'config.php';

$error = '';
$success = '';
$verified = false;

// Check if token is provided
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Verify token
    $stmt = $conn->prepare("SELECT id, email, first_name FROM users WHERE verification_token = ? AND token_expiry > NOW() AND email_verified = 0");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        
        // Update user as verified
        $update = $conn->prepare("UPDATE users SET email_verified = 1, verification_token = NULL, token_expiry = NULL WHERE id = ?");
        $update->bind_param("i", $user['id']);
        
        if ($update->execute()) {
            $verified = true;
            $success = "Email verified successfully! You can now login.";
            
            // Log verification
            logActivity($conn, $user['id'], 'email_verified', 'users', $user['id'], 
                       ['email' => $user['email']]);
        } else {
            $error = "Error verifying email. Please try again.";
        }
    } else {
        // Check if already verified
        $check = $conn->prepare("SELECT email_verified FROM users WHERE verification_token = ?");
        $check->bind_param("s", $token);
        $check->execute();
        $result = $check->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            if ($user['email_verified'] == 1) {
                $error = "Email already verified. You can login.";
            } else {
                $error = "Verification link has expired. Please request a new one.";
            }
        } else {
            $error = "Invalid verification token.";
        }
    }
} else {
    header("Location: register.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - Payroll System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
        }

        .verification-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            max-width: 450px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
        }

        .icon {
            font-size: 80px;
            margin-bottom: 20px;
        }

        .icon.success {
            color: #27ae60;
        }

        .icon.error {
            color: #e74c3c;
        }

        h2 {
            color: #333;
            margin-bottom: 15px;
        }

        p {
            color: #666;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn i {
            margin-right: 8px;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="verification-container">
        <?php if ($verified): ?>
            <div class="icon success">
                <i class="fas fa-check-circle"></i>
            </div>
            <h2>Email Verified!</h2>
            <p><?php echo htmlspecialchars($success); ?></p>
            <a href="login.php" class="btn">
                <i class="fas fa-sign-in-alt"></i>
                Proceed to Login
            </a>
        <?php elseif ($error): ?>
            <div class="icon error">
                <i class="fas fa-times-circle"></i>
            </div>
            <h2>Verification Failed</h2>
            <p><?php echo htmlspecialchars($error); ?></p>
            <a href="register.php" class="btn">
                <i class="fas fa-user-plus"></i>
                Register Again
            </a>
        <?php endif; ?>
    </div>
</body>
</html>