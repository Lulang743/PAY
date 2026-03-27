<?php
session_start();
require_once 'config.php';
require_once 'auth_check.php';
require_once 'functionsd.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_attendance'])) {
        $employee_id = $_POST['employee_id'];
        $work_date = $_POST['work_date'];
        $time_in = $_POST['time_in'] ?: null;
        $time_out = $_POST['time_out'] ?: null;
        $hours_worked = $_POST['hours_worked'] ?: 0;
        $overtime_hours = $_POST['overtime_hours'] ?: 0;
        $status = $_POST['status'];
        
        // Get employee pay type
        $emp_pay_sql = "SELECT pay_type FROM employees WHERE id = ?";
        $emp_pay_stmt = $conn->prepare($emp_pay_sql);
        $emp_pay_stmt->bind_param("i", $employee_id);
        $emp_pay_stmt->execute();
        $emp_pay_result = $emp_pay_stmt->get_result();
        $employee_pay = $emp_pay_result->fetch_assoc();
        $pay_type = $employee_pay['pay_type'] ?? 'hourly';
        
        // Calculate hours worked if not manually entered and employee is hourly
        if (empty($hours_worked) && !empty($time_in) && !empty($time_out) && $pay_type == 'hourly') {
            $time_in_obj = new DateTime($time_in);
            $time_out_obj = new DateTime($time_out);
            $interval = $time_in_obj->diff($time_out_obj);
            $hours_worked = $interval->h + ($interval->i / 60);
        }
        
        // For monthly employees, set hours_worked to 0 if not provided
        if ($pay_type == 'monthly' && empty($hours_worked)) {
            $hours_worked = 0;
        }
        
        // Determine status based on time if not manually selected
        if (empty($status) && !empty($time_in)) {
            $time_in_val = new DateTime($time_in);
            $cutoff_time = new DateTime('09:00:00');
            
            if ($time_in_val > $cutoff_time) {
                $status = 'late';
            } else {
                $status = 'present';
            }
        }
        
        // Check if attendance already exists for this date
        $check_sql = "SELECT id FROM attendance WHERE employee_id = ? AND work_date = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("is", $employee_id, $work_date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing record
            $sql = "UPDATE attendance SET 
                    time_in = ?, 
                    time_out = ?, 
                    hours_worked = ?, 
                    overtime_hours = ?, 
                    status = ? 
                    WHERE employee_id = ? AND work_date = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssddsis", $time_in, $time_out, $hours_worked, $overtime_hours, $status, $employee_id, $work_date);
        } else {
            // Insert new record
            $sql = "INSERT INTO attendance (employee_id, work_date, time_in, time_out, hours_worked, overtime_hours, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isssdds", $employee_id, $work_date, $time_in, $time_out, $hours_worked, $overtime_hours, $status);
        }
        
        if ($stmt->execute()) {
            $success = "Attendance recorded successfully!";
            
            // Check attendance rules after recording
            checkAttendanceRules($conn, $employee_id, $work_date);
        } else {
            $error = "Error recording attendance: " . $conn->error;
        }
    }
    
    if (isset($_POST['bulk_attendance'])) {
        // Handle bulk attendance marking
        $work_date = $_POST['work_date'];
        $attendances = $_POST['attendance'] ?? [];
        $processed_count = 0;
        
        foreach ($attendances as $employee_id => $data) {
            $time_in = $data['time_in'] ?? null;
            $time_out = $data['time_out'] ?? null;
            $status = $data['status'] ?? 'absent';
            $hours_worked = $data['hours_worked'] ?? 0;
            $overtime_hours = $data['overtime_hours'] ?? 0;
            
            // Get employee pay type
            $emp_pay_sql = "SELECT pay_type FROM employees WHERE id = ?";
            $emp_pay_stmt = $conn->prepare($emp_pay_sql);
            $emp_pay_stmt->bind_param("i", $employee_id);
            $emp_pay_stmt->execute();
            $emp_pay_result = $emp_pay_stmt->get_result();
            $employee_pay = $emp_pay_result->fetch_assoc();
            $pay_type = $employee_pay['pay_type'] ?? 'hourly';
            
            // For monthly employees, hours_worked can be 0
            if ($pay_type == 'monthly' && empty($hours_worked)) {
                $hours_worked = 0;
            }
            
            // Check if record exists
            $check_sql = "SELECT id FROM attendance WHERE employee_id = ? AND work_date = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("is", $employee_id, $work_date);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                // Update
                $sql = "UPDATE attendance SET 
                        time_in = ?, time_out = ?, hours_worked = ?, 
                        overtime_hours = ?, status = ? 
                        WHERE employee_id = ? AND work_date = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssddsis", $time_in, $time_out, $hours_worked, $overtime_hours, $status, $employee_id, $work_date);
            } else {
                // Insert
                $sql = "INSERT INTO attendance (employee_id, work_date, time_in, time_out, hours_worked, overtime_hours, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssdds", $employee_id, $work_date, $time_in, $time_out, $hours_worked, $overtime_hours, $status);
            }
            
            if ($stmt->execute()) {
                $processed_count++;
                // Check rules for each employee
                checkAttendanceRules($conn, $employee_id, $work_date);
            }
        }
        $success = "Bulk attendance recorded successfully for $processed_count employees!";
    }
    
    // Handle rule configuration
    if (isset($_POST['save_rule'])) {
        $rule_name = $_POST['rule_name'];
        $condition_field = $_POST['condition_field'];
        $condition_operator = $_POST['condition_operator'];
        $condition_value = $_POST['condition_value'];
        $action_type = $_POST['action_type'];
        $action_target = $_POST['action_target'];
        $action_message = $_POST['action_message'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $sql = "INSERT INTO automation_rules 
                (rule_name, condition_field, condition_operator, condition_value, 
                 action_type, action_target, action_message, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssi", $rule_name, $condition_field, $condition_operator, 
                         $condition_value, $action_type, $action_target, $action_message, $is_active);
        
        if ($stmt->execute()) {
            $success = "Automation rule saved successfully!";
        } else {
            $error = "Error saving rule: " . $conn->error;
        }
    }
    
    // Handle rule deletion
    if (isset($_POST['delete_rule'])) {
        $rule_id = $_POST['rule_id'];
        $sql = "DELETE FROM automation_rules WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $rule_id);
        
        if ($stmt->execute()) {
            $success = "Rule deleted successfully!";
        } else {
            $error = "Error deleting rule: " . $conn->error;
        }
    }
    
    // Handle notification dismissal
    if (isset($_POST['dismiss_notification'])) {
        $notification_id = $_POST['notification_id'];
        $sql = "UPDATE notifications SET is_read = 1 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $notification_id);
        $stmt->execute();
    }
}

// Function to check attendance rules
function checkAttendanceRules($conn, $employee_id, $work_date) {
    // Get active rules
    $rules_sql = "SELECT * FROM automation_rules WHERE is_active = 1 ORDER BY priority DESC";
    $rules_result = $conn->query($rules_sql);
    
    if ($rules_result && $rules_result->num_rows > 0) {
        while ($rule = $rules_result->fetch_assoc()) {
            evaluateRule($conn, $rule, $employee_id, $work_date);
        }
    }
    
    // Check attendance percentage rule (built-in)
    checkAttendancePercentage($conn, $employee_id);
    
    // Check consecutive absences
    checkConsecutiveAbsences($conn, $employee_id);
}

// Function to evaluate a single rule
function evaluateRule($conn, $rule, $employee_id, $work_date) {
    $condition_met = false;
    $condition_value = $rule['condition_value'];
    
    // Get the actual value based on condition field
    switch ($rule['condition_field']) {
        case 'attendance_percentage':
            $actual_value = getAttendancePercentage($conn, $employee_id, 30); // Last 30 days
            break;
        case 'consecutive_absences':
            $actual_value = getConsecutiveAbsences($conn, $employee_id);
            break;
        case 'late_count':
            $actual_value = getLateCount($conn, $employee_id, 30); // Last 30 days
            break;
        case 'overtime_hours':
            $actual_value = getOvertimeHours($conn, $employee_id, $work_date);
            break;
        case 'hours_worked':
            $actual_value = getHoursWorked($conn, $employee_id, $work_date);
            break;
        default:
            return false;
    }
    
    // Evaluate condition
    switch ($rule['condition_operator']) {
        case '<':
            $condition_met = ($actual_value < $condition_value);
            break;
        case '<=':
            $condition_met = ($actual_value <= $condition_value);
            break;
        case '>':
            $condition_met = ($actual_value > $condition_value);
            break;
        case '>=':
            $condition_met = ($actual_value >= $condition_value);
            break;
        case '==':
            $condition_met = ($actual_value == $condition_value);
            break;
        case '!=':
            $condition_met = ($actual_value != $condition_value);
            break;
    }
    
    // If condition met, perform action
    if ($condition_met) {
        performAction($conn, $rule, $employee_id, $actual_value);
    }
}

