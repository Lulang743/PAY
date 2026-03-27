<?php
session_start();
require_once 'config.php';
require_once 'auth_check.php';

// Allow admin, manager, hr, and employees to access (with different permissions)
$userRole = $_SESSION['user_role'] ?? '';
$isAdmin = in_array($userRole, ['admin', 'manager', 'hr']);
checkRole(['admin', 'manager', 'hr', 'employee']);

$currentUserId = $_SESSION['user_id'] ?? 0;

// Check if leave_config table exists and create if needed
$table_check = $conn->query("SHOW TABLES LIKE 'leave_config'");
if ($table_check && $table_check->num_rows == 0) {
    // Create the table if it doesn't exist
    $create_table = "CREATE TABLE IF NOT EXISTS leave_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        leave_type VARCHAR(50) UNIQUE,
        days_per_year INT DEFAULT 0,
        carry_forward_allowed TINYINT DEFAULT 0,
        max_accumulation_days INT DEFAULT 0,
        config_value VARCHAR(255)
    )";
    $conn->query($create_table);
    
    // Insert default values
    $defaults = [
        "INSERT INTO leave_config (leave_type, days_per_year, carry_forward_allowed, max_accumulation_days) VALUES ('annual', 21, 1, 5)",
        "INSERT INTO leave_config (leave_type, days_per_year, carry_forward_allowed, max_accumulation_days) VALUES ('sick', 10, 0, 0)",
        "INSERT INTO leave_config (leave_type, days_per_year, carry_forward_allowed, max_accumulation_days) VALUES ('maternity', 90, 0, 0)",
        "INSERT INTO leave_config (leave_type, days_per_year, carry_forward_allowed, max_accumulation_days) VALUES ('paternity', 5, 0, 0)",
        "INSERT INTO leave_config (leave_type, days_per_year, carry_forward_allowed, max_accumulation_days) VALUES ('unpaid', 0, 0, 0)",
        "INSERT INTO leave_config (leave_type, config_value) VALUES ('weekend_policy', 'exclude')"
    ];
    foreach ($defaults as $sql) {
        $conn->query($sql);
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Submit new leave request
    if (isset($_POST['submit_leave'])) {
        $employee_id = $isAdmin ? ($_POST['employee_id'] ?? $currentUserId) : $currentUserId;
        $leave_type = $_POST['leave_type'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $reason = $_POST['reason'];
        $contact_address = $_POST['contact_address'] ?? '';
        $contact_phone = $_POST['contact_phone'] ?? '';
        
        // Validate dates
        if (strtotime($start_date) > strtotime($end_date)) {
            $error = "End date must be after start date";
        } elseif (strtotime($start_date) < strtotime('today')) {
            $error = "Cannot request leave for past dates";
        } else {
            // Calculate total days (excluding weekends if configured)
            $total_days = calculateLeaveDays($start_date, $end_date, $conn);
            
            // Check leave balance
            $balanceCheck = checkLeaveBalance($employee_id, $leave_type, $total_days, $conn);
            if (!$balanceCheck['sufficient']) {
                $error = "Insufficient leave balance. Available: " . $balanceCheck['available'] . " days, Requested: " . $total_days . " days";
            } else {
                // Updated to match your table structure:
                // - Using 'days' instead of 'total_days'
                // - 'requested_by' is varchar(25) in your table
                $sql = "INSERT INTO leave_requests (
                    employee_id, 
                    leave_type, 
                    start_date, 
                    end_date, 
                    days, 
                    reason, 
                    contact_address, 
                    contact_phone, 
                    status, 
                    requested_by,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    // Convert currentUserId to string for requested_by (varchar field)
                    $requested_by_str = (string)$currentUserId;
                    
                    // Bind parameters - note the type changes:
                    // i = integer, s = string, d = decimal/double
                    $stmt->bind_param(
                        "isssissss", 
                        $employee_id,      // i - employee_id is int
                        $leave_type,       // s - leave_type is enum/string
                        $start_date,       // s - start_date is date string
                        $end_date,         // s - end_date is date string
                        $total_days,       // i - days is decimal(5,1) but we'll use int
                        $reason,           // s - reason is text
                        $contact_address,  // s - contact_address is varchar
                        $contact_phone,    // s - contact_phone is varchar
                        $requested_by_str  // s - requested_by is varchar(25)
                    );
                    
                    if ($stmt->execute()) {
                        $success = "Leave request submitted successfully! Total days: " . $total_days;
                        
                        // Get the inserted leave ID for notification
                        $leave_id = $conn->insert_id;
                        
                        // Notify managers (if notifications table exists)
                        logNotification($conn, $employee_id, 'leave_submitted', 'New leave request pending approval');
                        
                        // Optional: Add admin notes if needed
                        // You can add a default admin note here if required
                    } else {
                        $error = "Error submitting leave request: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = "Error preparing statement: " . $conn->error;
                }
            }
        }
    }
    
    // Approve/Reject leave request
    if (isset($_POST['update_status']) && $isAdmin) {
        $leave_id = $_POST['leave_id'];
        $status = $_POST['status'];
        $admin_notes = $_POST['remarks'] ?? ''; // Using admin_notes instead of remarks
        
        // Updated to match your table structure:
        // - Using processed_by instead of approved_by
        // - Using processed_date instead of approved_date
        // - Using admin_notes instead of remarks
        $sql = "UPDATE leave_requests 
                SET status = ?, 
                    processed_by = ?, 
                    processed_date = NOW(), 
                    admin_notes = CONCAT(IFNULL(admin_notes, ''), '\n', ?) 
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            // processed_by expects int, admin_notes is text
            $stmt->bind_param("sisi", $status, $currentUserId, $admin_notes, $leave_id);
            
            if ($stmt->execute()) {
                $success = "Leave request " . $status . " successfully!";
                
                // Get leave details for notification
                $leaveData = getLeaveById($leave_id, $conn);
                if ($leaveData) {
                    logNotification($conn, $leaveData['employee_id'], 'leave_' . $status, 'Your leave request has been ' . $status);
                }
            } else {
                $error = "Error updating leave status: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Error preparing statement: " . $conn->error;
        }
    }
    
    // Cancel leave request
    if (isset($_POST['cancel_leave'])) {
        $leave_id = $_POST['leave_id'];
        
        // Only allow cancellation if pending and owned by user (or admin)
        $check_sql = "SELECT employee_id, status FROM leave_requests WHERE id = ?";
        $check_stmt = $conn->prepare($check_sql);
        if ($check_stmt) {
            $check_stmt->bind_param("i", $leave_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_data = $check_result->fetch_assoc();
            
            if ($check_data && ($check_data['employee_id'] == $currentUserId || $isAdmin) && $check_data['status'] == 'pending') {
                $sql = "UPDATE leave_requests SET status = 'cancelled' WHERE id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $leave_id);
                    
                    if ($stmt->execute()) {
                        $success = "Leave request cancelled successfully!";
                        
                        // Log the cancellation
                        logNotification($conn, $check_data['employee_id'], 'leave_cancelled', 'Leave request has been cancelled');
                    } else {
                        $error = "Error cancelling leave request: " . $stmt->error;
                    }
                    $stmt->close();
                }
            } else {
                $error = "Cannot cancel this leave request";
            }
            $check_stmt->close();
        }
    }
    
    // Configure leave types (Admin only)
    if (isset($_POST['configure_leave']) && $isAdmin) {
        $leave_type = $_POST['leave_type_config'];
        $days_per_year = $_POST['days_per_year'];
        $carry_forward = $_POST['carry_forward'] ?? 0;
        $max_accumulation = $_POST['max_accumulation'] ?? 0;
        
        $sql = "INSERT INTO leave_config (leave_type, days_per_year, carry_forward_allowed, max_accumulation_days) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                days_per_year = ?, 
                carry_forward_allowed = ?, 
                max_accumulation_days = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("siiiiii", $leave_type, $days_per_year, $carry_forward, $max_accumulation, $days_per_year, $carry_forward, $max_accumulation);
            
            if ($stmt->execute()) {
                $success = "Leave configuration updated successfully!";
            } else {
                $error = "Error updating configuration: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Error preparing statement: " . $conn->error;
        }
    }
}
    
    // Approve/Reject leave request
 // Approve/Reject leave request
if (isset($_POST['update_status']) && $isAdmin) {
    $leave_id = $_POST['leave_id'];
    $status = $_POST['status'];
    $admin_notes = $_POST['remarks'] ?? '';
    
    // Find a valid processed_by ID
    $processed_by = null;
    
    // First, check what columns exist in the employees table
    $columns_check = $conn->query("SHOW COLUMNS FROM employees");
    $employee_columns = [];
    if ($columns_check) {
        while ($col = $columns_check->fetch_assoc()) {
            $employee_columns[] = $col['Field'];
        }
        // Debug: log columns
        error_log("Employees table columns: " . implode(", ", $employee_columns));
    }
    
    // Try current user first - with proper error checking
    $check_sql = "SELECT id FROM employees WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    
    if ($check_stmt === false) {
        // If prepare failed, try alternative column names
        error_log("Failed to prepare: " . $conn->error);
        
        // Try with different possible column names for user ID
        $alternative_sql = "SELECT id FROM employees WHERE user_id = ? OR employee_id = ?";
        $check_stmt = $conn->prepare($alternative_sql);
        
        if ($check_stmt) {
            $check_stmt->bind_param("ii", $currentUserId, $currentUserId);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result && $check_result->num_rows > 0) {
                $processed_by = $check_result->fetch_assoc()['id'];
            }
            $check_stmt->close();
        }
    } else {
        // Original query worked
        $check_stmt->bind_param("i", $currentUserId);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result && $check_result->num_rows > 0) {
            $processed_by = $check_result->fetch_assoc()['id'];
        }
        $check_stmt->close();
    }
    
    // If still no processed_by found, try to find any admin/manager
    if (!$processed_by) {
        // Try different possible role column names
        $role_fields = ['role', 'user_role', 'user_type', 'user_level', 'position'];
        $admin_found = false;
        
        foreach ($role_fields as $role_field) {
            if (in_array($role_field, $employee_columns)) {
                $admin_sql = "SELECT id FROM employees WHERE $role_field IN ('admin', 'manager', 'hr') LIMIT 1";
                $admin_result = $conn->query($admin_sql);
                
                if ($admin_result && $admin_result->num_rows > 0) {
                    $processed_by = $admin_result->fetch_assoc()['id'];
                    $warning = "Note: Your account doesn't have an employee record. Using another admin as processor.";
                    $admin_found = true;
                    break;
                }
            }
        }
        
        if (!$admin_found) {
            // Last resort: try to get any employee
            $any_emp_sql = "SELECT id FROM employees LIMIT 1";
            $any_emp_result = $conn->query($any_emp_sql);
            if ($any_emp_result && $any_emp_result->num_rows > 0) {
                $processed_by = $any_emp_result->fetch_assoc()['id'];
                $warning = "Warning: No admin found. Using any available employee as processor.";
            } else {
                // If no employees at all, insert current user as employee
                $error = "No employees found in the system. Please add employees first.";
                // Don't proceed with update
                $processed_by = null;
            }
        }
    }
    
    // Proceed with update if we have a valid processed_by
    if ($processed_by) {
        // Check if admin_notes column exists
        $notes_column = in_array('admin_notes', $employee_columns) ? 'admin_notes' : 'notes';
        $remarks_column = in_array('remarks', $employee_columns) ? 'remarks' : $notes_column;
        
        $sql = "UPDATE leave_requests 
                SET status = ?, 
                    processed_by = ?, 
                    processed_date = NOW(), 
                    $remarks_column = CONCAT(IFNULL($remarks_column, ''), '\n[', NOW(), '] ', ?) 
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sisi", $status, $processed_by, $admin_notes, $leave_id);
            
            if ($stmt->execute()) {
                $success = "Leave request " . $status . " successfully!";
                if (isset($warning)) {
                    $success .= " " . $warning;
                }
                
                // Get leave details for notification
                $leaveData = getLeaveById($leave_id, $conn);
                if ($leaveData) {
                    logNotification($conn, $leaveData['employee_id'], 'leave_' . $status, 'Your leave request has been ' . $status);
                }
            } else {
                $error = "Error updating leave status: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Error preparing update statement: " . $conn->error;
        }
    } else if (!isset($error)) {
        $error = "Could not find a valid processor for this leave request.";
    }
}
    // Cancel leave request
    if (isset($_POST['cancel_leave'])) {
        $leave_id = $_POST['leave_id'];
        
        // Only allow cancellation if pending and owned by user (or admin)
        $check_sql = "SELECT employee_id, status FROM leave_requests WHERE id = ?";
        $check_stmt = $conn->prepare($check_sql);
        if ($check_stmt) {
            $check_stmt->bind_param("i", $leave_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $check_data = $check_result->fetch_assoc();
            
            if ($check_data && ($check_data['employee_id'] == $currentUserId || $isAdmin) && $check_data['status'] == 'pending') {
                $sql = "UPDATE leave_requests SET status = 'cancelled' WHERE id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $leave_id);
                    
                    if ($stmt->execute()) {
                        $success = "Leave request cancelled successfully!";
                    } else {
                        $error = "Error cancelling leave request";
                    }
                }
            } else {
                $error = "Cannot cancel this leave request";
            }
        }
    }
    
    // Configure leave types (Admin only)
    if (isset($_POST['configure_leave']) && $isAdmin) {
        $leave_type = $_POST['leave_type_config'];
        $days_per_year = $_POST['days_per_year'];
        $carry_forward = $_POST['carry_forward'] ?? 0;
        $max_accumulation = $_POST['max_accumulation'] ?? 0;
        
        $sql = "INSERT INTO leave_config (leave_type, days_per_year, carry_forward_allowed, max_accumulation_days) 
                VALUES (?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE days_per_year = ?, carry_forward_allowed = ?, max_accumulation_days = ?";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("siiiiii", $leave_type, $days_per_year, $carry_forward, $max_accumulation, $days_per_year, $carry_forward, $max_accumulation);
            
            if ($stmt->execute()) {
                $success = "Leave configuration updated successfully!";
            } else {
                $error = "Error updating configuration: " . $conn->error;
            }
        } else {
            $error = "Error preparing statement: " . $conn->error;
        }
    }


