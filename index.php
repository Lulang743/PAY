<?php
session_start();

require_once 'config.php';
require_once 'auth_check.php';
require_once 'functionsd.php';

// Check if user has permission - MODIFIED to include more roles if needed
checkRole(['admin', 'manager', 'hr', 'employee']); // Added 'employee' if employees should access dashboard

// Get dashboard statistics
$stats = getDashboardStats($conn);
$recentPayroll = getRecentPayroll($conn);
$departmentStats = getDepartmentStats($conn);
$attendanceToday = getTodayAttendance($conn);
$upcomingLeaves = getUpcomingLeaves($conn);
$currentUser = getCurrentUser($conn);
$notificationCount = getNotificationCount($conn);

// Function to get payroll chart data
function getPayrollChartData($conn) {
    $chartData = ['labels' => [], 'gross_pay' => [], 'net_pay' => []];
    try {
        $query = "SELECT 
                    WEEK(payroll_period) as week_num,
                    DATE_FORMAT(MIN(payroll_period), '%b %d') as week_label,
                    SUM(gross_pay) as total_gross,
                    SUM(net_pay) as total_net
                  FROM payroll 
                  WHERE payroll_period >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
                  GROUP BY WEEK(payroll_period)
                  ORDER BY week_num ASC
                  LIMIT 4";
        
        $result = mysqli_query($conn, $query);
        if ($result && mysqli_num_rows($result) > 0) {
            while ($row = mysqli_fetch_assoc($result)) {
                $chartData['labels'][] = $row['week_label'];
                $chartData['gross_pay'][] = (float)$row['total_gross'];
                $chartData['net_pay'][] = (float)$row['total_net'];
            }
        }
    } catch (Exception $e) {
        error_log("Payroll chart data error: " . $e->getMessage());
    }
    return $chartData;
}

$payrollChartData = getPayrollChartData($conn);

// Get current time for greeting - using JavaScript for real-time update
$currentHour = date('H');
if ($currentHour < 12) {
    $greeting = 'Good Morning';
    $greetingIcon = 'fa-sun';
    $greetingColor = '#f39c12';
} elseif ($currentHour < 17) {
    $greeting = 'Good Afternoon';
    $greetingIcon = 'fa-cloud-sun';
    $greetingColor = '#e67e22';
} else {
    $greeting = 'Good Evening';
    $greetingIcon = 'fa-moon';
    $greetingColor = '#9b59b6';
}

$firstName = $currentUser['first_name'] ?? '';
$lastName = $currentUser['last_name'] ?? '';
$userRole = $currentUser['user_role'] ?? $_SESSION['user_role'] ?? '';
$userEmail = $currentUser['email'] ?? '';
$userAvatar = $currentUser['avatar'] ?? null;
$username = $currentUser['username'] ?? '';
$lastLogin = $currentUser['last_login'] ?? null;

