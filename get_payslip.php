<?php

require_once 'config.php';

if (!isset($_GET['id'])) {
    die('No payroll ID specified');
}

$id = intval($_GET['id']);

$sql = "SELECT p.*, e.employee_id, e.first_name, e.last_name, e.position, e.hourly_rate,
               d.name as department
        FROM payroll p 
        JOIN employees e ON p.employee_id = e.id 
        LEFT JOIN departments d ON e.department_id = d.id
        WHERE p.id = $id";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    die('Payroll record not found');
}

$payroll = $result->fetch_assoc();
?>

<div class="payslip">
    <div class="payslip-header">
        <h2>PAYSLIP</h2>
        <p>Payroll Management System</p>
    </div>
    
    <div class="payslip-details">
        <div class="payslip-section">
            <h3>Employee Details</h3>
            <div class="payslip-row">
                <span>Employee ID:</span>
                <strong><?php echo $payroll['employee_id']; ?></strong>
            </div>
            <div class="payslip-row">
                <span>Name:</span>
                <strong><?php echo $payroll['first_name'] . ' ' . $payroll['last_name']; ?></strong>
            </div>
            <div class="payslip-row">
                <span>Position:</span>
                <strong><?php echo $payroll['position']; ?></strong>
            </div>
            <div class="payslip-row">
                <span>Department:</span>
                <strong><?php echo $payroll['department'] ?? 'N/A'; ?></strong>
            </div>
        </div>
        
        <div class="payslip-section">
            <h3>Pay Period</h3>
            <div class="payslip-row">
                <span>Start Date:</span>
                <strong><?php echo date('M d, Y', strtotime($payroll['pay_period_start'])); ?></strong>
            </div>
            <div class="payslip-row">
                <span>End Date:</span>
                <strong><?php echo date('M d, Y', strtotime($payroll['pay_period_end'])); ?></strong>
            </div>
            <div class="payslip-row">
                <span>Payment Date:</span>
                <strong><?php echo $payroll['payment_date'] ? date('M d, Y', strtotime($payroll['payment_date'])) : 'Pending'; ?></strong>
            </div>
            <div class="payslip-row">
                <span>Status:</span>
                <strong><?php echo ucfirst($payroll['status']); ?></strong>
            </div>
        </div>
    </div>
    
    <div class="payslip-section">
        <h3>Earnings</h3>
        <div class="payslip-row">
            <span>Hourly Rate:</span>
            <strong>$<?php echo number_format($payroll['hourly_rate'], 2); ?></strong>
        </div>
        <div class="payslip-row">
            <span>Regular Hours:</span>
            <strong><?php echo number_format($payroll['regular_hours'], 1); ?> hrs</strong>
        </div>
        <div class="payslip-row">
            <span>Regular Pay:</span>
            <strong>$<?php echo number_format($payroll['regular_pay'], 2); ?></strong>
        </div>
        <div class="payslip-row">
            <span>Overtime Hours:</span>
            <strong><?php echo number_format($payroll['overtime_hours'], 1); ?> hrs</strong>
        </div>
        <div class="payslip-row">
            <span>Overtime Rate:</span>
            <strong>$<?php echo number_format($payroll['hourly_rate'] * 1.5, 2); ?>/hr</strong>
        </div>
        <div class="payslip-row">
            <span>Overtime Pay:</span>
            <strong>$<?php echo number_format($payroll['overtime_pay'], 2); ?></strong>
        </div>
        <div class="payslip-total payslip-row">
            <span>TOTAL PAY:</span>
            <strong>$<?php echo number_format($payroll['total_pay'], 2); ?></strong>
        </div>
    </div>
    
    <div class="payslip-footer">
        <p>This is a computer generated payslip. No signature required.</p>
        <p>Generated on: <?php echo date('M d, Y H:i:s'); ?></p>
    </div>
</div>