// Function to perform rule action
function performAction($conn, $rule, $employee_id, $actual_value) {
    // Get employee details
    $emp_sql = "SELECT e.*, u.id as user_id 
                FROM employees e 
                LEFT JOIN users u ON e.id = u.employee_id 
                WHERE e.id = ?";
    $emp_stmt = $conn->prepare($emp_sql);
    $emp_stmt->bind_param("i", $employee_id);
    $emp_stmt->execute();
    $employee = $emp_stmt->get_result()->fetch_assoc();
    
    if (!$employee) return;
    
    $message = str_replace(
        ['{employee_name}', '{actual_value}', '{threshold}'],
        [$employee['first_name'] . ' ' . $employee['last_name'], $actual_value, $rule['condition_value']],
        $rule['action_message']
    );
    
    switch ($rule['action_type']) {
        case 'notify_hr':
            // Notify HR users
            $hr_sql = "SELECT u.id FROM users u WHERE u.role IN ('hr', 'admin')";
            $hr_result = $conn->query($hr_sql);
            while ($hr_user = $hr_result->fetch_assoc()) {
                createNotification($conn, $hr_user['id'], 'rule_alert', $message, $employee_id, $rule['id']);
            }
            break;
            
        case 'notify_manager':
            // Notify manager if exists
            if ($employee['manager_id']) {
                $mgr_sql = "SELECT u.id FROM users u WHERE u.employee_id = ?";
                $mgr_stmt = $conn->prepare($mgr_sql);
                $mgr_stmt->bind_param("i", $employee['manager_id']);
                $mgr_stmt->execute();
                $mgr_result = $mgr_stmt->get_result();
                if ($mgr_user = $mgr_result->fetch_assoc()) {
                    createNotification($conn, $mgr_user['id'], 'rule_alert', $message, $employee_id, $rule['id']);
                }
            }
            break;
            
        case 'require_approval':
            // Create approval request
            createApprovalRequest($conn, $employee_id, $rule, $message);
            break;
            
        case 'send_email':
            // Send email notification
            sendEmailNotification($employee['email'], 'Attendance Alert', $message);
            break;
            
        case 'log_warning':
            // Log warning in system
            logWarning($conn, $employee_id, $message);
            break;
    }
}

// Function to create notification
function createNotification($conn, $user_id, $type, $message, $employee_id = null, $rule_id = null) {
    $sql = "INSERT INTO notifications (user_id, type, message, employee_id, rule_id, created_at, is_read) 
            VALUES (?, ?, ?, ?, ?, NOW(), 0)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issii", $user_id, $type, $message, $employee_id, $rule_id);
    $stmt->execute();
}

// Function to create approval request
function createApprovalRequest($conn, $employee_id, $rule, $message) {
    $sql = "INSERT INTO approval_requests (employee_id, rule_id, request_type, message, status, created_at) 
            VALUES (?, ?, 'attendance', ?, 'pending', NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $employee_id, $rule['id'], $message);
    $stmt->execute();
}

// Function to log warning
function logWarning($conn, $employee_id, $message) {
    $sql = "INSERT INTO employee_warnings (employee_id, warning_type, message, created_at) 
            VALUES (?, 'attendance', ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $employee_id, $message);
    $stmt->execute();
}

// Function to send email (placeholder)
function sendEmailNotification($email, $subject, $message) {
    // Implement actual email sending here
    error_log("Email would be sent to $email: $subject - $message");
}

// Function to get attendance percentage
function getAttendancePercentage($conn, $employee_id, $days = 30) {
    $start_date = date('Y-m-d', strtotime("-$days days"));
    $sql = "SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_days
            FROM attendance 
            WHERE employee_id = ? AND work_date >= ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $employee_id, $start_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if ($result['total_days'] > 0) {
        return ($result['present_days'] / $result['total_days']) * 100;
    }
    return 100;
}

// Function to get consecutive absences
function getConsecutiveAbsences($conn, $employee_id) {
    $sql = "SELECT work_date, status FROM attendance 
            WHERE employee_id = ? 
            ORDER BY work_date DESC 
            LIMIT 10";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $consecutive = 0;
    $expected_date = date('Y-m-d');
    
    while ($row = $result->fetch_assoc()) {
        if ($row['status'] == 'absent') {
            $consecutive++;
        } else {
            break;
        }
    }
    
    return $consecutive;
}

// Function to get late count
function getLateCount($conn, $employee_id, $days = 30) {
    $start_date = date('Y-m-d', strtotime("-$days days"));
    $sql = "SELECT COUNT(*) as late_count FROM attendance 
            WHERE employee_id = ? AND status = 'late' AND work_date >= ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $employee_id, $start_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['late_count'] ?? 0;
}

// Function to get overtime hours
function getOvertimeHours($conn, $employee_id, $work_date) {
    $sql = "SELECT overtime_hours FROM attendance 
            WHERE employee_id = ? AND work_date = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $employee_id, $work_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['overtime_hours'] ?? 0;
}

// Function to get hours worked
function getHoursWorked($conn, $employee_id, $work_date) {
    $sql = "SELECT hours_worked FROM attendance 
            WHERE employee_id = ? AND work_date = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $employee_id, $work_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['hours_worked'] ?? 0;
}

// Function to check attendance percentage (built-in rule)
function checkAttendancePercentage($conn, $employee_id) {
    $percentage = getAttendancePercentage($conn, $employee_id, 30);
    
    if ($percentage < 80) {
        // Get employee details
        $emp_sql = "SELECT e.*, u.id as user_id 
                    FROM employees e 
                    LEFT JOIN users u ON e.id = u.employee_id 
                    WHERE e.id = ?";
        $emp_stmt = $conn->prepare($emp_sql);
        $emp_stmt->bind_param("i", $employee_id);
        $emp_stmt->execute();
        $employee = $emp_stmt->get_result()->fetch_assoc();
        
        if ($employee) {
            $message = "{$employee['first_name']} {$employee['last_name']} has attendance below 80% ({$percentage}%) in the last 30 days.";
            
            // Notify HR
            $hr_sql = "SELECT id FROM users WHERE role IN ('hr', 'admin')";
            $hr_result = $conn->query($hr_sql);
            while ($hr_user = $hr_result->fetch_assoc()) {
                createNotification($conn, $hr_user['id'], 'attendance_alert', $message, $employee_id);
            }
        }
    }
}

