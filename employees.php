<?php
session_start();
require_once 'config.php';
require_once 'auth_check.php';

// Only allow admin, manager, and hr to access
checkRole(['admin', 'manager', 'hr']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Add new employee
    if (isset($_POST['add_employee'])) {
        $employee_id = $_POST['employee_id'];
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $position = $_POST['position'];
        $pay_type = $_POST['pay_type'];
        $hourly_rate = $_POST['hourly_rate'] ?: 0;
        $monthly_salary = $_POST['monthly_salary'] ?: 0;
        $hire_date = $_POST['hire_date'];
        $status = $_POST['status'] ?? 'active';
        
        // Validate based on pay type
        if ($pay_type == 'hourly' && $hourly_rate <= 0) {
            $error = "Hourly rate is required for hourly employees";
        } elseif ($pay_type == 'monthly' && $monthly_salary <= 0) {
            $error = "Monthly salary is required for monthly employees";
        } else {
            // Salary structure fields
            $basic_percentage = $_POST['basic_percentage'] ?? 70;
            $housing_percentage = $_POST['housing_percentage'] ?? 20;
            $transport_fixed = $_POST['transport_fixed'] ?? 500;
            $tax_rate = $_POST['tax_rate'] ?? 15;
            $pension_rate = $_POST['pension_rate'] ?? 7.5;
            $medical_aid_rate = $_POST['medical_aid_rate'] ?? 5;
            $base_salary = $_POST['base_salary'] ?? 0;
            $effective_from = $_POST['effective_from'] ?? date('Y-m-d');
            
            // Check if employee_id or email already exists
            $check_sql = "SELECT id FROM employees WHERE employee_id = ? OR email = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ss", $employee_id, $email);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = "Employee ID or Email already exists!";
            } else {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // Insert employee with pay type
                    $sql = "INSERT INTO employees (employee_id, first_name, last_name, email, phone, position, 
                                                   pay_type, hourly_rate, monthly_salary, hire_date, status) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sssssssdsss", 
                        $employee_id, $first_name, $last_name, $email, $phone, $position,
                        $pay_type, $hourly_rate, $monthly_salary, $hire_date, $status
                    );
                    
                    if (!$stmt->execute()) {
                        throw new Exception("Error adding employee: " . $conn->error);
                    }
                    
                    $new_employee_id = $conn->insert_id;
                    
                    // Calculate salary components
                    $basic_salary = ($base_salary * $basic_percentage) / 100;
                    $housing_allowance = ($basic_salary * $housing_percentage) / 100;
                    $transport_allowance = $transport_fixed;
                    $gross_salary = $basic_salary + $housing_allowance + $transport_allowance;
                    
                    $tax_amount = ($gross_salary * $tax_rate) / 100;
                    $pension_amount = ($gross_salary * $pension_rate) / 100;
                    $medical_aid_amount = ($gross_salary * $medical_aid_rate) / 100;
                    
                    // Calculate custom deductions
                    $custom_deductions_total = 0;
                    if (isset($_POST['custom_deductions']) && is_array($_POST['custom_deductions'])) {
                        foreach ($_POST['custom_deductions'] as $deduction_id => $deduction_data) {
                            if (isset($deduction_data['enabled']) && $deduction_data['enabled'] == 1 && !empty($deduction_data['value'])) {
                                // Get deduction type
                                $type_sql = "SELECT deduction_type FROM custom_deductions WHERE id = ?";
                                $type_stmt = $conn->prepare($type_sql);
                                $type_stmt->bind_param("i", $deduction_id);
                                $type_stmt->execute();
                                $type_result = $type_stmt->get_result();
                                $deduction_type = $type_result->fetch_assoc();
                                
                                $amount = $deduction_data['value'];
                                $is_percentage = ($deduction_type['deduction_type'] == 'percentage') ? 1 : 0;
                                
                                if ($is_percentage) {
                                    $custom_deductions_total += ($gross_salary * $amount) / 100;
                                } else {
                                    $custom_deductions_total += $amount;
                                }
                                
                                // Insert employee custom deduction
                                $custom_sql = "INSERT INTO employee_custom_deductions (employee_id, deduction_id, amount, is_percentage) 
                                              VALUES (?, ?, ?, ?)";
                                $custom_stmt = $conn->prepare($custom_sql);
                                $custom_stmt->bind_param("iidi", $new_employee_id, $deduction_id, $amount, $is_percentage);
                                $custom_stmt->execute();
                            }
                        }
                    }
                    
                    $net_salary = $gross_salary - $tax_amount - $pension_amount - $medical_aid_amount - $custom_deductions_total;
                    
                    // Insert salary structure
                    $salary_sql = "INSERT INTO employee_salary_structure 
                                  (employee_id, basic_salary, housing_allowance, transport_allowance, other_allowances,
                                   tax_amount, pension_amount, medical_aid_amount, custom_deductions, net_salary, effective_from) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $salary_stmt = $conn->prepare($salary_sql);
                    $other_allowances = 0;
                    $salary_stmt->bind_param("iddddddddds", 
                        $new_employee_id, $basic_salary, $housing_allowance, $transport_allowance, $other_allowances,
                        $tax_amount, $pension_amount, $medical_aid_amount, $custom_deductions_total, $net_salary, $effective_from
                    );
                    
                    if (!$salary_stmt->execute()) {
                        throw new Exception("Error adding salary structure: " . $conn->error);
                    }
                    
                    $conn->commit();
                    $success = "Employee added successfully with salary structure!";
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = $e->getMessage();
                }
            }
        }
    }
    
    // Update employee
    if (isset($_POST['update_employee'])) {
        $id = $_POST['employee_id_hidden'];
        $employee_id = $_POST['employee_id'];
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $position = $_POST['position'];
        $pay_type = $_POST['pay_type'];
        $hourly_rate = $_POST['hourly_rate'] ?: 0;
        $monthly_salary = $_POST['monthly_salary'] ?: 0;
        $hire_date = $_POST['hire_date'];
        $status = $_POST['status'];
        
        // Validate based on pay type
        if ($pay_type == 'hourly' && $hourly_rate <= 0) {
            $error = "Hourly rate is required for hourly employees";
        } elseif ($pay_type == 'monthly' && $monthly_salary <= 0) {
            $error = "Monthly salary is required for monthly employees";
        } else {
            // Check if employee_id or email already exists for another employee
            $check_sql = "SELECT id FROM employees WHERE (employee_id = ? OR email = ?) AND id != ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ssi", $employee_id, $email, $id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = "Employee ID or Email already exists for another employee!";
            } else {
                $sql = "UPDATE employees SET 
                        employee_id = ?, first_name = ?, last_name = ?, email = ?, 
                        phone = ?, position = ?, pay_type = ?, hourly_rate = ?, 
                        monthly_salary = ?, hire_date = ?, status = ?
                        WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssssdsssi", 
                    $employee_id, $first_name, $last_name, $email, $phone, $position,
                    $pay_type, $hourly_rate, $monthly_salary, $hire_date, $status, $id
                );
                
                if ($stmt->execute()) {
                    $success = "Employee updated successfully!";
                } else {
                    $error = "Error updating employee: " . $conn->error;
                }
            }
        }
    }
    
    // Update salary structure
    if (isset($_POST['update_salary_structure'])) {
        $structure_id = $_POST['structure_id'];
        $basic_salary = $_POST['basic_salary'];
        $housing_allowance = $_POST['housing_allowance'];
        $transport_allowance = $_POST['transport_allowance'];
        $other_allowances = $_POST['other_allowances'];
        $tax_amount = $_POST['tax_amount'];
        $pension_amount = $_POST['pension_amount'];
        $medical_aid_amount = $_POST['medical_aid_amount'];
        $custom_deductions = $_POST['custom_deductions'];
        $net_salary = $_POST['net_salary'];
        $effective_from = $_POST['effective_from'];
        
        $sql = "UPDATE employee_salary_structure SET 
                basic_salary = ?, housing_allowance = ?, transport_allowance = ?,
                other_allowances = ?, tax_amount = ?, pension_amount = ?,
                medical_aid_amount = ?, custom_deductions = ?, net_salary = ?, effective_from = ?
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("dddddddddss", 
            $basic_salary, $housing_allowance, $transport_allowance,
            $other_allowances, $tax_amount, $pension_amount,
            $medical_aid_amount, $custom_deductions, $net_salary, $effective_from,
            $structure_id
        );
        
        if ($stmt->execute()) {
            $success = "Salary structure updated successfully!";
        } else {
            $error = "Error updating salary structure: " . $conn->error;
        }
    }
    
    // Delete employee
    if (isset($_POST['delete_employee'])) {
        $id = $_POST['employee_id'];
        
        // Check if employee has payroll records
        $check_payroll_sql = "SELECT id FROM payroll WHERE employee_id = ? LIMIT 1";
        $check_payroll_stmt = $conn->prepare($check_payroll_sql);
        $check_payroll_stmt->bind_param("i", $id);
        $check_payroll_stmt->execute();
        $check_payroll_result = $check_payroll_stmt->get_result();
        
        if ($check_payroll_result->num_rows > 0) {
            $error = "Cannot delete employee. They have payroll records. Consider deactivating instead.";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Check if attendance table exists before trying to delete from it
                $table_check = $conn->query("SHOW TABLES LIKE 'attendance'");
                if ($table_check->num_rows > 0) {
                    $delete_attendance_sql = "DELETE FROM attendance WHERE employee_id = ?";
                    $delete_attendance_stmt = $conn->prepare($delete_attendance_sql);
                    $delete_attendance_stmt->bind_param("i", $id);
                    $delete_attendance_stmt->execute();
                }
                
                // Delete custom deductions
                $delete_custom_sql = "DELETE FROM employee_custom_deductions WHERE employee_id = ?";
                $delete_custom_stmt = $conn->prepare($delete_custom_sql);
                $delete_custom_stmt->bind_param("i", $id);
                $delete_custom_stmt->execute();
                
                // Delete salary structure
                $delete_salary_sql = "DELETE FROM employee_salary_structure WHERE employee_id = ?";
                $delete_salary_stmt = $conn->prepare($delete_salary_sql);
                $delete_salary_stmt->bind_param("i", $id);
                $delete_salary_stmt->execute();
                
                // Delete employee
                $delete_emp_sql = "DELETE FROM employees WHERE id = ?";
                $delete_emp_stmt = $conn->prepare($delete_emp_sql);
                $delete_emp_stmt->bind_param("i", $id);
                $delete_emp_stmt->execute();
                
                $conn->commit();
                $success = "Employee deleted successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error deleting employee: " . $e->getMessage();
            }
        }
    }
}

