<?php
// salary_config.php
session_start();
require_once 'config.php';
require_once 'auth_check.php';

// Only allow admin and hr to access
checkRole(['admin', 'hr', 'manager']);

// Handle configuration updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_config'])) {
        foreach ($_POST['config'] as $key => $value) {
            $update_sql = "UPDATE salary_config SET config_value = ? WHERE config_key = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ds", $value, $key);
            $update_stmt->execute();
        }
        $success = "Salary configuration updated successfully!";
    }
    
    if (isset($_POST['add_custom_deduction'])) {
        $name = $_POST['deduction_name'];
        $type = $_POST['deduction_type'];
        $mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
        $description = $_POST['description'];
        
        $insert_sql = "INSERT INTO custom_deductions (deduction_name, deduction_type, is_mandatory, description) 
                      VALUES (?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("ssis", $name, $type, $mandatory, $description);
        
        if ($insert_stmt->execute()) {
            $success = "Custom deduction added successfully!";
        } else {
            $error = "Error adding custom deduction: " . $conn->error;
        }
    }
    
    if (isset($_POST['update_deduction'])) {
        $id = $_POST['deduction_id'];
        $name = $_POST['deduction_name'];
        $type = $_POST['deduction_type'];
        $mandatory = isset($_POST['is_mandatory']) ? 1 : 0;
        $description = $_POST['description'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $update_sql = "UPDATE custom_deductions SET deduction_name = ?, deduction_type = ?, 
                      is_mandatory = ?, description = ?, is_active = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ssisii", $name, $type, $mandatory, $description, $is_active, $id);
        $update_stmt->execute();
        $success = "Custom deduction updated successfully!";
    }
    
    // Handle assigning deduction to employee
    if (isset($_POST['assign_deduction'])) {
        $employee_id = $_POST['employee_id'];
        $deduction_id = $_POST['deduction_id'];
        $amount = $_POST['amount'];
        $is_percentage = isset($_POST['is_percentage']) ? 1 : 0;
        
        // Check if already assigned
        $check_sql = "SELECT id FROM employee_custom_deductions 
                      WHERE employee_id = ? AND deduction_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $employee_id, $deduction_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing
            $update_sql = "UPDATE employee_custom_deductions 
                          SET amount = ?, is_percentage = ? 
                          WHERE employee_id = ? AND deduction_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("diii", $amount, $is_percentage, $employee_id, $deduction_id);
            $update_stmt->execute();
            $success = "Deduction assignment updated successfully!";
        } else {
            // Insert new
            $insert_sql = "INSERT INTO employee_custom_deductions (employee_id, deduction_id, amount, is_percentage) 
                          VALUES (?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iidi", $employee_id, $deduction_id, $amount, $is_percentage);
            $insert_stmt->execute();
            $success = "Deduction assigned to employee successfully!";
        }
    }
    
    // Handle remove deduction from employee
    if (isset($_POST['remove_employee_deduction'])) {
        $id = $_POST['assignment_id'];
        
        $delete_sql = "DELETE FROM employee_custom_deductions WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $id);
        $delete_stmt->execute();
        $success = "Deduction removed from employee successfully!";
    }
    
    // Handle update employee salary structure
    if (isset($_POST['update_employee_salary'])) {
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
        
        $update_sql = "UPDATE employee_salary_structure SET 
                      basic_salary = ?, housing_allowance = ?, transport_allowance = ?,
                      other_allowances = ?, tax_amount = ?, pension_amount = ?,
                      medical_aid_amount = ?, custom_deductions = ?, net_salary = ?
                      WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("dddddddddi", 
            $basic_salary, $housing_allowance, $transport_allowance,
            $other_allowances, $tax_amount, $pension_amount,
            $medical_aid_amount, $custom_deductions, $net_salary,
            $structure_id
        );
        $update_stmt->execute();
        $success = "Employee salary structure updated successfully!";
    }
}

// Fetch salary configuration
$config_sql = "SELECT * FROM salary_config WHERE is_active = 1 ORDER BY id";
$config_result = $conn->query($config_sql);

// Fetch custom deductions
$deductions_sql = "SELECT * FROM custom_deductions ORDER BY is_mandatory DESC, deduction_name";
$deductions_result = $conn->query($deductions_sql);

// Fetch all employees for dropdown
$employees_sql = "SELECT id, employee_id, first_name, last_name, position FROM employees WHERE status = 'active' ORDER BY first_name";
$employees_result = $conn->query($employees_sql);

// Fetch employee salary structures with details
$emp_salary_sql = "SELECT ess.*, e.employee_id, e.first_name, e.last_name, e.position, e.email
                   FROM employee_salary_structure ess
                   JOIN employees e ON ess.employee_id = e.id
                   WHERE ess.is_active = 1
                   ORDER BY e.first_name";
$emp_salary_result = $conn->query($emp_salary_sql);

// Fetch employee custom deductions
$emp_deductions_sql = "SELECT ecd.*, cd.deduction_name, cd.deduction_type, 
                      e.first_name, e.last_name, e.employee_id
                      FROM employee_custom_deductions ecd
                      JOIN custom_deductions cd ON ecd.deduction_id = cd.id
                      JOIN employees e ON ecd.employee_id = e.id
                      ORDER BY e.first_name, cd.deduction_name";
$emp_deductions_result = $conn->query($emp_deductions_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Configuration - Payroll System</title>
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
        
        .nav-links a:hover, .nav-links a.active {
            background: #667eea;
            color: white;
        }
        
        .btn {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-warning {
            background: #f39c12;
        }
        
        .btn-danger {
            background: #e74c3c;
        }
        
        .btn-info {
            background: #3498db;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h3 {
            margin: 20px 0 15px;
            color: #2c3e50;
            font-size: 18px;
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
        
        .config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .config-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .config-item label {
            display: block;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 10px;
        }
        
        .config-item input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .config-item small {
            display: block;
            color: #7f8c8d;
            margin-top: 5px;
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
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #e0e0e0;
            font-size: 13px;
        }
        
        .table td {
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
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
            background: #fef5e7;
            color: #f39c12;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #3498db;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #e74c3c;
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
            overflow-y: auto;
        }
        
        .modal-content {
            background: white;
            width: 90%;
            max-width: 600px;
            margin: 50px auto;
            padding: 25px;
            border-radius: 10px;
            position: relative;
        }
        
        .modal-lg {
            max-width: 800px;
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
        
        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 20px;
        }
        
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
        }
        
        .tab.active {
            border-bottom-color: #667eea;
            color: #667eea;
            font-weight: 600;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 12px;
            opacity: 0.9;
        }
        
        @media (max-width: 768px) {
            .grid-2, .grid-3 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Salary Configuration</h1>
            <p>Configure global salary components and manage employee-specific deductions</p>
        </div>
        
        <div class="nav">
            <div class="nav-links">
                <a href="dashboard.php"><i class="fas fa-dashboard"></i> Dashboard</a>
                <a href="employees.php"><i class="fas fa-users"></i> Employees</a>
                <a href="attendance.php"><i class="fas fa-clock"></i> Attendance</a>
                <a href="payroll.php"><i class="fas fa-money-bill"></i> Payroll</a>
                <a href="salary_config.php" class="active"><i class="fas fa-cog"></i> Salary Config</a>
            </div>
            <div>
                <button class="btn btn-success" onclick="openModal('addDeductionModal')">
                    <i class="fas fa-plus"></i> Add Custom Deduction
                </button>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Summary Cards -->
        <div class="summary-card">
            <div class="summary-stats">
                <?php
                // Get counts for summary
                $total_configs = $config_result->num_rows;
                $total_deductions = $deductions_result->num_rows;
                $total_employees_with_salary = $emp_salary_result->num_rows;
                ?>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $total_configs; ?></div>
                    <div class="stat-label">Salary Components</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $total_deductions; ?></div>
                    <div class="stat-label">Custom Deductions</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $total_employees_with_salary; ?></div>
                    <div class="stat-label">Employees with Salary Structure</div>
                </div>
            </div>
        </div>
        
        <!-- Tabs Navigation -->
        <div class="tabs">
            <div class="tab active" onclick="showTab('global-config')">Global Configuration</div>
            <div class="tab" onclick="showTab('custom-deductions')">Custom Deductions</div>
            <div class="tab" onclick="showTab('employee-deductions')">Employee Deductions</div>
            <div class="tab" onclick="showTab('salary-structures')">Salary Structures</div>
        </div>
        
        <!-- Global Configuration Tab -->
        <div id="global-config" class="tab-content active">
            <div class="card">
                <h2><i class="fas fa-sliders-h"></i> Global Salary Components</h2>
                <form method="POST">
                    <div class="config-grid">
                        <?php if ($config_result && $config_result->num_rows > 0): ?>
                            <?php 
                            $config_result->data_seek(0);
                            while($config = $config_result->fetch_assoc()): 
                            ?>
                                <div class="config-item">
                                    <label><?php echo htmlspecialchars($config['config_name']); ?></label>
                                    <input type="number" 
                                           name="config[<?php echo $config['config_key']; ?>]" 
                                           value="<?php echo $config['config_value']; ?>" 
                                           step="0.01" 
                                           min="0"
                                           max="<?php echo $config['config_type'] == 'percentage' ? '100' : '999999'; ?>">
                                    <small>
                                        Type: <?php echo ucfirst($config['config_type']); ?> | 
                                        <?php echo htmlspecialchars($config['description']); ?>
                                    </small>
                                </div>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top: 20px;">
                        <button type="submit" name="update_config" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Global Configuration
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Custom Deductions Tab -->
        <div id="custom-deductions" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-minus-circle"></i> Custom Deductions List</h2>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Mandatory</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($deductions_result && $deductions_result->num_rows > 0): ?>
                                <?php 
                                $deductions_result->data_seek(0);
                                while($deduction = $deductions_result->fetch_assoc()): 
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($deduction['deduction_name']); ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo ucfirst($deduction['deduction_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $deduction['is_active'] ? 'badge-success' : 'badge-warning'; ?>">
                                            <?php echo $deduction['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if($deduction['is_mandatory']): ?>
                                            <span class="badge badge-danger">Yes</span>
                                        <?php else: ?>
                                            <span class="badge badge-info">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($deduction['description'] ?? ''); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-info" onclick="editDeduction(<?php echo htmlspecialchars(json_encode($deduction)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px;">
                                        <i class="fas fa-info-circle fa-2x" style="color: #95a5a6;"></i>
                                        <p>No custom deductions found. Click "Add Custom Deduction" to create one.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Employee Deductions Tab -->
        <div id="employee-deductions" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-user-tag"></i> Employee-Specific Deductions</h2>
                
                <!-- Assign Deduction Form -->
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h3>Assign Deduction to Employee</h3>
                    <form method="POST" class="form-row">
                        <div class="form-group">
                            <label>Select Employee</label>
                            <select name="employee_id" required>
                                <option value="">Choose Employee...</option>
                                <?php 
                                if ($employees_result && $employees_result->num_rows > 0) {
                                    $employees_result->data_seek(0);
                                    while($emp = $employees_result->fetch_assoc()) {
                                        echo "<option value='{$emp['id']}'>" . htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $emp['employee_id'] . ')') . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Select Deduction</label>
                            <select name="deduction_id" required>
                                <option value="">Choose Deduction...</option>
                                <?php 
                                $deductions_result->data_seek(0);
                                while($ded = $deductions_result->fetch_assoc()) {
                                    echo "<option value='{$ded['id']}'>" . htmlspecialchars($ded['deduction_name'] . ' (' . $ded['deduction_type'] . ')') . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Amount/Percentage</label>
                            <input type="number" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="is_percentage"> Is Percentage?
                            </label>
                        </div>
                        <div class="form-group">
                            <button type="submit" name="assign_deduction" class="btn btn-success">
                                <i class="fas fa-plus"></i> Assign Deduction
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Employee Deductions List -->
                <h3>Current Employee Deductions</h3>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Deduction</th>
                                <th>Amount</th>
                                <th>Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($emp_deductions_result && $emp_deductions_result->num_rows > 0): ?>
                                <?php while($emp_ded = $emp_deductions_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($emp_ded['first_name'] . ' ' . $emp_ded['last_name']); ?></strong>
                                        <br><small><?php echo htmlspecialchars($emp_ded['employee_id']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($emp_ded['deduction_name']); ?></td>
                                    <td>
                                        <?php 
                                        if ($emp_ded['is_percentage']) {
                                            echo $emp_ded['amount'] . '%';
                                        } else {
                                            echo '$' . number_format($emp_ded['amount'], 2);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?php echo $emp_ded['is_percentage'] ? 'Percentage' : 'Fixed'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="assignment_id" value="<?php echo $emp_ded['id']; ?>">
                                            <button type="submit" name="remove_employee_deduction" class="btn btn-sm btn-danger" 
                                                    onclick="return confirm('Remove this deduction from employee?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px;">
                                        <i class="fas fa-info-circle fa-2x" style="color: #95a5a6;"></i>
                                        <p>No employee deductions assigned yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Salary Structures Tab -->
        <div id="salary-structures" class="tab-content">
            <div class="card">
                <h2><i class="fas fa-chart-pie"></i> Employee Salary Structures</h2>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Basic</th>
                                <th>Housing</th>
                                <th>Transport</th>
                                <th>Tax</th>
                                <th>Pension</th>
                                <th>Medical</th>
                                <th>Custom</th>
                                <th>Net</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($emp_salary_result && $emp_salary_result->num_rows > 0): ?>
                                <?php 
                                $emp_salary_result->data_seek(0);
                                while($emp_salary = $emp_salary_result->fetch_assoc()): 
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($emp_salary['first_name'] . ' ' . $emp_salary['last_name']); ?></strong>
                                        <br><small><?php echo htmlspecialchars($emp_salary['position']); ?></small>
                                    </td>
                                    <td>$<?php echo number_format($emp_salary['basic_salary'], 2); ?></td>
                                    <td>$<?php echo number_format($emp_salary['housing_allowance'], 2); ?></td>
                                    <td>$<?php echo number_format($emp_salary['transport_allowance'], 2); ?></td>
                                    <td>$<?php echo number_format($emp_salary['tax_amount'], 2); ?></td>
                                    <td>$<?php echo number_format($emp_salary['pension_amount'], 2); ?></td>
                                    <td>$<?php echo number_format($emp_salary['medical_aid_amount'], 2); ?></td>
                                    <td>$<?php echo number_format($emp_salary['custom_deductions'], 2); ?></td>
                                    <td><strong>$<?php echo number_format($emp_salary['net_salary'], 2); ?></strong></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="editSalaryStructure(<?php echo htmlspecialchars(json_encode($emp_salary)); ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 40px;">
                                        <i class="fas fa-info-circle fa-2x" style="color: #95a5a6;"></i>
                                        <p>No salary structures found. Add employees with salary details first.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Custom Deduction Modal -->
    <div id="addDeductionModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addDeductionModal')">&times;</span>
            <h2 style="margin-bottom: 20px;">Add Custom Deduction</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Deduction Name *</label>
                    <input type="text" name="deduction_name" required>
                </div>
                <div class="form-group">
                    <label>Deduction Type</label>
                    <select name="deduction_type">
                        <option value="fixed">Fixed Amount</option>
                        <option value="percentage">Percentage</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_mandatory"> Mandatory for all employees
                    </label>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="add_custom_deduction" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Deduction
                    </button>
                    <button type="button" class="btn btn-danger" onclick="closeModal('addDeductionModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Deduction Modal -->
    <div id="editDeductionModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editDeductionModal')">&times;</span>
            <h2 style="margin-bottom: 20px;">Edit Custom Deduction</h2>
            <form method="POST">
                <input type="hidden" name="deduction_id" id="edit_deduction_id">
                <div class="form-group">
                    <label>Deduction Name *</label>
                    <input type="text" name="deduction_name" id="edit_deduction_name" required>
                </div>
                <div class="form-group">
                    <label>Deduction Type</label>
                    <select name="deduction_type" id="edit_deduction_type">
                        <option value="fixed">Fixed Amount</option>
                        <option value="percentage">Percentage</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_mandatory" id="edit_is_mandatory"> Mandatory for all employees
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" id="edit_is_active" checked> Active
                    </label>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" rows="3"></textarea>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="update_deduction" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Deduction
                    </button>
                    <button type="button" class="btn btn-danger" onclick="closeModal('editDeductionModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Salary Structure Modal -->
    <div id="editSalaryModal" class="modal">
        <div class="modal-content modal-lg">
            <span class="close" onclick="closeModal('editSalaryModal')">&times;</span>
            <h2 style="margin-bottom: 20px;">Edit Employee Salary Structure</h2>
            <form method="POST" id="editSalaryForm">
                <input type="hidden" name="structure_id" id="edit_structure_id">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Basic Salary</label>
                        <input type="number" name="basic_salary" id="edit_basic_salary" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Housing Allowance</label>
                        <input type="number" name="housing_allowance" id="edit_housing_allowance" step="0.01" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Transport Allowance</label>
                        <input type="number" name="transport_allowance" id="edit_transport_allowance" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Other Allowances</label>
                        <input type="number" name="other_allowances" id="edit_other_allowances" step="0.01" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Tax Amount</label>
                        <input type="number" name="tax_amount" id="edit_tax_amount" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Pension Amount</label>
                        <input type="number" name="pension_amount" id="edit_pension_amount" step="0.01" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Medical Aid Amount</label>
                        <input type="number" name="medical_aid_amount" id="edit_medical_aid_amount" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Custom Deductions</label>
                        <input type="number" name="custom_deductions" id="edit_custom_deductions" step="0.01" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Net Salary</label>
                    <input type="number" name="net_salary" id="edit_net_salary" step="0.01" required readonly 
                           style="background: #f8f9fa; font-weight: bold;">
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="update_employee_salary" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Salary Structure
                    </button>
                    <button type="button" class="btn btn-danger" onclick="closeModal('editSalaryModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Tab switching
        function showTab(tabId) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        // Modal functions
        function openModal(id) {
            document.getElementById(id).style.display = 'block';
        }
        
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }
        
        // Edit deduction
        function editDeduction(deduction) {
            document.getElementById('edit_deduction_id').value = deduction.id;
            document.getElementById('edit_deduction_name').value = deduction.deduction_name;
            document.getElementById('edit_deduction_type').value = deduction.deduction_type;
            document.getElementById('edit_is_mandatory').checked = deduction.is_mandatory == 1;
            document.getElementById('edit_is_active').checked = deduction.is_active == 1;
            document.getElementById('edit_description').value = deduction.description || '';
            
            openModal('editDeductionModal');
        }
        
        // Edit salary structure
        function editSalaryStructure(structure) {
            document.getElementById('edit_structure_id').value = structure.id;
            document.getElementById('edit_basic_salary').value = structure.basic_salary;
            document.getElementById('edit_housing_allowance').value = structure.housing_allowance;
            document.getElementById('edit_transport_allowance').value = structure.transport_allowance;
            document.getElementById('edit_other_allowances').value = structure.other_allowances;
            document.getElementById('edit_tax_amount').value = structure.tax_amount;
            document.getElementById('edit_pension_amount').value = structure.pension_amount;
            document.getElementById('edit_medical_aid_amount').value = structure.medical_aid_amount;
            document.getElementById('edit_custom_deductions').value = structure.custom_deductions;
            
            calculateNetSalary();
            
            openModal('editSalaryModal');
        }
        
        // Calculate net salary
        function calculateNetSalary() {
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
        
        // Add event listeners for salary calculation
        document.getElementById('edit_basic_salary')?.addEventListener('input', calculateNetSalary);
        document.getElementById('edit_housing_allowance')?.addEventListener('input', calculateNetSalary);
        document.getElementById('edit_transport_allowance')?.addEventListener('input', calculateNetSalary);
        document.getElementById('edit_other_allowances')?.addEventListener('input', calculateNetSalary);
        document.getElementById('edit_tax_amount')?.addEventListener('input', calculateNetSalary);
        document.getElementById('edit_pension_amount')?.addEventListener('input', calculateNetSalary);
        document.getElementById('edit_medical_aid_amount')?.addEventListener('input', calculateNetSalary);
        document.getElementById('edit_custom_deductions')?.addEventListener('input', calculateNetSalary);
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
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