// Function to check consecutive absences (built-in rule)
function checkConsecutiveAbsences($conn, $employee_id) {
    $consecutive = getConsecutiveAbsences($conn, $employee_id);
    
    if ($consecutive >= 5) {
        // Get employee details
        $emp_sql = "SELECT e.*, u.id as user_id 
                    FROM employees e 
                    LEFT JOIN users u ON e.id = u.employee_id 
                    WHERE e.id = ?";
        $emp_stmt = $conn->prepare($emp_sql);
        $emp_stmt->bind_param("i", $employee_id);
        $emp_stmt->execute();
        $employee = $emp_stmt->get_result()->fetch_assoc();
        
        if ($employee) {
            $message = "{$employee['first_name']} {$employee['last_name']} has been absent for {$consecutive} consecutive days. Manager approval required.";
            
            // Create approval request
            $sql = "INSERT INTO approval_requests (employee_id, request_type, message, status, created_at) 
                    VALUES (?, 'leave_extension', ?, 'pending', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("is", $employee_id, $message);
            $stmt->execute();
            
            // Notify manager
            if ($employee['manager_id']) {
                $mgr_sql = "SELECT u.id FROM users u WHERE u.employee_id = ?";
                $mgr_stmt = $conn->prepare($mgr_sql);
                $mgr_stmt->bind_param("i", $employee['manager_id']);
                $mgr_stmt->execute();
                $mgr_result = $mgr_stmt->get_result();
                if ($mgr_user = $mgr_result->fetch_assoc()) {
                    createNotification($conn, $mgr_user['id'], 'approval_needed', $message, $employee_id);
                }
            }
        }
    }
}

// Create necessary tables if they don't exist
function createAutomationTables($conn) {
    // Automation rules table
    $rules_sql = "CREATE TABLE IF NOT EXISTS automation_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rule_name VARCHAR(255) NOT NULL,
        condition_field VARCHAR(50) NOT NULL,
        condition_operator VARCHAR(10) NOT NULL,
        condition_value DECIMAL(10,2) NOT NULL,
        action_type VARCHAR(50) NOT NULL,
        action_target VARCHAR(255),
        action_message TEXT,
        priority INT DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        created_at DATETIME,
        updated_at DATETIME,
        INDEX idx_active (is_active)
    )";
    $conn->query($rules_sql);
    
    // Notifications table
    $notifications_sql = "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(50),
        message TEXT,
        employee_id INT,
        rule_id INT,
        is_read BOOLEAN DEFAULT FALSE,
        created_at DATETIME,
        read_at DATETIME,
        INDEX idx_user (user_id, is_read),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    $conn->query($notifications_sql);
    
    // Approval requests table
    $approvals_sql = "CREATE TABLE IF NOT EXISTS approval_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        rule_id INT,
        request_type VARCHAR(50),
        message TEXT,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at DATETIME,
        reviewed_at DATETIME,
        reviewed_by INT,
        INDEX idx_status (status),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )";
    $conn->query($approvals_sql);
    
    // Employee warnings table
    $warnings_sql = "CREATE TABLE IF NOT EXISTS employee_warnings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        warning_type VARCHAR(50),
        message TEXT,
        created_at DATETIME,
        INDEX idx_employee (employee_id),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )";
    $conn->query($warnings_sql);
    
    // Insert default rules if table is empty
    $check_sql = "SELECT COUNT(*) as count FROM automation_rules";
    $check_result = $conn->query($check_sql);
    $count = $check_result->fetch_assoc()['count'];
    
    if ($count == 0) {
        $default_rules = [
            [
                'rule_name' => 'Low Attendance Alert',
                'condition_field' => 'attendance_percentage',
                'condition_operator' => '<',
                'condition_value' => 80,
                'action_type' => 'notify_hr',
                'action_message' => 'Employee {employee_name} has attendance below {threshold}% (currently {actual_value}%)',
                'priority' => 10
            ],
            [
                'rule_name' => 'Excessive Absences',
                'condition_field' => 'consecutive_absences',
                'condition_operator' => '>=',
                'condition_value' => 5,
                'action_type' => 'require_approval',
                'action_message' => 'Employee {employee_name} has been absent for {actual_value} consecutive days. Manager approval required.',
                'priority' => 8
            ],
            [
                'rule_name' => 'Frequent Lateness',
                'condition_field' => 'late_count',
                'condition_operator' => '>=',
                'condition_value' => 3,
                'action_type' => 'notify_manager',
                'action_message' => 'Employee {employee_name} has been late {actual_value} times in the last 30 days.',
                'priority' => 6
            ],
            [
                'rule_name' => 'Excessive Overtime',
                'condition_field' => 'overtime_hours',
                'condition_operator' => '>',
                'condition_value' => 4,
                'action_type' => 'log_warning',
                'action_message' => 'Employee {employee_name} worked {actual_value} hours overtime today. Please monitor.',
                'priority' => 5
            ]
        ];
        
        foreach ($default_rules as $rule) {
            $sql = "INSERT INTO automation_rules 
                    (rule_name, condition_field, condition_operator, condition_value, action_type, action_message, priority, is_active, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssdssi", 
                $rule['rule_name'], 
                $rule['condition_field'], 
                $rule['condition_operator'], 
                $rule['condition_value'], 
                $rule['action_type'], 
                $rule['action_message'], 
                $rule['priority']
            );
            $stmt->execute();
        }
    }
}

// Create automation tables
createAutomationTables($conn);

// Fetch employees for dropdown with pay type information
$emp_sql = "SELECT id, employee_id, first_name, last_name, pay_type FROM employees WHERE status = 'active' ORDER BY first_name";
$emp_result = $conn->query($emp_sql);

// Fetch all attendance records with employee pay type for filtering
$att_sql = "SELECT a.*, e.employee_id, e.first_name, e.last_name, e.pay_type 
            FROM attendance a 
            JOIN employees e ON a.employee_id = e.id 
            ORDER BY a.work_date DESC, a.time_in ASC";
$att_result = $conn->query($att_sql);

// Get today's date for bulk attendance
$today = date('Y-m-d');

// Fetch all active employees for bulk attendance with pay type
$bulk_emp_sql = "SELECT id, employee_id, first_name, last_name, pay_type FROM employees WHERE status = 'active' ORDER BY first_name";
$bulk_emp_result = $conn->query($bulk_emp_sql);

// Check if attendance already marked for today
$today_att_sql = "SELECT employee_id FROM attendance WHERE work_date = '$today'";
$today_att_result = $conn->query($today_att_sql);
$marked_employees = [];
while ($row = $today_att_result->fetch_assoc()) {
    $marked_employees[] = $row['employee_id'];
}

// Get chart data
// Monthly attendance summary for chart
$monthly_sql = "SELECT 
                DATE_FORMAT(work_date, '%Y-%m') as month,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count
                FROM attendance 
                WHERE work_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(work_date, '%Y-%m')
                ORDER BY month ASC";
$monthly_result = $conn->query($monthly_sql);

// Status distribution for today's chart
$status_sql = "SELECT 
                status,
                COUNT(*) as count
                FROM attendance 
                WHERE work_date = '$today'
                GROUP BY status";
$status_result = $conn->query($status_sql);

// Fetch active rules
$rules_sql = "SELECT * FROM automation_rules ORDER BY priority DESC, created_at DESC";
$rules_result = $conn->query($rules_sql);

// Fetch unread notifications for current user
$user_id = $_SESSION['user_id'];
$notifications_sql = "SELECT n.*, e.first_name, e.last_name 
                      FROM notifications n 
                      LEFT JOIN employees e ON n.employee_id = e.id 
                      WHERE n.user_id = ? AND n.is_read = 0 
                      ORDER BY n.created_at DESC 
                      LIMIT 10";
$notifications_stmt = $conn->prepare($notifications_sql);
$notifications_stmt->bind_param("i", $user_id);
$notifications_stmt->execute();
$notifications_result = $notifications_stmt->get_result();
$notification_count = $notifications_result->num_rows;

// Fetch pending approval requests (for managers/admins)
$pending_approvals_sql = "SELECT ar.*, e.first_name, e.last_name 
                          FROM approval_requests ar 
                          JOIN employees e ON ar.employee_id = e.id 
                          WHERE ar.status = 'pending' 
                          ORDER BY ar.created_at DESC 
                          LIMIT 10";