// Handle view request
$view_mode = false;
$view_employee = null;
$view_salary = null;
$view_custom_deductions = [];
$view_attendance = [];
$attendance_summary = [];
$monthly_attendance = [];

// Check if attendance table exists
$attendance_table_exists = false;
$table_check = $conn->query("SHOW TABLES LIKE 'attendance'");
if ($table_check && $table_check->num_rows > 0) {
    $attendance_table_exists = true;
}

if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    
    // Fetch employee details
    $view_sql = "SELECT * FROM employees WHERE id = ?";
    $view_stmt = $conn->prepare($view_sql);
    if ($view_stmt) {
        $view_stmt->bind_param("i", $view_id);
        $view_stmt->execute();
        $view_result = $view_stmt->get_result();
        
        if ($view_result->num_rows > 0) {
            $view_mode = true;
            $view_employee = $view_result->fetch_assoc();
            
            // Fetch salary structure
            $salary_sql = "SELECT * FROM employee_salary_structure 
                           WHERE employee_id = ? AND is_active = 1 
                           ORDER BY effective_from DESC LIMIT 1";
            $salary_stmt = $conn->prepare($salary_sql);
            if ($salary_stmt) {
                $salary_stmt->bind_param("i", $view_id);
                $salary_stmt->execute();
                $salary_result = $salary_stmt->get_result();
                $view_salary = $salary_result->fetch_assoc();
            }
            
            // Fetch custom deductions
            $custom_sql = "SELECT cd.*, ecd.amount, ecd.is_percentage 
                           FROM employee_custom_deductions ecd
                           JOIN custom_deductions cd ON ecd.deduction_id = cd.id
                           WHERE ecd.employee_id = ?";
            $custom_stmt = $conn->prepare($custom_sql);
            if ($custom_stmt) {
                $custom_stmt->bind_param("i", $view_id);
                $custom_stmt->execute();
                $custom_result = $custom_stmt->get_result();
                while ($row = $custom_result->fetch_assoc()) {
                    $view_custom_deductions[] = $row;
                }
            }
            
            // Fetch attendance records only if table exists
            if ($attendance_table_exists) {
                // Fetch attendance records (last 30 days)
                $attendance_sql = "SELECT * FROM attendance 
                                   WHERE employee_id = ? 
                                   AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                   ORDER BY date DESC";
                $attendance_stmt = $conn->prepare($attendance_sql);
                if ($attendance_stmt) {
                    $attendance_stmt->bind_param("i", $view_id);
                    $attendance_stmt->execute();
                    $attendance_result = $attendance_stmt->get_result();
                    while ($row = $attendance_result->fetch_assoc()) {
                        $view_attendance[] = $row;
                    }
                }
                
                // Calculate attendance summary
                $summary_sql = "SELECT 
                                COUNT(*) as total_days,
                                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                                SUM(CASE WHEN status = 'half-day' THEN 1 ELSE 0 END) as half_days,
                                SUM(TIMESTAMPDIFF(HOUR, check_in, check_out)) as total_hours,
                                AVG(TIMESTAMPDIFF(HOUR, check_in, check_out)) as avg_hours
                                FROM attendance 
                                WHERE employee_id = ? 
                                AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
                $summary_stmt = $conn->prepare($summary_sql);
                if ($summary_stmt) {
                    $summary_stmt->bind_param("i", $view_id);
                    $summary_stmt->execute();
                    $summary_result = $summary_stmt->get_result();
                    $attendance_summary = $summary_result->fetch_assoc();
                }
                
                // Calculate monthly attendance for chart
                $monthly_sql = "SELECT 
                                DATE_FORMAT(date, '%Y-%m') as month,
                                COUNT(*) as total_days,
                                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days
                                FROM attendance 
                                WHERE employee_id = ?
                                AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                                GROUP BY DATE_FORMAT(date, '%Y-%m')
                                ORDER BY month ASC";
                $monthly_stmt = $conn->prepare($monthly_sql);
                if ($monthly_stmt) {
                    $monthly_stmt->bind_param("i", $view_id);
                    $monthly_stmt->execute();
                    $monthly_result = $monthly_stmt->get_result();
                    while ($row = $monthly_result->fetch_assoc()) {
                        $monthly_attendance[] = $row;
                    }
                }
            }
        }
    }
}

// Handle edit request
$edit_mode = false;
$edit_employee = null;
$edit_salary = null;

if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    
    // Fetch employee details
    $edit_sql = "SELECT * FROM employees WHERE id = ?";
    $edit_stmt = $conn->prepare($edit_sql);
    if ($edit_stmt) {
        $edit_stmt->bind_param("i", $edit_id);
        $edit_stmt->execute();
        $edit_result = $edit_stmt->get_result();
        
        if ($edit_result->num_rows > 0) {
            $edit_mode = true;
            $edit_employee = $edit_result->fetch_assoc();
            
            // Fetch salary structure
            $salary_sql = "SELECT * FROM employee_salary_structure 
                           WHERE employee_id = ? AND is_active = 1 
                           ORDER BY effective_from DESC LIMIT 1";
            $salary_stmt = $conn->prepare($salary_sql);
            if ($salary_stmt) {
                $salary_stmt->bind_param("i", $edit_id);
                $salary_stmt->execute();
                $salary_result = $salary_stmt->get_result();
                $edit_salary = $salary_result->fetch_assoc();
            }
        }
    }
}

// Fetch all employees with their salary info
$sql = "SELECT e.*, 
        ess.basic_salary, ess.housing_allowance, ess.transport_allowance,
        ess.tax_amount, ess.pension_amount, ess.medical_aid_amount,
        ess.custom_deductions, ess.net_salary
        FROM employees e
        LEFT JOIN employee_salary_structure ess ON e.id = ess.employee_id AND ess.is_active = 1
        ORDER BY e.id DESC";
$result = $conn->query($sql);

// Get statistics for charts
$total_employees = $result->num_rows;

// Fetch configuration values
$config_sql = "SELECT config_key, config_value, config_type FROM salary_config WHERE is_active = 1";
$config_result = $conn->query($config_sql);
$configs = [];
while($row = $config_result->fetch_assoc()) {
    $configs[$row['config_key']] = $row;
}

// Fetch custom deductions
$deductions_sql = "SELECT * FROM custom_deductions WHERE is_active = 1 ORDER BY is_mandatory DESC, deduction_name";
$deductions_result = $conn->query($deductions_sql);

// Department distribution
$dept_sql = "SELECT d.name as department, COUNT(e.id) as count 
             FROM departments d 
             LEFT JOIN employees e ON d.id = e.department_id 
             GROUP BY d.id, d.name";
$dept_result = $conn->query($dept_sql);

