<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch($action) {
        case 'generate_bulk':
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            
            // Get all active employees
            $emp_sql = "SELECT id FROM employees WHERE status = 'active'";
            $emp_result = $conn->query($emp_sql);
            
            $payroll_ids = [];
            while ($employee = $emp_result->fetch_assoc()) {
                // Calculate payroll
                $payroll_data = calculatePayroll($employee['id'], $start_date, $end_date, $conn);
                
                // Insert into database
                $insert_sql = "INSERT INTO payroll (
                    employee_id, pay_period_start, pay_period_end,
                    regular_hours, overtime_hours, regular_pay, overtime_pay,
                    gross_pay, federal_tax, state_tax, social_security,
                    medicare, health_insurance, retirement_401k,
                    total_deductions, net_pay, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->bind_param(
                    "issddddddddddddd",
                    $employee['id'],
                    $start_date,
                    $end_date,
                    $payroll_data['regular_hours'],
                    $payroll_data['overtime_hours'],
                    $payroll_data['regular_pay'],
                    $payroll_data['overtime_pay'],
                    $payroll_data['gross_pay'],
                    $payroll_data['federal_tax'],
                    $payroll_data['state_tax'],
                    $payroll_data['social_security'],
                    $payroll_data['medicare'],
                    $payroll_data['health_insurance'],
                    $payroll_data['retirement_401k'],
                    $payroll_data['total_deductions'],
                    $payroll_data['net_pay']
                );
                
                if ($stmt->execute()) {
                    $payroll_ids[] = $conn->insert_id;
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Payroll generated successfully',
                'payroll_ids' => $payroll_ids
            ]);
            break;
            
        case 'process_payment':
            $payroll_id = $_POST['payroll_id'];
            $payment_method = $_POST['payment_method'];
            
            $sql = "UPDATE payroll SET 
                    status = 'paid',
                    payment_method = ?,
                    payment_date = CURDATE()
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $payment_method, $payroll_id);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Payment processed successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Error processing payment'
                ]);
            }
            break;
            
        case 'delete_payroll':
            $payroll_id = $_POST['payroll_id'];
            
            $sql = "DELETE FROM payroll WHERE id = ? AND status = 'draft'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $payroll_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Payroll deleted successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot delete processed payroll'
                ]);
            }
            break;
    }
}