// Helper functions
function calculateLeaveDays($start_date, $end_date, $conn) {
    // Check if weekends should be excluded
    $exclude_weekends = true; // Default value
    
    $config_query = $conn->query("SELECT config_value FROM leave_config WHERE leave_type = 'weekend_policy' LIMIT 1");
    if ($config_query && $config_query->num_rows > 0) {
        $config = $config_query->fetch_assoc();
        $exclude_weekends = ($config['config_value'] ?? 'exclude') == 'exclude';
    }
    
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day'); // Include end date
    
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    
    $days = 0;
    foreach ($period as $date) {
        $dayOfWeek = $date->format('N');
        if (!$exclude_weekends || ($dayOfWeek < 6)) { // Monday=1, Friday=5, Saturday=6, Sunday=7
            $days++;
        }
    }
    return $days;
}

function checkLeaveBalance($employee_id, $leave_type, $requested_days, $conn) {
    // Get allocated days from leave_config
    $allocated = 0;
    $alloc_sql = "SELECT days_per_year FROM leave_config WHERE leave_type = ?";
    $alloc_stmt = $conn->prepare($alloc_sql);
    if ($alloc_stmt) {
        $alloc_stmt->bind_param("s", $leave_type);
        $alloc_stmt->execute();
        $alloc_result = $alloc_stmt->get_result();
        if ($alloc_result && $alloc_result->num_rows > 0) {
            $allocated = $alloc_result->fetch_assoc()['days_per_year'] ?? 0;
        }
    }
    
    // Get used days (approved leaves this year) - using 'days' column
    $year = date('Y');
    $used = 0;
    $used_sql = "SELECT COALESCE(SUM(days), 0) as used FROM leave_requests 
                 WHERE employee_id = ? AND leave_type = ? AND status = 'approved' 
                 AND YEAR(start_date) = ?";
    $used_stmt = $conn->prepare($used_sql);
    if ($used_stmt) {
        $used_stmt->bind_param("isi", $employee_id, $leave_type, $year);
        $used_stmt->execute();
        $used_result = $used_stmt->get_result();
        if ($used_result && $used_result->num_rows > 0) {
            $used = $used_result->fetch_assoc()['used'] ?? 0;
        }
    }
    
    // Get pending days - using 'days' column
    $pending = 0;
    $pending_sql = "SELECT COALESCE(SUM(days), 0) as pending FROM leave_requests 
                    WHERE employee_id = ? AND leave_type = ? AND status = 'pending'";
    $pending_stmt = $conn->prepare($pending_sql);
    if ($pending_stmt) {
        $pending_stmt->bind_param("is", $employee_id, $leave_type);
        $pending_stmt->execute();
        $pending_result = $pending_stmt->get_result();
        if ($pending_result && $pending_result->num_rows > 0) {
            $pending = $pending_result->fetch_assoc()['pending'] ?? 0;
        }
    }
    
    $available = max(0, $allocated - $used - $pending);
    
    return [
        'sufficient' => $available >= $requested_days,
        'available' => $available,
        'allocated' => $allocated,
        'used' => $used,
        'pending' => $pending
    ];
}
function updateLeaveBalance($leave_id, $conn) {
    // Deduct from balance (implementation depends on your balance tracking method)
    // This could update a running balance table or be calculated on-the-fly
    return true;
}

