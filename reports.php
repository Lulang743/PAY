<?php
// reports.php
session_start();
require_once 'config.php';
require_once 'auth_check.php';

// Only allow admin, manager, and hr to access
checkRole(['admin', 'manager', 'hr']);

// Initialize variables
$error = null;
$success = null;

// Test database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['generate_forecast'])) {
        $months = intval($_POST['forecast_months'] ?? 3);
        
        // Calculate forecast with error handling
        $forecast_sql = "SELECT 
                            DATE_FORMAT(pay_period_end, '%Y-%m') as month,
                            SUM(total_pay) as total_payroll,
                            COUNT(DISTINCT employee_id) as employee_count,
                            AVG(total_pay) as avg_pay
                         FROM payroll
                         WHERE pay_period_end >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                         GROUP BY DATE_FORMAT(pay_period_end, '%Y-%m')
                         ORDER BY month ASC";
        
        $forecast_result = $conn->query($forecast_sql);
        
        if (!$forecast_result) {
            $error = "Error fetching forecast data: " . $conn->error;
        } else {
            $historical_data = [];
            $growth_rates = [];
            $previous = null;
            
            while ($row = $forecast_result->fetch_assoc()) {
                $historical_data[] = $row;
                if ($previous !== null) {
                    $growth_rate = $previous > 0 ? (($row['total_payroll'] - $previous) / $previous) * 100 : 0;
                    $growth_rates[] = $growth_rate;
                }
                $previous = $row['total_payroll'];
            }
            
            // Calculate average growth rate
            $avg_growth_rate = !empty($growth_rates) ? array_sum($growth_rates) / count($growth_rates) : 5;
            
            // Generate forecast
            $forecast = [];
            $last_amount = $previous ?? 0;
            $last_date = !empty($historical_data) ? end($historical_data)['month'] : date('Y-m');
            
            for ($i = 1; $i <= $months; $i++) {
                $next_month = date('Y-m', strtotime($last_date . " +$i month"));
                $predicted_amount = $last_amount * (1 + ($avg_growth_rate / 100));
                $forecast[] = [
                    'month' => $next_month,
                    'predicted' => $predicted_amount,
                    'lower_bound' => $predicted_amount * 0.95,
                    'upper_bound' => $predicted_amount * 1.05
                ];
                $last_amount = $predicted_amount;
            }
            
            $forecast_data = [
                'historical' => $historical_data,
                'forecast' => $forecast,
                'growth_rate' => $avg_growth_rate
            ];
            
            // Store report
            if (isset($_SESSION['user_id'])) {
                $report_json = json_encode($forecast_data);
                $store_sql = "INSERT INTO reports (report_name, report_type, report_data, parameters, created_by) 
                              VALUES (?, 'financial', ?, ?, ?)";
                $store_stmt = $conn->prepare($store_sql);
                if ($store_stmt) {
                    $report_name = "Financial Forecast - " . date('Y-m-d H:i:s');
                    $params = json_encode(['months' => $months]);
                    $store_stmt->bind_param("sssi", $report_name, $report_json, $params, $_SESSION['user_id']);
                    $store_stmt->execute();
                    $store_stmt->close();
                }
            }
            
            $success = "Forecast generated successfully!";
        }
    }
    
    if (isset($_POST['analyze_department'])) {
        $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
        $start_date = $_POST['start_date'] ?? date('Y-m-01');
        $end_date = $_POST['end_date'] ?? date('Y-m-d');
        
        $dept_condition = $department_id ? "AND e.department_id = " . intval($department_id) : "";
        
        // Department cost analysis
        $dept_sql = "SELECT 
                        COALESCE(d.id, 0) as dept_id,
                        COALESCE(d.name, 'No Department') as department,
                        COUNT(DISTINCT e.id) as employee_count,
                        COALESCE(SUM(p.total_pay), 0) as total_payroll,
                        COALESCE(AVG(p.total_pay), 0) as avg_salary,
                        COALESCE(SUM(p.regular_hours), 0) as total_hours,
                        COALESCE(SUM(p.overtime_hours), 0) as total_overtime,
                        COALESCE(SUM(p.overtime_pay), 0) as overtime_cost,
                        COALESCE(SUM(p.tax_amount), 0) as total_tax,
                        COALESCE(SUM(p.pension_amount), 0) as total_pension
                     FROM departments d
                     LEFT JOIN employees e ON d.id = e.department_id
                     LEFT JOIN payroll p ON e.id = p.employee_id 
                        AND p.pay_period_start >= ? 
                        AND p.pay_period_end <= ?
                     WHERE 1=1 $dept_condition
                     GROUP BY d.id, d.name
                     HAVING employee_count > 0
                     ORDER BY total_payroll DESC";
        
        $dept_stmt = $conn->prepare($dept_sql);
        if ($dept_stmt) {
            $dept_stmt->bind_param("ss", $start_date, $end_date);
            $dept_stmt->execute();
            $dept_result = $dept_stmt->get_result();
            
            $department_analysis = [];
            while ($row = $dept_result->fetch_assoc()) {
                $department_analysis[] = $row;
            }
            $dept_stmt->close();
            
            // Store report
            if (isset($_SESSION['user_id']) && !empty($department_analysis)) {
                $report_json = json_encode($department_analysis);
                $store_sql = "INSERT INTO reports (report_name, report_type, report_data, parameters, created_by) 
                              VALUES (?, 'department', ?, ?, ?)";
                $store_stmt = $conn->prepare($store_sql);
                if ($store_stmt) {
                    $report_name = "Department Analysis - " . date('Y-m-d H:i:s');
                    $params = json_encode(['department_id' => $department_id, 'start_date' => $start_date, 'end_date' => $end_date]);
                    $store_stmt->bind_param("sssi", $report_name, $report_json, $params, $_SESSION['user_id']);
                    $store_stmt->execute();
                    $store_stmt->close();
                }
            }
            
            $success = "Department analysis completed successfully!";
        } else {
            $error = "Error preparing department query: " . $conn->error;
        }
    }
    
    if (isset($_POST['generate_custom_report'])) {
        $fields = $_POST['fields'] ?? [];
        $filters = $_POST['filters'] ?? [];
        $format = $_POST['export_format'] ?? 'csv';
        
        if (empty($fields)) {
            $error = "Please select at least one field";
        } else {
            // Build SELECT clause
            $select_fields = [];
            $field_mappings = [
                'employee_id' => 'e.employee_id',
                'employee_name' => "CONCAT(e.first_name, ' ', e.last_name) as employee_name",
                'department' => 'COALESCE(d.name, "No Department") as department',
                'position' => 'e.position',
                'pay_period' => "CONCAT(p.pay_period_start, ' to ', p.pay_period_end) as pay_period",
                'regular_hours' => 'p.regular_hours',
                'overtime_hours' => 'p.overtime_hours',
                'regular_pay' => 'p.regular_pay',
                'overtime_pay' => 'p.overtime_pay',
                'housing_allowance' => 'p.housing_allowance',
                'transport_allowance' => 'p.transport_allowance',
                'tax_amount' => 'p.tax_amount',
                'pension_amount' => 'p.pension_amount',
                'medical_aid' => 'p.medical_aid_amount',
                'custom_deductions' => 'p.custom_deductions',
                'total_pay' => 'p.total_pay',
                'net_pay' => 'p.net_pay',
                'status' => 'p.status',
                'payment_date' => 'p.payment_date'
            ];
            
            foreach ($fields as $field) {
                if (isset($field_mappings[$field])) {
                    $select_fields[] = $field_mappings[$field];
                }
            }
            
            if (empty($select_fields)) {
                $select_fields = ['p.*', 'e.employee_id', "CONCAT(e.first_name, ' ', e.last_name) as employee_name"];
            }
            
            // Build WHERE clause
            $where_conditions = ["1=1"];
            $params = [];
            $types = "";
            
            if (!empty($filters['department'])) {
                $where_conditions[] = "d.id = ?";
                $params[] = intval($filters['department']);
                $types .= "i";
            }
            
            if (!empty($filters['start_date'])) {
                $where_conditions[] = "p.pay_period_start >= ?";
                $params[] = $filters['start_date'];
                $types .= "s";
            }
            
            if (!empty($filters['end_date'])) {
                $where_conditions[] = "p.pay_period_end <= ?";
                $params[] = $filters['end_date'];
                $types .= "s";
            }
            
            if (!empty($filters['status'])) {
                $where_conditions[] = "p.status = ?";
                $params[] = $filters['status'];
                $types .= "s";
            }
            
            $where_clause = implode(" AND ", $where_conditions);
            
            // Execute query
            $select_clause = implode(", ", $select_fields);
            $custom_sql = "SELECT $select_clause 
                           FROM payroll p
                           JOIN employees e ON p.employee_id = e.id
                           LEFT JOIN departments d ON e.department_id = d.id
                           WHERE $where_clause
                           ORDER BY p.pay_period_end DESC, e.last_name
                           LIMIT 5000"; // Limit to prevent memory issues
            
            $custom_stmt = $conn->prepare($custom_sql);
            if ($custom_stmt) {
                if (!empty($params)) {
                    $custom_stmt->bind_param($types, ...$params);
                }
                $custom_stmt->execute();
                $custom_result = $custom_stmt->get_result();
                
                $report_data = [];
                while ($row = $custom_result->fetch_assoc()) {
                    $report_data[] = $row;
                }
                $custom_stmt->close();
                
                // Handle export
                if ($format == 'csv') {
                    exportToCSV($report_data, 'custom_report_' . date('Y-m-d') . '.csv');
                } elseif ($format == 'excel') {
                    exportToExcel($report_data, 'custom_report_' . date('Y-m-d') . '.xls');
                } elseif ($format == 'pdf') {
                    $success = "PDF generation feature coming soon!";
                }
                
                // Store report
                if (isset($_SESSION['user_id']) && !empty($report_data)) {
                    $report_json = json_encode($report_data);
                    $store_sql = "INSERT INTO reports (report_name, report_type, report_data, parameters, created_by) 
                                  VALUES (?, 'custom', ?, ?, ?)";
                    $store_stmt = $conn->prepare($store_sql);
                    if ($store_stmt) {
                        $report_name = "Custom Report - " . date('Y-m-d H:i:s');
                        $params_json = json_encode(['fields' => $fields, 'filters' => $filters, 'row_count' => count($report_data)]);
                        $store_stmt->bind_param("sssi", $report_name, $report_json, $params_json, $_SESSION['user_id']);
                        $store_stmt->execute();
                        $store_stmt->close();
                    }
                }
                
                $success = count($report_data) . " records exported successfully!";
            } else {
                $error = "Error preparing custom report: " . $conn->error;
            }
        }
    }
    
    if (isset($_POST['generate_ai_insights'])) {
        // Detect anomalies
        $anomaly_result = detectAnomalies($conn);
        
        // Predict absenteeism
        $absenteeism_prediction = predictAbsenteeism($conn);
        
        // Identify high overtime departments
        $high_overtime = identifyHighOvertime($conn);
        
        // Get anomalies
        $anomalies_sql = "SELECT pa.*, p.employee_id, e.first_name, e.last_name, 
                          p.pay_period_start, p.pay_period_end, p.total_pay
                          FROM payroll_anomalies pa
                          JOIN payroll p ON pa.payroll_id = p.id
                          JOIN employees e ON p.employee_id = e.id
                          WHERE pa.is_resolved = 0
                          ORDER BY FIELD(pa.severity, 'high', 'medium', 'low'), pa.detected_at DESC
                          LIMIT 20";
        $anomalies_result = $conn->query($anomalies_sql);
        
        $anomalies = [];
        if ($anomalies_result) {
            while ($row = $anomalies_result->fetch_assoc()) {
                $anomalies[] = $row;
            }
        }
        
        $ai_insights = [
            'absenteeism_prediction' => $absenteeism_prediction,
            'high_overtime_departments' => $high_overtime,
            'anomalies' => $anomalies,
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        // Store report
        if (isset($_SESSION['user_id'])) {
            $report_json = json_encode($ai_insights);
            $store_sql = "INSERT INTO reports (report_name, report_type, report_data, parameters, created_by) 
                          VALUES (?, 'ai_insights', ?, ?, ?)";
            $store_stmt = $conn->prepare($store_sql);
            if ($store_stmt) {
                $report_name = "AI Insights - " . date('Y-m-d H:i:s');
                $params_json = json_encode(['type' => 'ai_insights']);
                $store_stmt->bind_param("sssi", $report_name, $report_json, $params_json, $_SESSION['user_id']);
                $store_stmt->execute();
                $store_stmt->close();
            }
        }
        
        $success = "AI Insights generated successfully!";
    }
}

