<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';
$showForm = true;

// Check if token is provided
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    
    // Verify token
    $stmt = $conn->prepare("SELECT id, email FROM users WHERE reset_token = ? AND reset_expires > NOW() AND status = 'active'");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $error = "Invalid or expired reset token";
        $showForm = false;
    }
} else {
    header("Location: login.php");
    exit();
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $token = $_POST['token'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate password
    if (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif (!preg_match("/[A-Z]/", $password)) {
        $error = "Password must contain at least one uppercase letter";
    } elseif (!preg_match("/[a-z]/", $password)) {
        $error = "Password must contain at least one lowercase letter";
    } elseif (!preg_match("/[0-9]/", $password)) {
        $error = "Password must contain at least one number";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        // Update password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE reset_token = ?");
        $stmt->bind_param("ss", $hashed_password, $token);
        
        if ($stmt->execute()) {
            $success = "Password has been reset successfully. You can now login with your new password.";
            $showForm = false;
            
            // Log password reset
            logActivity($conn, null, 'password_reset_complete', 'users', null, 
                       ['ip' => $_SERVER['REMOTE_ADDR']]);
        } else {
            $error = "Error resetting password";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Payroll System</title>
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

        .reset-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .reset-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .reset-header h2 {
            font-size: 28px;
            color: #333;
            margin-bottom: 10px;
        }

        .reset-header p {
            color: #666;
            font-size: 14px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
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

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }

        .input-group {
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }

        .input-group input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .input-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-strength {
            margin-top: 10px;
            height: 5px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: all 0.3s;
        }

        .strength-text {
            font-size: 12px;
            margin-top: 5px;
            color: #666;
        }

        .requirements {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .requirements h4 {
            font-size: 14px;
            color: #333;
            margin-bottom: 10px;
        }

        .requirements ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .requirements li {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
        }

        .requirements li i {
            margin-right: 8px;
            font-size: 12px;
        }

        .requirements li.valid {
            color: #27ae60;
        }

        .requirements li.invalid {
            color: #e74c3c;
        }

        .reset-btn {
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
        }

        .reset-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .reset-btn i {
            margin-right: 8px;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-header">
            <h2>Reset Password</h2>
            <p>Enter your new password below</p>
        </div>

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
            <div class="back-link">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <form method="POST" action="" id="resetForm">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label for="password">New Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="Enter new password" required 
                               onkeyup="checkPasswordStrength()">
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                    <div class="strength-text" id="strengthText"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               placeholder="Confirm new password" required
                               onkeyup="checkPasswordMatch()">
                    </div>
                    <div id="matchMessage" style="font-size: 12px; margin-top: 5px;"></div>
                </div>

                <div class="requirements">
                    <h4>Password Requirements:</h4>
                    <ul>
                        <li id="req-length" class="invalid">
                            <i class="fas fa-times-circle"></i> At least 8 characters
                        </li>
                        <li id="req-uppercase" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one uppercase letter
                        </li>
                        <li id="req-lowercase" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one lowercase letter
                        </li>
                        <li id="req-number" class="invalid">
                            <i class="fas fa-times-circle"></i> At least one number
                        </li>
                        <li id="req-match" class="invalid">
                            <i class="fas fa-times-circle"></i> Passwords match
                        </li>
                    </ul>
                </div>

                <button type="submit" name="reset_password" class="reset-btn" id="submitBtn" disabled>
                    <i class="fas fa-sync-alt"></i>
                    Reset Password
                </button>
            </form>

            <div class="back-link">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');
            
            // Check requirements
            const hasLength = password.length >= 8;
            const hasUppercase = /[A-Z]/.test(password);
            const hasLowercase = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            
            // Update requirement indicators
            document.getElementById('req-length').className = hasLength ? 'valid' : 'invalid';
            document.getElementById('req-uppercase').className = hasUppercase ? 'valid' : 'invalid';
            document.getElementById('req-lowercase').className = hasLowercase ? 'valid' : 'invalid';
            document.getElementById('req-number').className = hasNumber ? 'valid' : 'invalid';
            
            document.getElementById('req-length').innerHTML = `<i class="fas fa-${hasLength ? 'check' : 'times'}-circle"></i> At least 8 characters`;
            document.getElementById('req-uppercase').innerHTML = `<i class="fas fa-${hasUppercase ? 'check' : 'times'}-circle"></i> At least one uppercase letter`;
            document.getElementById('req-lowercase').innerHTML = `<i class="fas fa-${hasLowercase ? 'check' : 'times'}-circle"></i> At least one lowercase letter`;
            document.getElementById('req-number').innerHTML = `<i class="fas fa-${hasNumber ? 'check' : 'times'}-circle"></i> At least one number`;
            
            // Calculate strength
            let strength = 0;
            if (hasLength) strength += 25;
            if (hasUppercase) strength += 25;
            if (hasLowercase) strength += 25;
            if (hasNumber) strength += 25;
            
            strengthBar.style.width = strength + '%';
            
            // Set color based on strength
            if (strength <= 25) {
                strengthBar.style.background = '#e74c3c';
                strengthText.textContent = 'Weak password';
                strengthText.style.color = '#e74c3c';
            } else if (strength <= 50) {
                strengthBar.style.background = '#f39c12';
                strengthText.textContent = 'Fair password';
                strengthText.style.color = '#f39c12';
            } else if (strength <= 75) {
                strengthBar.style.background = '#3498db';
                strengthText.textContent = 'Good password';
                strengthText.style.color = '#3498db';
            } else {
                strengthBar.style.background = '#27ae60';
                strengthText.textContent = 'Strong password';
                strengthText.style.color = '#27ae60';
            }
            
            checkPasswordMatch();
        }

        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchMessage = document.getElementById('matchMessage');
            const reqMatch = document.getElementById('req-match');
            const submitBtn = document.getElementById('submitBtn');
            
            if (confirm.length > 0) {
                if (password === confirm) {
                    matchMessage.innerHTML = '<i class="fas fa-check-circle" style="color: #27ae60;"></i> Passwords match';
                    matchMessage.style.color = '#27ae60';
                    reqMatch.className = 'valid';
                    reqMatch.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                } else {
                    matchMessage.innerHTML = '<i class="fas fa-times-circle" style="color: #e74c3c;"></i> Passwords do not match';
                    matchMessage.style.color = '#e74c3c';
                    reqMatch.className = 'invalid';
                    reqMatch.innerHTML = '<i class="fas fa-times-circle"></i> Passwords match';
                }
            } else {
                matchMessage.innerHTML = '';
            }
            
            // Enable/disable submit button
            const hasLength = password.length >= 8;
            const hasUppercase = /[A-Z]/.test(password);
            const hasLowercase = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const passwordsMatch = password === confirm && password.length > 0;
            
            submitBtn.disabled = !(hasLength && hasUppercase && hasLowercase && hasNumber && passwordsMatch);
        }

        // Prevent form submission if requirements not met
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            if (password !== confirm) {
                e.preventDefault();
                alert('Passwords do not match');
            }
        });
        
    </script>
</body>
</html>