function getLeaveById($leave_id, $conn) {
    $sql = "SELECT * FROM leave_requests WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $leave_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            // Map fields for consistency
            $row['total_days'] = $row['days'] ?? 0;
            $row['approved_by'] = $row['processed_by'];
            $row['approved_date'] = $row['processed_date'];
            $row['remarks'] = $row['admin_notes'];
            return $row;
        }
    }
    return null;
}

function logNotification($conn, $user_id, $type, $message) {
    // Check if notifications table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($table_check && $table_check->num_rows > 0) {
        $sql = "INSERT INTO notifications (user_id, type, message, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iss", $user_id, $type, $message);
            $stmt->execute();
        }
    }
}

// Fetch data for display

// Get all leave types configuration
$leave_types = [];
$leave_types_sql = "SELECT * FROM leave_config WHERE leave_type != 'weekend_policy' ORDER BY leave_type";
$leave_types_result = $conn->query($leave_types_sql);

if ($leave_types_result && $leave_types_result->num_rows > 0) {
    while ($row = $leave_types_result->fetch_assoc()) {
        $leave_types[] = $row;
    }
}

// Default leave types if none configured
if (empty($leave_types)) {
    $leave_types = [
        ['leave_type' => 'annual', 'days_per_year' => 21, 'carry_forward_allowed' => 1, 'max_accumulation_days' => 5],
        ['leave_type' => 'sick', 'days_per_year' => 10, 'carry_forward_allowed' => 0, 'max_accumulation_days' => 0],
        ['leave_type' => 'maternity', 'days_per_year' => 90, 'carry_forward_allowed' => 0, 'max_accumulation_days' => 0],
        ['leave_type' => 'paternity', 'days_per_year' => 5, 'carry_forward_allowed' => 0, 'max_accumulation_days' => 0],
        ['leave_type' => 'unpaid', 'days_per_year' => 0, 'carry_forward_allowed' => 0, 'max_accumulation_days' => 0]
    ];
}

// Get employees for admin dropdown
$employees = [];
if ($isAdmin) {
    $emp_sql = "SELECT id, employee_id, CONCAT(first_name, ' ', last_name) as name FROM employees WHERE status = 'active' ORDER BY first_name";
    $emp_result = $conn->query($emp_sql);
    if ($emp_result && $emp_result->num_rows > 0) {
        while ($row = $emp_result->fetch_assoc()) {
            $employees[] = $row;
        }
    }
}

// Get leave requests - FIXED for your table structure
$leave_requests = [];
if ($isAdmin) {
    // Admin sees all requests - adjusted to match your table columns
    $leaves_sql = "SELECT lr.*, 
                   CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                   e.employee_id as emp_code,
                   CONCAT(a.first_name, ' ', a.last_name) as approver_name
                   FROM leave_requests lr
                   JOIN employees e ON lr.employee_id = e.id
                   LEFT JOIN employees a ON lr.processed_by = a.id
                   ORDER BY lr.created_at DESC";
    $leaves_result = $conn->query($leaves_sql);
    
    if ($leaves_result && $leaves_result->num_rows > 0) {
        while ($row = $leaves_result->fetch_assoc()) {
            // Map your table fields to what the display expects
            $row['total_days'] = $row['days'] ?? 0; // Map 'days' to 'total_days'
            $row['approved_by'] = $row['processed_by']; // Map processed_by to approved_by
            $row['approved_date'] = $row['processed_date']; // Map processed_date to approved_date
            $row['remarks'] = $row['admin_notes']; // Map admin_notes to remarks
            $leave_requests[] = $row;
        }
    }
} else {
    // Employee sees only their own
    $leaves_sql = "SELECT lr.*, 
                   CONCAT(a.first_name, ' ', a.last_name) as approver_name
                   FROM leave_requests lr
                   LEFT JOIN employees a ON lr.processed_by = a.id
                   WHERE lr.employee_id = ?
                   ORDER BY lr.created_at DESC";
    $leaves_stmt = $conn->prepare($leaves_sql);
    if ($leaves_stmt) {
        $leaves_stmt->bind_param("i", $currentUserId);
        $leaves_stmt->execute();
        $leaves_result = $leaves_stmt->get_result();
        
        if ($leaves_result && $leaves_result->num_rows > 0) {
            while ($row = $leaves_result->fetch_assoc()) {
                // Map your table fields
                $row['total_days'] = $row['days'] ?? 0;
                $row['approved_by'] = $row['processed_by'];
                $row['approved_date'] = $row['processed_date'];
                $row['remarks'] = $row['admin_notes'];
                $leave_requests[] = $row;
            }
        }
    }
}
// Get current user's leave balances (for employees)
$my_balances = [];
if (!$isAdmin) {
    foreach ($leave_types as $type) {
        $balance = checkLeaveBalance($currentUserId, $type['leave_type'], 0, $conn);
        $my_balances[$type['leave_type']] = $balance;
    }
}