// Status distribution
$status_sql = "SELECT status, COUNT(*) as count FROM employees GROUP BY status";
$status_result = $conn->query($status_sql);

// Pay type distribution
$paytype_sql = "SELECT pay_type, COUNT(*) as count FROM employees GROUP BY pay_type";
$paytype_result = $conn->query($paytype_sql);

// Monthly hiring trend (last 6 months)
$hiring_sql = "SELECT 
                DATE_FORMAT(hire_date, '%Y-%m') as month,
                COUNT(*) as count
                FROM employees 
                WHERE hire_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(hire_date, '%Y-%m')
                ORDER BY month ASC";
$hiring_result = $conn->query($hiring_sql);

// Position distribution
$position_sql = "SELECT position, COUNT(*) as count 
                 FROM employees 
                 GROUP BY position 
                 ORDER BY count DESC 
                 LIMIT 5";
$position_result = $conn->query($position_sql);

// Get salary statistics
$salary_stats_sql = "SELECT 
                     AVG(net_salary) as avg_salary,
                     MIN(net_salary) as min_salary,
                     MAX(net_salary) as max_salary,
                     SUM(net_salary) as total_payroll
                     FROM employee_salary_structure WHERE is_active = 1";
$salary_stats_result = $conn->query($salary_stats_sql);
$salary_stats = $salary_stats_result->fetch_assoc();