$pending_approvals_result = $conn->query($pending_approvals_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Management - Payroll System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f6f9;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            position: relative;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .notification-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: #e74c3c;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            cursor: pointer;
        }
        
        .notification-badge i {
            margin-right: 5px;
        }
        
        .notification-panel {
            display: none;
            position: absolute;
            top: 80px;
            right: 20px;
            width: 350px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .notification-panel.show {
            display: block;
        }
        
        .notification-header {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
        }
        
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .notification-item:hover {
            background: #f7fafc;
        }
        
        .notification-item.unread {
            background: #ebf8ff;
        }
        
        .notification-time {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
        .nav {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .nav-links a {
            color: #2c3e50;
            text-decoration: none;
            padding: 10px 20px;
            margin-right: 10px;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .nav-links a:hover {
            background: #667eea;
            color: white;
        }
        
        .nav-links a.active {
            background: #667eea;
            color: white;
        }
        
        .btn-add {
            background: #27ae60;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-add:hover {
            background: #229954;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }
        
        .btn-close-form {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }
        
        .btn-close-form:hover {
            background: #c0392b;
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card h2 {
            margin-bottom: 20px;
            color: #2c3e50;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h2 i {
            color: #667eea;
        }
        
        .form-container {
            display: none;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            margin-bottom: 20px;
            animation: slideDown 0.3s ease;
        }
        
        .form-container.show {
            display: block;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            background: #667eea;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #5a67d8;
        }
        
        .btn-success {
            background: #48bb78;
        }
        
        .btn-success:hover {
            background: #38a169;
        }
        
        .btn-warning {
            background: #ed8936;
        }
        
        .btn-warning:hover {
            background: #dd6b20;
        }
        
        .btn-danger {
            background: #f56565;
        }
        
        .btn-danger:hover {
            background: #e53e3e;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .pay-type-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .pay-type-hourly {
            background: #e6fffa;
            color: #319795;
        }
        
        .pay-type-monthly {
            background: #feebc8;
            color: #c05621;
        }
        
        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .chart-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f7fafc;
            color: #2c3e50;
            font-weight: 600;
            padding: 15px;
            text-align: left;
            font-size: 14px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }
        
        .table tr:hover {
            background: #f7fafc;
        }
        
        .success {
            background: #c6f6d5;
            color: #22543d;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #48bb78;
        }
        
        .error {
            background: #fed7d7;
            color: #742a2a;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #f56565;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-present {
            background: #c6f6d5;
            color: #22543d;
        }
        
        .status-absent {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .status-late {
            background: #feebc8;
            color: #744210;
        }
        
        .status-half-day {
            background: #e9d8fd;
            color: #553c9a;
        }
        
        .time-display {
            font-family: monospace;
            font-size: 13px;
        }
        
        .bulk-table {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
        }
        
        .bulk-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .bulk-table th {
            background: #f7fafc;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .bulk-table td, .bulk-table th {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .tab-container {
            margin-bottom: 20px;
        }
        
        .tab {
            display: inline-block;
            padding: 10px 20px;
            background: #f7fafc;
            color: #2c3e50;
            cursor: pointer;
            border-radius: 5px 5px 0 0;
            margin-right: 5px;
            border: 1px solid #e2e8f0;
            border-bottom: none;
        }
        
        .tab.active {
            background: white;
            border-bottom: 2px solid #667eea;
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
            padding: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 0 5px 5px 5px;
            background: white;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Filter Styles */
        .filters-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
            color: #2c3e50;
            font-size: 12px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filter-stats {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #666;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-stats .stats-info {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filter-stats .stats-info span {
            font-weight: 600;
            color: #667eea;
        }

        .quick-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .quick-filter-btn {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid #ddd;
            background: white;
        }

        .quick-filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .quick-filter-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .reset-btn {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
        }

        .reset-btn:hover {
            background: #7f8c8d;
        }
        
        /* Rules Grid */
        .rules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .rule-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        
        .rule-card .rule-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .rule-card .rule-name {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .rule-card .rule-badge {
            background: #667eea;
            color: white;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
        }
        
        .rule-card .rule-condition {
            background: #f7fafc;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        .rule-card .rule-action {
            background: #e6fffa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
            font-size: 13px;
        }
        
        .rule-card .rule-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #718096;
        }
        
        .rule-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .rule-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #48bb78;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        
        /* Alert badges */
        .alert-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 5px;
        }
        
        .alert-badge.warning {
            background: #feebc8;
            color: #744210;
        }
        
        .alert-badge.danger {
            background: #fed7d7;
            color: #742a2a;
        }
        
        .alert-badge.info {
            background: #bee3f8;
            color: #2c5282;
        }
        
        .input-hint {
            font-size: 11px;
            color: #718096;
            margin-top: 3px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #95a5a6;
        }
        
        .empty-state i {
            font-size: 50px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Alaki Payroll</h1>
            <p>Record and manage employee attendance with time tracking</p>
            
            <!-- Notification Badge -->
            <?php if ($notification_count > 0): ?>
            <div class="notification-badge" onclick="toggleNotifications()">
                <i class="fas fa-bell"></i> <?php echo $notification_count; ?> New
            </div>
            <?php endif; ?>
            
            <!-- Notification Panel -->
            <div class="notification-panel" id="notificationPanel">
                <div class="notification-header">
                    <i class="fas fa-bell"></i> Notifications
                </div>
                <?php if ($notifications_result && $notifications_result->num_rows > 0): ?>
                    <?php while($notification = $notifications_result->fetch_assoc()): ?>
                    <div class="notification-item unread">
                        <strong><?php echo htmlspecialchars($notification['message']); ?></strong>
                        <?php if ($notification['first_name']): ?>
                        <div style="font-size: 12px; color: #718096; margin-top: 5px;">
                            Employee: <?php echo $notification['first_name'] . ' ' . $notification['last_name']; ?>
                        </div>
                        <?php endif; ?>
                        <div class="notification-time">
                            <?php echo date('M d, H:i', strtotime($notification['created_at'])); ?>
                        </div>
                        <form method="POST" style="margin-top: 5px;">
                            <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                            <button type="submit" name="dismiss_notification" class="btn btn-sm" style="font-size: 11px; padding: 3px 8px;">
                                Dismiss
                            </button>
                        </form>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding: 20px; text-align: center; color: #718096;">
                        No new notifications
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="nav">
            <div class="nav-links">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="employees.php"><i class="fas fa-users"></i> Employees</a>
                <a href="attendance.php" class="active"><i class="fas fa-clock"></i> Attendance</a>
                <a href="leave.php"><i class="fas fa-calendar-alt"></i> Leaves</a>
                <a href="payroll.php"><i class="fas fa-money-bill"></i> Payroll</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>
            <div>
                <button class="btn-add" onclick="toggleSingleForm()">
                    <i class="fas fa-plus-circle"></i> Record Attendance
                </button>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Pending Approvals Alert -->
        <?php if ($pending_approvals_result && $pending_approvals_result->num_rows > 0): ?>
        <div class="card" style="background: #feebc8; border-left: 4px solid #ed8936;">
            <h3 style="color: #744210; margin-bottom: 10px;">
                <i class="fas fa-clock"></i> Pending Approvals
            </h3>
            <?php while($approval = $pending_approvals_result->fetch_assoc()): ?>
            <div style="padding: 10px; background: white; border-radius: 5px; margin-bottom: 10px;">
                <strong><?php echo $approval['first_name'] . ' ' . $approval['last_name']; ?></strong>
                <p><?php echo $approval['message']; ?></p>
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button class="btn btn-success btn-sm" onclick="approveRequest(<?php echo $approval['id']; ?>)">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="btn btn-danger btn-sm" onclick="rejectRequest(<?php echo $approval['id']; ?>)">
                        <i class="fas fa-times"></i> Reject
                    </button>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
        
        <!-- Single Entry Form (Hidden by default) -->
        <div class="form-container" id="singleForm">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="color: #2c3e50;"><i class="fas fa-user-plus"></i> Record Individual Attendance</h3>
                <button class="btn-close-form" onclick="toggleSingleForm()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
            
            <form method="POST" action="" id="attendanceForm">
                <div class="form-row">
                    <div class="form-group">
                        <label>Employee:</label>
                        <select name="employee_id" id="employee_id" required onchange="updatePayType()">
                            <option value="">Select Employee</option>
                            <?php 
                            $emp_result->data_seek(0);
                            while($emp = $emp_result->fetch_assoc()): 
                                $pay_type_label = $emp['pay_type'] == 'monthly' ? 'Monthly' : 'Hourly';
                                $badge_class = $emp['pay_type'] == 'monthly' ? 'pay-type-monthly' : 'pay-type-hourly';
                            ?>
                            <option value="<?php echo $emp['id']; ?>" data-pay-type="<?php echo $emp['pay_type']; ?>">
                                <?php echo $emp['employee_id'] . ' - ' . $emp['first_name'] . ' ' . $emp['last_name']; ?>
                                <span class="pay-type-badge <?php echo $badge_class; ?>"><?php echo $pay_type_label; ?></span>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Work Date:</label>
                        <input type="date" name="work_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Time In:</label>
                        <input type="time" name="time_in" id="time_in" step="60" onchange="calculateHours()">
                    </div>
                    
                    <div class="form-group">
                        <label>Time Out:</label>
                        <input type="time" name="time_out" id="time_out" step="60" onchange="calculateHours()">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Hours Worked:</label>
                        <input type="number" step="0.5" name="hours_worked" id="hours_worked" value="8">
                        <div class="input-hint" id="hours_hint"></div>
                    </div>
                    
                    <div class="form-group">
                        <label>Overtime Hours:</label>
                        <input type="number" step="0.5" name="overtime_hours" id="overtime_hours" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label>Status:</label>
                        <select name="status" id="status">
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="late">Late</option>
                            <option value="half-day">Half Day</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" name="add_attendance" class="btn">
                    <i class="fas fa-save"></i> Record Attendance
                </button>
            </form>
        </div>
        
        <!-- Charts Row -->
        <div class="charts-row">
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Monthly Attendance Trend</h3>
                </div>
                <div class="chart-container">
                    <canvas id="attendanceChart"></canvas>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Today's Attendance Status</h3>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="tab-container">
            <div class="tab active" onclick="showTab('single')">Single Entry</div>
            <div class="tab" onclick="showTab('bulk')">Bulk Entry</div>
            <div class="tab" onclick="showTab('records')">View Records</div>
        </div>
        
        <!-- Single Entry Tab -->
        <div id="single-tab" class="tab-content active">
            <div class="card">
                <h2><i class="fas fa-user-plus"></i> Record Individual Attendance</h2>
                
                <form method="POST" action="" id="attendanceForm2">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Employee:</label>
                            <select name="employee_id" id="employee_id2" required onchange="updatePayType2()">
                                <option value="">Select Employee</option>
                                <?php 
                                $emp_result->data_seek(0);
                                while($emp = $emp_result->fetch_assoc()): 
                                    $pay_type_label = $emp['pay_type'] == 'monthly' ? 'Monthly' : 'Hourly';
                                    $badge_class = $emp['pay_type'] == 'monthly' ? 'pay-type-monthly' : 'pay-type-hourly';
                                ?>
                                <option value="<?php echo $emp['id']; ?>" data-pay-type="<?php echo $emp['pay_type']; ?>">
                                    <?php echo $emp['employee_id'] . ' - ' . $emp['first_name'] . ' ' . $emp['last_name']; ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                            <div id="pay_type_display2" class="input-hint"></div>
                        </div>
                        
                        <div class="form-group">
                            <label>Work Date:</label>
                            <input type="date" name="work_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Time In:</label>
                            <input type="time" name="time_in" id="time_in2" step="60" onchange="calculateHours2()">
                        </div>
                        
                        <div class="form-group">
                            <label>Time Out:</label>
                            <input type="time" name="time_out" id="time_out2" step="60" onchange="calculateHours2()">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Hours Worked:</label>
                            <input type="number" step="0.5" name="hours_worked" id="hours_worked2" value="8">
                            <div class="input-hint" id="hours_hint2"></div>
                        </div>
                        
                        <div class="form-group">
                            <label>Overtime Hours:</label>
                            <input type="number" step="0.5" name="overtime_hours" id="overtime_hours2" value="0">
                        </div>
                        
                        <div class="form-group">
                            <label>Status:</label>
                            <select name="status" id="status2">
                                <option value="present">Present</option>
                                <option value="absent">Absent</option>
                                <option value="late">Late</option>
                                <option value="half-day">Half Day</option>
                            </select>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_attendance" class="btn">
                        <i class="fas fa-save"></i> Record Attendance
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Bulk Entry Tab -->
        <div id="bulk-tab" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-users"></i> Bulk Attendance Entry</h2>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Attendance Date:</label>
                        <input type="date" name="work_date" value="<?php echo $today; ?>" required>
                    </div>
                    
                    <div class="bulk-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Pay Type</th>
                                    <th>Time In</th>
                                    <th>Time Out</th>
                                    <th>Hours</th>
                                    <th>OT</th>
                                    <th>Status</th>
                                 </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $bulk_emp_result->data_seek(0);
                                while($emp = $bulk_emp_result->fetch_assoc()): 
                                $marked = in_array($emp['id'], $marked_employees);
                                $pay_type_label = $emp['pay_type'] == 'monthly' ? 'Monthly' : 'Hourly';
                                $badge_class = $emp['pay_type'] == 'monthly' ? 'pay-type-monthly' : 'pay-type-hourly';
                                ?>
                                 <tr>
                                    <td>
                                        <?php echo $emp['first_name'] . ' ' . $emp['last_name']; ?>
                                        <input type="hidden" name="attendance[<?php echo $emp['id']; ?>][employee_id]" value="<?php echo $emp['id']; ?>">
                                    </td>
                                    <td>
                                        <span class="pay-type-badge <?php echo $badge_class; ?>"><?php echo $pay_type_label; ?></span>
                                    </td>
                                    <td>
                                        <input type="time" name="attendance[<?php echo $emp['id']; ?>][time_in]" class="time_in_<?php echo $emp['id']; ?>" onchange="calculateBulkHours(<?php echo $emp['id']; ?>)">
                                    </td>
                                    <td>
                                        <input type="time" name="attendance[<?php echo $emp['id']; ?>][time_out]" class="time_out_<?php echo $emp['id']; ?>" onchange="calculateBulkHours(<?php echo $emp['id']; ?>)">
                                    </td>
                                    <td>
                                        <input type="number" step="0.5" name="attendance[<?php echo $emp['id']; ?>][hours_worked]" class="hours_<?php echo $emp['id']; ?>" value="8" style="width: 70px;">
                                    </td>
                                    <td>
                                        <input type="number" step="0.5" name="attendance[<?php echo $emp['id']; ?>][overtime_hours]" value="0" style="width: 70px;">
                                    </td>
                                    <td>
                                        <select name="attendance[<?php echo $emp['id']; ?>][status]">
                                            <option value="present">Present</option>
                                            <option value="absent">Absent</option>
                                            <option value="late">Late</option>
                                            <option value="half-day">Half Day</option>
                                        </select>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" name="bulk_attendance" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Bulk Attendance
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Records Tab with Filtering -->
        <div id="records-tab" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-history"></i> Attendance Records</h2>
                
                <!-- Filter Section -->
                <div class="filters-container">
                    <div class="filter-group">
                        <label><i class="fas fa-search"></i> Search</label>
                        <input type="text" id="searchInput" placeholder="Employee name or ID...">
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Date From</label>
                        <input type="date" id="dateFrom">
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Date To</label>
                        <input type="date" id="dateTo">
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-clock"></i> Pay Type</label>
                        <select id="payTypeFilter">
                            <option value="">All</option>
                            <option value="hourly">Hourly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-tag"></i> Status</label>
                        <select id="statusFilter">
                            <option value="">All</option>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="late">Late</option>
                            <option value="half-day">Half Day</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label><i class="fas fa-chart-line"></i> Sort By</label>
                        <select id="sortBy">
                            <option value="date_desc">Date (Newest First)</option>
                            <option value="date_asc">Date (Oldest First)</option>
                            <option value="name_asc">Name (A-Z)</option>
                            <option value="name_desc">Name (Z-A)</option>
                            <option value="hours_desc">Hours (High to Low)</option>
                            <option value="hours_asc">Hours (Low to High)</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <button id="resetFilters" class="reset-btn">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </div>
                
                <!-- Quick Filters -->
                <div class="quick-filters">
                    <button class="quick-filter-btn" data-filter="today">Today</button>
                    <button class="quick-filter-btn" data-filter="week">This Week</button>
                    <button class="quick-filter-btn" data-filter="month">This Month</button>
                    <button class="quick-filter-btn" data-status="present">Present</button>
                    <button class="quick-filter-btn" data-status="absent">Absent</button>
                    <button class="quick-filter-btn" data-status="late">Late</button>
                    <button class="quick-filter-btn" data-status="half-day">Half Day</button>
                </div>
                
                <!-- Filter Stats -->
                <div class="filter-stats" id="filterStats">
                    <div class="stats-info">
                        <i class="fas fa-chart-line"></i> Showing <span id="visibleCount">0</span> of <span id="totalCount">0</span> records
                    </div>
                    <div class="stats-info" id="summaryStats"></div>
                </div>
                
                <div class="table-container">
                    <table class="table" id="attendanceTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Employee ID</th>
                                <th>Employee Name</th>
                                <th>Pay Type</th>
                                <th>Time In</th>
                                <th>Time Out</th>
                                <th>Regular Hours</th>
                                <th>Overtime</th>
                                <th>Total Hours</th>
                                <th>Status</th>
                                <th>Alerts</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceTableBody">
                            <?php if ($att_result && $att_result->num_rows > 0): ?>
                                <?php 
                                $att_result->data_seek(0);
                                while($att = $att_result->fetch_assoc()): 
                                    $attendance_pct = getAttendancePercentage($conn, $att['employee_id'], 30);
                                    $pay_type_label = $att['pay_type'] == 'monthly' ? 'Monthly' : 'Hourly';
                                    $badge_class = $att['pay_type'] == 'monthly' ? 'pay-type-monthly' : 'pay-type-hourly';
                                ?>
                                <tr class="attendance-row" 
                                    data-date="<?php echo $att['work_date']; ?>"
                                    data-employee-id="<?php echo htmlspecialchars($att['employee_id']); ?>"
                                    data-employee-name="<?php echo htmlspecialchars($att['first_name'] . ' ' . $att['last_name']); ?>"
                                    data-pay-type="<?php echo $att['pay_type']; ?>"
                                    data-status="<?php echo $att['status'] ?? 'present'; ?>"
                                    data-hours="<?php echo $att['hours_worked'] + $att['overtime_hours']; ?>">
                                    <td><?php echo date('Y-m-d', strtotime($att['work_date'])); ?></td>
                                    <td><?php echo $att['employee_id']; ?></td>
                                    <td><?php echo $att['first_name'] . ' ' . $att['last_name']; ?></td>
                                    <td>
                                        <span class="pay-type-badge <?php echo $badge_class; ?>"><?php echo $pay_type_label; ?></span>
                                    </td>
                                    <td class="time-display">
                                        <?php 
                                        if (!empty($att['time_in'])) {
                                            echo date('h:i A', strtotime($att['time_in']));
                                        } else {
                                            echo '--';
                                        }
                                        ?>
                                    </td>
                                    <td class="time-display">
                                        <?php 
                                        if (!empty($att['time_out'])) {
                                            echo date('h:i A', strtotime($att['time_out']));
                                        } else {
                                            echo '--';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo number_format($att['hours_worked'], 2); ?></td>
                                    <td><?php echo number_format($att['overtime_hours'], 2); ?></td>
                                    <td><?php echo number_format($att['hours_worked'] + $att['overtime_hours'], 2); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $att['status'] ?? 'present'; ?>">
                                            <?php echo ucfirst($att['status'] ?? 'present'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($attendance_pct < 80): ?>
                                            <span class="alert-badge warning" title="Attendance below 80%">
                                                <i class="fas fa-exclamation-triangle"></i> <?php echo number_format($attendance_pct, 1); ?>%
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="empty-state">
                                        <i class="fas fa-info-circle"></i> No attendance records found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2><i class="fas fa-calculator"></i> Generate Payroll</h2>
            <form action="payroll.php" method="GET">
                <div class="form-row">
                    <div class="form-group">
                        <label>Pay Period Start:</label>
                        <input type="date" name="start_date" required value="<?php echo date('Y-m-01'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Pay Period End:</label>
                        <input type="date" name="end_date" required value="<?php echo date('Y-m-t'); ?>">
                    </div>
                </div>
                <button type="submit" name="generate_payroll" class="btn btn-success">
                    <i class="fas fa-file-invoice"></i> Generate Payroll
                </button>
            </form>
        </div>
    </div>
    
    <!-- Automation Rules Section -->
    <div class="card">
        <h2><i class="fas fa-robot"></i> Attendance Automation Rules</h2>
        
        <!-- Add Rule Form -->
        <form method="POST" action="" style="background: #f7fafc; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
            <h3 style="margin-bottom: 15px;">Create New Rule</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Rule Name</label>
                    <input type="text" name="rule_name" placeholder="e.g., Low Attendance Alert" required>
                </div>
                
                <div class="form-group">
                    <label>Condition Field</label>
                    <select name="condition_field" required>
                        <option value="attendance_percentage">Attendance %</option>
                        <option value="consecutive_absences">Consecutive Absences</option>
                        <option value="late_count">Late Count (30 days)</option>
                        <option value="overtime_hours">Overtime Hours (today)</option>
                        <option value="hours_worked">Hours Worked (today)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Operator</label>
                    <select name="condition_operator" required>
                        <option value="<">Less than (<)</option>
                        <option value="<=">Less than or equal (<=)</option>
                        <option value=">">Greater than (>)</option>
                        <option value=">=">Greater than or equal (>=)</option>
                        <option value="==">Equal (==)</option>
                        <option value="!=">Not equal (!=)</option>
                    </select>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Condition Value</label>
                    <input type="number" step="0.01" name="condition_value" placeholder="e.g., 80" required>
                </div>
                
                <div class="form-group">
                    <label>Action Type</label>
                    <select name="action_type" required>
                        <option value="notify_hr">Notify HR</option>
                        <option value="notify_manager">Notify Manager</option>
                        <option value="require_approval">Require Manager Approval</option>
                        <option value="send_email">Send Email</option>
                        <option value="log_warning">Log Warning</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Action Message (use {employee_name}, {actual_value}, {threshold})</label>
                <textarea name="action_message" rows="3" required 
                    placeholder="e.g., Employee {employee_name} has attendance below {threshold}% (currently {actual_value}%)"></textarea>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" checked> Active
                </label>
            </div>
            
            <button type="submit" name="save_rule" class="btn btn-success">
                <i class="fas fa-save"></i> Save Rule
            </button>
        </form>
        
        <!-- Existing Rules -->
        <div class="rules-grid">
            <?php if ($rules_result && $rules_result->num_rows > 0): ?>
                <?php while($rule = $rules_result->fetch_assoc()): ?>
                <div class="rule-card">
                    <div class="rule-header">
                        <span class="rule-name"><?php echo htmlspecialchars($rule['rule_name']); ?></span>
                        <span class="rule-badge">Priority <?php echo $rule['priority']; ?></span>
                    </div>
                    
                    <div class="rule-condition">
                        <strong>IF</strong> <?php echo str_replace('_', ' ', $rule['condition_field']); ?> 
                        <?php echo $rule['condition_operator']; ?> <?php echo $rule['condition_value']; ?>
                    </div>
                    
                    <div class="rule-action">
                        <strong>THEN</strong> <?php echo str_replace('_', ' ', $rule['action_type']); ?>
                    </div>
                    
                    <div style="font-size: 12px; margin-bottom: 10px; color: #4a5568;">
                        <?php echo htmlspecialchars($rule['action_message']); ?>
                    </div>
                    
                    <div class="rule-footer">
                        <span>Created: <?php echo date('M d, Y', strtotime($rule['created_at'])); ?></span>
                        
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
                            <button type="submit" name="delete_rule" class="btn btn-danger btn-sm" 
                                    onclick="return confirm('Delete this rule?')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                    
                    <label class="rule-toggle" style="margin-top: 10px;">
                        <input type="checkbox" <?php echo $rule['is_active'] ? 'checked' : ''; ?> 
                               onchange="toggleRule(<?php echo $rule['id']; ?>, this.checked)">
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #95a5a6;">
                    <i class="fas fa-robot fa-3x mb-3"></i>
                    <p>No automation rules created yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Toggle Single Form
        function toggleSingleForm() {
            const form = document.getElementById('singleForm');
            form.classList.toggle('show');
            if (form.classList.contains('show')) {
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Toggle Notifications
        function toggleNotifications() {
            const panel = document.getElementById('notificationPanel');
            panel.classList.toggle('show');
        }

        // Close notifications when clicking outside
        document.addEventListener('click', function(event) {
            const panel = document.getElementById('notificationPanel');
            const badge = document.querySelector('.notification-badge');
            
            if (panel && badge && !panel.contains(event.target) && !badge.contains(event.target)) {
                panel.classList.remove('show');
            }
        });

        // Tab switching
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }
        
        // Update pay type hint for first form
        function updatePayType() {
            const select = document.getElementById('employee_id');
            const selectedOption = select.options[select.selectedIndex];
            const payType = selectedOption ? selectedOption.dataset.payType : null;
            const hoursField = document.getElementById('hours_worked');
            const hoursHint = document.getElementById('hours_hint');
            
            if (payType === 'monthly') {
                hoursHint.innerHTML = 'Optional for monthly employees';
                hoursField.placeholder = 'Optional';
            } else {
                hoursHint.innerHTML = 'Required for hourly employees';
                hoursField.placeholder = 'Required';
            }
        }
        
        // Update pay type hint for second form
        function updatePayType2() {
            const select = document.getElementById('employee_id2');
            const selectedOption = select.options[select.selectedIndex];
            const payType = selectedOption ? selectedOption.dataset.payType : null;
            const hoursField = document.getElementById('hours_worked2');
            const hoursHint = document.getElementById('hours_hint2');
            const payTypeDisplay = document.getElementById('pay_type_display2');
            
            if (payType === 'monthly') {
                hoursHint.innerHTML = 'Optional for monthly employees';
                hoursField.placeholder = 'Optional';
                payTypeDisplay.innerHTML = 'Monthly-paid employee - hours are optional';
            } else {
                hoursHint.innerHTML = 'Required for hourly employees';
                hoursField.placeholder = 'Required';
                payTypeDisplay.innerHTML = 'Hourly-paid employee - hours required';
            }
        }
        
        // Calculate hours worked based on time in and time out (for hidden form)
        function calculateHours() {
            const select = document.getElementById('employee_id');
            const selectedOption = select.options[select.selectedIndex];
            const payType = selectedOption ? selectedOption.dataset.payType : 'hourly';
            
            const timeIn = document.getElementById('time_in').value;
            const timeOut = document.getElementById('time_out').value;
            
            if (timeIn && timeOut && payType === 'hourly') {
                const [inHour, inMin] = timeIn.split(':').map(Number);
                const [outHour, outMin] = timeOut.split(':').map(Number);
                
                let hours = outHour - inHour;
                let minutes = outMin - inMin;
                
                if (minutes < 0) {
                    hours--;
                    minutes += 60;
                }
                
                const totalHours = hours + (minutes / 60);
                
                // Standard work day is 8 hours
                if (totalHours > 8) {
                    document.getElementById('hours_worked').value = 8;
                    document.getElementById('overtime_hours').value = (totalHours - 8).toFixed(1);
                } else {
                    document.getElementById('hours_worked').value = totalHours.toFixed(1);
                    document.getElementById('overtime_hours').value = 0;
                }
                
                // Determine if late (after 9 AM)
                if (inHour > 9 || (inHour === 9 && inMin > 0)) {
                    document.getElementById('status').value = 'late';
                } else {
                    document.getElementById('status').value = 'present';
                }
            } else if (payType === 'monthly') {
                // For monthly employees, hours are optional
                document.getElementById('hours_worked').value = 0;
                document.getElementById('overtime_hours').value = 0;
            }
        }
        
        // Calculate hours for the tab form
        function calculateHours2() {
            const select = document.getElementById('employee_id2');
            const selectedOption = select.options[select.selectedIndex];
            const payType = selectedOption ? selectedOption.dataset.payType : 'hourly';
            
            const timeIn = document.getElementById('time_in2').value;
            const timeOut = document.getElementById('time_out2').value;
            
            if (timeIn && timeOut && payType === 'hourly') {
                const [inHour, inMin] = timeIn.split(':').map(Number);
                const [outHour, outMin] = timeOut.split(':').map(Number);
                
                let hours = outHour - inHour;
                let minutes = outMin - inMin;
                
                if (minutes < 0) {
                    hours--;
                    minutes += 60;
                }
                
                const totalHours = hours + (minutes / 60);
                
                if (totalHours > 8) {
                    document.getElementById('hours_worked2').value = 8;
                    document.getElementById('overtime_hours2').value = (totalHours - 8).toFixed(1);
                } else {
                    document.getElementById('hours_worked2').value = totalHours.toFixed(1);
                    document.getElementById('overtime_hours2').value = 0;
                }
                
                if (inHour > 9 || (inHour === 9 && inMin > 0)) {
                    document.getElementById('status2').value = 'late';
                } else {
                    document.getElementById('status2').value = 'present';
                }
            } else if (payType === 'monthly') {
                // For monthly employees, hours are optional
                document.getElementById('hours_worked2').value = 0;
                document.getElementById('overtime_hours2').value = 0;
            }
        }
        
        // Calculate bulk hours
        function calculateBulkHours(employeeId) {
            const timeIn = document.querySelector('.time_in_' + employeeId).value;
            const timeOut = document.querySelector('.time_out_' + employeeId).value;
            const hoursField = document.querySelector('.hours_' + employeeId);
            
            if (timeIn && timeOut) {
                const [inHour, inMin] = timeIn.split(':').map(Number);
                const [outHour, outMin] = timeOut.split(':').map(Number);
                
                let hours = outHour - inHour;
                let minutes = outMin - inMin;
                
                if (minutes < 0) {
                    hours--;
                    minutes += 60;
                }
                
                const totalHours = hours + (minutes / 60);
                
                if (totalHours > 8) {
                    hoursField.value = 8;
                } else {
                    hoursField.value = totalHours.toFixed(1);
                }
            }
        }

        // Toggle rule active status
        function toggleRule(ruleId, isActive) {
            fetch('update_rule_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    rule_id: ruleId,
                    is_active: isActive
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Rule updated successfully');
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }

        // Approve request
        function approveRequest(requestId) {
            if (confirm('Approve this request?')) {
                fetch('approve_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        request_id: requestId,
                        action: 'approve'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error approving request');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error processing request');
                });
            }
        }

        // Reject request
        function rejectRequest(requestId) {
            if (confirm('Reject this request?')) {
                fetch('approve_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        request_id: requestId,
                        action: 'reject'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error rejecting request');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error processing request');
                });
            }
        }

        // Chart data
        const monthlyData = <?php
            $months = [];
            $present = [];
            $late = [];
            $absent = [];
            if ($monthly_result && $monthly_result->num_rows > 0) {
                while ($row = $monthly_result->fetch_assoc()) {
                    $months[] = date('M Y', strtotime($row['month'] . '-01'));
                    $present[] = $row['present_count'];
                    $late[] = $row['late_count'];
                    $absent[] = $row['absent_count'];
                }
            }
            echo json_encode([
                'months' => $months,
                'present' => $present,
                'late' => $late,
                'absent' => $absent
            ]);
        ?>;

        // Attendance Trend Chart
        const ctx1 = document.getElementById('attendanceChart').getContext('2d');
        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: monthlyData.months,
                datasets: [{
                    label: 'Present',
                    data: monthlyData.present,
                    borderColor: '#48bb78',
                    backgroundColor: 'rgba(72, 187, 120, 0.1)',
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 4
                }, {
                    label: 'Late',
                    data: monthlyData.late,
                    borderColor: '#ed8936',
                    backgroundColor: 'rgba(237, 137, 54, 0.1)',
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 4
                }, {
                    label: 'Absent',
                    data: monthlyData.absent,
                    borderColor: '#f56565',
                    backgroundColor: 'rgba(245, 101, 101, 0.1)',
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11 } }
                    }
                }
            }
        });

        // Status Distribution Chart
        const statusData = <?php
            $status_counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'half-day' => 0];
            if ($status_result && $status_result->num_rows > 0) {
                while ($row = $status_result->fetch_assoc()) {
                    $status_counts[$row['status']] = $row['count'];
                }
            }
            echo json_encode([
                ['Present', $status_counts['present']],
                ['Late', $status_counts['late']],
                ['Absent', $status_counts['absent']],
                ['Half Day', $status_counts['half-day']]
            ]);
        ?>;

        const ctx2 = document.getElementById('statusChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: statusData.map(item => item[0]),
                datasets: [{
                    data: statusData.map(item => item[1]),
                    backgroundColor: ['#48bb78', '#ed8936', '#f56565', '#9f7aea'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11 } }
                    }
                },
                cutout: '60%'
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.success, .error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Attendance Records Filtering
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const dateFrom = document.getElementById('dateFrom');
            const dateTo = document.getElementById('dateTo');
            const payTypeFilter = document.getElementById('payTypeFilter');
            const statusFilter = document.getElementById('statusFilter');
            const sortBy = document.getElementById('sortBy');
            const resetBtn = document.getElementById('resetFilters');
            const attendanceRows = document.querySelectorAll('.attendance-row');
            const totalCountSpan = document.getElementById('totalCount');
            const visibleCountSpan = document.getElementById('visibleCount');
            const summaryStats = document.getElementById('summaryStats');
            
            // Store all attendance data
            let attendanceData = Array.from(attendanceRows);
            
            // Update total count
            if (totalCountSpan) {
                totalCountSpan.textContent = attendanceData.length;
            }
            
            // Function to filter and display attendance records
            function filterAttendance() {
                const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
                const fromDate = dateFrom ? dateFrom.value : '';
                const toDate = dateTo ? dateTo.value : '';
                const payType = payTypeFilter ? payTypeFilter.value : '';
                const status = statusFilter ? statusFilter.value : '';
                const sortValue = sortBy ? sortBy.value : 'date_desc';
                
                let visibleRows = attendanceData.filter(row => {
                    const date = row.getAttribute('data-date') || '';
                    const employeeId = row.getAttribute('data-employee-id')?.toLowerCase() || '';
                    const employeeName = row.getAttribute('data-employee-name')?.toLowerCase() || '';
                    const rowPayType = row.getAttribute('data-pay-type') || '';
                    const rowStatus = row.getAttribute('data-status') || '';
                    
                    // Search filter
                    const matchesSearch = searchTerm === '' || 
                        employeeId.includes(searchTerm) ||
                        employeeName.includes(searchTerm);
                    
                    // Date range filter
                    let matchesDate = true;
                    if (fromDate && date < fromDate) matchesDate = false;
                    if (toDate && date > toDate) matchesDate = false;
                    
                    // Pay type filter
                    const matchesPayType = payType === '' || rowPayType === payType;
                    
                    // Status filter
                    const matchesStatus = status === '' || rowStatus === status;
                    
                    return matchesSearch && matchesDate && matchesPayType && matchesStatus;
                });
                
                // Apply sorting
                visibleRows = sortAttendance(visibleRows, sortValue);
                
                // Calculate summary stats
                calculateSummary(visibleRows);
                
                // Update display
                const tbody = document.getElementById('attendanceTableBody');
                if (tbody) {
                    tbody.innerHTML = '';
                    if (visibleRows.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="11" class="empty-state">
                                    <i class="fas fa-search"></i>
                                    <p>No attendance records match your filters.</p>
                                    <button class="btn btn-primary" onclick="document.getElementById('resetFilters').click()" style="margin-top: 10px;">
                                        <i class="fas fa-undo"></i> Clear Filters
                                    </button>
                                </td>
                            </tr>
                        `;
                    } else {
                        visibleRows.forEach(row => {
                            tbody.appendChild(row.cloneNode(true));
                        });
                    }
                }
                
                // Update visible count
                if (visibleCountSpan) {
                    visibleCountSpan.textContent = visibleRows.length;
                }
            }
            
            // Function to sort attendance records
            function sortAttendance(rows, sortValue) {
                const sortedRows = [...rows];
                
                sortedRows.sort((a, b) => {
                    switch(sortValue) {
                        case 'date_asc':
                            return a.getAttribute('data-date').localeCompare(b.getAttribute('data-date'));
                        case 'date_desc':
                            return b.getAttribute('data-date').localeCompare(a.getAttribute('data-date'));
                        case 'name_asc':
                            return a.getAttribute('data-employee-name').localeCompare(b.getAttribute('data-employee-name'));
                        case 'name_desc':
                            return b.getAttribute('data-employee-name').localeCompare(a.getAttribute('data-employee-name'));
                        case 'hours_asc':
                            return parseFloat(a.getAttribute('data-hours')) - parseFloat(b.getAttribute('data-hours'));
                        case 'hours_desc':
                            return parseFloat(b.getAttribute('data-hours')) - parseFloat(a.getAttribute('data-hours'));
                        default:
                            return b.getAttribute('data-date').localeCompare(a.getAttribute('data-date'));
                    }
                });
                
                return sortedRows;
            }
            
            // Function to calculate summary statistics
            function calculateSummary(rows) {
                if (!summaryStats) return;
                
                let totalHours = 0;
                let presentCount = 0;
                let lateCount = 0;
                let absentCount = 0;
                let halfDayCount = 0;
                
                rows.forEach(row => {
                    const hours = parseFloat(row.getAttribute('data-hours')) || 0;
                    const status = row.getAttribute('data-status');
                    
                    totalHours += hours;
                    
                    switch(status) {
                        case 'present':
                            presentCount++;
                            break;
                        case 'late':
                            lateCount++;
                            break;
                        case 'absent':
                            absentCount++;
                            break;
                        case 'half-day':
                            halfDayCount++;
                            break;
                    }
                });
                
                summaryStats.innerHTML = `
                    <span><i class="fas fa-clock"></i> Total Hours: ${totalHours.toFixed(1)}</span>
                    <span><i class="fas fa-check-circle" style="color: #48bb78;"></i> Present: ${presentCount}</span>
                    <span><i class="fas fa-exclamation-triangle" style="color: #ed8936;"></i> Late: ${lateCount}</span>
                    <span><i class="fas fa-times-circle" style="color: #f56565;"></i> Absent: ${absentCount}</span>
                    <span><i class="fas fa-adjust"></i> Half Day: ${halfDayCount}</span>
                `;
            }
            
            // Function to reset all filters
            function resetFilters() {
                if (searchInput) searchInput.value = '';
                if (dateFrom) dateFrom.value = '';
                if (dateTo) dateTo.value = '';
                if (payTypeFilter) payTypeFilter.value = '';
                if (statusFilter) statusFilter.value = '';
                if (sortBy) sortBy.value = 'date_desc';
                filterAttendance();
            }
            
            // Quick filter functionality
            function setupQuickFilters() {
                const quickFilters = document.querySelectorAll('.quick-filter-btn');
                quickFilters.forEach(btn => {
                    btn.addEventListener('click', function() {
                        const filterType = this.getAttribute('data-filter');
                        const filterStatus = this.getAttribute('data-status');
                        const today = new Date();
                        
                        // Remove active class from all
                        quickFilters.forEach(b => b.classList.remove('active'));
                        this.classList.add('active');
                        
                        if (filterType === 'today') {
                            const todayStr = today.toISOString().split('T')[0];
                            if (dateFrom) dateFrom.value = todayStr;
                            if (dateTo) dateTo.value = todayStr;
                        } else if (filterType === 'week') {
                            const weekAgo = new Date(today);
                            weekAgo.setDate(today.getDate() - 7);
                            if (dateFrom) dateFrom.value = weekAgo.toISOString().split('T')[0];
                            if (dateTo) dateTo.value = today.toISOString().split('T')[0];
                        } else if (filterType === 'month') {
                            const monthAgo = new Date(today);
                            monthAgo.setMonth(today.getMonth() - 1);
                            if (dateFrom) dateFrom.value = monthAgo.toISOString().split('T')[0];
                            if (dateTo) dateTo.value = today.toISOString().split('T')[0];
                        } else if (filterStatus) {
                            if (statusFilter) statusFilter.value = filterStatus;
                            if (dateFrom) dateFrom.value = '';
                            if (dateTo) dateTo.value = '';
                        }
                        
                        // Trigger filter
                        filterAttendance();
                    });
                });
            }
            
            // Add event listeners
            if (searchInput) searchInput.addEventListener('input', filterAttendance);
            if (dateFrom) dateFrom.addEventListener('change', filterAttendance);
            if (dateTo) dateTo.addEventListener('change', filterAttendance);
            if (payTypeFilter) payTypeFilter.addEventListener('change', filterAttendance);
            if (statusFilter) statusFilter.addEventListener('change', filterAttendance);
            if (sortBy) sortBy.addEventListener('change', filterAttendance);
            if (resetBtn) resetBtn.addEventListener('click', resetFilters);
            
            // Setup quick filters
            setupQuickFilters();
            
            // Initial filter
            filterAttendance();
        });
    </script>
</body>
</html>