// Get upcoming approved leaves for calendar
$upcoming_leaves = [];
$upcoming_sql = "SELECT lr.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name
                 FROM leave_requests lr
                 JOIN employees e ON lr.employee_id = e.id
                 WHERE lr.status = 'approved' 
                 AND lr.end_date >= CURDATE()
                 ORDER BY lr.start_date ASC
                 LIMIT 20";
$upcoming_result = $conn->query($upcoming_sql);
if ($upcoming_result && $upcoming_result->num_rows > 0) {
    while ($row = $upcoming_result->fetch_assoc()) {
        $upcoming_leaves[] = $row;
    }
}

// Statistics
// Statistics
$current_year = date('Y');
$stats = [
    'total_requests' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'today_on_leave' => 0,
    'monthly_total' => 0
];

if ($isAdmin) {
    // Overall stats - using your table structure
    $stats_sql = "SELECT 
                  COUNT(*) as total,
                  SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                  SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                  SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                  FROM leave_requests WHERE YEAR(created_at) = ?";
    $stats_stmt = $conn->prepare($stats_sql);
    if ($stats_stmt) {
        $stats_stmt->bind_param("i", $current_year);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        if ($stats_result && $stats_result->num_rows > 0) {
            $row = $stats_result->fetch_assoc();
            $stats['total_requests'] = $row['total'] ?? 0;
            $stats['pending'] = $row['pending'] ?? 0;
            $stats['approved'] = $row['approved'] ?? 0;
            $stats['rejected'] = $row['rejected'] ?? 0;
        }
    }
    
    // Today's on leave - using your table structure
    $today_sql = "SELECT COUNT(DISTINCT employee_id) as count FROM leave_requests 
                  WHERE status = 'approved' AND CURDATE() BETWEEN start_date AND end_date";
    $today_result = $conn->query($today_sql);
    if ($today_result && $today_result->num_rows > 0) {
        $stats['today_on_leave'] = $today_result->fetch_assoc()['count'] ?? 0;
    }
    
    // This month's total days - using 'days' instead of 'total_days'
    $month_sql = "SELECT COALESCE(SUM(days), 0) as total FROM leave_requests 
                  WHERE status = 'approved' AND MONTH(start_date) = MONTH(CURDATE()) AND YEAR(start_date) = YEAR(CURDATE())";
    $month_result = $conn->query($month_sql);
    if ($month_result && $month_result->num_rows > 0) {
        $stats['monthly_total'] = $month_result->fetch_assoc()['total'] ?? 0;
    }
} else {
    // Personal stats - using your table structure
    $stats_sql = "SELECT 
                  COUNT(*) as total,
                  SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                  SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                  SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                  FROM leave_requests WHERE employee_id = ? AND YEAR(created_at) = ?";
    $stats_stmt = $conn->prepare($stats_sql);
    if ($stats_stmt) {
        $stats_stmt->bind_param("ii", $currentUserId, $current_year);
        $stats_stmt->execute();
        $stats_result = $stats_stmt->get_result();
        if ($stats_result && $stats_result->num_rows > 0) {
            $row = $stats_result->fetch_assoc();
            $stats['total_requests'] = $row['total'] ?? 0;
            $stats['pending'] = $row['pending'] ?? 0;
            $stats['approved'] = $row['approved'] ?? 0;
            $stats['rejected'] = $row['rejected'] ?? 0;
        }
    }
}
// Handle view request
$view_mode = false;
$view_leave = null;
$view_employee = null;
$view_approver = null;