// Debug: Uncomment to check current user role (remove after debugging)
// echo "<!-- Debug: Current user role: " . $userRole . " -->";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | PayrollPro</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Your existing CSS styles here (keeping them as they are) */
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #818cf8;
            --secondary: #ec4899;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --dark: #1e293b;
            --light: #f8fafc;
            --sidebar-width: 280px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --shadow-glow: 0 0 20px rgba(99, 102, 241, 0.3);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #f0f4ff 0%, #e0e7ff 50%, #f5f3ff 100%);
            min-height: 100vh;
            color: var(--dark);
            overflow-x: hidden;
        }

        /* Animated Background */
        .bg-pattern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.4;
            background-image: 
                radial-gradient(circle at 20% 50%, rgba(99, 102, 241, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(236, 72, 153, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 20%, rgba(16, 185, 129, 0.05) 0%, transparent 50%);
        }

        .wrapper {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* Glass Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(20px);
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            border-right: 1px solid rgba(255,255,255,0.1);
            box-shadow: var(--shadow-lg);
        }

        .sidebar-header {
            padding: 30px 25px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.2) 0%, rgba(236, 72, 153, 0.2) 100%);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            position: relative;
            overflow: hidden;
        }

        .sidebar-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .sidebar-header h3 {
            margin: 0;
            font-weight: 800;
            font-size: 26px;
            background: linear-gradient(135deg, #fff 0%, #c7d2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            letter-spacing: -0.5px;
        }

        .sidebar-header p {
            margin: 8px 0 0;
            font-size: 12px;
            opacity: 0.7;
            color: #cbd5e1;
            font-weight: 500;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .sidebar-menu {
            padding: 20px 15px;
        }

        .sidebar-menu a {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            transition: var(--transition);
            margin: 4px 0;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            font-weight: 500;
        }

        .sidebar-menu a::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0;
            background: linear-gradient(90deg, var(--primary) 0%, transparent 100%);
            transition: width 0.3s ease;
            opacity: 0.2;
        }

        .sidebar-menu a:hover::before,
        .sidebar-menu a.active::before {
            width: 100%;
        }

        .sidebar-menu a:hover {
            color: #fff;
            transform: translateX(8px);
            background: rgba(255,255,255,0.05);
        }

        .sidebar-menu a.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #fff;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
        }

        .sidebar-menu a i {
            width: 28px;
            margin-right: 12px;
            font-size: 18px;
            transition: transform 0.3s ease;
        }

        .sidebar-menu a:hover i {
            transform: scale(1.1);
        }

        .sidebar-menu a span {
            font-size: 14px;
            font-weight: 600;
        }

        .sidebar-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.2) 50%, transparent 100%);
            margin: 20px 15px;
        }

        /* Main Content */
        .content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 30px;
            transition: var(--transition);
        }

        /* Glass Top Nav */
        .top-nav {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(20px);
            padding: 20px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 20px;
            z-index: 100;
        }

        .page-title h4 {
            margin: 0;
            color: var(--dark);
            font-weight: 700;
            font-size: 24px;
            letter-spacing: -0.5px;
        }

        .page-title p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .live-dot {
            width: 6px;
            height: 6px;
            background: var(--success);
            border-radius: 50%;
            animation: blink 2s infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .current-time {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }

        .time-display {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        .live-timestamp {
            font-size: 10px;
            color: #64748b;
            margin-left: 8px;
        }

        .user-dropdown {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .notification-icon {
            position: relative;
            cursor: pointer;
            padding: 12px;
            background: rgba(99, 102, 241, 0.1);
            border-radius: 12px;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        .notification-icon:hover {
            background: rgba(99, 102, 241, 0.2);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);
        }

        .notification-icon i {
            font-size: 18px;
            color: var(--primary);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: linear-gradient(135deg, var(--danger) 0%, #dc2626 100%);
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: 700;
            min-width: 18px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 8px 16px 8px 8px;
            border-radius: 16px;
            transition: var(--transition);
            background: rgba(255,255,255,0.5);
            border: 1px solid rgba(255,255,255,0.6);
        }

        .user-profile:hover {
            background: rgba(255,255,255,0.9);
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: var(--shadow-sm);
        }

        .user-info {
            line-height: 1.3;
        }

        .user-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .user-role {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .dropdown-menu {
            border: none;
            box-shadow: var(--shadow-lg), 0 0 0 1px rgba(0,0,0,0.05);
            border-radius: 16px;
            padding: 12px;
            min-width: 220px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            margin-top: 10px !important;
        }

        .dropdown-item {
            padding: 12px 16px;
            font-size: 13px;
            color: var(--dark);
            transition: var(--transition);
            border-radius: 10px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dropdown-item:hover {
            background: rgba(99, 102, 241, 0.1);
            color: var(--primary);
            transform: translateX(4px);
        }

        .dropdown-item i {
            width: 20px;
            color: var(--primary);
            font-size: 16px;
        }

        .welcome-banner {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.9) 0%, rgba(236, 72, 153, 0.9) 100%);
            color: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-lg), var(--shadow-glow);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(-30px, -30px) rotate(10deg); }
        }

        .welcome-content {
            display: flex;
            align-items: center;
            gap: 25px;
            position: relative;
            z-index: 1;
        }

        .welcome-icon {
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            border: 1px solid rgba(255,255,255,0.3);
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            animation: iconFloat 3s ease-in-out infinite;
        }

        @keyframes iconFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .welcome-text h5 {
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .welcome-text p {
            margin: 0;
            font-size: 14px;
            opacity: 0.95;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .welcome-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-size: 12px;
        }

        .welcome-actions {
            display: flex;
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .btn-glass {
            padding: 12px 24px;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 12px;
            font-weight: 600;
            font-size: 13px;
            transition: var(--transition);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-glass:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.6);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color) 0%, transparent 100%);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card.employees { --card-color: var(--primary); }
        .stat-card.attendance { --card-color: var(--success); }
        .stat-card.payroll { --card-color: var(--warning); }
        .stat-card.leaves { --card-color: var(--danger); }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .stat-title {
            color: #64748b;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            background: linear-gradient(135deg, var(--card-color) 0%, transparent 100%);
            color: var(--card-color);
            position: relative;
            overflow: hidden;
        }

        .stat-icon::after {
            content: '';
            position: absolute;
            inset: 0;
            background: var(--card-color);
            opacity: 0.1;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 8px;
            letter-spacing: -1px;
            line-height: 1;
        }

        .stat-change {
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .trend-up { color: var(--success); }
        .trend-down { color: var(--danger); }

        .progress-bar {
            height: 4px;
            background: rgba(0,0,0,0.05);
            border-radius: 2px;
            margin-top: 16px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--card-color);
            border-radius: 2px;
            transition: width 1s ease;
            position: relative;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 20px;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.5));
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { transform: translateX(-20px); }
            100% { transform: translateX(20px); }
        }

        .chart-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.6);
            height: 100%;
            transition: var(--transition);
        }

        .chart-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-title::before {
            content: '';
            width: 4px;
            height: 24px;
            background: linear-gradient(180deg, var(--primary) 0%, var(--secondary) 100%);
            border-radius: 2px;
        }

        .chart-select {
            padding: 8px 16px;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            color: var(--dark);
            background: rgba(255,255,255,0.5);
            cursor: pointer;
            transition: var(--transition);
        }

        .chart-select:hover {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .chart-container {
            position: relative;
            height: 280px;
            width: 100%;
        }

        .table-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.6);
            height: 100%;
            transition: var(--transition);
        }

        .table-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .view-all {
            color: var(--primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 10px;
        }

        .view-all:hover {
            background: rgba(99, 102, 241, 0.1);
            gap: 10px;
        }

        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .data-table th {
            padding: 12px 16px;
            color: #64748b;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid rgba(0,0,0,0.05);
        }

        .data-table td {
            padding: 16px;
            background: rgba(248, 250, 252, 0.5);
            font-size: 13px;
            color: var(--dark);
            font-weight: 600;
            border: none;
        }

        .data-table tr {
            transition: var(--transition);
        }

        .data-table tbody tr:hover td {
            background: rgba(99, 102, 241, 0.05);
            transform: scale(1.01);
        }

        .data-table td:first-child {
            border-radius: 12px 0 0 12px;
        }

        .data-table td:last-child {
            border-radius: 0 12px 12px 0;
        }

        .employee-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .employee-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 12px;
        }

        .employee-info {
            line-height: 1.3;
        }

        .employee-name {
            font-weight: 700;
            color: var(--dark);
        }

        .employee-meta {
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
        }

        .badge-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
        }

        .badge-present, .badge-approved, .badge-paid {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }

        .badge-absent {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.2);
        }

        .badge-late, .badge-pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
            border-color: rgba(245, 158, 11, 0.2);
        }

        .badge-half-day {
            background: rgba(6, 182, 212, 0.1);
            color: var(--info);
            border-color: rgba(6, 182, 212, 0.2);
        }

        .badge-status::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            margin-top: 30px;
        }

        .quick-action {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 24px 16px;
            text-align: center;
            text-decoration: none;
            color: var(--dark);
            transition: var(--transition);
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.6);
            position: relative;
            overflow: hidden;
        }

        .quick-action::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--action-color) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .quick-action:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
            color: var(--action-color);
        }

        .quick-action:hover::before {
            opacity: 0.05;
        }

        .quick-action i {
            font-size: 28px;
            margin-bottom: 12px;
            display: block;
            transition: transform 0.3s;
            color: var(--action-color);
        }

        .quick-action:hover i {
            transform: scale(1.2) rotate(5deg);
        }

        .quick-action span {
            display: block;
            font-size: 13px;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }

        .quick-action:nth-child(1) { --action-color: var(--primary); }
        .quick-action:nth-child(2) { --action-color: var(--success); }
        .quick-action:nth-child(3) { --action-color: var(--warning); }
        .quick-action:nth-child(4) { --action-color: var(--danger); }
        .quick-action:nth-child(5) { --action-color: var(--info); }
        .quick-action:nth-child(6) { --action-color: var(--secondary); }

        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .no-data-text {
            font-size: 14px;
            font-weight: 600;
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .quick-actions-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                margin-left: calc(-1 * var(--sidebar-width));
            }
            .content {
                margin-left: 0;
                padding: 20px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .quick-actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .welcome-banner {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            .welcome-content {
                flex-direction: column;
            }
        }

        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
            border-radius: 8px;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(99, 102, 241, 0.3);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(99, 102, 241, 0.5);
        }
    </style>
