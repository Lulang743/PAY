<?php
session_start();
require_once 'config.php';
require_once 'auth_check.php';

// Only allow admin, manager, and hr to access
checkRole(['admin', 'manager', 'hr']);

// Handle payroll generation
if (isset($_GET['generate_payroll'])) {
    $start_date = $_GET['start_date'];
    $end_date = $_GET['end_date'];
    
    // Check if payroll already exists for this period
    $check_sql = "SELECT id FROM payroll WHERE pay_period_start = ? AND pay_period_end = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ss", $start_date, $end_date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "Payroll for this period has already been generated!";
    } else {
        // Calculate payroll for each employee
        $emp_sql = "SELECT * FROM employees WHERE status = 'active'";
        $emp_result = $conn->query($emp_sql);
        
        while ($employee = $emp_result->fetch_assoc()) {
            // Get attendance for the period
            $att_sql = "SELECT SUM(hours_worked) as total_hours, SUM(overtime_hours) as total_overtime 
                       FROM attendance 
                       WHERE employee_id = ? AND work_date BETWEEN ? AND ?";
            $att_stmt = $conn->prepare($att_sql);
            $att_stmt->bind_param("iss", $employee['id'], $start_date, $end_date);
            $att_stmt->execute();
            $att_result = $att_stmt->get_result();
            $attendance = $att_result->fetch_assoc();
            
            $regular_hours = $attendance['total_hours'] ?? 0;
            $overtime_hours = $attendance['total_overtime'] ?? 0;
            
            // Calculate pay
            $regular_pay = $regular_hours * $employee['hourly_rate'];
            $overtime_pay = $overtime_hours * ($employee['hourly_rate'] * 1.5); // 1.5x for overtime
            $total_pay = $regular_pay + $overtime_pay;
            
            // Insert into payroll table
            $insert_sql = "INSERT INTO payroll (employee_id, pay_period_start, pay_period_end, 
                          regular_hours, overtime_hours, regular_pay, overtime_pay, total_pay, status) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("issddddd", $employee['id'], $start_date, $end_date, 
                                   $regular_hours, $overtime_hours, $regular_pay, $overtime_pay, $total_pay);
            $insert_stmt->execute();
        }
        
        $success = "Payroll generated successfully for period " . date('M d, Y', strtotime($start_date)) . " to " . date('M d, Y', strtotime($end_date));
    }
}

// Handle single payment processing
if (isset($_POST['process_payment'])) {
    $payroll_id = $_POST['payroll_id'];
    $payment_date = date('Y-m-d');
    
    $sql = "UPDATE payroll SET status = 'paid', payment_date = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $payment_date, $payroll_id);
    
    if ($stmt->execute()) {
        $success = "Payment processed successfully!";
    } else {
        $error = "Error processing payment: " . $conn->error;
    }
}

// Handle bulk payment processing
if (isset($_POST['process_bulk_payments'])) {
    $payroll_ids = $_POST['payroll_ids'] ?? [];
    $payment_date = date('Y-m-d');
    
    if (!empty($payroll_ids)) {
        $ids = implode(',', array_map('intval', $payroll_ids));
        $sql = "UPDATE payroll SET status = 'paid', payment_date = ? WHERE id IN ($ids) AND status = 'pending'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $payment_date);
        
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $success = $affected . " payment(s) processed successfully!";
        } else {
            $error = "Error processing payments: " . $conn->error;
        }
    } else {
        $error = "No payroll records selected!";
    }
}

// Handle delete payroll record
if (isset($_GET['delete_payroll'])) {
    $payroll_id = intval($_GET['delete_payroll']);
    
    $sql = "DELETE FROM payroll WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $payroll_id);
    
    if ($stmt->execute()) {
        $success = "Payroll record deleted successfully!";
    } else {
        $error = "Error deleting payroll record: " . $conn->error;
    }
}