if (isset($_GET['view']) && $isAdmin) {
    $view_id = intval($_GET['view']);
    $view_leave = getLeaveById($view_id, $conn);
    if ($view_leave) {
        // Get employee details
        $emp_sql = "SELECT CONCAT(first_name, ' ', last_name) as name, employee_id, position, email, phone 
                    FROM employees WHERE id = ?";
        $emp_stmt = $conn->prepare($emp_sql);
        if ($emp_stmt) {
            $emp_stmt->bind_param("i", $view_leave['employee_id']);
            $emp_stmt->execute();
            $emp_result = $emp_stmt->get_result();
            if ($emp_result && $emp_result->num_rows > 0) {
                $view_employee = $emp_result->fetch_assoc();
            }
        }
        
        // Get approver details
        if ($view_leave['approved_by']) {
            $app_sql = "SELECT CONCAT(first_name, ' ', last_name) as name FROM employees WHERE id = ?";
            $app_stmt = $conn->prepare($app_sql);
            if ($app_stmt) {
                $app_stmt->bind_param("i", $view_leave['approved_by']);
                $app_stmt->execute();
                $app_result = $app_stmt->get_result();
                if ($app_result && $app_result->num_rows > 0) {
                    $view_approver = $app_result->fetch_assoc();
                }
            }
        }
        
        $view_mode = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alaki Payroll</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
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
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #27ae60;
            color: white;
        }
        
        .btn-primary:hover {
            background: #229954;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }
        
        .btn-secondary {
            background: #3498db;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-back {
            background: #95a5a6;
            color: white;
            margin-bottom: 20px;
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: all 0.3s;
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
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-info h3 {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .stat-info p {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        /* Status Badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-approved {
            background: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-cancelled {
            background: #e2e3e5;
            color: #383d41;
        }
        
        /* Leave Type Badges */
        .leave-type-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .leave-annual { background: #e3f2fd; color: #1976d2; }
        .leave-sick { background: #ffebee; color: #c62828; }
        .leave-maternity { background: #f3e5f5; color: #7b1fa2; }
        .leave-paternity { background: #e8f5e9; color: #2e7d32; }
        .leave-unpaid { background: #fff3e0; color: #ef6c00; }
        .leave-emergency { background: #fce4ec; color: #c2185b; }
        
        /* Balance Cards */
        .balance-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .balance-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid #667eea;
            position: relative;
            overflow: hidden;
        }
        
        .balance-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 60px;
            height: 60px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 0 0 0 60px;
        }
        
        .balance-card .type {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .balance-card .days {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .balance-card .details {
            font-size: 11px;
            color: #95a5a6;
        }
        
        .balance-card .progress-bar {
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            margin-top: 10px;
            overflow: hidden;
        }
        
        .balance-card .progress-fill {
            height: 100%;
            background: #667eea;
            border-radius: 2px;
            transition: width 0.3s;
        }
        
        /* Form Styles */
        .form-container {
            display: none;
            animation: slideDown 0.3s ease;
        }
        
        .form-container.show {
            display: block;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .btn-submit {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
        }
        
        .btn-submit:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
            padding: 15px;
            text-align: left;
            font-size: 14px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
            color: #2c3e50;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.show {
            display: flex;
        }
        
        .modal-content {
            background: white;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 25px;
            border-radius: 10px;
            position: relative;
            animation: modalSlide 0.3s ease;
        }
        
        @keyframes modalSlide {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .modal-header h3 {
            color: #2c3e50;
            font-size: 20px;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: #95a5a6;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: #e74c3c;
        }
        
        /* View Section */
        .view-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .view-section h4 {
            color: #2c3e50;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .view-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .view-item {
            display: flex;
            flex-direction: column;
        }
        
        .view-label {
            font-size: 11px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        
        .view-value {
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e0e0e0;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -24px;
            top: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #667eea;
            border: 2px solid white;
            box-shadow: 0 0 0 2px #667eea;
        }
        
        .timeline-item.pending::before { background: #f39c12; box-shadow: 0 0 0 2px #f39c12; }
        .timeline-item.approved::before { background: #27ae60; box-shadow: 0 0 0 2px #27ae60; }
        .timeline-item.rejected::before { background: #e74c3c; box-shadow: 0 0 0 2px #e74c3c; }
        
        .timeline-date {
            font-size: 11px;
            color: #95a5a6;
            margin-bottom: 5px;
        }
        
        .timeline-content {
            background: white;
            padding: 10px 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        /* Alerts */
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid #27ae60;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid #e74c3c;
        }
        
        .info-box {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid #17a2b8;
        }
        
        /* Empty State */
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
        
        /* Responsive */
        @media (max-width: 768px) {
            .nav {
                flex-direction: column;
                gap: 10px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .view-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Print Styles */
        @media print {
            .nav, .btn, .modal, .form-container { display: none !important; }
            .card { box-shadow: none; break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class=""></i> Alaki Payroll</h1>
            <p><?php echo $isAdmin ? 'Manage employee leave requests and approvals' : 'Request and track your leave'; ?></p>
        </div>
        
        <!-- Navigation -->
        <div class="nav">
            <div class="nav-links">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="employees.php"><i class="fas fa-users"></i> Employees</a>
                <a href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>
                <a href="leave.php" class="active"><i class="fas fa-calendar-alt"></i> Leave</a>
                <a href="payroll.php"><i class="fas fa-money-bill"></i> Payroll</a>
            </div>
            <div>
                <?php if (!$view_mode): ?>
                <button class="btn btn-primary" onclick="toggleForm()">
                    <i class="fas fa-plus-circle"></i> 
                    <?php echo $isAdmin ? 'New Leave Request' : 'Request Leave'; ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Back Button for View Mode -->
        <?php if ($view_mode): ?>
            <a href="leave.php" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back to Leave List
            </a>
        <?php endif; ?>
        
        <!-- Alerts -->
        <?php if (isset($success)): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- View Leave Details Mode (Admin Only) -->
        <?php if ($view_mode && $view_leave && $isAdmin): ?>
            <div class="card">
                <div class="modal-header" style="border-bottom: none; padding: 0; margin-bottom: 20px;">
                    <h2>
                        <i class="fas fa-file-alt"></i> Leave Request Details
                        <span class="status-badge status-<?php echo $view_leave['status']; ?>" style="margin-left: 15px;">
                            <?php echo ucfirst($view_leave['status']); ?>
                        </span>
                    </h2>
                </div>
                
                <!-- Employee Info -->
                <div class="view-section">
                    <h4><i class="fas fa-user"></i> Employee Information</h4>
                    <div class="view-grid">
                        <div class="view-item">
                            <span class="view-label">Employee Name</span>
                            <span class="view-value"><?php echo htmlspecialchars($view_employee['name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">Employee ID</span>
                            <span class="view-value"><?php echo htmlspecialchars($view_employee['employee_id'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">Position</span>
                            <span class="view-value"><?php echo htmlspecialchars($view_employee['position'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">Contact</span>
                            <span class="view-value"><?php echo htmlspecialchars($view_employee['email'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Leave Details -->
                <div class="view-section">
                    <h4><i class="fas fa-calendar"></i> Leave Details</h4>
                    <div class="view-grid">
                        <div class="view-item">
                            <span class="view-label">Leave Type</span>
                            <span class="view-value">
                                <span class="leave-type-badge leave-<?php echo $view_leave['leave_type']; ?>">
                                    <?php echo ucfirst($view_leave['leave_type']); ?>
                                </span>
                            </span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">Duration</span>
                            <span class="view-value"><?php echo $view_leave['total_days']; ?> days</span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">Start Date</span>
                            <span class="view-value"><?php echo date('F d, Y', strtotime($view_leave['start_date'])); ?></span>
                        </div>
                        <div class="view-item">
                            <span class="view-label">End Date</span>
                            <span class="view-value"><?php echo date('F d, Y', strtotime($view_leave['end_date'])); ?></span>
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <span class="view-label">Reason</span>
                        <p style="margin-top: 5px; padding: 10px; background: white; border-radius: 5px;">
                            <?php echo nl2br(htmlspecialchars($view_leave['reason'])); ?>
                        </p>
                    </div>
                </div>
                
                <!-- Approval Timeline -->
                <div class="view-section">
                    <h4><i class="fas fa-history"></i> Approval Timeline</h4>
                    <div class="timeline">
                        <div class="timeline-item <?php echo $view_leave['status']; ?>">
                            <div class="timeline-date">
                                <?php echo date('F d, Y h:i A', strtotime($view_leave['created_at'])); ?>
                            </div>
                            <div class="timeline-content">
                                <strong>Requested</strong> by employee
                            </div>
                        </div>
                        
                        <?php if ($view_leave['status'] != 'pending'): ?>
                        <div class="timeline-item <?php echo $view_leave['status']; ?>">
                            <div class="timeline-date">
                                <?php echo isset($view_leave['approved_date']) ? date('F d, Y h:i A', strtotime($view_leave['approved_date'])) : 'N/A'; ?>
                            </div>
                            <div class="timeline-content">
                                <strong><?php echo ucfirst($view_leave['status']); ?></strong>
                                <?php if (isset($view_approver['name'])): ?>
                                    by <?php echo htmlspecialchars($view_approver['name']); ?>
                                <?php endif; ?>
                                <?php if ($view_leave['remarks']): ?>
                                    <br><small>Remarks: <?php echo htmlspecialchars($view_leave['remarks']); ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Action Buttons for Pending Requests -->
                <?php if ($view_leave['status'] == 'pending'): ?>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button class="btn btn-success" onclick="showApprovalModal(<?php echo $view_leave['id']; ?>, 'approve')">
                        <i class="fas fa-check"></i> Approve Request
                    </button>
                    <button class="btn btn-danger" onclick="showApprovalModal(<?php echo $view_leave['id']; ?>, 'reject')">
                        <i class="fas fa-times"></i> Reject Request
                    </button>
                </div>
                <?php endif; ?>
            </div>
        
        <!-- Main Leave List -->
        <?php else: ?>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e8f4fd; color: #3498db;">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $isAdmin ? 'Total Requests' : 'My Requests'; ?></h3>
                        <p><?php echo $stats['total_requests']; ?></p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fff3cd; color: #f39c12;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Pending</h3>
                        <p><?php echo $stats['pending']; ?></p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #d4edda; color: #27ae60;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Approved</h3>
                        <p><?php echo $stats['approved']; ?></p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f8d7da; color: #e74c3c;">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Rejected</h3>
                        <p><?php echo $stats['rejected']; ?></p>
                    </div>
                </div>
                
                <?php if ($isAdmin): ?>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e8f8f0; color: #17a2b8;">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="stat-info">
                        <h3>Today On Leave</h3>
                        <p><?php echo $stats['today_on_leave']; ?></p>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="background: #f3e5f5; color: #9b59b6;">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-info">
                        <h3>This Month</h3>
                        <p><?php echo $stats['monthly_total']; ?> days</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Employee Leave Balances (for employees) -->
            <?php if (!$isAdmin && !empty($my_balances)): ?>
            <div class="card">
                <h2><i class="fas fa-wallet"></i> My Leave Balances (<?php echo date('Y'); ?>)</h2>
                <div class="balance-grid">
                    <?php foreach ($leave_types as $type): 
                        $balance = $my_balances[$type['leave_type']] ?? ['available' => 0, 'allocated' => 0, 'used' => 0];
                        $percentage = $balance['allocated'] > 0 ? ($balance['available'] / $balance['allocated']) * 100 : 0;
                    ?>
                    <div class="balance-card">
                        <div class="type"><?php echo ucfirst($type['leave_type']); ?> Leave</div>
                        <div class="days"><?php echo number_format($balance['available'], 1); ?></div>
                        <div class="details">
                            of <?php echo $balance['allocated']; ?> days | Used: <?php echo $balance['used']; ?>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- New Leave Request Form -->
<div class="card" id="formCard" style="display: none;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2><i class="fas fa-plus-circle"></i> New Leave Request</h2>
        <button class="btn btn-danger" onclick="toggleForm()" style="padding: 8px 15px;">
            <i class="fas fa-times"></i> Cancel
        </button>
    </div>
    
    <form method="POST" action="" onsubmit="return validateLeaveForm()">
        <?php if ($isAdmin): ?>
        <div class="form-row">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Employee <span style="color: #e74c3c;">*</span></label>
                <select name="employee_id" required>
                    <option value="">Select Employee</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?php echo $emp['id']; ?>">
                        <?php echo htmlspecialchars($emp['name'] . ' (' . $emp['employee_id'] . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="form-row">
            <div class="form-group">
                <label>Leave Type <span style="color: #e74c3c;">*</span></label>
                <select name="leave_type" id="leave_type" required onchange="updateBalanceInfo()">
                    <option value="">Select Type</option>
                    <?php foreach ($leave_types as $type): 
                        // Safely get values with defaults
                        $leave_type_value = isset($type['leave_type']) ? $type['leave_type'] : '';
                        $days_per_year = isset($type['days_per_year']) ? $type['days_per_year'] : 0;
                        $display_name = ucfirst(str_replace('_', ' ', $leave_type_value));
                    ?>
                    <option value="<?php echo htmlspecialchars($leave_type_value); ?>" 
                            data-days="<?php echo htmlspecialchars($days_per_year); ?>">
                        <?php echo htmlspecialchars($display_name); ?> (<?php echo htmlspecialchars($days_per_year); ?> days/year)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Total Days</label>
                <input type="text" id="calculated_days" readonly style="background: #f8f9fa; font-weight: 600;">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Start Date <span style="color: #e74c3c;">*</span></label>
                <input type="date" name="start_date" id="start_date" required min="<?php echo date('Y-m-d'); ?>" onchange="calculateDays()">
            </div>
            
            <div class="form-group">
                <label>End Date <span style="color: #e74c3c;">*</span></label>
                <input type="date" name="end_date" id="end_date" required min="<?php echo date('Y-m-d'); ?>" onchange="calculateDays()">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Reason <span style="color: #e74c3c;">*</span></label>
                <textarea name="reason" required placeholder="Please provide a detailed reason for your leave request..."></textarea>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Contact Address (while on leave)</label>
                <input type="text" name="contact_address" placeholder="Address during leave period">
            </div>
            
            <div class="form-group">
                <label>Contact Phone (while on leave)</label>
                <input type="tel" name="contact_phone" placeholder="Phone number during leave">
            </div>
        </div>
        
        <div class="info-box" id="balanceInfo" style="display: none;">
            <i class="fas fa-info-circle"></i>
            <span id="balanceText"></span>
        </div>
        
        <button type="submit" name="submit_leave" class="btn-submit">
            <i class="fas fa-paper-plane"></i> Submit Request
        </button>
    </form>
</div>
            <!-- Upcoming Leaves Calendar (Admin Only) -->
            <?php if ($isAdmin && !empty($upcoming_leaves)): ?>
            <div class="card">
                <h2><i class="fas fa-calendar-week"></i> Upcoming Approved Leaves</h2>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Dates</th>
                                <th>Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($upcoming_leaves, 0, 10) as $leave): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($leave['employee_name']); ?></td>
                                <td>
                                    <span class="leave-type-badge leave-<?php echo $leave['leave_type']; ?>">
                                        <?php echo ucfirst($leave['leave_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d', strtotime($leave['start_date'])); ?> - <?php echo date('M d, Y', strtotime($leave['end_date'])); ?></td>
                                <td><?php echo date('D', strtotime($leave['start_date'])); ?> - <?php echo date('D', strtotime($leave['end_date'])); ?></td>
                                <td><strong><?php echo $leave['total_days']; ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Leave Requests List -->
            <div class="card">
                <h2>
                    <i class="fas fa-list"></i> 
                    <?php echo $isAdmin ? 'All Leave Requests' : 'My Leave Requests'; ?>
                </h2>
                
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <?php if ($isAdmin): ?>
                                <th>Employee</th>
                                <?php endif; ?>
                                <th>Type</th>
                                <th>Duration</th>
                                <th>Dates</th>
                                <th>Days</th>
                                <th>Status</th>
                                <th>Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($leave_requests)): ?>
                                <?php foreach ($leave_requests as $request): ?>
                                <tr>
                                    <?php if ($isAdmin): ?>
                                    <td>
                                        <strong><?php echo htmlspecialchars($request['employee_name'] ?? 'N/A'); ?></strong>
                                        <br><small><?php echo htmlspecialchars($request['emp_code'] ?? ''); ?></small>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="leave-type-badge leave-<?php echo $request['leave_type']; ?>">
                                            <?php echo ucfirst($request['leave_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('M d', strtotime($request['start_date'])); ?> - 
                                        <?php echo date('M d', strtotime($request['end_date'])); ?>
                                    </td>
                                    <td><?php echo date('D, Y', strtotime($request['start_date'])); ?></td>
                                    <td><strong><?php echo $request['total_days']; ?></strong></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $request['status']; ?>">
                                            <i class="fas fa-<?php 
                                                echo $request['status'] == 'pending' ? 'clock' : 
                                                     ($request['status'] == 'approved' ? 'check' : 
                                                     ($request['status'] == 'rejected' ? 'times' : 'ban')); 
                                            ?>"></i>
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($request['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($isAdmin): ?>
                                            <a href="?view=<?php echo $request['id']; ?>" class="btn btn-secondary btn-sm" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($request['status'] == 'pending'): ?>
                                            <button class="btn btn-success btn-sm" onclick="quickApprove(<?php echo $request['id']; ?>)" title="Quick Approve">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn btn-danger btn-sm" onclick="quickReject(<?php echo $request['id']; ?>)" title="Quick Reject">
                                                <i class="fas fa-times"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <!-- Employee actions -->
                                            <?php if ($request['status'] == 'pending'): ?>
                                            <button class="btn btn-danger btn-sm" onclick="cancelLeave(<?php echo $request['id']; ?>)" title="Cancel Request">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $isAdmin ? 8 : 7; ?>" class="empty-state">
                                        <i class="fas fa-calendar-times"></i>
                                        <p>No leave requests found.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Leave Configuration (Admin Only) -->
            <!-- Leave Configuration (Admin Only) -->
<?php if ($isAdmin): ?>
<div class="card">
    <h2><i class="fas fa-cog"></i> Leave Configuration</h2>
    <form method="POST" action="">
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Leave Type</th>
                        <th>Days/Year</th>
                        <th>Carry Forward</th>
                        <th>Max Accumulation</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leave_types as $type): 
                        // Safely get all values with defaults
                        $leave_type_value = isset($type['leave_type']) ? $type['leave_type'] : '';
                        $days_per_year = isset($type['days_per_year']) ? $type['days_per_year'] : 0;
                        $carry_forward_allowed = isset($type['carry_forward_allowed']) ? $type['carry_forward_allowed'] : 0;
                        $max_accumulation_days = isset($type['max_accumulation_days']) ? $type['max_accumulation_days'] : 0;
                        $display_name = ucfirst(str_replace('_', ' ', $leave_type_value));
                    ?>
                    <tr>
                        <td>
                            <span class="leave-type-badge leave-<?php echo htmlspecialchars($leave_type_value); ?>">
                                <?php echo htmlspecialchars($display_name); ?>
                            </span>
                        </td>
                        <td>
                            <input type="number" 
                                   name="days_per_year" 
                                   value="<?php echo htmlspecialchars($days_per_year); ?>" 
                                   min="0" 
                                   style="width: 80px; padding: 5px;">
                        </td>
                        <td>
                            <select name="carry_forward" style="padding: 5px;">
                                <option value="1" <?php echo $carry_forward_allowed == 1 ? 'selected' : ''; ?>>Yes</option>
                                <option value="0" <?php echo $carry_forward_allowed == 0 ? 'selected' : ''; ?>>No</option>
                            </select>
                        </td>
                        <td>
                            <input type="number" 
                                   name="max_accumulation" 
                                   value="<?php echo htmlspecialchars($max_accumulation_days); ?>" 
                                   min="0" 
                                   style="width: 80px; padding: 5px;">
                        </td>
                        <td>
                            <input type="hidden" name="leave_type_config" value="<?php echo htmlspecialchars($leave_type_value); ?>">
                            <button type="submit" name="configure_leave" class="btn btn-secondary btn-sm">
                                <i class="fas fa-save"></i> Update
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
<?php endif; ?>
            
        <?php endif; ?>
    </div>
    
    <!-- Approval Modal -->
    <div id="approvalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Process Leave Request</h3>
                <button class="close-modal" onclick="closeApprovalModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="leave_id" id="modalLeaveId">
                <input type="hidden" name="status" id="modalStatus">
                
                <div class="form-group">
                    <label>Remarks (Optional)</label>
                    <textarea name="remarks" placeholder="Add any comments or remarks..."></textarea>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeApprovalModal()">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-submit" id="modalSubmitBtn">
                        Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Cancel Confirmation Form (Hidden) -->
    <form method="POST" id="cancelForm" style="display: none;">
        <input type="hidden" name="leave_id" id="cancelLeaveId">
        <input type="hidden" name="cancel_leave" value="1">
    </form>

    <script>
        // Toggle form visibility
        function toggleForm() {
            const formCard = document.getElementById('formCard');
            if (formCard.style.display === 'none') {
                formCard.style.display = 'block';
                formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                formCard.style.display = 'none';
            }
        }
        
        // Calculate leave days
        function calculateDays() {
            const start = document.getElementById('start_date').value;
            const end = document.getElementById('end_date').value;
            
            if (start && end) {
                if (new Date(start) > new Date(end)) {
                    alert('End date must be after start date');
                    document.getElementById('end_date').value = '';
                    return;
                }
                
                // Simple calculation (excluding weekends)
                let count = 0;
                const cur = new Date(start);
                const endDate = new Date(end);
                
                while (cur <= endDate) {
                    const dayOfWeek = cur.getDay();
                    if (dayOfWeek !== 0 && dayOfWeek !== 6) { // Exclude Sunday (0) and Saturday (6)
                        count++;
                    }
                    cur.setDate(cur.getDate() + 1);
                }
                
                document.getElementById('calculated_days').value = count + ' working days';
                updateBalanceInfo();
            }
        }
        
        // Update balance info display
        function updateBalanceInfo() {
            const leaveType = document.getElementById('leave_type').value;
            const daysField = document.getElementById('calculated_days').value;
            const days = parseInt(daysField) || 0;
            
            if (leaveType && days) {
                const select = document.querySelector('#leave_type option:checked');
                const allocated = select ? select.dataset.days || 0 : 0;
                
                document.getElementById('balanceInfo').style.display = 'flex';
                document.getElementById('balanceText').textContent = 
                    `Allocated: ${allocated} days/year | Requesting: ${days} working days`;
            }
        }
        
        // Validate leave form
        function validateLeaveForm() {
            const leaveType = document.getElementById('leave_type').value;
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const reason = document.querySelector('textarea[name="reason"]').value;
            
            if (!leaveType || !startDate || !endDate || !reason.trim()) {
                alert('Please fill in all required fields');
                return false;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                alert('End date must be after start date');
                return false;
            }
            
            return true;
        }
        
        // Modal functions
        function showApprovalModal(leaveId, action) {
            document.getElementById('modalLeaveId').value = leaveId;
            document.getElementById('modalStatus').value = action;
            document.getElementById('modalTitle').textContent = action === 'approve' ? 'Approve Leave Request' : 'Reject Leave Request';
            document.getElementById('modalSubmitBtn').textContent = action === 'approve' ? 'Approve' : 'Reject';
            document.getElementById('modalSubmitBtn').className = 'btn btn-submit ' + (action === 'approve' ? 'btn-success' : 'btn-danger');
            document.getElementById('approvalModal').classList.add('show');
        }
        
        function closeApprovalModal() {
            document.getElementById('approvalModal').classList.remove('show');
        }
        
        // Quick actions
        function quickApprove(leaveId) {
            if (confirm('Are you sure you want to approve this leave request?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="leave_id" value="${leaveId}">
                    <input type="hidden" name="status" value="approved">
                    <input type="hidden" name="update_status" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function quickReject(leaveId) {
            if (confirm('Are you sure you want to reject this leave request?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="leave_id" value="${leaveId}">
                    <input type="hidden" name="status" value="rejected">
                    <input type="hidden" name="update_status" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function cancelLeave(leaveId) {
            if (confirm('Are you sure you want to cancel this leave request?')) {
                document.getElementById('cancelLeaveId').value = leaveId;
                document.getElementById('cancelForm').submit();
            }
        }
        
        // Close modal on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }
        
        // Auto-hide alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.success, .error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
        
        // Print functionality
        function printLeaveDetails() {
            window.print();
        }
        // js/inactivity-tracker.js
class InactivityTracker {
    constructor(timeout = 60, warningTime = 5) { 
        this.timeout = timeout * 1000; // Convert to milliseconds
        this.warningTime = warningTime * 1000; // Warning time in milliseconds
        this.timer = null;
        this.warningTimer = null;
        this.warningShown = false;
        this.events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        this.init();
    }

    init() {
        // Reset timer on any user activity
        this.events.forEach(event => {
            document.addEventListener(event, () => this.resetTimer());
        });

        // Start the timer
        this.startTimer();
    }

    startTimer() {
        this.timer = setTimeout(() => {
            this.showWarning();
        }, this.timeout - this.warningTime); // Show warning before lock
    }

    resetTimer() {
        // Clear both timers
        if (this.timer) {
            clearTimeout(this.timer);
        }
        if (this.warningTimer) {
            clearTimeout(this.warningTimer);
        }
        
        // Hide any existing warning
        this.hideWarning();
        
        // Reset warning flag
        this.warningShown = false;
        
        // Start timer again
        this.startTimer();
    }

    showWarning() {
        if (this.warningShown) return;
        
        this.warningShown = true;
        
        // Create custom warning dialog
        const warningDialog = this.createWarningDialog();
        document.body.appendChild(warningDialog);
        
        // Animate the countdown
        let countdown = 5;
        const countdownElement = document.getElementById('countdown');
        
        this.warningTimer = setInterval(() => {
            countdown--;
            if (countdownElement) {
                countdownElement.textContent = countdown;
            }
            
            if (countdown <= 0) {
                this.hideWarning();
                this.lockSession();
            }
        }, 1000);
        
        // Add event listener for cancel button
        document.getElementById('cancel-lock')?.addEventListener('click', () => {
            this.hideWarning();
            this.resetTimer();
        });
    }

    createWarningDialog() {
        const dialog = document.createElement('div');
        dialog.id = 'session-warning';
        dialog.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        `;

        dialog.innerHTML = `
            <style>
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes slideUp {
                    from { transform: translateY(30px); opacity: 0; }
                    to { transform: translateY(0); opacity: 1; }
                }
                @keyframes pulse {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.1); }
                }
            </style>
            <div style="
                background: white;
                border-radius: 20px;
                padding: 40px;
                max-width: 400px;
                width: 90%;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                animation: slideUp 0.3s ease;
                font-family: 'Plus Jakarta Sans', sans-serif;
            ">
                <div style="
                    width: 80px;
                    height: 80px;
                    background: linear-gradient(135deg, #6366f1, #ec4899);
                    border-radius: 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 auto 20px;
                    animation: pulse 2s infinite;
                ">
                    <i class="fas fa-clock" style="font-size: 40px; color: white;"></i>
                </div>
                
                <h2 style="
                    color: #1e293b;
                    font-size: 24px;
                    font-weight: 800;
                    margin-bottom: 10px;
                    letter-spacing: -0.5px;
                ">Session Expiring Soon</h2>
                
                <p style="
                    color: #64748b;
                    font-size: 14px;
                    font-weight: 500;
                    margin-bottom: 25px;
                    line-height: 1.6;
                ">
                    Your session will be locked due to inactivity in 
                    <span style="
                        display: inline-block;
                        background: #6366f1;
                        color: white;
                        padding: 2px 10px;
                        border-radius: 20px;
                        font-weight: 700;
                        margin: 0 3px;
                    " id="countdown">5</span> 
                    seconds.
                </p>
                
                <div style="
                    width: 100%;
                    height: 4px;
                    background: #e2e8f0;
                    border-radius: 2px;
                    margin-bottom: 25px;
                    overflow: hidden;
                ">
                    <div id="progress-bar" style="
                        width: 100%;
                        height: 100%;
                        background: linear-gradient(90deg, #6366f1, #ec4899);
                        animation: shrink 5s linear forwards;
                    "></div>
                </div>
                
                <style>
                    @keyframes shrink {
                        from { width: 100%; }
                        to { width: 0%; }
                    }
                </style>
                
                <div style="display: flex; gap: 10px;">
                    <button id="cancel-lock" style="
                        flex: 1;
                        padding: 14px;
                        background: white;
                        border: 2px solid #e2e8f0;
                        border-radius: 12px;
                        color: #1e293b;
                        font-weight: 700;
                        font-size: 14px;
                        cursor: pointer;
                        transition: all 0.3s;
                    " onmouseover="this.style.borderColor='#6366f1'; this.style.color='#6366f1'" 
                       onmouseout="this.style.borderColor='#e2e8f0'; this.style.color='#1e293b'">
                        <i class="fas fa-times" style="margin-right: 8px;"></i>
                        Cancel
                    </button>
                    
                    <button id="lock-now" style="
                        flex: 1;
                        padding: 14px;
                        background: linear-gradient(135deg, #6366f1, #ec4899);
                        border: none;
                        border-radius: 12px;
                        color: white;
                        font-weight: 700;
                        font-size: 14px;
                        cursor: pointer;
                        transition: all 0.3s;
                    " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 25px rgba(99,102,241,0.4)'"
                       onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">
                        <i class="fas fa-lock" style="margin-right: 8px;"></i>
                        Lock Now
                    </button>
                </div>
            </div>
        `;

        // Add event listener for lock now button
        setTimeout(() => {
            document.getElementById('lock-now')?.addEventListener('click', () => {
                this.hideWarning();
                this.lockSession();
            });
        }, 100);

        return dialog;
    }

    hideWarning() {
        // Clear warning timer
        if (this.warningTimer) {
            clearInterval(this.warningTimer);
            this.warningTimer = null;
        }
        
        // Remove warning dialog
        const warningDialog = document.getElementById('session-warning');
        if (warningDialog) {
            warningDialog.remove();
        }
        
        this.warningShown = false;
    }

    lockSession() {
        // Hide any existing warning
        this.hideWarning();
        
        // Show a quick loading effect before redirect
        const loader = document.createElement('div');
        loader.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(99, 102, 241, 0.9);
            backdrop-filter: blur(5px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            flex-direction: column;
            gap: 20px;
            transition: opacity 0.3s;
        `;
        
        loader.innerHTML = `
            <div style="
                width: 60px;
                height: 60px;
                border: 4px solid rgba(255,255,255,0.3);
                border-top-color: white;
                border-radius: 50%;
                animation: spin 1s linear infinite;
            "></div>
            <div style="color: white; font-size: 18px; font-weight: 600;">Locking session...</div>
            <style>
                @keyframes spin {
                    to { transform: rotate(360deg); }
                }
            </style>
        `;
        
        document.body.appendChild(loader);
        
        // Redirect after a short delay for visual feedback
        setTimeout(() => {
            window.location.href = 'lock.php';
        }, 500);
    }
}

// Initialize tracker when page loads
document.addEventListener('DOMContentLoaded', () => {
    // Lock after 60 seconds of inactivity, show warning 5 seconds before
    new InactivityTracker(60, 5); 
});
    </script>
</body>
</html>