// Export functions
function exportToCSV($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Add headers
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
    }
    
    // Add data
    foreach ($data as $row) {
        // Format numbers
        foreach ($row as $key => $value) {
            if (is_numeric($value) && strpos($key, 'pay') !== false || strpos($key, 'amount') !== false || strpos($key, 'salary') !== false) {
                $row[$key] = number_format($value, 2);
            }
        }
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

function exportToExcel($data, $filename) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo "<html>";
    echo "<head>";
    echo "<meta charset='UTF-8'>";
    echo "<style>";
    echo "td { mso-number-format:\\@; }"; // Prevent Excel from auto-formatting
    echo "</style>";
    echo "</head>";
    echo "<body>";
    echo "<table border='1'>";
    
    // Add headers
    if (!empty($data)) {
        echo "<tr style='background: #f0f0f0; font-weight: bold;'>";
        foreach (array_keys($data[0]) as $header) {
            echo "<th>" . htmlspecialchars(ucwords(str_replace('_', ' ', $header))) . "</th>";
        }
        echo "</tr>";
    }
    
    // Add data
    foreach ($data as $row) {
        echo "<tr>";
        foreach ($row as $key => $cell) {
            // Format currency
            if (is_numeric($cell) && (strpos($key, 'pay') !== false || strpos($key, 'amount') !== false || strpos($key, 'salary') !== false)) {
                echo "<td style='text-align: right;'>$" . number_format($cell, 2) . "</td>";
            } else {
                echo "<td>" . htmlspecialchars($cell ?? '') . "</td>";
            }
        }
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</body>";
    echo "</html>";
    exit();
}

// AI Functions
function detectAnomalies($conn) {
    // Calculate statistics
    $stats_sql = "SELECT 
                    AVG(total_pay) as avg_pay,
                    STDDEV(total_pay) as stddev_pay,
                    AVG(overtime_hours) as avg_overtime,
                    STDDEV(overtime_hours) as stddev_overtime
                  FROM payroll
                  WHERE pay_period_end >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
    
    $stats_result = $conn->query($stats_sql);
    if (!$stats_result) {
        return false;
    }
    
    $stats = $stats_result->fetch_assoc();
    
    $avg_pay = $stats['avg_pay'] ?? 0;
    $stddev_pay = $stats['stddev_pay'] ?? ($avg_pay * 0.2);
    $avg_overtime = $stats['avg_overtime'] ?? 0;
    $stddev_overtime = $stats['stddev_overtime'] ?? 5;
    
    // Detect pay anomalies (more than 2 standard deviations from mean)
    $anomaly_sql = "SELECT p.id, p.employee_id, p.total_pay, p.overtime_hours,
                           e.first_name, e.last_name
                    FROM payroll p
                    JOIN employees e ON p.employee_id = e.id
                    WHERE p.pay_period_end >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                      AND (p.total_pay > ? + 2 * ? OR p.total_pay < ? - 2 * ?
                           OR p.overtime_hours > ? + 2 * ?)";
    
    $upper_pay = $avg_pay + 2 * $stddev_pay;
    $lower_pay = max(0, $avg_pay - 2 * $stddev_pay);
    $upper_overtime = $avg_overtime + 2 * $stddev_overtime;
    
    $anomaly_stmt = $conn->prepare($anomaly_sql);
    if ($anomaly_stmt) {
        $anomaly_stmt->bind_param("dddddd", $avg_pay, $stddev_pay, $avg_pay, $stddev_pay, $avg_overtime, $stddev_overtime);
        $anomaly_stmt->execute();
        $anomaly_result = $anomaly_stmt->get_result();
        
        while ($row = $anomaly_result->fetch_assoc()) {
            $type = '';
            $severity = 'medium';
            $description = '';
            
            if ($row['total_pay'] > $upper_pay) {
                $type = 'high_pay';
                $description = "Pay of $" . number_format($row['total_pay'], 2) . " is unusually high";
                $severity = 'high';
            } elseif ($row['total_pay'] < $lower_pay && $row['total_pay'] > 0) {
                $type = 'low_pay';
                $description = "Pay of $" . number_format($row['total_pay'], 2) . " is unusually low";
            } elseif ($row['overtime_hours'] > $upper_overtime) {
                $type = 'high_overtime';
                $description = "Overtime of " . $row['overtime_hours'] . " hours is unusually high";
                $severity = 'high';
            }
            
            if ($type) {
                // Check if already detected
                $check_sql = "SELECT id FROM payroll_anomalies WHERE payroll_id = ? AND anomaly_type = ?";
                $check_stmt = $conn->prepare($check_sql);
                if ($check_stmt) {
                    $check_stmt->bind_param("is", $row['id'], $type);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows == 0) {
                        $insert_sql = "INSERT INTO payroll_anomalies (payroll_id, anomaly_type, description, severity) 
                                      VALUES (?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        if ($insert_stmt) {
                            $insert_stmt->bind_param("isss", $row['id'], $type, $description, $severity);
                            $insert_stmt->execute();
                            $insert_stmt->close();
                        }
                    }
                    $check_stmt->close();
                }
            }
        }
        $anomaly_stmt->close();
    }
    return true;
}

function predictAbsenteeism($conn) {
    // Get historical attendance data
    $attendance_sql = "SELECT 
                        DATE_FORMAT(work_date, '%W') as day_of_week,
                        COUNT(*) as total_days,
                        SUM(CASE WHEN hours_worked < 4 THEN 1 ELSE 0 END) as absent_days
                       FROM attendance
                       WHERE work_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                       GROUP BY DATE_FORMAT(work_date, '%W')";
    
    $attendance_result = $conn->query($attendance_sql);
    if (!$attendance_result) {
        return [];
    }
    
    $absenteeism_by_day = [];
    while ($row = $attendance_result->fetch_assoc()) {
        $absenteeism_rate = $row['total_days'] > 0 ? ($row['absent_days'] / $row['total_days']) * 100 : 5;
        $absenteeism_by_day[$row['day_of_week']] = $absenteeism_rate;
    }
    
    // Get total employees for prediction
    $emp_count_sql = "SELECT COUNT(*) as total FROM employees WHERE status = 'active'";
    $emp_count_result = $conn->query($emp_count_sql);
    $total_employees = $emp_count_result ? $emp_count_result->fetch_assoc()['total'] : 50;
    
    // Predict next 30 days absenteeism
    $predictions = [];
    for ($i = 1; $i <= 30; $i++) {
        $date = date('Y-m-d', strtotime("+$i days"));
        $day_of_week = date('l', strtotime($date));
        $absenteeism_rate = $absenteeism_by_day[$day_of_week] ?? 5;
        
        $predictions[] = [
            'date' => $date,
            'day' => $day_of_week,
            'predicted_absenteeism_rate' => round($absenteeism_rate, 2),
            'expected_absent_employees' => round(($absenteeism_rate / 100) * $total_employees)
        ];
    }
    
    return $predictions;
}

function identifyHighOvertime($conn) {
    $overtime_sql = "SELECT 
                        COALESCE(d.name, 'No Department') as department,
                        SUM(p.overtime_hours) as total_overtime,
                        COUNT(DISTINCT p.employee_id) as employees_with_overtime,
                        AVG(p.overtime_hours) as avg_overtime_per_employee,
                        SUM(p.overtime_pay) as overtime_cost
                     FROM payroll p
                     JOIN employees e ON p.employee_id = e.id
                     LEFT JOIN departments d ON e.department_id = d.id
                     WHERE p.pay_period_end >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
                       AND p.overtime_hours > 0
                     GROUP BY d.id, d.name
                     HAVING avg_overtime_per_employee > 5
                     ORDER BY avg_overtime_per_employee DESC";
    
    $overtime_result = $conn->query($overtime_sql);
    $result = [];
    if ($overtime_result) {
        while ($row = $overtime_result->fetch_assoc()) {
            $result[] = $row;
        }
    }
    return $result;
}

// Fetch payroll growth trends
$growth_sql = "SELECT 
                DATE_FORMAT(pay_period_end, '%Y-%m') as month,
                SUM(total_pay) as total_payroll,
                COUNT(DISTINCT employee_id) as employee_count,
                AVG(total_pay) as avg_pay
               FROM payroll
               WHERE pay_period_end >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
               GROUP BY DATE_FORMAT(pay_period_end, '%Y-%m')
               ORDER BY month ASC";
$growth_result = $conn->query($growth_sql);

$growth_data = [];
if ($growth_result) {
    while ($row = $growth_result->fetch_assoc()) {
        $growth_data[] = $row;
    }
}

// Fetch departments for dropdown
$depts_sql = "SELECT id, name FROM departments ORDER BY name";
$depts_result = $conn->query($depts_sql);

// Fetch previous reports
$reports_sql = "SELECT r.*, COALESCE(u.username, 'System') as created_by_name 
                FROM reports r
                LEFT JOIN users u ON r.created_by = u.id
                ORDER BY r.created_at DESC
                LIMIT 20";
$reports_result = $conn->query($reports_sql);

// Calculate latest stats
$latest = !empty($growth_data) ? end($growth_data) : ['total_payroll' => 0, 'employee_count' => 0, 'avg_pay' => 0];
$previous = !empty($growth_data) && count($growth_data) > 1 ? prev($growth_data) : $latest;
$growth_rate = $previous && $previous['total_payroll'] > 0 ? (($latest['total_payroll'] - $previous['total_payroll']) / $previous['total_payroll']) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Payroll System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Copy all the styles from the previous version */
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
        }
        
        .nav {
            background: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
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
        }
        
        .btn-secondary {
            background: #3498db;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background: #f39c12;
            color: white;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
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
        
        .card h3 {
            margin: 15px 0 10px;
            color: #2c3e50;
            font-size: 16px;
        }
        
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        
        .grid-4 {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #27ae60;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #e74c3c;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #3498db;
        }
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            font-weight: 600;
            color: #7f8c8d;
        }
        
        .tab:hover {
            color: #667eea;
        }
        
        .tab.active {
            border-bottom-color: #667eea;
            color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .chart-container {
            height: 300px;
            margin-bottom: 20px;
        }
        
        .field-selector {
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 15px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .field-item {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .field-item:last-child {
            border-bottom: none;
        }
        
        .field-item label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .anomaly-high {
            background: #fde9e9;
            border-left: 4px solid #e74c3c;
        }
        
        .anomaly-medium {
            background: #fef5e7;
            border-left: 4px solid #f39c12;
        }
        
        .anomaly-low {
            background: #e8f4fd;
            border-left: 4px solid #3498db;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #27ae60;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #e74c3c;
        }
        
        @media (max-width: 768px) {
            .grid-2, .grid-3, .grid-4 {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Reports & Analytics</h1>
            <p>Financial forecasting, department analysis, custom reports, and AI-powered insights</p>
        </div>
        
        <div class="nav">
            <div class="nav-links">
                <a href="dashboard.php"><i class="fas fa-dashboard"></i> Dashboard</a>
                <a href="employees.php"><i class="fas fa-users"></i> Employees</a>
                <a href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>
                <a href="payroll.php"><i class="fas fa-money-bill"></i> Payroll</a>
                <a href="reports.php" class="active"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Tabs Navigation -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('forecast')">📊 Financial Forecast</div>
            <div class="tab" onclick="showTab('department')">🏢 Department Analysis</div>
            <div class="tab" onclick="showTab('custom')">🔧 Custom Report Builder</div>
            <div class="tab" onclick="showTab('ai')">🤖 AI Insights</div>
            <div class="tab" onclick="showTab('history')">📜 Report History</div>
        </div>
        
        <!-- Financial Forecast Tab -->
        <div id="forecast" class="tab-content active">
            <div class="card">
                <h2><i class="fas fa-chart-line"></i> Payroll Growth Trends</h2>
                
                <div class="chart-container">
                    <canvas id="growthChart"></canvas>
                </div>
                
                <div class="grid-4">
                    <div class="stat-card" style="background: linear-gradient(135deg, #3498db, #2980b9);">
                        <div class="stat-value">$<?php echo number_format($latest['total_payroll'] ?? 0, 2); ?></div>
                        <div class="stat-label">Latest Monthly Payroll</div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, #27ae60, #229954);">
                        <div class="stat-value <?php echo $growth_rate >= 0 ? '' : 'text-danger'; ?>">
                            <?php echo number_format($growth_rate, 2); ?>%
                        </div>
                        <div class="stat-label">Monthly Growth Rate</div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, #f39c12, #e67e22);">
                        <div class="stat-value"><?php echo $latest['employee_count'] ?? 0; ?></div>
                        <div class="stat-label">Active Employees</div>
                    </div>
                    <div class="stat-card" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);">
                        <div class="stat-value">$<?php echo number_format($latest['avg_pay'] ?? 0, 2); ?></div>
                        <div class="stat-label">Average Salary</div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h2><i class="fas fa-chart-line"></i> Financial Forecasting</h2>
                
                <form method="POST" class="form-row">
                    <div class="form-group" style="flex: 2;">
                        <label>Forecast Period (Months)</label>
                        <select name="forecast_months">
                            <option value="1">1 Month</option>
                            <option value="3" selected>3 Months</option>
                            <option value="6">6 Months</option>
                            <option value="12">12 Months</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; display: flex; align-items: flex-end;">
                        <button type="submit" name="generate_forecast" class="btn btn-primary" style="width: 100%;">
                            <i class="fas fa-chart-line"></i> Generate Forecast
                        </button>
                    </div>
                </form>
                
                <?php if (isset($forecast_data)): ?>
                <div style="margin-top: 20px;">
                    <h3>Forecast Results</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Predicted Payroll</th>
                                <th>Lower Bound (95%)</th>
                                <th>Upper Bound (95%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forecast_data['forecast'] as $f): ?>
                            <tr>
                                <td><?php echo date('F Y', strtotime($f['month'] . '-01')); ?></td>
                                <td><strong>$<?php echo number_format($f['predicted'], 2); ?></strong></td>
                                <td>$<?php echo number_format($f['lower_bound'], 2); ?></td>
                                <td>$<?php echo number_format($f['upper_bound'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top: 10px; color: #7f8c8d;">
                        <i class="fas fa-info-circle"></i> 
                        Based on historical growth rate of <?php echo number_format($forecast_data['growth_rate'], 2); ?>% per month
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Department Analysis Tab -->
        <div id="department" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-building"></i> Department Cost Analysis</h2>
                
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Department (Optional)</label>
                            <select name="department_id">
                                <option value="">All Departments</option>
                                <?php if ($depts_result && $depts_result->num_rows > 0): ?>
                                    <?php $depts_result->data_seek(0); while($dept = $depts_result->fetch_assoc()): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo date('Y-m-01'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <button type="submit" name="analyze_department" class="btn btn-primary">
                        <i class="fas fa-chart-pie"></i> Analyze Departments
                    </button>
                </form>
                
                <?php if (isset($department_analysis) && !empty($department_analysis)): ?>
                <div style="margin-top: 20px;">
                    <div class="chart-container">
                        <canvas id="deptChart"></canvas>
                    </div>
                    
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Employees</th>
                                <th>Total Payroll</th>
                                <th>Avg Salary</th>
                                <th>Total Hours</th>
                                <th>Overtime Hours</th>
                                <th>Overtime Cost</th>
                                <th>Tax</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_payroll = 0;
                            foreach ($department_analysis as $dept): 
                                $total_payroll += $dept['total_payroll'];
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dept['department']); ?></strong></td>
                                <td><?php echo $dept['employee_count']; ?></td>
                                <td>$<?php echo number_format($dept['total_payroll'], 2); ?></td>
                                <td>$<?php echo number_format($dept['avg_salary'], 2); ?></td>
                                <td><?php echo number_format($dept['total_hours'], 1); ?></td>
                                <td><?php echo number_format($dept['total_overtime'], 1); ?></td>
                                <td>$<?php echo number_format($dept['overtime_cost'], 2); ?></td>
                                <td>$<?php echo number_format($dept['total_tax'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8f9fa; font-weight: bold;">
                                <td colspan="2">TOTAL</td>
                                <td>$<?php echo number_format($total_payroll, 2); ?></td>
                                <td colspan="5"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Custom Report Builder Tab -->
        <div id="custom" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-tools"></i> Custom Report Builder</h2>
                
                <form method="POST" onsubmit="return validateCustomReport()">
                    <div class="grid-2">
                        <!-- Field Selector -->
                        <div>
                            <h3>Select Fields</h3>
                            <div class="field-selector">
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="employee_id" checked> Employee ID
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="employee_name" checked> Employee Name
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="department"> Department
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="position"> Position
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="pay_period" checked> Pay Period
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="regular_hours"> Regular Hours
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="overtime_hours"> Overtime Hours
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="regular_pay"> Regular Pay
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="overtime_pay"> Overtime Pay
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="housing_allowance"> Housing Allowance
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="transport_allowance"> Transport Allowance
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="tax_amount"> Tax Amount
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="pension_amount"> Pension
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="medical_aid"> Medical Aid
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="custom_deductions"> Custom Deductions
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="total_pay" checked> Total Pay
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="net_pay" checked> Net Pay
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="status"> Status
                                    </label>
                                </div>
                                <div class="field-item">
                                    <label>
                                        <input type="checkbox" name="fields[]" value="payment_date"> Payment Date
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filters -->
                        <div>
                            <h3>Filters</h3>
                            <div class="form-group">
                                <label>Department</label>
                                <select name="filters[department]">
                                    <option value="">All Departments</option>
                                    <?php if ($depts_result && $depts_result->num_rows > 0): ?>
                                        <?php $depts_result->data_seek(0); while($dept = $depts_result->fetch_assoc()): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" name="filters[start_date]" value="<?php echo date('Y-m-01'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" name="filters[end_date]" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Status</label>
                                <select name="filters[status]">
                                    <option value="">All Statuses</option>
                                    <option value="pending">Pending</option>
                                    <option value="paid">Paid</option>
                                </select>
                            </div>
                            
                            <h3 style="margin-top: 20px;">Export Format</h3>
                            <div class="form-group">
                                <select name="export_format">
                                    <option value="csv">CSV</option>
                                    <option value="excel">Excel</option>
                                    <option value="pdf">PDF (Coming Soon)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; text-align: center;">
                        <button type="submit" name="generate_custom_report" class="btn btn-primary">
                            <i class="fas fa-file-export"></i> Generate & Export Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- AI Insights Tab -->
        <div id="ai" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-robot"></i> AI-Powered Insights</h2>
                
                <form method="POST">
                    <button type="submit" name="generate_ai_insights" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Refresh AI Insights
                    </button>
                </form>
                
                <?php if (isset($ai_insights)): ?>
                <div style="margin-top: 20px;">
                    <!-- Anomalies -->
                    <h3>🚨 Detected Anomalies</h3>
                    <?php if (!empty($ai_insights['anomalies'])): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Period</th>
                                <th>Issue</th>
                                <th>Severity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ai_insights['anomalies'] as $anomaly): ?>
                            <tr class="anomaly-<?php echo $anomaly['severity']; ?>">
                                <td><?php echo htmlspecialchars($anomaly['first_name'] . ' ' . $anomaly['last_name']); ?></td>
                                <td><?php echo date('M d', strtotime($anomaly['pay_period_start'])) . ' - ' . date('M d', strtotime($anomaly['pay_period_end'])); ?></td>
                                <td><?php echo htmlspecialchars($anomaly['description']); ?></td>
                                <td>
                                    <span class="badge badge-<?php 
                                        echo $anomaly['severity'] == 'high' ? 'danger' : 
                                            ($anomaly['severity'] == 'medium' ? 'warning' : 'info'); 
                                    ?>">
                                        <?php echo ucfirst($anomaly['severity']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="text-center" style="padding: 20px; color: #7f8c8d;">No anomalies detected</p>
                    <?php endif; ?>
                    
                    <!-- High Overtime Departments -->
                    <h3 style="margin-top: 30px;">⏰ High Overtime Departments</h3>
                    <?php if (!empty($ai_insights['high_overtime_departments'])): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Total Overtime</th>
                                <th>Employees with OT</th>
                                <th>Avg OT/Employee</th>
                                <th>Overtime Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ai_insights['high_overtime_departments'] as $dept): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($dept['department']); ?></strong></td>
                                <td><?php echo number_format($dept['total_overtime'], 1); ?> hrs</td>
                                <td><?php echo $dept['employees_with_overtime']; ?></td>
                                <td><?php echo number_format($dept['avg_overtime_per_employee'], 1); ?> hrs</td>
                                <td>$<?php echo number_format($dept['overtime_cost'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="text-center" style="padding: 20px; color: #7f8c8d;">No high overtime departments detected</p>
                    <?php endif; ?>
                    
                    <!-- Absenteeism Prediction -->
                    <h3 style="margin-top: 30px;">📅 Next Month Absenteeism Prediction</h3>
                    <?php if (!empty($ai_insights['absenteeism_prediction'])): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Predicted Absenteeism Rate</th>
                                    <th>Expected Absent Employees</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $predictions = array_slice($ai_insights['absenteeism_prediction'], 0, 10);
                                foreach ($predictions as $pred): 
                                ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($pred['date'])); ?></td>
                                    <td><?php echo $pred['day']; ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo $pred['predicted_absenteeism_rate'] > 10 ? 'danger' : 'info'; ?>">
                                            <?php echo $pred['predicted_absenteeism_rate']; ?>%
                                        </span>
                                    </td>
                                    <td><?php echo $pred['expected_absent_employees']; ?> employees</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p style="margin-top: 10px; color: #7f8c8d;">
                            <i class="fas fa-info-circle"></i> Showing first 10 days. Based on historical attendance patterns.
                        </p>
                    </div>
                    <?php else: ?>
                    <p class="text-center" style="padding: 20px; color: #7f8c8d;">Insufficient attendance data for predictions</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Report History Tab -->
        <div id="history" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-history"></i> Generated Reports</h2>
                
                <table class="table">
                    <thead>
                        <tr>
                            <th>Report Name</th>
                            <th>Type</th>
                            <th>Generated By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($reports_result && $reports_result->num_rows > 0): ?>
                            <?php while($report = $reports_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($report['report_name']); ?></td>
                                <td>
                                    <span class="badge badge-<?php 
                                        echo $report['report_type'] == 'financial' ? 'success' : 
                                            ($report['report_type'] == 'department' ? 'info' : 
                                            ($report['report_type'] == 'custom' ? 'warning' : 'danger')); 
                                    ?>">
                                        <?php echo ucfirst($report['report_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($report['created_by_name']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($report['created_at'])); ?></td>
                                <td>
                                    <a href="view_report.php?id=<?php echo $report['id']; ?>" class="btn btn-sm btn-secondary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-file-alt fa-3x" style="color: #95a5a6;"></i>
                                    <p>No reports generated yet</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
            
            // Store active tab in session storage
            sessionStorage.setItem('activeReportTab', tabId);
        }
        
        // Restore active tab from session storage
        window.onload = function() {
            const activeTab = sessionStorage.getItem('activeReportTab');
            if (activeTab) {
                document.querySelectorAll('.tab').forEach(tab => {
                    if (tab.textContent.includes(activeTab === 'forecast' ? 'Financial' : 
                                                activeTab === 'department' ? 'Department' :
                                                activeTab === 'custom' ? 'Custom' :
                                                activeTab === 'ai' ? 'AI' : 'History')) {
                        tab.click();
                    }
                });
            }
        }
        
        // Validate custom report
        function validateCustomReport() {
            const checkboxes = document.querySelectorAll('input[name="fields[]"]:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one field');
                return false;
            }
            return true;
        }
        
        // Growth Chart
        <?php if (!empty($growth_data)): ?>
        const growthCtx = document.getElementById('growthChart').getContext('2d');
        new Chart(growthCtx, {
            type: 'line',
            data: {
                labels: [<?php 
                    foreach($growth_data as $row) {
                        echo "'" . date('M Y', strtotime($row['month'] . '-01')) . "',";
                    }
                ?>],
                datasets: [{
                    label: 'Total Payroll',
                    data: [<?php foreach($growth_data as $row) { echo $row['total_payroll'] . ","; } ?>],
                    borderColor: '#27ae60',
                    backgroundColor: 'rgba(39, 174, 96, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                }, {
                    label: 'Average Salary',
                    data: [<?php foreach($growth_data as $row) { echo $row['avg_pay'] . ","; } ?>],
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += '$' + context.parsed.y.toLocaleString();
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false
                        },
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

       
    </script>
</body>
</html>