// Handle delete all payroll for a period
if (isset($_POST['delete_period'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    $sql = "DELETE FROM payroll WHERE pay_period_start = ? AND pay_period_end = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    
    if ($stmt->execute()) {
        $success = "All payroll records for the period deleted successfully!";
    } else {
        $error = "Error deleting payroll records: " . $conn->error;
    }
}

// Fetch payroll records with employee details
$payroll_sql = "SELECT p.*, e.employee_id, e.first_name, e.last_name, e.position, e.hourly_rate
                FROM payroll p 
                JOIN employees e ON p.employee_id = e.id 
                ORDER BY p.pay_period_end DESC, p.created_at DESC";
$payroll_result = $conn->query($payroll_sql);

// Get distinct pay periods for summary
$periods_sql = "SELECT DISTINCT pay_period_start, pay_period_end, 
                COUNT(*) as employee_count,
                SUM(total_pay) as period_total,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count
                FROM payroll 
                GROUP BY pay_period_start, pay_period_end
                ORDER BY pay_period_end DESC";
$periods_result = $conn->query($periods_sql);

// Get chart data - Monthly payroll summary
$monthly_sql = "SELECT 
                DATE_FORMAT(pay_period_end, '%Y-%m') as month,
                SUM(total_pay) as total_payroll,
                COUNT(DISTINCT employee_id) as employee_count,
                AVG(total_pay) as avg_pay
                FROM payroll 
                WHERE pay_period_end >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(pay_period_end, '%Y-%m')
                ORDER BY month ASC";
$monthly_result = $conn->query($monthly_sql);

// Payment status distribution
$status_sql = "SELECT 
                status,
                COUNT(*) as count,
                SUM(total_pay) as amount
                FROM payroll 
                GROUP BY status";
$status_result = $conn->query($status_sql);

// Top earners
$top_earners_sql = "SELECT 
                    e.first_name, e.last_name, e.position,
                    SUM(p.total_pay) as total_earned
                    FROM payroll p
                    JOIN employees e ON p.employee_id = e.id
                    GROUP BY e.id, e.first_name, e.last_name, e.position
                    ORDER BY total_earned DESC
                    LIMIT 5";
$top_earners_result = $conn->query($top_earners_sql);

// Get summary statistics
$summary_sql = "SELECT 
                COUNT(DISTINCT employee_id) as total_employees,
                SUM(total_pay) as total_payroll,
                SUM(CASE WHEN status = 'pending' THEN total_pay ELSE 0 END) as pending_payroll,
                SUM(CASE WHEN status = 'paid' THEN total_pay ELSE 0 END) as paid_payroll,
                COUNT(*) as total_transactions,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count
                FROM payroll";
$summary_result = $conn->query($summary_sql);
$summary = $summary_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management - Payroll System</title>
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
            background: #3498db;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-warning {
            background: #f39c12;
        }
        
        .btn-warning:hover {
            background: #e67e22;
        }
        
        .btn-danger {
            background: #e74c3c;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-print {
            background: #9b59b6;
        }
        
        .btn-print:hover {
            background: #8e44ad;
        }
        
        .btn-generate {
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
        
        .btn-generate:hover {
            background: #229954;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .stat-title {
            color: #7f8c8d;
            font-size: 13px;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .stat-sub {
            color: #95a5a6;
            font-size: 12px;
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .chart-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
        
        /* Form Styles */
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
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        /* Table Styles */
        .table-responsive {
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
            vertical-align: middle;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-pending {
            background: #fef5e7;
            color: #f39c12;
        }
        
        .badge-paid {
            background: #d4edda;
            color: #27ae60;
        }
        
        .total-row {
            font-weight: bold;
            background: #e8f4f8;
        }
        
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
        
        .generate-form {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .checkbox-column {
            width: 40px;
            text-align: center;
        }
        
        .action-bar {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            flex-wrap: wrap;
        }
        
        .selected-count {
            margin-left: 10px;
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .summary-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .summary-box .total {
            font-size: 32px;
            font-weight: 700;
        }
        
        .period-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .period-tab {
            background: white;
            border: 1px solid #e0e0e0;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .period-tab:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .period-tab.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        @media (max-width: 768px) {
            .nav {
                flex-direction: column;
                gap: 10px;
            }
            
            .nav-links {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Alaki Payroll</h1>
            <p>Manage employee payments, generate payslips, and process payroll</p>
        </div>
        
        <div class="nav">
            <div class="nav-links">
                <a href="dashboard.php"><i class="fas fa-dashboard"></i> Dashboard</a>
                <a href="employees.php"><i class="fas fa-users"></i> Employees</a>
                <a href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>
                <a href="leave.php"><i class="fas fa-calendar-alt"></i> Leaves</a>
                <a href="payroll.php" class="active"><i class="fas fa-money-bill"></i> Payroll</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>
            <div>
                <a href="payslip_bulk.php" class="btn btn-print">
                    <i class="fas fa-print"></i> Bulk Print
                </a>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Summary Box -->
        <div class="summary-box">
            <div>
                <h3 style="font-weight: 300; margin-bottom: 5px;">Total Payroll</h3>
                <span class="total">LSL<?php echo number_format($summary['total_payroll'] ?? 0, 2); ?></span>
            </div>
            <div style="text-align: right;">
                <div style="margin-bottom: 5px;">Pending: <strong>LSL<?php echo number_format($summary['pending_payroll'] ?? 0, 2); ?></strong> (<?php echo $summary['pending_count'] ?? 0; ?> employees)</div>
                <div>Paid: <strong>LSL<?php echo number_format($summary['paid_payroll'] ?? 0, 2); ?></strong> (<?php echo $summary['paid_count'] ?? 0; ?> employees)</div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">Total Employees Paid</div>
                <div class="stat-value"><?php echo $summary['total_employees'] ?? 0; ?></div>
                <div class="stat-sub">Across all periods</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-title">Total Transactions</div>
                <div class="stat-value"><?php echo $summary['total_transactions'] ?? 0; ?></div>
                <div class="stat-sub">Payroll records</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-title">Pending Payments</div>
                <div class="stat-value"><?php echo $summary['pending_count'] ?? 0; ?></div>
                <div class="stat-sub">Awaiting processing</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-title">Completed Payments</div>
                <div class="stat-value"><?php echo $summary['paid_count'] ?? 0; ?></div>
                <div class="stat-sub">Successfully processed</div>
            </div>
        </div>
        
        <!-- Generate Payroll Form -->
        <div class="generate-form">
            <h2 style="margin-bottom: 20px; color: #2c3e50;">
                <i class="fas fa-calculator" style="color: #667eea;"></i> Generate New Payroll
            </h2>
            <form method="GET" action="">
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
                <div class="form-row">
                    <div class="form-group">
                        <button type="submit" name="generate_payroll" class="btn btn-success" style="padding: 12px 25px;">
                            <i class="fas fa-file-invoice"></i> Generate Payroll
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Monthly Payroll Trend -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Monthly Payroll Trend</h3>
                </div>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
            
            <!-- Payment Status Distribution -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Payment Status Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            
            <!-- Top Earners -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Top 5 Earners</h3>
                </div>
                <div class="chart-container">
                    <canvas id="earnersChart"></canvas>
                </div>
            </div>
            
            <!-- Period Summary -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Recent Pay Periods</h3>
                </div>
                <div style="max-height: 250px; overflow-y: auto;">
                    <table style="width: 100%; font-size: 13px;">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Employees</th>
                                <th>Total</th>
                                <th>Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($periods_result && $periods_result->num_rows > 0): ?>
                                <?php while($period = $periods_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('M d', strtotime($period['pay_period_start'])) . '-' . date('M d, Y', strtotime($period['pay_period_end'])); ?></td>
                                    <td><?php echo $period['employee_count']; ?></td>
                                    <td>LSL<?php echo number_format($period['period_total'], 2); ?></td>
                                    <td><?php echo $period['paid_count']; ?>/<?php echo $period['employee_count']; ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Bulk Actions Bar -->
        <div class="action-bar">
            <input type="checkbox" id="selectAll" onclick="toggleSelectAll()">
            <label for="selectAll">Select All</label>
            <span class="selected-count" id="selectedCount">0 selected</span>
            <button class="btn btn-success" onclick="processBulkPayments()">
                <i class="fas fa-check-circle"></i> Process Selected
            </button>
            <button class="btn btn-print" onclick="printSelectedPayslips()">
                <i class="fas fa-print"></i> Print Selected Payslips
            </button>
            <button class="btn btn-danger" onclick="deleteSelected()">
                <i class="fas fa-trash"></i> Delete Selected
            </button>
        </div>
        
        <!-- Payroll Records -->
        <div class="card">
            <h2>
                <i class="fas fa-list"></i> Payroll Records
            </h2>
            
            <div class="table-responsive">
                <form id="bulkForm" method="POST" action="">
                    <input type="hidden" name="process_bulk_payments" value="1">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="checkbox-column">
                                    <input type="checkbox" id="selectAllHeader" onclick="toggleSelectAll()">
                                </th>
                                <th>Pay Period</th>
                                <th>Employee ID</th>
                                <th>Employee Name</th>
                                <th>Position</th>
                                <th>Regular Hours</th>
                                <th>OT Hours</th>
                                <th>Regular Pay</th>
                                <th>OT Pay</th>
                                <th>Total Pay</th>
                                <th>Status</th>
                                <th>Payment Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $grand_total = 0;
                            $pending_total = 0;
                            $paid_total = 0;
                            
                            if ($payroll_result && $payroll_result->num_rows > 0):
                                while($payroll = $payroll_result->fetch_assoc()): 
                                    $grand_total += $payroll['total_pay'];
                                    if ($payroll['status'] == 'pending') {
                                        $pending_total += $payroll['total_pay'];
                                    } else {
                                        $paid_total += $payroll['total_pay'];
                                    }
                            ?>
                            <tr>
                                <td class="checkbox-column">
                                    <input type="checkbox" name="payroll_ids[]" value="<?php echo $payroll['id']; ?>" 
                                           class="payroll-checkbox" <?php echo $payroll['status'] == 'paid' ? 'disabled' : ''; ?>
                                           onchange="updateSelectedCount()">
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($payroll['pay_period_start'])); ?><br>
                                    <small>to <?php echo date('M d, Y', strtotime($payroll['pay_period_end'])); ?></small>
                                </td>
                                <td><strong><?php echo $payroll['employee_id']; ?></strong></td>
                                <td><?php echo $payroll['first_name'] . ' ' . $payroll['last_name']; ?></td>
                                <td><?php echo $payroll['position']; ?></td>
                                <td><?php echo number_format($payroll['regular_hours'], 1); ?></td>
                                <td><?php echo number_format($payroll['overtime_hours'], 1); ?></td>
                                <td>LSL<?php echo number_format($payroll['regular_pay'], 2); ?></td>
                                <td>LSL<?php echo number_format($payroll['overtime_pay'], 2); ?></td>
                                <td><strong>LSL<?php echo number_format($payroll['total_pay'], 2); ?></strong></td>
                                <td>
                                    <span class="badge badge-<?php echo $payroll['status']; ?>">
                                        <?php echo ucfirst($payroll['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $payroll['payment_date'] ? date('M d, Y', strtotime($payroll['payment_date'])) : '-'; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                        <?php if($payroll['status'] == 'pending'): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="payroll_id" value="<?php echo $payroll['id']; ?>">
                                            <button type="submit" name="process_payment" class="btn btn-success btn-sm" title="Process Payment">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <a href="payslip.php?id=<?php echo $payroll['id']; ?>" target="_blank" class="btn btn-print btn-sm" title="View/Print Payslip">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        
                                        <a href="?delete_payroll=<?php echo $payroll['id']; ?>" 
                                           class="btn btn-danger btn-sm" 
                                           title="Delete Record"
                                           onclick="return confirm('Are you sure you want to delete this payroll record?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="13" style="text-align: center; padding: 40px; color: #95a5a6;">
                                    <i class="fas fa-money-bill-wave fa-3x mb-3"></i>
                                    <p>No payroll records found. Generate payroll to get started.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if ($grand_total > 0): ?>
                        <tfoot>
                            <tr class="total-row">
                                <td colspan="9" style="text-align: right;"><strong>Grand Total:</strong></td>
                                <td><strong>LSL<?php echo number_format($grand_total, 2); ?></strong></td>
                                <td colspan="3"></td>
                            </tr>
                            <tr class="total-row" style="background: #fff3cd;">
                                <td colspan="9" style="text-align: right;"><strong>Pending Total:</strong></td>
                                <td><strong>LSL<?php echo number_format($pending_total, 2); ?></strong></td>
                                <td colspan="3"></td>
                            </tr>
                            <tr class="total-row" style="background: #d4edda;">
                                <td colspan="9" style="text-align: right;"><strong>Paid Total:</strong></td>
                                <td><strong>LSL<?php echo number_format($paid_total, 2); ?></strong></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Monthly Payroll Trend Chart
        const monthlyData = <?php
            $months = [];
            $amounts = [];
            if ($monthly_result && $monthly_result->num_rows > 0) {
                while($row = $monthly_result->fetch_assoc()) {
                    $months[] = "'" . date('M Y', strtotime($row['month'] . '-01')) . "'";
                    $amounts[] = $row['total_payroll'];
                }
            }
            echo '{
                "months": [' . implode(',', $months) . '],
                "amounts": [' . implode(',', $amounts) . ']
            }';
        ?>;

        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: monthlyData.months,
                datasets: [{
                    label: 'Total Payroll',
                    data: monthlyData.amounts,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#3498db',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Total: $' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Status Distribution Chart
        const statusData = <?php
            $status_labels = [];
            $status_amounts = [];
            $status_counts = [];
            if ($status_result && $status_result->num_rows > 0) {
                while($row = $status_result->fetch_assoc()) {
                    $status_labels[] = "'" . ucfirst($row['status']) . "'";
                    $status_amounts[] = $row['amount'];
                    $status_counts[] = $row['count'];
                }
            }
            echo '{
                "labels": [' . implode(',', $status_labels) . '],
                "amounts": [' . implode(',', $status_amounts) . '],
                "counts": [' . implode(',', $status_counts) . ']
            }';
        ?>;

        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: statusData.labels,
                datasets: [{
                    data: statusData.amounts,
                    backgroundColor: ['#f39c12', '#27ae60'],
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
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw;
                                const count = statusData.counts[context.dataIndex];
                                return `${label}: $${value.toLocaleString()} (${count} records)`;
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });

        // Top Earners Chart
        const earnersData = <?php
            $earner_names = [];
            $earner_amounts = [];
            if ($top_earners_result && $top_earners_result->num_rows > 0) {
                while($row = $top_earners_result->fetch_assoc()) {
                    $earner_names[] = "'" . $row['first_name'] . ' ' . $row['last_name'] . "'";
                    $earner_amounts[] = $row['total_earned'];
                }
            }
            echo '{
                "names": [' . implode(',', $earner_names) . '],
                "amounts": [' . implode(',', $earner_amounts) . ']
            }';
        ?>;

        const earnersCtx = document.getElementById('earnersChart').getContext('2d');
        new Chart(earnersCtx, {
            type: 'bar',
            data: {
                labels: earnersData.names,
                datasets: [{
                    label: 'Total Earnings',
                    data: earnersData.amounts,
                    backgroundColor: '#e74c3c',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Total: $' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Bulk selection functions
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.payroll-checkbox:not(:disabled)');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            document.getElementById('selectAllHeader').checked = selectAll.checked;
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.payroll-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('selectedCount').textContent = count + ' selected';
            
            const selectAll = document.getElementById('selectAll');
            const totalCheckboxes = document.querySelectorAll('.payroll-checkbox:not(:disabled)').length;
            
            if (count === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (count === totalCheckboxes) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.indeterminate = true;
            }
        }
        
        function processBulkPayments() {
            const checkboxes = document.querySelectorAll('.payroll-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select at least one payroll record to process.');
                return;
            }
            
            if (confirm('Process payments for ' + checkboxes.length + ' selected records?')) {
                document.getElementById('bulkForm').submit();
            }
        }
        
        function printSelectedPayslips() {
            const checkboxes = document.querySelectorAll('.payroll-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select at least one payroll record to print.');
                return;
            }
            
            const ids = Array.from(checkboxes).map(cb => cb.value);
            window.open('payslip.php?ids=' + ids.join(','), '_blank');
        }
        
        function deleteSelected() {
            const checkboxes = document.querySelectorAll('.payroll-checkbox:checked');
            
            if (checkboxes.length === 0) {
                alert('Please select at least one payroll record to delete.');
                return;
            }
            
            if (confirm('Are you sure you want to delete ' + checkboxes.length + ' selected records? This action cannot be undone.')) {
                const ids = Array.from(checkboxes).map(cb => cb.value);
                window.location.href = '?delete_multiple=' + ids.join(',');
            }
        }
        
        // Initialize selected count on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
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
       
    </script>
</body>
</html>