// Get pay type counts
$hourly_count = 0;
$monthly_count = 0;
if ($paytype_result && $paytype_result->num_rows > 0) {
    while($row = $paytype_result->fetch_assoc()) {
        if ($row['pay_type'] == 'hourly') $hourly_count = $row['count'];
        if ($row['pay_type'] == 'monthly') $monthly_count = $row['count'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management - Payroll System</title>
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
        
        .nav-actions {
            display: flex;
            gap: 10px;
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
            transform: translateY(-2px);
        }
        
        .btn-config {
            background: #9b59b6;
            color: white;
        }
        
        .btn-config:hover {
            background: #8e44ad;
            transform: translateY(-2px);
        }
        
        .btn-view {
            background: #3498db;
            color: white;
        }
        
        .btn-edit {
            background: #f39c12;
            color: white;
        }
        
        .btn-delete {
            background: #e74c3c;
            color: white;
        }
        
        .btn-back {
            background: #95a5a6;
            color: white;
            margin-bottom: 20px;
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
        
        .stat-info small {
            font-size: 12px;
            color: #95a5a6;
        }
        
        /* Pay Type Badges */
        .pay-type-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .pay-type-hourly {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .pay-type-monthly {
            background: #fff3e0;
            color: #f57c00;
        }
        
        /* Attendance Badges */
        .attendance-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .attendance-present {
            background: #d4edda;
            color: #155724;
        }
        
        .attendance-absent {
            background: #f8d7da;
            color: #721c24;
        }
        
        .attendance-late {
            background: #fff3cd;
            color: #856404;
        }
        
        .attendance-half-day {
            background: #fff3e0;
            color: #f57c00;
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
        
        /* View Mode Styles */
        .view-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        
        .view-section h3 {
            color: #2c3e50;
            font-size: 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .view-section h3 i {
            color: #667eea;
        }
        
        .view-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .view-item {
            padding: 10px;
            background: white;
            border-radius: 8px;
        }
        
        .view-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .view-value {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .view-value-large {
            font-size: 24px;
            font-weight: 700;
            color: #27ae60;
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
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .form-section h3 {
            margin-bottom: 15px;
            color: #2c3e50;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-section h3 i {
            color: #667eea;
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
        
        .form-group input[readonly] {
            background: #f8f9fa;
            cursor: not-allowed;
        }
        
        .badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-success {
            background: #d4edda;
            color: #27ae60;
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
            margin-top: 10px;
        }
        
        .btn-submit:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
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
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-on-leave {
            background: #fff3cd;
            color: #856404;
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
            animation: slideIn 0.3s ease;
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
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
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
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            margin: 50px auto;
            padding: 25px;
            border-radius: 10px;
            position: relative;
        }
        
        .modal-content h3 {
            margin-bottom: 20px;
            color: #2c3e50;
        }
        
        .close {
            position: absolute;
            right: 20px;
            top: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #7f8c8d;
        }
        
        .close:hover {
            color: #e74c3c;
        }
        
        .pay-type-toggle {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .pay-type-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .pay-type-option.selected {
            border-color: #667eea;
            background: #f0f4ff;
        }
        
        .pay-type-option input[type="radio"] {
            width: auto;
            margin: 0;
        }
        
        /* Attendance specific styles */
        .attendance-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .attendance-stat {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .attendance-stat .label {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        
        .attendance-stat .value {
            font-size: 24px;
            font-weight: 700;
        }
        
        .attendance-stat .value.present { color: #27ae60; }
        .attendance-stat .value.absent { color: #e74c3c; }
        .attendance-stat .value.late { color: #f39c12; }
        .attendance-stat .value.hours { color: #3498db; }
        
        /* Info message for missing attendance table */
        .info-message {
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
        
        /* Filter Styles */
        .filters-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
            color: #2c3e50;
        }

        .filter-group input,
        .filter-group select {
            transition: all 0.3s ease;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        #filterStats {
            animation: fadeIn 0.3s ease;
        }

        .quick-filter {
            transition: all 0.3s ease;
        }

        .quick-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .quick-filter.active {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
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
            
            .nav-links a {
                margin-bottom: 5px;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Alaki Payroll</h1>
            <p>Manage employees and their salary structures</p>
        </div>
        
        <div class="nav">
            <div class="nav-links">
                <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="employees.php" class="active"><i class="fas fa-users"></i> Employees</a>
                <a href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>
                <a href="leave.php"><i class="fas fa-calendar-alt"></i> Leaves</a>
                <a href="payroll.php"><i class="fas fa-money-bill"></i> Payroll</a>
                <a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a>
            </div>
            <div class="nav-actions">
                <a href="salary_config.php" class="btn btn-config">
                    <i class="fas fa-cog"></i> Salary Config
                </a>
                <button class="btn btn-primary" onclick="toggleForm()">
                    <i class="fas fa-plus-circle"></i> Add Employee
                </button>
            </div>
        </div>
        
        <!-- Back Button for View/Edit Modes -->
        <?php if ($view_mode || $edit_mode): ?>
            <a href="employees.php" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back to Employee List
            </a>
        <?php endif; ?>
        
        <!-- Alert Messages -->
        <?php if (isset($success)): ?>
            <div class="success">
                <i class="fas fa-check-circle" style="font-size: 20px;"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle" style="font-size: 20px;"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- View Employee Mode -->
        <?php if ($view_mode && $view_employee): ?>
            <div class="card">
                <h2>
                    <i class="fas fa-user"></i> Employee Details - <?php echo htmlspecialchars($view_employee['first_name'] . ' ' . $view_employee['last_name']); ?>
                </h2>
                
                <!-- Personal Information -->
                <div class="view-section">
                    <h3><i class="fas fa-id-card"></i> Personal Information</h3>
                    <div class="view-grid">
                        <div class="view-item">
                            <div class="view-label">Employee ID</div>
                            <div class="view-value"><?php echo htmlspecialchars($view_employee['employee_id']); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Full Name</div>
                            <div class="view-value"><?php echo htmlspecialchars($view_employee['first_name'] . ' ' . $view_employee['last_name']); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Email</div>
                            <div class="view-value"><?php echo htmlspecialchars($view_employee['email']); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Phone</div>
                            <div class="view-value"><?php echo htmlspecialchars($view_employee['phone'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Position</div>
                            <div class="view-value"><?php echo htmlspecialchars($view_employee['position']); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Pay Type</div>
                            <div class="view-value">
                                <span class="pay-type-badge pay-type-<?php echo $view_employee['pay_type']; ?>">
                                    <?php echo ucfirst($view_employee['pay_type']); ?>
                                </span>
                            </div>
                        </div>
                        <?php if ($view_employee['pay_type'] == 'hourly'): ?>
                        <div class="view-item">
                            <div class="view-label">Hourly Rate</div>
                            <div class="view-value">LSL<?php echo number_format($view_employee['hourly_rate'], 2); ?></div>
                        </div>
                        <?php else: ?>
                        <div class="view-item">
                            <div class="view-label">Monthly Salary</div>
                            <div class="view-value">LSL<?php echo number_format($view_employee['monthly_salary'], 2); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="view-item">
                            <div class="view-label">Hire Date</div>
                            <div class="view-value"><?php echo date('F d, Y', strtotime($view_employee['hire_date'])); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Status</div>
                            <div class="view-value">
                                <span class="status-badge status-<?php echo $view_employee['status']; ?>">
                                    <?php echo ucfirst($view_employee['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($attendance_table_exists): ?>
                <!-- Attendance Summary Section -->
                <div class="view-section">
                    <h3><i class="fas fa-clock"></i> Attendance Summary (Last 30 Days)</h3>
                    
                    <div class="attendance-summary-grid">
                        <div class="attendance-stat">
                            <div class="label">Total Days</div>
                            <div class="value"><?php echo $attendance_summary['total_days'] ?? 0; ?></div>
                        </div>
                        <div class="attendance-stat">
                            <div class="label">Present</div>
                            <div class="value present"><?php echo $attendance_summary['present_days'] ?? 0; ?></div>
                        </div>
                        <div class="attendance-stat">
                            <div class="label">Absent</div>
                            <div class="value absent"><?php echo $attendance_summary['absent_days'] ?? 0; ?></div>
                        </div>
                        <div class="attendance-stat">
                            <div class="label">Late</div>
                            <div class="value late"><?php echo $attendance_summary['late_days'] ?? 0; ?></div>
                        </div>
                        <div class="attendance-stat">
                            <div class="label">Half Days</div>
                            <div class="value"><?php echo $attendance_summary['half_days'] ?? 0; ?></div>
                        </div>
                        <div class="attendance-stat">
                            <div class="label">Total Hours</div>
                            <div class="value hours"><?php echo number_format($attendance_summary['total_hours'] ?? 0, 1); ?></div>
                        </div>
                        <div class="attendance-stat">
                            <div class="label">Avg Hours/Day</div>
                            <div class="value hours"><?php echo number_format($attendance_summary['avg_hours'] ?? 0, 1); ?></div>
                        </div>
                    </div>
                    
                    <!-- Attendance Chart -->
                    <?php if (!empty($monthly_attendance)): ?>
                    <div style="margin-top: 20px; height: 200px;">
                        <canvas id="attendanceChart"></canvas>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Attendance Records -->
                <div class="view-section">
                    <h3><i class="fas fa-list"></i> Recent Attendance Records</h3>
                    
                    <?php if (!empty($view_attendance)): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Hours Worked</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($view_attendance as $attendance): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($attendance['date'])); ?></td>
                                    <td><?php echo $attendance['check_in'] ? date('H:i', strtotime($attendance['check_in'])) : '-'; ?></td>
                                    <td><?php echo $attendance['check_out'] ? date('H:i', strtotime($attendance['check_out'])) : '-'; ?></td>
                                    <td>
                                        <?php 
                                        if ($attendance['check_in'] && $attendance['check_out']) {
                                            $hours = (strtotime($attendance['check_out']) - strtotime($attendance['check_in'])) / 3600;
                                            echo number_format($hours, 1) . ' hrs';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="attendance-badge attendance-<?php echo $attendance['status']; ?>">
                                            <?php echo ucfirst($attendance['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($attendance['notes'] ?? ''); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clock"></i>
                        <p>No attendance records found for the last 30 days.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Info message when attendance table doesn't exist -->
                <div class="info-message">
                    <i class="fas fa-info-circle" style="font-size: 20px;"></i>
                    <div>
                        <strong>Attendance tracking is not set up yet.</strong>
                        <p style="margin-top: 5px; font-size: 13px;">The attendance table needs to be created to track employee attendance. Please run the database setup script.</p>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Salary Structure -->
                <?php if ($view_salary): ?>
                <div class="view-section">
                    <h3><i class="fas fa-calculator"></i> Current Salary Structure</h3>
                    <div class="view-grid">
                        <div class="view-item">
                            <div class="view-label">Basic Salary</div>
                            <div class="view-value">LSL<?php echo number_format($view_salary['basic_salary'], 2); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Housing Allowance</div>
                            <div class="view-value">LSL<?php echo number_format($view_salary['housing_allowance'], 2); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Transport Allowance</div>
                            <div class="view-value">LSL<?php echo number_format($view_salary['transport_allowance'], 2); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Other Allowances</div>
                            <div class="view-value">LSL<?php echo number_format($view_salary['other_allowances'] ?? 0, 2); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Tax Amount</div>
                            <div class="view-value">LSL<?php echo number_format($view_salary['tax_amount'], 2); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Pension Amount</div>
                            <div class="view-value">LSL<?php echo number_format($view_salary['pension_amount'], 2); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Medical Aid</div>
                            <div class="view-value">LSL<?php echo number_format($view_salary['medical_aid_amount'], 2); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Custom Deductions</div>
                            <div class="view-value">LSL<?php echo number_format($view_salary['custom_deductions'] ?? 0, 2); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Effective From</div>
                            <div class="view-value"><?php echo date('F d, Y', strtotime($view_salary['effective_from'])); ?></div>
                        </div>
                        <div class="view-item">
                            <div class="view-label">Net Salary</div>
                            <div class="view-value-large">LSL<?php echo number_format($view_salary['net_salary'], 2); ?></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Custom Deductions -->
                <?php if (!empty($view_custom_deductions)): ?>
                <div class="view-section">
                    <h3><i class="fas fa-minus-circle"></i> Custom Deductions</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Deduction Name</th>
                                <th>Amount</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($view_custom_deductions as $deduction): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($deduction['deduction_name']); ?></td>
                                <td>
                                    <?php 
                                    if ($deduction['is_percentage']) {
                                        echo $deduction['amount'] . '%';
                                    } else {
                                        echo 'LSL' . number_format($deduction['amount'], 2);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <?php echo $deduction['is_percentage'] ? 'Percentage' : 'Fixed'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($attendance_table_exists && !empty($monthly_attendance)): ?>
            <script>
                // Attendance Chart
                const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
                new Chart(attendanceCtx, {
                    type: 'line',
                    data: {
                        labels: [<?php 
                            $months = [];
                            $present_days = [];
                            foreach ($monthly_attendance as $month) {
                                $months[] = "'" . date('M Y', strtotime($month['month'] . '-01')) . "'";
                                $present_days[] = $month['present_days'];
                            }
                            echo implode(',', $months);
                        ?>],
                        datasets: [{
                            label: 'Present Days',
                            data: [<?php echo implode(',', $present_days); ?>],
                            borderColor: '#27ae60',
                            backgroundColor: 'rgba(39, 174, 96, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#27ae60',
                            pointRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { stepSize: 1 }
                            }
                        }
                    }
                });
            </script>
            <?php endif; ?>
        
        <!-- Edit Employee Mode -->
        <?php elseif ($edit_mode && $edit_employee): ?>
            <div class="card">
                <h2>
                    <i class="fas fa-edit"></i> Edit Employee - <?php echo htmlspecialchars($edit_employee['first_name'] . ' ' . $edit_employee['last_name']); ?>
                </h2>
                
                <form method="POST" action="" onsubmit="return confirm('Save changes?')">
                    <input type="hidden" name="employee_id_hidden" value="<?php echo $edit_employee['id']; ?>">
                    
                    <div class="form-section">
                        <h3><i class="fas fa-id-card"></i> Basic Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Employee ID <span style="color: #e74c3c;">*</span></label>
                                <input type="text" name="employee_id" value="<?php echo htmlspecialchars($edit_employee['employee_id']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Hire Date <span style="color: #e74c3c;">*</span></label>
                                <input type="date" name="hire_date" value="<?php echo $edit_employee['hire_date']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name <span style="color: #e74c3c;">*</span></label>
                                <input type="text" name="first_name" value="<?php echo htmlspecialchars($edit_employee['first_name']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Last Name <span style="color: #e74c3c;">*</span></label>
                                <input type="text" name="last_name" value="<?php echo htmlspecialchars($edit_employee['last_name']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Email <span style="color: #e74c3c;">*</span></label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($edit_employee['email']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" value="<?php echo htmlspecialchars($edit_employee['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Position <span style="color: #e74c3c;">*</span></label>
                                <input type="text" name="position" value="<?php echo htmlspecialchars($edit_employee['position']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Pay Type <span style="color: #e74c3c;">*</span></label>
                                <select name="pay_type" id="edit_pay_type" required onchange="toggleEditPayTypeFields()">
                                    <option value="hourly" <?php echo $edit_employee['pay_type'] == 'hourly' ? 'selected' : ''; ?>>Hourly</option>
                                    <option value="monthly" <?php echo $edit_employee['pay_type'] == 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row" id="edit_hourly_rate_row" style="<?php echo $edit_employee['pay_type'] == 'monthly' ? 'display: none;' : ''; ?>">
                            <div class="form-group">
                                <label>Hourly Rate (LSL) <span style="color: #e74c3c;">*</span></label>
                                <input type="number" step="0.01" name="hourly_rate" id="edit_hourly_rate" 
                                       value="<?php echo $edit_employee['hourly_rate']; ?>" min="0.01">
                            </div>
                        </div>
                        
                        <div class="form-row" id="edit_monthly_salary_row" style="<?php echo $edit_employee['pay_type'] == 'hourly' ? 'display: none;' : ''; ?>">
                            <div class="form-group">
                                <label>Monthly Salary (LSL) <span style="color: #e74c3c;">*</span></label>
                                <input type="number" step="0.01" name="monthly_salary" id="edit_monthly_salary" 
                                       value="<?php echo $edit_employee['monthly_salary']; ?>" min="0.01">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="active" <?php echo $edit_employee['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $edit_employee['status'] == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="on-leave" <?php echo $edit_employee['status'] == 'on-leave' ? 'selected' : ''; ?>>On Leave</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" name="update_employee" class="btn-submit">
                        <i class="fas fa-save"></i> Update Employee
                    </button>
                </form>
                
                <!-- Edit Salary Structure -->
                <?php if ($edit_salary): ?>
                <div style="margin-top: 30px;">
                    <h3><i class="fas fa-calculator"></i> Edit Salary Structure</h3>
                    <form method="POST" action="" onsubmit="return confirm('Update salary structure?')">
                        <input type="hidden" name="structure_id" value="<?php echo $edit_salary['id']; ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Basic Salary</label>
                                <input type="number" name="basic_salary" id="edit_basic_salary" 
                                       value="<?php echo $edit_salary['basic_salary']; ?>" step="0.01" required
                                       oninput="calculateEditNetSalary()">
                            </div>
                            
                            <div class="form-group">
                                <label>Housing Allowance</label>
                                <input type="number" name="housing_allowance" id="edit_housing_allowance" 
                                       value="<?php echo $edit_salary['housing_allowance']; ?>" step="0.01" required
                                       oninput="calculateEditNetSalary()">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Transport Allowance</label>
                                <input type="number" name="transport_allowance" id="edit_transport_allowance" 
                                       value="<?php echo $edit_salary['transport_allowance']; ?>" step="0.01" required
                                       oninput="calculateEditNetSalary()">
                            </div>
                            
                            <div class="form-group">
                                <label>Other Allowances</label>
                                <input type="number" name="other_allowances" id="edit_other_allowances" 
                                       value="<?php echo $edit_salary['other_allowances'] ?? 0; ?>" step="0.01" required
                                       oninput="calculateEditNetSalary()">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Tax Amount</label>
                                <input type="number" name="tax_amount" id="edit_tax_amount" 
                                       value="<?php echo $edit_salary['tax_amount']; ?>" step="0.01" required
                                       oninput="calculateEditNetSalary()">
                            </div>
                            
                            <div class="form-group">
                                <label>Pension Amount</label>
                                <input type="number" name="pension_amount" id="edit_pension_amount" 
                                       value="<?php echo $edit_salary['pension_amount']; ?>" step="0.01" required
                                       oninput="calculateEditNetSalary()">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Medical Aid Amount</label>
                                <input type="number" name="medical_aid_amount" id="edit_medical_aid_amount" 
                                       value="<?php echo $edit_salary['medical_aid_amount']; ?>" step="0.01" required
                                       oninput="calculateEditNetSalary()">
                            </div>
                            
                            <div class="form-group">
                                <label>Custom Deductions</label>
                                <input type="number" name="custom_deductions" id="edit_custom_deductions" 
                                       value="<?php echo $edit_salary['custom_deductions'] ?? 0; ?>" step="0.01" required
                                       oninput="calculateEditNetSalary()">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Effective From</label>
                                <input type="date" name="effective_from" value="<?php echo $edit_salary['effective_from']; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Net Salary</label>
                                <input type="number" name="net_salary" id="edit_net_salary" 
                                       value="<?php echo $edit_salary['net_salary']; ?>" step="0.01" readonly
                                       style="background: #f8f9fa; font-weight: bold; color: #27ae60;">
                            </div>
                        </div>
                        
                        <button type="submit" name="update_salary_structure" class="btn-submit">
                            <i class="fas fa-save"></i> Update Salary Structure
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        
        <!-- Main Employee List -->
        <?php else: ?>
        
        <!-- Add Employee Form (Hidden by default) -->
        <div class="card" id="formCard" style="display: none;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>
                    <i class="fas fa-user-plus"></i> Add New Employee with Salary Structure
                </h2>
                <button class="btn-close-form" onclick="toggleForm()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
            
            <div class="form-container show" id="employeeForm">
                <form method="POST" action="" onsubmit="return validateForm()">
                    <!-- Basic Information Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-id-card"></i> Basic Information</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Employee ID <span style="color: #e74c3c;">*</span></label>
                                <input type="text" name="employee_id" placeholder="e.g., EMP001" required 
                                       pattern="[A-Za-z0-9-]+" title="Only letters, numbers, and hyphens allowed">
                            </div>
                            
                            <div class="form-group">
                                <label>Hire Date <span style="color: #e74c3c;">*</span></label>
                                <input type="date" name="hire_date" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>First Name <span style="color: #e74c3c;">*</span></label>
                                <input type="text" name="first_name" placeholder="Enter first name" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Last Name <span style="color: #e74c3c;">*</span></label>
                                <input type="text" name="last_name" placeholder="Enter last name" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Email <span style="color: #e74c3c;">*</span></label>
                                <input type="email" name="email" placeholder="employee@company.com" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" name="phone" placeholder="(123) 456-7890">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Position <span style="color: #e74c3c;">*</span></label>
                                <input type="text" name="position" placeholder="e.g., Software Developer" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Pay Type <span style="color: #e74c3c;">*</span></label>
                                <select name="pay_type" id="pay_type" required onchange="togglePayTypeFields()">
                                    <option value="hourly" selected>Hourly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row" id="hourly_rate_row">
                            <div class="form-group">
                                <label>Hourly Rate (LSL) <span style="color: #e74c3c;">*</span></label>
                                <input type="number" step="0.01" name="hourly_rate" id="hourly_rate" placeholder="25.00" min="0.01" value="25.00">
                            </div>
                        </div>
                        
                        <div class="form-row" id="monthly_salary_row" style="display: none;">
                            <div class="form-group">
                                <label>Monthly Salary (LSL) <span style="color: #e74c3c;">*</span></label>
                                <input type="number" step="0.01" name="monthly_salary" id="monthly_salary" placeholder="3000.00" min="0.01" value="3000.00">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="on-leave">On Leave</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Salary Structure Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-calculator"></i> Salary Structure (Applies to both pay types)</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Base Total Salary (LSL) <span style="color: #e74c3c;">*</span></label>
                                <input type="number" name="base_salary" id="base_salary" step="0.01" min="0" required
                                       value="3000" oninput="calculateSalary()">
                                <small>Enter the base salary before component calculations</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Effective From <span style="color: #e74c3c;">*</span></label>
                                <input type="date" name="effective_from" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Basic Salary %</label>
                                <input type="number" name="basic_percentage" id="basic_percentage" 
                                       step="0.01" min="0" max="100" 
                                       value="<?php echo $configs['basic_percentage']['config_value'] ?? 70; ?>"
                                       class="salary-component" oninput="calculateSalary()">
                            </div>
                            
                            <div class="form-group">
                                <label>Housing Allowance %</label>
                                <input type="number" name="housing_percentage" id="housing_percentage" 
                                       step="0.01" min="0" max="100" 
                                       value="<?php echo $configs['housing_percentage']['config_value'] ?? 20; ?>"
                                       class="salary-component" oninput="calculateSalary()">
                            </div>
                            
                            <div class="form-group">
                                <label>Transport Allowance (LSL)</label>
                                <input type="number" name="transport_fixed" id="transport_fixed" 
                                       step="0.01" min="0" 
                                       value="<?php echo $configs['transport_fixed']['config_value'] ?? 500; ?>"
                                       class="salary-component" oninput="calculateSalary()">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Tax Rate %</label>
                                <input type="number" name="tax_rate" id="tax_rate" 
                                       step="0.01" min="0" max="100" 
                                       value="<?php echo $configs['tax_rate']['config_value'] ?? 15; ?>"
                                       class="salary-component" oninput="calculateSalary()">
                            </div>
                            
                            <div class="form-group">
                                <label>Pension Rate %</label>
                                <input type="number" name="pension_rate" id="pension_rate" 
                                       step="0.01" min="0" max="100" 
                                       value="<?php echo $configs['pension_rate']['config_value'] ?? 7.5; ?>"
                                       class="salary-component" oninput="calculateSalary()">
                            </div>
                            
                            <div class="form-group">
                                <label>Medical Aid Rate %</label>
                                <input type="number" name="medical_aid_rate" id="medical_aid_rate" 
                                       step="0.01" min="0" max="100" 
                                       value="<?php echo $configs['medical_aid_rate']['config_value'] ?? 5; ?>"
                                       class="salary-component" oninput="calculateSalary()">
                            </div>
                        </div>
                        
                        <!-- Salary Preview -->
                        <div class="salary-preview">
                            <h4><i class="fas fa-chart-line"></i> Salary Calculation Preview</h4>
                            <table class="preview-table">
                                <tr>
                                    <td>Basic Salary:</td>
                                    <td>LSL<span id="preview_basic">0.00</span></td>
                                </tr>
                                <tr>
                                    <td>Housing Allowance:</td>
                                    <td>LSL<span id="preview_housing">0.00</span></td>
                                </tr>
                                <tr>
                                    <td>Transport Allowance:</td>
                                    <td>LSL<span id="preview_transport">0.00</span></td>
                                </tr>
                                <tr style="font-weight: bold; color: #ffd700;">
                                    <td>Gross Salary:</td>
                                    <td>LSL<span id="preview_gross">0.00</span></td>
                                </tr>
                                <tr>
                                    <td>Tax:</td>
                                    <td>-LSL<span id="preview_tax">0.00</span></td>
                                </tr>
                                <tr>
                                    <td>Pension:</td>
                                    <td>-LSL<span id="preview_pension">0.00</span></td>
                                </tr>
                                <tr>
                                    <td>Medical Aid:</td>
                                    <td>-LSL<span id="preview_medical">0.00</span></td>
                                </tr>
                                <tr>
                                    <td>Custom Deductions:</td>
                                    <td>-LSL<span id="preview_custom">0.00</span></td>
                                </tr>
                                <tr class="preview-total">
                                    <td>Net Salary:</td>
                                    <td>LSL<span id="preview_net">0.00</span></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_employee" class="btn-submit">
                        <i class="fas fa-save"></i> Save Employee with Salary Structure
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <?php
        // Get status counts
        $active_count = 0;
        $inactive_count = 0;
        $onleave_count = 0;
        
        if ($status_result && $status_result->num_rows > 0) {
            while($row = $status_result->fetch_assoc()) {
                if ($row['status'] == 'active') $active_count = $row['count'];
                if ($row['status'] == 'inactive') $inactive_count = $row['count'];
                if ($row['status'] == 'on-leave') $onleave_count = $row['count'];
            }
        }
        ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background: #e8f4fd; color: #3498db;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3>Total Employees</h3>
                    <p><?php echo $total_employees; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #e8f8f0; color: #27ae60;">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-info">
                    <h3>Active</h3>
                    <p><?php echo $active_count; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #fde9e9; color: #e74c3c;">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-info">
                    <h3>Inactive</h3>
                    <p><?php echo $inactive_count; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #fef5e7; color: #f39c12;">
                    <i class="fas fa-umbrella-beach"></i>
                </div>
                <div class="stat-info">
                    <h3>On Leave</h3>
                    <p><?php echo $onleave_count; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #e8f4fd; color: #9b59b6;">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3>Hourly</h3>
                    <p><?php echo $hourly_count; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #fff3e0; color: #f57c00;">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-info">
                    <h3>Monthly</h3>
                    <p><?php echo $monthly_count; ?></p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: #f3e5f5; color: #9b59b6;">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <h3>Avg. Salary</h3>
                    <p>LSL<?php echo number_format($salary_stats['avg_salary'] ?? 0, 2); ?></p>
                    <small>Min: LSL<?php echo number_format($salary_stats['min_salary'] ?? 0, 2); ?> | Max: LSL<?php echo number_format($salary_stats['max_salary'] ?? 0, 2); ?></small>
                </div>
            </div>
        </div>
        
        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Status Distribution Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Employee Status Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            
            <!-- Pay Type Distribution Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Pay Type Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="paytypeChart"></canvas>
                </div>
            </div>
            
            <!-- Department Distribution Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Department Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
            
            <!-- Hiring Trend Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Hiring Trend (Last 6 Months)</h3>
                </div>
                <div class="chart-container">
                    <canvas id="hiringChart"></canvas>
                </div>
            </div>
            
            <!-- Top Positions Chart -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Top Positions</h3>
                </div>
                <div class="chart-container">
                    <canvas id="positionChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="card" style="margin-bottom: 20px;">
            <h2>
                <i class="fas fa-filter"></i> Filter Employees
            </h2>
            
            <div class="filters-container" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
                <div class="filter-group" style="flex: 2; min-width: 200px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; color: #666;">
                        <i class="fas fa-search"></i> Search
                    </label>
                    <input type="text" id="searchInput" placeholder="Search by name, ID, email, or position..." 
                           style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                </div>
                
                <div class="filter-group" style="flex: 1; min-width: 150px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; color: #666;">
                        <i class="fas fa-building"></i> Pay Type
                    </label>
                    <select id="payTypeFilter" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="">All Pay Types</option>
                        <option value="hourly">Hourly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                
                <div class="filter-group" style="flex: 1; min-width: 150px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; color: #666;">
                        <i class="fas fa-tag"></i> Status
                    </label>
                    <select id="statusFilter" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="on-leave">On Leave</option>
                    </select>
                </div>
                
                <div class="filter-group" style="flex: 1; min-width: 150px;">
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; color: #666;">
                        <i class="fas fa-chart-line"></i> Sort By
                    </label>
                    <select id="sortBy" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                        <option value="id_desc">Newest First</option>
                        <option value="id_asc">Oldest First</option>
                        <option value="name_asc">Name (A-Z)</option>
                        <option value="name_desc">Name (Z-A)</option>
                        <option value="salary_high">Salary (High to Low)</option>
                        <option value="salary_low">Salary (Low to High)</option>
                        <option value="employee_id">Employee ID</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <button id="resetFilters" class="btn btn-secondary" style="padding: 10px 20px;">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </div>
            
            <!-- Dynamic filter stats -->
            <div id="filterStats" style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 6px; font-size: 13px; color: #666;">
                <i class="fas fa-chart-line"></i> Showing <span id="visibleCount">0</span> of <span id="totalCount">0</span> employees
            </div>
        </div>

        <!-- Employee List -->
        <div class="card">
            <h2>
                <i class="fas fa-list"></i> Employee List
            </h2>
            
            <div class="table-container">
                <table class="table" id="employeeTable">
                    <thead>
                        <tr>
                            <th>Employee ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Position</th>
                            <th>Pay Type</th>
                            <th>Rate/Salary</th>
                            <th>Net Salary</th>
                            <th>Hire Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="employeeTableBody">
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php 
                            $result->data_seek(0);
                            while($row = $result->fetch_assoc()): 
                                $pay_type = $row['pay_type'] ?? 'hourly';
                                $rate_display = $pay_type == 'hourly' ? 
                                    'LSL' . number_format($row['hourly_rate'], 2) . '/hr' : 
                                    'LSL' . number_format($row['monthly_salary'], 2) . '/mo';
                                $net_salary = $row['net_salary'] ?? 0;
                                $status_class = '';
                                if ($row['status'] == 'active') $status_class = 'status-active';
                                if ($row['status'] == 'inactive') $status_class = 'status-inactive';
                                if ($row['status'] == 'on-leave') $status_class = 'status-on-leave';
                            ?>
                            <tr class="employee-row" 
                                data-id="<?php echo $row['id']; ?>"
                                data-employee-id="<?php echo htmlspecialchars($row['employee_id']); ?>"
                                data-name="<?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>"
                                data-email="<?php echo htmlspecialchars($row['email']); ?>"
                                data-position="<?php echo htmlspecialchars($row['position']); ?>"
                                data-pay-type="<?php echo $pay_type; ?>"
                                data-status="<?php echo $row['status']; ?>"
                                data-net-salary="<?php echo $net_salary; ?>"
                                data-hire-date="<?php echo $row['hire_date']; ?>">
                                <td><strong><?php echo htmlspecialchars($row['employee_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><?php echo htmlspecialchars($row['position']); ?></td>
                                <td>
                                    <span class="pay-type-badge pay-type-<?php echo $pay_type; ?>">
                                        <?php echo ucfirst($pay_type); ?>
                                    </span>
                                </td>
                                <td><?php echo $rate_display; ?></td>
                                <td><strong>LSL<?php echo number_format($net_salary, 2); ?></strong></td>
                                <td><?php echo date('M d, Y', strtotime($row['hire_date'])); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?view=<?php echo $row['id']; ?>" class="btn btn-view btn-sm" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="?edit=<?php echo $row['id']; ?>" class="btn btn-edit btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button class="btn btn-delete btn-sm" title="Delete" 
                                                onclick="showDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="empty-state">
                                    <i class="fas fa-users"></i>
                                    <p>No employees found. Click "Add Employee" to get started.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeDeleteModal()">&times;</span>
            <h3><i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> Confirm Delete</h3>
            <p style="margin: 20px 0;">Are you sure you want to delete <strong id="deleteEmployeeName"></strong>?</p>
            <p style="color: #e74c3c; font-size: 13px; margin-bottom: 20px;">
                <i class="fas fa-info-circle"></i> This action cannot be undone if the employee has no payroll records.
            </p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="employee_id" id="deleteEmployeeId">
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" name="delete_employee" class="btn btn-delete">Delete Employee</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle form visibility
        function toggleForm() {
            const formCard = document.getElementById('formCard');
            
            if (formCard.style.display === 'none') {
                formCard.style.display = 'block';
                formCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                setTimeout(calculateSalary, 100);
            } else {
                formCard.style.display = 'none';
            }
        }

        // Toggle pay type fields for add form
        function togglePayTypeFields() {
            const payType = document.getElementById('pay_type').value;
            const hourlyRow = document.getElementById('hourly_rate_row');
            const monthlyRow = document.getElementById('monthly_salary_row');
            const hourlyInput = document.getElementById('hourly_rate');
            const monthlyInput = document.getElementById('monthly_salary');
            
            if (payType === 'hourly') {
                hourlyRow.style.display = 'flex';
                monthlyRow.style.display = 'none';
                hourlyInput.required = true;
                monthlyInput.required = false;
            } else {
                hourlyRow.style.display = 'none';
                monthlyRow.style.display = 'flex';
                hourlyInput.required = false;
                monthlyInput.required = true;
            }
        }

        // Toggle pay type fields for edit form
        function toggleEditPayTypeFields() {
            const payType = document.getElementById('edit_pay_type').value;
            const hourlyRow = document.getElementById('edit_hourly_rate_row');
            const monthlyRow = document.getElementById('edit_monthly_salary_row');
            const hourlyInput = document.getElementById('edit_hourly_rate');
            const monthlyInput = document.getElementById('edit_monthly_salary');
            
            if (payType === 'hourly') {
                hourlyRow.style.display = 'flex';
                monthlyRow.style.display = 'none';
                hourlyInput.required = true;
                monthlyInput.required = false;
            } else {
                hourlyRow.style.display = 'none';
                monthlyRow.style.display = 'flex';
                hourlyInput.required = false;
                monthlyInput.required = true;
            }
        }

        // Calculate salary preview for new employee
        function calculateSalary() {
            const baseSalary = parseFloat(document.getElementById('base_salary').value) || 0;
            
            const basicPercent = parseFloat(document.getElementById('basic_percentage').value) || 70;
            const housingPercent = parseFloat(document.getElementById('housing_percentage').value) || 20;
            const transportFixed = parseFloat(document.getElementById('transport_fixed').value) || 500;
            const taxRate = parseFloat(document.getElementById('tax_rate').value) || 15;
            const pensionRate = parseFloat(document.getElementById('pension_rate').value) || 7.5;
            const medicalRate = parseFloat(document.getElementById('medical_aid_rate').value) || 5;
            
            const basicSalary = (baseSalary * basicPercent) / 100;
            const housingAllowance = (basicSalary * housingPercent) / 100;
            const transportAllowance = transportFixed;
            const grossSalary = basicSalary + housingAllowance + transportAllowance;
            
            const taxAmount = (grossSalary * taxRate) / 100;
            const pensionAmount = (grossSalary * pensionRate) / 100;
            const medicalAmount = (grossSalary * medicalRate) / 100;
            
            const netSalary = grossSalary - taxAmount - pensionAmount - medicalAmount;
            
            document.getElementById('preview_basic').textContent = basicSalary.toFixed(2);
            document.getElementById('preview_housing').textContent = housingAllowance.toFixed(2);
            document.getElementById('preview_transport').textContent = transportAllowance.toFixed(2);
            document.getElementById('preview_gross').textContent = grossSalary.toFixed(2);
            document.getElementById('preview_tax').textContent = taxAmount.toFixed(2);
            document.getElementById('preview_pension').textContent = pensionAmount.toFixed(2);
            document.getElementById('preview_medical').textContent = medicalAmount.toFixed(2);
            document.getElementById('preview_custom').textContent = '0.00';
            document.getElementById('preview_net').textContent = netSalary.toFixed(2);
        }

        // Calculate net salary for edit form
        function calculateEditNetSalary() {
            const basic = parseFloat(document.getElementById('edit_basic_salary').value) || 0;
            const housing = parseFloat(document.getElementById('edit_housing_allowance').value) || 0;
            const transport = parseFloat(document.getElementById('edit_transport_allowance').value) || 0;
            const other = parseFloat(document.getElementById('edit_other_allowances').value) || 0;
            const tax = parseFloat(document.getElementById('edit_tax_amount').value) || 0;
            const pension = parseFloat(document.getElementById('edit_pension_amount').value) || 0;
            const medical = parseFloat(document.getElementById('edit_medical_aid_amount').value) || 0;
            const custom = parseFloat(document.getElementById('edit_custom_deductions').value) || 0;
            
            const gross = basic + housing + transport + other;
            const net = gross - tax - pension - medical - custom;
            
            document.getElementById('edit_net_salary').value = net.toFixed(2);
        }

        // Validate form before submission
        function validateForm() {
            const baseSalary = parseFloat(document.getElementById('base_salary').value);
            if (baseSalary <= 0) {
                alert('Base salary must be greater than 0');
                return false;
            }
            
            const payType = document.getElementById('pay_type').value;
            if (payType === 'hourly') {
                const hourlyRate = parseFloat(document.getElementById('hourly_rate').value);
                if (!hourlyRate || hourlyRate <= 0) {
                    alert('Hourly rate is required for hourly employees');
                    return false;
                }
            } else {
                const monthlySalary = parseFloat(document.getElementById('monthly_salary').value);
                if (!monthlySalary || monthlySalary <= 0) {
                    alert('Monthly salary is required for monthly employees');
                    return false;
                }
            }
            
            return true;
        }

        // Delete modal functions
        function showDeleteModal(id, name) {
            document.getElementById('deleteEmployeeId').value = id;
            document.getElementById('deleteEmployeeName').textContent = name;
            document.getElementById('deleteModal').style.display = 'block';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.success, .error');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Inactive', 'On Leave'],
                datasets: [{
                    data: [<?php echo $active_count; ?>, <?php echo $inactive_count; ?>, <?php echo $onleave_count; ?>],
                    backgroundColor: ['#27ae60', '#e74c3c', '#f39c12'],
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

        // Pay Type Distribution Chart
        const paytypeCtx = document.getElementById('paytypeChart').getContext('2d');
        new Chart(paytypeCtx, {
            type: 'doughnut',
            data: {
                labels: ['Hourly', 'Monthly'],
                datasets: [{
                    data: [<?php echo $hourly_count; ?>, <?php echo $monthly_count; ?>],
                    backgroundColor: ['#1976d2', '#f57c00'],
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

        // Department Distribution Chart
        const deptCtx = document.getElementById('departmentChart').getContext('2d');
        new Chart(deptCtx, {
            type: 'bar',
            data: {
                labels: [<?php 
                    $dept_labels = [];
                    $dept_counts = [];
                    if ($dept_result && $dept_result->num_rows > 0) {
                        while($row = $dept_result->fetch_assoc()) {
                            $dept_labels[] = "'" . addslashes($row['department']) . "'";
                            $dept_counts[] = $row['count'];
                        }
                    } else {
                        $dept_labels[] = "'No Data'";
                        $dept_counts[] = 0;
                    }
                    echo implode(',', $dept_labels);
                ?>],
                datasets: [{
                    label: 'Number of Employees',
                    data: [<?php echo implode(',', $dept_counts); ?>],
                    backgroundColor: '#3498db',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });

        // Hiring Trend Chart
        const hiringCtx = document.getElementById('hiringChart').getContext('2d');
        new Chart(hiringCtx, {
            type: 'line',
            data: {
                labels: [<?php 
                    $months = [];
                    $hiring_counts = [];
                    if ($hiring_result && $hiring_result->num_rows > 0) {
                        while($row = $hiring_result->fetch_assoc()) {
                            $months[] = "'" . date('M Y', strtotime($row['month'] . '-01')) . "'";
                            $hiring_counts[] = $row['count'];
                        }
                    }
                    echo implode(',', $months);
                ?>],
                datasets: [{
                    label: 'New Hires',
                    data: [<?php echo implode(',', $hiring_counts); ?>],
                    borderColor: '#9b59b6',
                    backgroundColor: 'rgba(155, 89, 182, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#9b59b6',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });

        // Top Positions Chart
        const positionCtx = document.getElementById('positionChart').getContext('2d');
        new Chart(positionCtx, {
            type: 'pie',
            data: {
                labels: [<?php 
                    $position_labels = [];
                    $position_counts = [];
                    if ($position_result && $position_result->num_rows > 0) {
                        while($row = $position_result->fetch_assoc()) {
                            $position_labels[] = "'" . addslashes($row['position']) . "'";
                            $position_counts[] = $row['count'];
                        }
                    }
                    echo implode(',', $position_labels);
                ?>],
                datasets: [{
                    data: [<?php echo implode(',', $position_counts); ?>],
                    backgroundColor: ['#3498db', '#e74c3c', '#f39c12', '#27ae60', '#9b59b6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 10 } }
                    }
                }
            }
        });

        // Employee filtering functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const payTypeFilter = document.getElementById('payTypeFilter');
            const statusFilter = document.getElementById('statusFilter');
            const sortBy = document.getElementById('sortBy');
            const resetBtn = document.getElementById('resetFilters');
            const employeeRows = document.querySelectorAll('.employee-row');
            const totalCountSpan = document.getElementById('totalCount');
            const visibleCountSpan = document.getElementById('visibleCount');
            
            // Store all employee data for sorting
            let employees = Array.from(employeeRows);
            
            // Update total count
            if (totalCountSpan) {
                totalCountSpan.textContent = employees.length;
            }
            
            // Function to filter and display employees
            function filterEmployees() {
                const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
                const payType = payTypeFilter ? payTypeFilter.value : '';
                const status = statusFilter ? statusFilter.value : '';
                const sortValue = sortBy ? sortBy.value : 'id_desc';
                
                let visibleRows = employees.filter(row => {
                    // Get data attributes
                    const employeeId = row.getAttribute('data-employee-id')?.toLowerCase() || '';
                    const name = row.getAttribute('data-name')?.toLowerCase() || '';
                    const email = row.getAttribute('data-email')?.toLowerCase() || '';
                    const position = row.getAttribute('data-position')?.toLowerCase() || '';
                    const rowPayType = row.getAttribute('data-pay-type') || '';
                    const rowStatus = row.getAttribute('data-status') || '';
                    
                    // Search filter (checks multiple fields)
                    const matchesSearch = searchTerm === '' || 
                        employeeId.includes(searchTerm) ||
                        name.includes(searchTerm) ||
                        email.includes(searchTerm) ||
                        position.includes(searchTerm);
                    
                    // Pay type filter
                    const matchesPayType = payType === '' || rowPayType === payType;
                    
                    // Status filter
                    const matchesStatus = status === '' || rowStatus === status;
                    
                    return matchesSearch && matchesPayType && matchesStatus;
                });
                
                // Apply sorting
                visibleRows = sortEmployees(visibleRows, sortValue);
                
                // Update display
                const tbody = document.getElementById('employeeTableBody');
                if (tbody) {
                    tbody.innerHTML = '';
                    if (visibleRows.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="10" class="empty-state">
                                    <i class="fas fa-search"></i>
                                    <p>No employees match your filters.</p>
                                    <button class="btn btn-primary" onclick="document.getElementById('resetFilters').click()" style="margin-top: 10px;">
                                        <i class="fas fa-undo"></i> Clear Filters
                                    </button>
                                <\/td>
                            </tr>
                        `;
                    } else {
                        visibleRows.forEach(row => {
                            tbody.appendChild(row.cloneNode(true));
                        });
                        // Re-attach delete modal functionality to new buttons
                        attachDeleteHandlers();
                    }
                }
                
                // Update filter stats
                if (visibleCountSpan) {
                    visibleCountSpan.textContent = visibleRows.length;
                }
            }
            
            // Function to sort employees
            function sortEmployees(rows, sortValue) {
                const sortedRows = [...rows];
                
                sortedRows.sort((a, b) => {
                    switch(sortValue) {
                        case 'name_asc':
                            return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                        case 'name_desc':
                            return b.getAttribute('data-name').localeCompare(a.getAttribute('data-name'));
                        case 'employee_id':
                            return a.getAttribute('data-employee-id').localeCompare(b.getAttribute('data-employee-id'));
                        case 'salary_high':
                            return parseFloat(b.getAttribute('data-net-salary')) - parseFloat(a.getAttribute('data-net-salary'));
                        case 'salary_low':
                            return parseFloat(a.getAttribute('data-net-salary')) - parseFloat(b.getAttribute('data-net-salary'));
                        case 'id_asc':
                            return parseInt(a.getAttribute('data-id')) - parseInt(b.getAttribute('data-id'));
                        case 'id_desc':
                        default:
                            return parseInt(b.getAttribute('data-id')) - parseInt(a.getAttribute('data-id'));
                    }
                });
                
                return sortedRows;
            }
            
            // Function to reset all filters
            function resetFilters() {
                if (searchInput) searchInput.value = '';
                if (payTypeFilter) payTypeFilter.value = '';
                if (statusFilter) statusFilter.value = '';
                if (sortBy) sortBy.value = 'id_desc';
                filterEmployees();
            }
            
            // Function to attach delete handlers to buttons
            function attachDeleteHandlers() {
                const deleteButtons = document.querySelectorAll('.btn-delete');
                deleteButtons.forEach(btn => {
                    btn.onclick = function() {
                        const row = this.closest('tr');
                        const id = row ? row.getAttribute('data-id') : '';
                        const name = row ? row.querySelector('td:nth-child(2)').textContent : '';
                        if (id && name) {
                            showDeleteModal(id, name);
                        }
                    };
                });
            }
            
            // Add event listeners
            if (searchInput) searchInput.addEventListener('input', filterEmployees);
            if (payTypeFilter) payTypeFilter.addEventListener('change', filterEmployees);
            if (statusFilter) statusFilter.addEventListener('change', filterEmployees);
            if (sortBy) sortBy.addEventListener('change', filterEmployees);
            if (resetBtn) resetBtn.addEventListener('click', resetFilters);
            
            // Initial filter to show all
            filterEmployees();
        });
        
        // Add a quick filter by status buttons
        function addQuickFilters() {
            const statsGrid = document.querySelector('.stats-grid');
            if (statsGrid && !document.getElementById('quickFilters')) {
                const quickFiltersDiv = document.createElement('div');
                quickFiltersDiv.id = 'quickFilters';
                quickFiltersDiv.style.cssText = 'margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap;';
                quickFiltersDiv.innerHTML = `
                    <button class="btn btn-secondary quick-filter" data-status="">
                        <i class="fas fa-users"></i> All
                    </button>
                    <button class="btn btn-primary quick-filter" data-status="active" style="background: #27ae60;">
                        <i class="fas fa-user-check"></i> Active
                    </button>
                    <button class="btn btn-primary quick-filter" data-status="inactive" style="background: #e74c3c;">
                        <i class="fas fa-user-times"></i> Inactive
                    </button>
                    <button class="btn btn-primary quick-filter" data-status="on-leave" style="background: #f39c12;">
                        <i class="fas fa-umbrella-beach"></i> On Leave
                    </button>
                    <button class="btn btn-primary quick-filter" data-pay-type="hourly" style="background: #3498db;">
                        <i class="fas fa-clock"></i> Hourly
                    </button>
                    <button class="btn btn-primary quick-filter" data-pay-type="monthly" style="background: #9b59b6;">
                        <i class="fas fa-calendar-alt"></i> Monthly
                    </button>
                `;
                
                // Insert after stats grid
                statsGrid.parentNode.insertBefore(quickFiltersDiv, statsGrid.nextSibling);
                
                // Add event listeners to quick filters
                document.querySelectorAll('.quick-filter').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const status = this.getAttribute('data-status');
                        const payType = this.getAttribute('data-pay-type');
                        
                        if (status) {
                            document.getElementById('statusFilter').value = status;
                            document.getElementById('payTypeFilter').value = '';
                        } else if (payType) {
                            document.getElementById('payTypeFilter').value = payType;
                            document.getElementById('statusFilter').value = '';
                        } else {
                            document.getElementById('statusFilter').value = '';
                            document.getElementById('payTypeFilter').value = '';
                        }
                        document.getElementById('searchInput').value = '';
                        
                        // Trigger filter
                        const event = new Event('change');
                        document.getElementById('statusFilter').dispatchEvent(event);
                        document.getElementById('payTypeFilter').dispatchEvent(event);
                    });
                });
            }
        }
        
        // Call quick filters after page loads
        setTimeout(addQuickFilters, 500);
    </script>
</body>
</html>