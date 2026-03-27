<?php
session_start();
require_once 'config.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';
$showForm = true;

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    
    // Get form data
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $employee_id = isset($_POST['employee_id']) ? trim($_POST['employee_id']) : null;
    $terms = isset($_POST['terms']) ? true : false;
    
    // Validation
    $errors = [];
    
    // Check terms acceptance
    if (!$terms) {
        $errors[] = "You must accept the Terms and Conditions";
    }
    
    // Validate first name
    if (empty($first_name)) {
        $errors[] = "First name is required";
    } elseif (!preg_match("/^[a-zA-Z\s'-]+$/", $first_name)) {
        $errors[] = "First name can only contain letters, spaces, apostrophes, and hyphens";
    }
    
    // Validate last name
    if (empty($last_name)) {
        $errors[] = "Last name is required";
    } elseif (!preg_match("/^[a-zA-Z\s'-]+$/", $last_name)) {
        $errors[] = "Last name can only contain letters, spaces, apostrophes, and hyphens";
    }
    
    // Validate email
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    } else {
        // Check if email already exists
        $check_email = getUserByUsername($conn, $email);
        if ($check_email) {
            $errors[] = "Email address is already registered";
        }
    }
    
    // Validate username
    if (empty($username)) {
        $errors[] = "Username is required";
    } elseif (strlen($username) < 4) {
        $errors[] = "Username must be at least 4 characters long";
    } elseif (!preg_match("/^[a-zA-Z0-9_]+$/", $username)) {
        $errors[] = "Username can only contain letters, numbers, and underscores";
    } else {
        // Check if username already exists
        $check_username = getUserByUsername($conn, $username);
        if ($check_username) {
            $errors[] = "Username is already taken";
        }
    }
    
    // Validate password
    $password_errors = validatePassword($password);
    if (!empty($password_errors)) {
        $errors = array_merge($errors, $password_errors);
    }
    
    // Confirm password
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    // Validate employee ID if provided (optional)
    if (!empty($employee_id)) {
        $stmt = $conn->prepare("SELECT id FROM employees WHERE employee_id = ?");
        $stmt->bind_param("s", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            $errors[] = "Invalid Employee ID. Please check with HR.";
        } else {
            $employee = $result->fetch_assoc();
            $employee_db_id = $employee['id'];
            
            // Check if employee ID is already linked to a user
            $check_employee = $conn->prepare("SELECT id FROM users WHERE employee_id = ?");
            $check_employee->bind_param("i", $employee_db_id);
            $check_employee->execute();
            $emp_result = $check_employee->get_result();
            
            if ($emp_result->num_rows > 0) {
                $errors[] = "This Employee ID is already registered";
            }
        }
    }
    
    // If no errors, proceed with registration
    if (empty($errors)) {
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Generate email verification token
        $verification_token = bin2hex(random_bytes(32));
        $token_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Get IP and user agent
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Insert new user
            $sql = "INSERT INTO users (
                username, 
                email, 
                password, 
                first_name, 
                last_name, 
                employee_id,
                role,
                status,
                email_verified,
                verification_token,
                token_expiry,
                last_login_ip,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, 'employee', 'active', 0, ?, ?, ?, NOW())";
            
            $stmt = $conn->prepare($sql);
            $employee_id_int = !empty($employee_db_id) ? $employee_db_id : null;
            $stmt->bind_param(
                "sssssisss", 
                $username, 
                $email, 
                $hashed_password, 
                $first_name, 
                $last_name, 
                $employee_id_int,
                $verification_token,
                $token_expiry,
                $ip
            );
            
            if ($stmt->execute()) {
                $new_user_id = $conn->insert_id;
                
                // Log registration
                logActivity($conn, $new_user_id, 'user_registered', 'users', $new_user_id, [
                    'email' => $email,
                    'username' => $username,
                    'ip' => $ip,
                    'user_agent' => $user_agent
                ]);
                
                // Create welcome notification (if you have notifications table)
                // sendWelcomeEmail($email, $first_name, $verification_token);
                
                $conn->commit();
                
                // Registration successful
                $success = "Registration successful! Please check your email to verify your account.";
                $showForm = false;
                
                // In a real application, send verification email here
                // For demo, we'll show the verification link
                $verification_link = "http://" . $_SERVER['HTTP_HOST'] . "/PAY/verify_email.php?token=" . $verification_token;
                $success .= "<br><small>Demo verification link: <a href='$verification_link'>$verification_link</a></small>";
                
            } else {
                throw new Exception("Error creating user account");
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Registration failed: " . $e->getMessage();
            error_log("Registration error: " . $e->getMessage());
        }
    }
    
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Payroll Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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

        .register-container {
            width: 100%;
            max-width: 500px;
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .register-header h2 {
            font-size: 32px;
            color: #333;
            margin-bottom: 10px;
        }

        .register-header p {
            color: #666;
            font-size: 14px;
        }

        .register-header p a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

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

        .form-group {
            margin-bottom: 20px;
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

        .input-group input,
        .input-group select {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: #f9f9f9;
        }

        .input-group input:focus,
        .input-group select:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .input-group input:focus + i {
            color: #667eea;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            color: #999;
            cursor: pointer;
            z-index: 2;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        /* Password Strength Indicator */
        .password-strength {
            margin-top: 10px;
        }

        .strength-bar {
            height: 5px;
            background: #e0e0e0;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 5px;
        }

        .strength-progress {
            height: 100%;
            width: 0;
            transition: all 0.3s;
        }

        .strength-text {
            font-size: 12px;
            color: #666;
        }

        /* Requirements List */
        .requirements {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 15px 0;
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

        /* Name Row (First & Last) */
        .name-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        /* Checkbox */
        .checkbox-group {
            margin: 20px 0;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 14px;
            color: #666;
        }

        .checkbox-label input {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-label a {
            color: #667eea;
            text-decoration: none;
        }

        .checkbox-label a:hover {
            text-decoration: underline;
        }

        /* Register Button */
        .register-btn {
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
            margin-bottom: 20px;
        }

        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .register-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .register-btn i {
            margin-right: 8px;
        }

        /* Login Link */
        .login-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        /* Tooltip */
        .tooltip {
            position: relative;
            display: inline-block;
            margin-left: 5px;
            color: #999;
            cursor: help;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background: #333;
            color: #fff;
            text-align: center;
            padding: 5px;
            border-radius: 6px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 12px;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .register-container {
                padding: 20px;
            }
            
            .name-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h2>Create Account</h2>
            <p>Join our payroll management system</p>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $success; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <!-- Registration Form -->
            <form method="POST" action="" id="registerForm" onsubmit="return validateForm()">
                <!-- Name Fields -->
                <div class="name-row">
                    <div class="form-group">
                        <label for="first_name">
                            First Name
                            <span class="tooltip">
                                <i class="fas fa-info-circle"></i>
                                <span class="tooltiptext">Enter your legal first name</span>
                            </span>
                        </label>
                        <div class="input-group">
                            <i class="fas fa-user"></i>
                            <input type="text" id="first_name" name="first_name" 
                                   placeholder="John" required
                                   value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>"
                                   pattern="[a-zA-Z\s'-]+"
                                   title="First name can only contain letters, spaces, apostrophes, and hyphens">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <div class="input-group">
                            <i class="fas fa-user"></i>
                            <input type="text" id="last_name" name="last_name" 
                                   placeholder="Doe" required
                                   value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>"
                                   pattern="[a-zA-Z\s'-]+"
                                   title="Last name can only contain letters, spaces, apostrophes, and hyphens">
                        </div>
                    </div>
                </div>

                <!-- Email -->
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-group">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" 
                               placeholder="john.doe@example.com" required
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>

                <!-- Username -->
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-group">
                        <i class="fas fa-at"></i>
                        <input type="text" id="username" name="username" 
                               placeholder="johndoe123" required
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                               pattern="[a-zA-Z0-9_]+"
                               title="Username can only contain letters, numbers, and underscores"
                               minlength="4">
                    </div>
                    <small style="color: #999;">Minimum 4 characters (letters, numbers, underscores only)</small>
                </div>

                <!-- Employee ID (Optional) -->
                <div class="form-group">
                    <label for="employee_id">
                        Employee ID (Optional)
                        <span class="tooltip">
                            <i class="fas fa-info-circle"></i>
                            <span class="tooltiptext">If you're an existing employee, enter your Employee ID</span>
                        </span>
                    </label>
                    <div class="input-group">
                        <i class="fas fa-id-badge"></i>
                        <input type="text" id="employee_id" name="employee_id" 
                               placeholder="EMP001"
                               value="<?php echo isset($_POST['employee_id']) ? htmlspecialchars($_POST['employee_id']) : ''; ?>">
                    </div>
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="Create a strong password" required
                               onkeyup="checkPasswordStrength()">
                        <i class="fas fa-eye password-toggle" onclick="togglePassword('password')"></i>
                    </div>
                    
                    <!-- Password Strength Indicator -->
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div class="strength-progress" id="strengthBar"></div>
                        </div>
                        <span class="strength-text" id="strengthText"></span>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               placeholder="Re-enter your password" required
                               onkeyup="checkPasswordMatch()">
                        <i class="fas fa-eye password-toggle" onclick="togglePassword('confirm_password')"></i>
                    </div>
                    <div id="matchMessage" style="font-size: 12px; margin-top: 5px;"></div>
                </div>

                <!-- Password Requirements -->
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

                <!-- Terms and Conditions -->
                <div class="checkbox-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="terms" id="terms" required>
                        I accept the <a href="#" onclick="showTerms()">Terms and Conditions</a> and 
                        <a href="#" onclick="showPrivacy()">Privacy Policy</a>
                    </label>
                </div>

                <!-- Register Button -->
                <button type="submit" name="register" class="register-btn" id="registerBtn" disabled>
                    <i class="fas fa-user-plus"></i>
                    Create Account
                </button>
            </form>

            <!-- Login Link -->
            <div class="login-link">
                Already have an account? <a href="login.php">Sign In</a>
            </div>
        <?php else: ?>
            <!-- After successful registration -->
            <div style="text-align: center;">
                <i class="fas fa-check-circle" style="font-size: 60px; color: #27ae60; margin-bottom: 20px;"></i>
                <p style="margin-bottom: 20px;">Your account has been created successfully!</p>
                <a href="login.php" class="register-btn" style="text-decoration: none; display: inline-block; width: auto; padding: 12px 30px;">
                    <i class="fas fa-sign-in-alt"></i> Proceed to Login
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Toggle Password Visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggle = field.nextElementSibling;
            
            if (field.type === 'password') {
                field.type = 'text';
                toggle.classList.remove('fa-eye');
                toggle.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                toggle.classList.remove('fa-eye-slash');
                toggle.classList.add('fa-eye');
            }
        }

        // Check Password Strength
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
            updateRequirement('req-length', hasLength);
            updateRequirement('req-uppercase', hasUppercase);
            updateRequirement('req-lowercase', hasLowercase);
            updateRequirement('req-number', hasNumber);
            
            // Calculate strength
            let strength = 0;
            if (hasLength) strength += 25;
            if (hasUppercase) strength += 25;
            if (hasLowercase) strength += 25;
            if (hasNumber) strength += 25;
            
            strengthBar.style.width = strength + '%';
            
            // Set color and text based on strength
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
            updateRegisterButton();
        }

        // Check Password Match
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchMessage = document.getElementById('matchMessage');
            
            if (confirm.length > 0) {
                if (password === confirm) {
                    matchMessage.innerHTML = '<i class="fas fa-check-circle" style="color: #27ae60;"></i> Passwords match';
                    matchMessage.style.color = '#27ae60';
                    updateRequirement('req-match', true);
                } else {
                    matchMessage.innerHTML = '<i class="fas fa-times-circle" style="color: #e74c3c;"></i> Passwords do not match';
                    matchMessage.style.color = '#e74c3c';
                    updateRequirement('req-match', false);
                }
            } else {
                matchMessage.innerHTML = '';
                updateRequirement('req-match', false);
            }
            
            updateRegisterButton();
        }

        // Update Requirement Indicator
        function updateRequirement(elementId, isValid) {
            const element = document.getElementById(elementId);
            const icon = element.querySelector('i');
            
            if (isValid) {
                element.className = 'valid';
                icon.className = 'fas fa-check-circle';
            } else {
                element.className = 'invalid';
                icon.className = 'fas fa-times-circle';
            }
        }

        // Update Register Button State
        function updateRegisterButton() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const terms = document.getElementById('terms').checked;
            
            const hasLength = password.length >= 8;
            const hasUppercase = /[A-Z]/.test(password);
            const hasLowercase = /[a-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const passwordsMatch = password === confirm && password.length > 0;
            
            const isValid = hasLength && hasUppercase && hasLowercase && hasNumber && passwordsMatch && terms;
            
            document.getElementById('registerBtn').disabled = !isValid;
        }

        // Form Validation
        function validateForm() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            if (password !== confirm) {
                alert('Passwords do not match!');
                return false;
            }
            
            return true;
        }

        // Terms and Conditions Popup
        function showTerms() {
            alert('Terms and Conditions:\n\n1. You must be at least 18 years old to use this system.\n2. You are responsible for maintaining the confidentiality of your account.\n3. You agree to provide accurate and complete information.\n4. We reserve the right to terminate accounts that violate our terms.\n\nPlease contact administrator for complete terms and conditions.');
        }

        function showPrivacy() {
            alert('Privacy Policy:\n\n1. We collect personal information you provide to us.\n2. We use cookies to enhance your experience.\n3. Your data is encrypted and securely stored.\n4. We do not share your personal information with third parties.\n\nPlease contact administrator for complete privacy policy.');
        }

        // Real-time username availability check (optional)
        let usernameTimeout;
        document.getElementById('username').addEventListener('input', function() {
            clearTimeout(usernameTimeout);
            const username = this.value;
            
            if (username.length >= 4) {
                usernameTimeout = setTimeout(function() {
                    // You can implement AJAX check here
                    console.log('Checking username availability for: ' + username);
                }, 500);
            }
        });

        // Real-time email validation
        document.getElementById('email').addEventListener('input', function() {
            const email = this.value;
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (email.length > 0) {
                if (emailPattern.test(email)) {
                    this.style.borderColor = '#27ae60';
                } else {
                    this.style.borderColor = '#e74c3c';
                }
            } else {
                this.style.borderColor = '#e0e0e0';
            }
        });

        // Listen for terms checkbox change
        document.getElementById('terms').addEventListener('change', updateRegisterButton);

        // Initialize on page load
        window.onload = function() {
            updateRegisterButton();
        };
        
    </script>
</body>
</html>