</head>
<body>
    <div class="bg-pattern"></div>
    
    <div class="wrapper">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>Alaki Payroll</h3>
                <p>Advanced Payroll System</p>
            </div>
            
            <div class="sidebar-menu">
                <a href="dashboard.php" class="active">
                    <i class="fas fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
                <?php if(in_array($userRole, ['admin', 'manager', 'hr'])): ?>
                <a href="employees.php">
                    <i class="fas fa-users"></i>
                    <span>Employees</span>
                </a>
                <a href="attendance.php">
                    <i class="fas fa-clock"></i>
                    <span>Attendance</span>
                </a>
                <a href="leave.php">
                    <i class="fas fa-calendar-check"></i>
                    <span>Leave Management</span>
                </a>
                <a href="payroll.php">
                    <i class="fas fa-wallet"></i>
                    <span>Payroll</span>
                </a>
                <a href="reports.php">
                    <i class="fas fa-chart-line"></i>
                    <span>Reports</span>
                </a>
               
                <a href="users.php">
                    <i class="fas fa-users"></i>
                    <span>Users</span>
                </a>
                <?php endif; ?>
                <?php if(in_array($userRole, ['admin'])): ?>
                <a href="settings.php">
                    <i class="fas fa-sliders-h"></i>
                    <span>Settings</span>
                </a>
                <?php endif; ?>
                <div class="sidebar-divider"></div>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <!-- Top Navigation -->
            <div class="top-nav">
                <div class="page-title">
                    <h4>Dashboard Overview</h4>
                    <p>
                        <i class="fas fa-calendar-day"></i> 
                        <span id="currentDate"><?php echo date('l, F j, Y'); ?></span>
                        <span class="live-indicator">
                            <span class="live-dot"></span>
                            Live
                        </span>
                        <span class="current-time">
                            <i class="far fa-clock"></i>
                            <span id="liveTime"></span>
                        </span>
                    </p>
                </div>
                
                <div class="user-dropdown">
                    <div class="notification-icon" onclick="window.location.href='notifications.php'">
                        <i class="fas fa-bell"></i>
                        <?php if($notificationCount > 0): ?>
                            <span class="notification-badge"><?php echo $notificationCount; ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="dropdown">
                        <div class="user-profile" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo !empty($userAvatar) ? $userAvatar : 'https://ui-avatars.com/api/?name='.urlencode($firstName ?: 'User').'&background=6366f1&color=fff&size=128&bold=true'; ?>" 
                                 alt="Profile" class="user-avatar">
                            <div class="user-info d-none d-md-block">
                                <div class="user-name">
                                    <?php echo htmlspecialchars($firstName ?: 'User'); ?>
                                    <i class="fas fa-chevron-down" style="font-size: 10px; color: #94a3b8;"></i>
                                </div>
                                <div class="user-role">
                                    <span class="role-badge"><?php echo ucfirst($userRole); ?></span>
                                </div>
                            </div>
                        </div>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php">
                                <i class="fas fa-user-circle"></i>My Profile
                            </a></li>
                            <?php if(in_array($userRole, ['admin'])): ?>
                            <li><a class="dropdown-item" href="settings.php">
                                <i class="fas fa-cog"></i>Settings
                            </a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="change-password.php">
                                <i class="fas fa-shield-alt"></i>Security
                            </a></li>
                            <li><hr class="dropdown-divider" style="margin: 8px 0; opacity: 0.1;"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php">
                                <i class="fas fa-power-off"></i>Sign Out
                            </a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Welcome Banner -->
            <?php if(!empty($firstName) || !empty($userEmail)): ?>
            <div class="welcome-banner">
                <div class="welcome-content">
                    <div class="welcome-icon" id="greetingIcon">
                        <i class="fas <?php echo $greetingIcon; ?>"></i>
                    </div>
                    <div class="welcome-text">
                        <h5 id="greetingMessage"><?php echo $greeting; ?>, <?php echo htmlspecialchars($firstName); ?>! 👋</h5>
                        <p>
                            <span class="welcome-meta">
                                <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($userEmail); ?>
                            </span>
                            <span class="welcome-meta">
                                <i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($username); ?>
                            </span>
                            <?php if(!empty($lastLogin)): ?>
                            <span class="welcome-meta">
                                <i class="fas fa-clock"></i> Last login: <?php echo date('M d, H:i', strtotime($lastLogin)); ?>
                            </span>
                            <?php endif; ?>
                            <span class="welcome-meta" id="currentTimeMeta">
                                <i class="far fa-clock"></i> 
                                <span id="liveTimeMeta"></span>
                            </span>
                        </p>
                    </div>
                </div>
                <div class="welcome-actions">
                    <button class="btn-glass" onclick="window.location.href='profile.php'">
                        <i class="fas fa-user"></i> Profile
                    </button>
                    <?php if(in_array($userRole, ['admin'])): ?>
                    <button class="btn-glass" onclick="window.location.href='settings.php'">
                        <i class="fas fa-cog"></i> Settings
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card employees">
                    <div class="stat-header">
                        <span class="stat-title">Total Employees</span>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['total_employees'] ?? 0); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-arrow-trend-up trend-up"></i>
                        <span>Active workforce</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 85%"></div>
                    </div>
                </div>

                <div class="stat-card attendance">
                    <div class="stat-header">
                        <span class="stat-title">Present Today</span>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['present_today'] ?? 0); ?></div>
                    <div class="stat-change">
                        <span class="trend-up"><?php echo $stats['attendance_rate'] ?? 0; ?>%</span>
                        <span>attendance rate</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $stats['attendance_rate'] ?? 0; ?>%"></div>
                    </div>
                </div>

                <div class="stat-card payroll">
                    <div class="stat-header">
                        <span class="stat-title">Pending Payroll</span>
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="stat-value">LSL<?php echo number_format($stats['pending_payroll'] ?? 0, 2); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-exclamation-circle" style="color: var(--warning)"></i>
                        <span><?php echo $stats['pending_count'] ?? 0; ?> pending</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 60%"></div>
                    </div>
                </div>

                <div class="stat-card leaves">
                    <div class="stat-header">
                        <span class="stat-title">On Leave Today</span>
                        <div class="stat-icon">
                            <i class="fas fa-calendar-minus"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo number_format($stats['on_leave'] ?? 0); ?></div>
                    <div class="stat-change">
                        <i class="fas fa-check-circle trend-up"></i>
                        <span><?php echo $stats['approved_leaves'] ?? 0; ?> approved</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 40%"></div>
                    </div>
                </div>
            </div>

            <!-- Charts Row - Only show for admin/manager/hr -->
            <?php if(in_array($userRole, ['admin', 'manager', 'hr'])): ?>
            <div class="row g-4 mb-4">
                <div class="col-lg-8">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h5 class="chart-title">Payroll Analytics</h5>
                            <select class="chart-select" id="payrollPeriod">
                                <option>Last 4 Weeks</option>
                                <option>This Month</option>
                                <option>Last Month</option>
                                <option>This Year</option>
                            </select>
                        </div>
                        <div class="chart-container">
                            <canvas id="payrollChart"></canvas>
                            <?php if (empty($payrollChartData['labels'])): ?>
                                <div class="no-data">
                                    <i class="fas fa-chart-area"></i>
                                    <div class="no-data-text">No payroll data available</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h5 class="chart-title">Departments</h5>
                        </div>
                        <div class="chart-container">
                            <canvas id="deptChart"></canvas>
                            <?php if (empty($departmentStats)): ?>
                                <div class="no-data">
                                    <i class="fas fa-pie-chart"></i>
                                    <div class="no-data-text">No department data</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tables Row - Only show for admin/manager/hr -->
            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    <div class="table-card">
                        <div class="table-header">
                            <h5 class="chart-title">Recent Payroll</h5>
                            <a href="payroll.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
                        </div>
                        
                        <?php if (!empty($recentPayroll)): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Period</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($recentPayroll as $pay): ?>
                                    <tr>
                                        <td>
                                            <div class="employee-cell">
                                                <div class="employee-avatar">
                                                    <?php echo strtoupper(substr($pay['employee_name'] ?? 'U', 0, 2)); ?>
                                                </div>
                                                <div class="employee-info">
                                                    <div class="employee-name"><?php echo htmlspecialchars($pay['employee_name'] ?? ''); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($pay['period'] ?? ''); ?></td>
                                        <td style="font-weight: 700; color: var(--success);">LSL<?php echo number_format($pay['gross_pay'] ?? 0, 2); ?></td>
                                        <td>
                                            <span class="badge-status badge-<?php echo $pay['status'] ?? 'pending'; ?>">
                                                <?php echo ucfirst($pay['status'] ?? 'Pending'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-receipt"></i>
                                <div class="no-data-text">No recent payroll records</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="table-card">
                        <div class="table-header">
                            <h5 class="chart-title">Today's Attendance</h5>
                            <a href="attendance.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
                        </div>
                        
                        <?php if (!empty($attendanceToday)): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Time In</th>
                                        <th>Time Out</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($attendanceToday as $att): ?>
                                    <tr>
                                        <td>
                                            <div class="employee-cell">
                                                <div class="employee-avatar" style="background: linear-gradient(135deg, var(--success) 0%, #059669 100%);">
                                                    <?php echo strtoupper(substr($att['employee_name'] ?? 'U', 0, 2)); ?>
                                                </div>
                                                <div class="employee-info">
                                                    <div class="employee-name"><?php echo htmlspecialchars($att['employee_name'] ?? ''); ?></div>
                                                    <div class="employee-meta"><?php echo htmlspecialchars($att['position'] ?? ''); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="time-display">
                                            <?php 
                                            if (!empty($att['time_in']) && $att['time_in'] != '--') {
                                                echo $att['time_in'];
                                                if (strtotime($att['time_in']) > strtotime('09:00')) {
                                                    echo ' <span style="color: var(--warning); font-size: 10px;"><i class="fas fa-clock"></i> Late</span>';
                                                }
                                            } else {
                                                echo '--';
                                            }
                                            ?>
                                        </td>
                                        <td class="time-display">
                                            <?php echo $att['time_out'] ?? '--'; ?>
                                        </td>
                                        <td>
                                            <span class="badge-status badge-<?php echo $att['status'] ?? 'present'; ?>">
                                                <?php echo ucfirst($att['status'] ?? 'Present'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-clipboard-check"></i>
                                <div class="no-data-text">No attendance records today</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upcoming Leaves -->
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <div class="table-card">
                        <div class="table-header">
                            <h5 class="chart-title">Upcoming Leaves</h5>
                            <a href="leave.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
                        </div>
                        
                        <?php if (!empty($upcomingLeaves)): ?>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Department</th>
                                        <th>Type</th>
                                        <th>Duration</th>
                                        <th>Days</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($upcomingLeaves as $leave): ?>
                                    <tr>
                                        <td>
                                            <div class="employee-cell">
                                                <div class="employee-avatar" style="background: linear-gradient(135deg, var(--info) 0%, #0891b2 100%);">
                                                    <?php echo strtoupper(substr($leave['employee_name'] ?? 'U', 0, 2)); ?>
                                                </div>
                                                <div class="employee-name"><?php echo htmlspecialchars($leave['employee_name'] ?? ''); ?></div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($leave['department'] ?? 'N/A'); ?></td>
                                        <td><?php echo ucfirst($leave['leave_type'] ?? ''); ?></td>
                                        <td>
                                            <div style="font-size: 12px; color: #64748b;">
                                                <?php echo $leave['start_date'] ?? ''; ?> → <?php echo $leave['end_date'] ?? ''; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-weight: 700; color: var(--primary);">
                                                <?php echo $leave['total_days'] ?? 0; ?> days
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-status badge-<?php echo $leave['status'] ?? 'pending'; ?>">
                                                <?php echo ucfirst($leave['status'] ?? 'Pending'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-calendar-check"></i>
                                <div class="no-data-text">No upcoming leave requests</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Employee Dashboard View -->
            <div class="row">
                <div class="col-12">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h5 class="chart-title">My Recent Activity</h5>
                        </div>
                        <div class="no-data" style="padding: 40px;">
                            <i class="fas fa-user-check"></i>
                            <div class="no-data-text">Welcome to your employee dashboard!</div>
                            <div class="no-data-text" style="font-size: 12px; margin-top: 10px;">
                                Use the menu to manage your profile, leave requests, and attendance.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="quick-actions-grid">
                <?php if(in_array($userRole, ['admin', 'hr'])): ?>
                <a href="employees.php?action=add" class="quick-action">
                    <i class="fas fa-user-plus"></i>
                    <span>Add Employee</span>
                </a>
                <?php endif; ?>
                <?php if(in_array($userRole, ['admin', 'manager', 'hr'])): ?>
                <a href="attendance.php" class="quick-action">
                    <i class="fas fa-fingerprint"></i>
                    <span>Mark Attendance</span>
                </a>
                <a href="payroll.php?action=generate" class="quick-action">
                    <i class="fas fa-calculator"></i>
                    <span>Generate Payroll</span>
                </a>
                <a href="reports.php" class="quick-action">
                    <i class="fas fa-file-export"></i>
                    <span>Reports</span>
                </a>
                <?php endif; ?>
                <a href="leave.php?action=request" class="quick-action">
                    <i class="fas fa-calendar-plus"></i>
                    <span>Leave Request</span>
                </a>
                <a href="profile.php" class="quick-action">
                    <i class="fas fa-id-card"></i>
                    <span>My Profile</span>
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Real-time clock functionality
        function updateTime() {
            const now = new Date();
            
            // Format time as HH:MM:SS AM/PM
            let hours = now.getHours();
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            
            hours = hours % 12;
            hours = hours ? hours : 12;
            const timeString = `${hours}:${minutes}:${seconds} ${ampm}`;
            
            // Update all time displays
            const timeElements = document.querySelectorAll('#liveTime, #liveTimeMeta');
            timeElements.forEach(el => {
                if (el) el.textContent = timeString;
            });
            
            // Update greeting based on current hour
            const currentHour = now.getHours();
            let greeting = '';
            let icon = '';
            
            if (currentHour < 12) {
                greeting = 'Good Morning';
                icon = 'fa-sun';
            } else if (currentHour < 17) {
                greeting = 'Good Afternoon';
                icon = 'fa-cloud-sun';
            } else {
                greeting = 'Good Evening';
                icon = 'fa-moon';
            }
            
            // Update greeting message and icon
            const greetingElement = document.getElementById('greetingMessage');
            const iconElement = document.querySelector('#greetingIcon i');
            
            if (greetingElement) {
                const namePart = greetingElement.textContent.split(',')[1];
                if (namePart) {
                    greetingElement.textContent = `${greeting},${namePart}`;
                }
            }
            
            if (iconElement) {
                iconElement.className = `fas ${icon}`;
            }
            
            // Update date
            const dateElement = document.getElementById('currentDate');
            if (dateElement) {
                const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                const dateString = now.toLocaleDateString(undefined, options);
                dateElement.textContent = dateString;
            }
        }

        // Update time immediately and then every second
        updateTime();
        setInterval(updateTime, 1000);

        <?php if(!empty($payrollChartData['labels']) && in_array($userRole, ['admin', 'manager', 'hr'])): ?>
        // Chart Configuration
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        Chart.defaults.color = '#64748b';
        
        const ctx1 = document.getElementById('payrollChart').getContext('2d');
        
        // Create gradient
        const gradient1 = ctx1.createLinearGradient(0, 0, 0, 400);
        gradient1.addColorStop(0, 'rgba(99, 102, 241, 0.3)');
        gradient1.addColorStop(1, 'rgba(99, 102, 241, 0.0)');
        
        const gradient2 = ctx1.createLinearGradient(0, 0, 0, 400);
        gradient2.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
        gradient2.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

        new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($payrollChartData['labels']); ?>,
                datasets: [{
                    label: 'Gross Pay',
                    data: <?php echo json_encode($payrollChartData['gross_pay']); ?>,
                    borderColor: '#6366f1',
                    backgroundColor: gradient1,
                    tension: 0.4,
                    borderWidth: 3,
                    pointRadius: 6,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#6366f1',
                    pointBorderWidth: 3,
                    pointHoverRadius: 8,
                    fill: true
                }, {
                    label: 'Net Pay',
                    data: <?php echo json_encode($payrollChartData['net_pay']); ?>,
                    borderColor: '#10b981',
                    backgroundColor: gradient2,
                    tension: 0.4,
                    borderWidth: 3,
                    pointRadius: 6,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#10b981',
                    pointBorderWidth: 3,
                    pointHoverRadius: 8,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 20,
                            font: { size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(30, 41, 59, 0.9)',
                        padding: 12,
                        cornerRadius: 8,
                        titleFont: { size: 13, weight: '700' },
                        bodyFont: { size: 12, weight: '600' },
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': LSL ' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.03)',
                            drawBorder: false
                        },
                        ticks: {
                            callback: function(value) {
                                return 'LSL ' + value.toLocaleString();
                            },
                            font: { size: 11, weight: '600' }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, weight: '600' } }
                    }
                }
            }
        });
        <?php endif; ?>

        <?php if(!empty($departmentStats) && in_array($userRole, ['admin', 'manager', 'hr'])): ?>
        const ctx2 = document.getElementById('deptChart').getContext('2d');
        new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($departmentStats, 'department')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($departmentStats, 'count')); ?>,
                    backgroundColor: [
                        '#6366f1', '#ec4899', '#10b981', '#f59e0b', 
                        '#06b6d4', '#8b5cf6', '#ef4444', '#14b8a6'
                    ],
                    borderWidth: 0,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 15,
                            font: { size: 11, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(30, 41, 59, 0.9)',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((context.raw / total) * 100).toFixed(1);
                                return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Animate stats on load
        document.addEventListener('DOMContentLoaded', function() {
            const statValues = document.querySelectorAll('.stat-value');
            statValues.forEach(stat => {
                const finalValue = stat.textContent;
                if (finalValue && finalValue !== '0') {
                    stat.textContent = '0';
                    setTimeout(() => {
                        stat.style.transition = 'all 1s ease';
                        stat.textContent = finalValue;
                    }, 100);
                }
            });
        });

        // Period selector
        const periodSelector = document.getElementById('payrollPeriod');
        if (periodSelector) {
            periodSelector.addEventListener('change', function() {
                this.style.opacity = '0.5';
                setTimeout(() => {
                    this.style.opacity = '1';
                    console.log('Period changed to:', this.value);
                }, 300);
            });
        }
    </script>
</body>
</html>