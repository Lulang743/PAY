<?php
// functionsd.php - Database functions for payroll system

/**
 * Get dashboard statistics
 */
function getDashboardStats($conn) {
    $stats = [
        'total_employees' => 0,
        'present_today' => 0,
        'attendance_rate' => 0,
        'pending_payroll' => 0,
        'pending_count' => 0,
        'on_leave' => 0,
        'approved_leaves' => 0
    ];
    
    try {
        // Total employees
        $query = "SELECT COUNT(*) as total FROM employees WHERE status = 'active'";
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $stats['total_employees'] = mysqli_fetch_assoc($result)['total'];
        }
        
        // Present today
        $today = date('Y-m-d');
        $query = "SELECT COUNT(DISTINCT employee_id) as present FROM attendance 
                  WHERE work_date = '$today' AND status = 'present'";
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $stats['present_today'] = mysqli_fetch_assoc($result)['present'];
        }
        
        // Attendance rate
        if ($stats['total_employees'] > 0) {
            $stats['attendance_rate'] = round(($stats['present_today'] / $stats['total_employees']) * 100);
        }
        
        // Pending payroll
        $current_month = date('Y-m');
        $query = "SELECT COALESCE(SUM(total_pay), 0) as total, COUNT(*) as count FROM payroll 
                  WHERE pay_period_start LIKE '$current_month%' AND status = 'pending'";
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $stats['pending_payroll'] = $row['total'] ?? 0;
            $stats['pending_count'] = $row['count'] ?? 0;
        }
        
        // On leave today
        $query = "SELECT COUNT(*) as on_leave FROM leave_requests 
                  WHERE status = 'approved' AND '$today' BETWEEN start_date AND end_date";
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $stats['on_leave'] = mysqli_fetch_assoc($result)['on_leave'];
        }
        
        // Approved leaves count
        $query = "SELECT COUNT(*) as approved FROM leave_requests WHERE status = 'approved'";
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $stats['approved_leaves'] = mysqli_fetch_assoc($result)['approved'];
        }
        
    } catch (Exception $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get recent payroll entries
 */
/**
 * Get recent payroll entries
 */
function getRecentPayroll($conn, $limit = 5) {
    $payroll = [];
    try {
        $query = "SELECT 
                    p.id,
                    p.employee_id,
                    p.pay_period_start,
                    p.pay_period_end,
                    p.regular_hours,
                    p.overtime_hours,
                    p.regular_pay,
                    p.overtime_pay,
                    p.total_pay,
                    p.status,
                    p.payment_date,
                    p.created_at,
                    e.first_name,
                    e.last_name,
                    e.employee_id as emp_code,
                    CONCAT(e.first_name, ' ', e.last_name) as employee_name
                  FROM payroll p
                  JOIN employees e ON p.employee_id = e.id
                  ORDER BY p.created_at DESC, p.pay_period_end DESC
                  LIMIT " . intval($limit);
        
        $result = mysqli_query($conn, $query);
        
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                // Format the pay period for display
                $row['period'] = date('M Y', strtotime($row['pay_period_start'])) . ' - ' . 
                                 date('M Y', strtotime($row['pay_period_end']));
                
                // Set gross_pay from total_pay (for dashboard compatibility)
                $row['gross_pay'] = $row['total_pay'] ?? 0;
                
                $payroll[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Recent payroll error: " . $e->getMessage());
    }
    return $payroll;
}


/**
 * Get department statistics including departments with no employees
 */
function getDepartmentStats($conn) {
    $stats = [];
    try {
        $query = "SELECT d.department_name as department, COUNT(e.id) as count 
                  FROM departments d
                  LEFT JOIN employees e ON d.id = e.department_id AND e.status = 'active'
                  GROUP BY d.id, d.department_name, d.department_code
                  ORDER BY d.department_name ASC";
        
        $result = mysqli_query($conn, $query);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $stats[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Department stats error: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Get today's attendance with hours and overtime
 */
function getTodayAttendance($conn, $limit = 5) {
    $attendance = [];
    try {
        $today = date('Y-m-d');
        
        $query = "SELECT a.*, e.first_name, e.last_name,e.position,
                  CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                  a.work_date, a.hours_worked, a.overtime_hours
                  FROM attendance a
                  JOIN employees e ON a.employee_id = e.id
                  WHERE a.work_date = '$today'
                  ORDER BY a.hours_worked DESC
                  LIMIT $limit";
        
        $result = mysqli_query($conn, $query);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $attendance[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Today attendance error: " . $e->getMessage());
    }
    
    return $attendance;
}
/**
 * Get upcoming leaves
 */
function getUpcomingLeaves($conn, $limit = 5) {
    $leaves = [];
    try {
        $today = date('Y-m-d');
        
        $query = "SELECT l.*, e.first_name, e.last_name,
                  CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                  DATEDIFF(l.end_date, l.start_date) + 1 as total_days,
                  l.status, l.leave_type, l.start_date, l.end_date
                  FROM leave_requests l
                  JOIN employees e ON l.employee_id = e.id
                  WHERE l.start_date >= '$today' AND l.status IN ('approved', 'pending')
                  ORDER BY l.start_date ASC
                  LIMIT $limit";
        
        $result = mysqli_query($conn, $query);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $row['start_date'] = date('M d, Y', strtotime($row['start_date']));
                $row['end_date'] = date('M d, Y', strtotime($row['end_date']));
                $leaves[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Upcoming leaves error: " . $e->getMessage());
    }
    
    return $leaves;
}

/**
 * Get current user info from database - Essential fields only, no mock data
 */
function getCurrentUser($conn) {
    // Initialize with empty values
    $userData = [
        'id' => 0,
        'first_name' => '',
        'last_name' => '',
        'full_name' => '',
        'email' => '',
        'user_role' => '',
        'avatar' => null
    ];
    
    try {
        if (isset($_SESSION['user_id'])) {
            $user_id = intval($_SESSION['user_id']);
            
            $query = "SELECT 
                        id,
                        first_name,
                        last_name,
                        CONCAT(first_name, ' ', last_name) as full_name,
                        email,
                        role,
                        avatar
                      FROM users 
                      WHERE id = $user_id";
            
            $result = mysqli_query($conn, $query);
            
            if ($result && mysqli_num_rows($result) > 0) {
                $dbUser = mysqli_fetch_assoc($result);
                
                $userData['id'] = $dbUser['id'];
                $userData['first_name'] = $dbUser['first_name'] ?? '';
                $userData['last_name'] = $dbUser['last_name'] ?? '';
                $userData['full_name'] = $dbUser['full_name'] ?? '';
                $userData['email'] = $dbUser['email'] ?? '';
                $userData['user_role'] = $dbUser['role'] ?? '';
                $userData['avatar'] = $dbUser['avatar'] ?? null;
                
                // Update session with actual user data
                if (!empty($userData['full_name'])) {
                    $_SESSION['user_name'] = $userData['full_name'];
                }
                if (!empty($userData['user_role'])) {
                    $_SESSION['user_role'] = $userData['user_role'];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Current user error: " . $e->getMessage());
    }
    
    return $userData;
}

/**
 * Get notification count
 */
function getNotificationCount($conn) {
    $count = 0;
    try {
        $today = date('Y-m-d');
        
        // Count pending leave requests
        $query = "SELECT COUNT(*) as count FROM leave_requests WHERE status = 'pending'";
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $pending_leaves = mysqli_fetch_assoc($result)['count'];
            $count += $pending_leaves;
        }
        
        // Count employees not marked attendance today
        $query = "SELECT COUNT(*) as count FROM employees e 
                  WHERE e.status = 'active' 
                  AND NOT EXISTS (
                      SELECT 1 FROM attendance a 
                      WHERE a.employee_id = e.id AND a.attendance_date = '$today'
                  )";
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            $missing_attendance = mysqli_fetch_assoc($result)['count'];
            $count += $missing_attendance;
        }
    } catch (Exception $e) {
        error_log("Notification count error: " . $e->getMessage());
    }
    
    return $count;
}

/**
 * Check user role authorization
 */


/**
 * Get database connection (if config.php doesn't have it)
 */
function getDBConnection() {
    $host = 'localhost';
    $username = 'root';
    $password = '';
    $database = 'payroll_db';
    
    $conn = mysqli_connect($host, $username, $password, $database);
    
    if (!$conn) {
        die("Connection failed: " . mysqli_connect_error());
    }
    
    return $conn;
}

?>