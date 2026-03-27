<?php
session_start();
require_once 'config.php';
require_once 'auth_check.php';

checkRole(['admin', 'manager', 'hr']);

$payroll_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$bulk_ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
$compact_mode = isset($_GET['compact']) ? true : false;

if ($payroll_id > 0) {
    $sql = "SELECT 
                p.*,
                e.employee_id as emp_code,
                e.first_name,
                e.last_name,
                e.position,
                e.hourly_rate,
                d.department_name as department,
                CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                ess.basic_salary as monthly_basic,
                ess.housing_allowance as monthly_housing,
                ess.transport_allowance as monthly_transport,
                ess.other_allowances,
                ess.tax_amount as monthly_tax,
                ess.pension_amount as monthly_pension,
                ess.medical_aid_amount as monthly_medical,
                ess.custom_deductions as monthly_custom,
                ess.net_salary as monthly_net
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN employee_salary_structure ess ON e.id = ess.employee_id 
                AND ess.is_active = 1 
                AND ess.effective_from <= p.pay_period_end
            WHERE p.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $payroll_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payslip = $result->fetch_assoc();
    
    if (!$payslip) {
        die("Payslip not found");
    }
    
    $custom_sql = "SELECT cd.deduction_name, ecd.amount, ecd.is_percentage, cd.deduction_type
                   FROM employee_custom_deductions ecd
                   JOIN custom_deductions cd ON ecd.deduction_id = cd.id
                   WHERE ecd.employee_id = ?";
    $custom_stmt = $conn->prepare($custom_sql);
    $custom_stmt->bind_param("i", $payslip['employee_id']);
    $custom_stmt->execute();
    $custom_result = $custom_stmt->get_result();
    $custom_deductions = [];
    while ($row = $custom_result->fetch_assoc()) {
        $custom_deductions[] = $row;
    }
    
    $company = [
        'name' => 'PayrollPro',
        'address' => '123 Business Ave, Suite 100',
        'city' => 'New York, NY 10001',
        'phone' => '(212) 555-0123',
        'email' => 'finance@payrollpro.com',
        'website' => 'www.payrollpro.com',
        'tax_id' => 'XX-XXXXXXX'
    ];
    
    $days_in_month = date('t', strtotime($payslip['pay_period_end']));
    $days_in_month = max(1, $days_in_month);
    $total_hours_worked = ($payslip['regular_hours'] ?? 0) + ($payslip['overtime_hours'] ?? 0);
    $days_worked = ceil($total_hours_worked / 8);
    $days_worked = max(0, $days_worked);
    $proportion = $days_in_month > 0 ? $days_worked / $days_in_month : 0;
    $proportion = min(1, max(0, $proportion));
}

if (!empty($bulk_ids)) {
    $ids = implode(',', array_map('intval', $bulk_ids));
    $sql = "SELECT 
                p.*,
                e.employee_id as emp_code,
                e.first_name,
                e.last_name,
                e.position,
                e.hourly_rate,
                d.department_name as department,
                CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                ess.basic_salary as monthly_basic,
                ess.housing_allowance as monthly_housing,
                ess.transport_allowance as monthly_transport,
                ess.other_allowances,
                ess.tax_amount as monthly_tax,
                ess.pension_amount as monthly_pension,
                ess.medical_aid_amount as monthly_medical,
                ess.custom_deductions as monthly_custom,
                ess.net_salary as monthly_net
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN employee_salary_structure ess ON e.id = ess.employee_id 
                AND ess.is_active = 1 
                AND ess.effective_from <= p.pay_period_end
            WHERE p.id IN ($ids)
            ORDER BY e.first_name, e.last_name";
    
    $result = $conn->query($sql);
    $payslips = [];
    while ($row = $result->fetch_assoc()) {
        $payslips[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - PayrollPro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #2c3e50;
            --accent: #3498db;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f0f2f5;
            padding: 20px;
            font-size: 10pt;
            line-height: 1.3;
        }

        /* Print Controls */
        .print-controls {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        .print-btn, .back-btn, .size-toggle {
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            margin: 0 5px;
            transition: all 0.2s;
        }

        .print-btn {
            background: var(--accent);
            color: white;
        }

        .back-btn {
            background: #95a5a6;
            color: white;
        }

        .size-toggle {
            background: #ecf0f1;
            color: var(--primary);
            border: 2px solid transparent;
        }

        .size-toggle.active {
            border-color: var(--accent);
            background: #e8f4fd;
        }

        .print-btn:hover { background: #2980b9; }
        .back-btn:hover { background: #7f8c8d; }

        /* Size Options */
        .size-options {
            margin-top: 10px;
            font-size: 11px;
            color: #7f8c8d;
        }

        /* Main Container - Optimized for small paper */
        .payslip-wrapper {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        /* Compact Mode (A5/Thermal size) */
        .payslip-wrapper.compact {
            max-width: 480px; /* A5 width approximation */
            font-size: 9pt;
        }

        .payslip-wrapper.compact .payslip-header {
            padding: 15px;
        }

        .payslip-wrapper.compact .company-logo h1 {
            font-size: 18px;
        }

        .payslip-wrapper.compact .payslip-title h2 {
            font-size: 24px;
        }

        .payslip-wrapper.compact .payslip-body {
            padding: 15px;
        }

        .payslip-wrapper.compact .details-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .payslip-wrapper.compact .summary-cards {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .payslip-wrapper.compact .earnings-table th,
        .payslip-wrapper.compact .earnings-table td {
            padding: 6px 8px;
            font-size: 9pt;
        }

        .payslip-wrapper.compact .net-pay-box {
            padding: 12px;
        }

        .payslip-wrapper.compact .net-pay-box .amount {
            font-size: 24px;
        }

        /* Header */
        .payslip-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: white;
            padding: 20px;
            position: relative;
        }

        .company-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .company-logo h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 1px;
            margin: 0;
        }

        .company-logo p {
            font-size: 9px;
            opacity: 0.9;
            margin: 2px 0 0;
            letter-spacing: 1px;
        }

        .payslip-title {
            text-align: right;
        }

        .payslip-title h2 {
            font-size: 28px;
            font-weight: 800;
            margin: 0;
            letter-spacing: 2px;
        }

        .payslip-title p {
            font-size: 11px;
            opacity: 0.9;
            margin: 2px 0 0;
        }

        /* Body */
        .payslip-body {
            padding: 20px;
        }

        /* Compact Info Bar */
        .info-bar {
            background: #f8f9fa;
            border-left: 3px solid var(--accent);
            padding: 10px 12px;
            margin-bottom: 15px;
            border-radius: 0 6px 6px 0;
            font-size: 9pt;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        .info-bar span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .info-bar i {
            color: var(--accent);
            font-size: 10px;
        }

        /* Employee Section */
        .employee-section {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
        }

        .section-title {
            font-size: 10px;
            font-weight: 700;
            color: var(--primary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .section-title i {
            color: var(--accent);
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 8px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .detail-value {
            font-size: 11px;
            font-weight: 700;
            color: var(--primary);
        }

        /* Period Badge */
        .period-badge {
            background: linear-gradient(135deg, #fff9e6 0%, #ffeeba 100%);
            border: 1px solid #ffeaa7;
            border-radius: 20px;
            padding: 6px 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 9pt;
            font-weight: 600;
            color: #856404;
            margin-bottom: 15px;
        }

        .period-badge i {
            font-size: 10px;
        }

        /* Table Styles */
        .earnings-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 9pt;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            overflow: hidden;
        }

        .earnings-table th {
            background: var(--primary);
            color: white;
            font-weight: 600;
            font-size: 8pt;
            padding: 8px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .earnings-table th:last-child {
            text-align: right;
        }

        .earnings-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .earnings-table tr:last-child td {
            border-bottom: none;
        }

        .earnings-table .desc-cell {
            font-weight: 600;
            color: var(--primary);
        }

        .earnings-table .desc-cell small {
            display: block;
            font-size: 8px;
            color: #7f8c8d;
            font-weight: 400;
            margin-top: 1px;
        }

        .earnings-table .amount-cell {
            text-align: right;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            white-space: nowrap;
        }

        .earnings-table .total-row {
            background: #e8f4fd;
            font-weight: 700;
        }

        .earnings-table .total-row td {
            border-top: 2px solid var(--accent);
            border-bottom: 2px solid var(--accent);
        }

        .earnings-table .deduction-row td {
            color: var(--danger);
        }

        .earnings-table .deduction-row .amount-cell::before {
            content: '-';
        }

        /* Compact Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .summary-card {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 10px;
            text-align: center;
            border: 1px solid #e9ecef;
        }

        .summary-card .label {
            font-size: 7px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }

        .summary-card .value {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary);
            font-family: 'Courier New', monospace;
        }

        .summary-card .value small {
            font-size: 8px;
            color: #7f8c8d;
            font-weight: 400;
        }

        /* Net Pay Box */
        .net-pay-box {
            background: linear-gradient(135deg, var(--success) 0%, #2ecc71 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 15px;
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
        }

        .net-pay-box .label {
            font-size: 10px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .net-pay-box .amount {
            font-size: 32px;
            font-weight: 800;
            font-family: 'Courier New', monospace;
            letter-spacing: -1px;
        }

        .net-pay-box .meta {
            font-size: 9px;
            opacity: 0.9;
            margin-top: 5px;
        }

        /* Status Bar */
        .status-bar {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 10px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 9pt;
            border: 1px solid #e9ecef;
        }

        .payment-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 8pt;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-paid {
            background: #d4edda;
            color: var(--success);
        }

        /* Footer */
        .payslip-footer {
            border-top: 1px dashed #bdc3c7;
            padding-top: 15px;
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 8pt;
            color: #7f8c8d;
            flex-wrap: wrap;
            gap: 10px;
        }

        .footer-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .footer-item i {
            color: var(--accent);
        }

        .qr-placeholder {
            width: 50px;
            height: 50px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #bdc3c7;
        }

        /* Bulk Mode */
        .bulk-payslip {
            page-break-after: always;
            margin-bottom: 20px;
        }

        .bulk-payslip:last-child {
            page-break-after: auto;
        }

        /* Error Message */
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            max-width: 400px;
            margin: 50px auto;
        }

        /* Print Styles - Optimized for small paper */
        @media print {
            @page {
                size: A5 portrait; /* Optimized for A5 */
                margin: 10mm;
            }

            body {
                background: white;
                padding: 0;
                font-size: 9pt;
            }

            .print-controls {
                display: none;
            }

            .payslip-wrapper {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
                margin: 0;
            }

            .payslip-wrapper.compact {
                max-width: 100%;
            }

            .payslip-header {
                background: var(--primary) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                padding: 15px;
            }

            .earnings-table th {
                background: var(--primary) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .net-pay-box {
                background: var(--success) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                box-shadow: none;
            }

            .total-row {
                background: #e8f4fd !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .period-badge {
                background: #fff9e6 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .summary-card {
                background: #f8f9fa !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .status-paid {
                background: #d4edda !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .status-pending {
                background: #fff3cd !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* Ensure page breaks work */
            .payslip-wrapper {
                page-break-inside: avoid;
            }

            .earnings-table {
                page-break-inside: auto;
            }

            .earnings-table tr {
                page-break-inside: avoid;
            }
        }

        /* Thermal Printer Optimizations */
        @media print and (max-width: 80mm) {
            @page {
                size: 80mm 297mm; /* Thermal roll */
                margin: 5mm;
            }

            body {
                font-size: 8pt;
            }

            .payslip-header {
                padding: 10px;
            }

            .company-logo h1 {
                font-size: 14px;
            }

            .payslip-title h2 {
                font-size: 18px;
            }

            .details-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .earnings-table {
                font-size: 8pt;
            }

            .earnings-table th,
            .earnings-table td {
                padding: 4px 6px;
            }
        }

        /* Mobile Responsive */
        @media screen and (max-width: 600px) {
            body {
                padding: 10px;
            }

            .details-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .payslip-footer {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- Print Controls -->
    <div class="print-controls no-print">
        <div>
            <a href="payroll.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button onclick="window.print()" class="print-btn">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
        <div class="size-options">
            <button onclick="toggleSize('auto')" class="size-toggle active" id="btn-auto">Auto (A4)</button>
            <button onclick="toggleSize('compact')" class="size-toggle" id="btn-compact">Compact (A5)</button>
            <button onclick="toggleSize('thermal')" class="size-toggle" id="btn-thermal">Thermal (80mm)</button>
            <span style="margin-left: 10px;">| Paper Size: <span id="current-size">Auto</span></span>
        </div>
        <div style="margin-top: 8px; font-size: 10px; color: #95a5a6;">
            <i class="fas fa-info-circle"></i> 
            Select "Compact" for A5/half-letter, "Thermal" for receipt printers
        </div>
    </div>

    <?php if (isset($payslip) && $payslip): 
        $monthly_basic = $payslip['monthly_basic'] ?? 0;
        $monthly_housing = $payslip['monthly_housing'] ?? 0;
        $monthly_transport = $payslip['monthly_transport'] ?? 0;
        $monthly_other = $payslip['other_allowances'] ?? 0;
        $monthly_tax = $payslip['monthly_tax'] ?? 0;
        $monthly_pension = $payslip['monthly_pension'] ?? 0;
        $monthly_medical = $payslip['monthly_medical'] ?? 0;
        
        $basic_pay = $monthly_basic * $proportion;
        $housing_pay = $monthly_housing * $proportion;
        $transport_pay = $monthly_transport * $proportion;
        $other_allowances = $monthly_other * $proportion;
        
        $tax_deduction = $monthly_tax * $proportion;
        $pension_deduction = $monthly_pension * $proportion;
        $medical_deduction = $monthly_medical * $proportion;
        
        $regular_pay = $payslip['regular_pay'] ?? 0;
        $overtime_pay = $payslip['overtime_pay'] ?? 0;
        
        $gross_pay = $basic_pay + $housing_pay + $transport_pay + $other_allowances + 
                     $regular_pay + $overtime_pay;
        
        $custom_deductions_total = 0;
        $custom_deductions_list = [];
        foreach ($custom_deductions as $custom) {
            if ($custom['is_percentage'] || $custom['deduction_type'] == 'percentage') {
                $amount = $gross_pay > 0 ? ($gross_pay * $custom['amount']) / 100 : 0;
            } else {
                $amount = $custom['amount'] * $proportion;
            }
            $custom_deductions_total += $amount;
            $custom_deductions_list[] = [
                'name' => $custom['deduction_name'],
                'amount' => $amount
            ];
        }
        
        $total_deductions = $tax_deduction + $pension_deduction + $medical_deduction + $custom_deductions_total;
        $net_pay = max(0, $gross_pay - $total_deductions);
        
        $total_hours = ($payslip['regular_hours'] ?? 0) + ($payslip['overtime_hours'] ?? 0);
        $overtime_rate = ($payslip['hourly_rate'] ?? 0) * 1.5;
        
        $tax_rate = $monthly_basic > 0 ? ($monthly_tax / $monthly_basic) * 100 : 0;
        $pension_rate = $monthly_basic > 0 ? ($monthly_pension / $monthly_basic) * 100 : 0;
        $medical_rate = $monthly_basic > 0 ? ($monthly_medical / $monthly_basic) * 100 : 0;
    ?>
        <!-- Single Payslip -->
        <div class="payslip-wrapper" id="payslip-container">
            <!-- Header -->
            <div class="payslip-header">
                <div class="company-info">
                    <div class="company-logo">
                        <h1>PayrollPro</h1>
                        <p>Salary Slip</p>
                    </div>
                    <div class="payslip-title">
                        <h2>PAYSLIP</h2>
                        <p>#<?php echo str_pad($payslip['id'] ?? 0, 6, '0', STR_PAD_LEFT); ?></p>
                    </div>
                </div>
            </div>

            <div class="payslip-body">
                <!-- Compact Info Bar -->
                <div class="info-bar">
                    <span><i class="fas fa-building"></i> <?php echo $company['name']; ?></span>
                    <span><i class="fas fa-phone"></i> <?php echo $company['phone']; ?></span>
                    <span><i class="fas fa-map-marker-alt"></i> <?php echo $company['city']; ?></span>
                </div>

                <!-- Employee Details -->
                <div class="employee-section">
                    <div class="section-title">
                        <i class="fas fa-user"></i> Employee Details
                    </div>
                    <div class="details-grid">
                        <div class="detail-item">
                            <span class="detail-label">ID</span>
                            <span class="detail-value"><?php echo htmlspecialchars($payslip['emp_code'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Name</span>
                            <span class="detail-value"><?php echo htmlspecialchars($payslip['employee_name'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Position</span>
                            <span class="detail-value"><?php echo htmlspecialchars($payslip['position'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Department</span>
                            <span class="detail-value"><?php echo htmlspecialchars($payslip['department'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Pay Period -->
                <div class="period-badge">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo isset($payslip['pay_period_start']) ? date('M d, Y', strtotime($payslip['pay_period_start'])) : 'N/A'; ?> - 
                    <?php echo isset($payslip['pay_period_end']) ? date('M d, Y', strtotime($payslip['pay_period_end'])) : 'N/A'; ?>
                    (<?php echo $days_worked; ?>/<?php echo $days_in_month; ?> days)
                </div>

                <!-- Earnings Table -->
                <table class="earnings-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="text-align: right;">Amount ($)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Earnings -->
                        <?php if ($basic_pay > 0): ?>
                        <tr>
                            <td class="desc-cell">
                                Basic Salary
                                <small>Monthly: $<?php echo number_format($monthly_basic, 2); ?></small>
                            </td>
                            <td class="amount-cell">$<?php echo number_format($basic_pay, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($housing_pay > 0): ?>
                        <tr>
                            <td class="desc-cell">
                                Housing
                                <small>Monthly: $<?php echo number_format($monthly_housing, 2); ?></small>
                            </td>
                            <td class="amount-cell">$<?php echo number_format($housing_pay, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($transport_pay > 0): ?>
                        <tr>
                            <td class="desc-cell">
                                Transport
                                <small>Monthly: $<?php echo number_format($monthly_transport, 2); ?></small>
                            </td>
                            <td class="amount-cell">$<?php echo number_format($transport_pay, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($other_allowances > 0): ?>
                        <tr>
                            <td class="desc-cell">Other Allowances</td>
                            <td class="amount-cell">$<?php echo number_format($other_allowances, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($regular_pay > 0): ?>
                        <tr>
                            <td class="desc-cell">
                                Regular Hours
                                <small><?php echo number_format($payslip['regular_hours'] ?? 0, 1); ?> hrs @ $<?php echo number_format($payslip['hourly_rate'] ?? 0, 2); ?></small>
                            </td>
                            <td class="amount-cell">$<?php echo number_format($regular_pay, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($overtime_pay > 0): ?>
                        <tr>
                            <td class="desc-cell">
                                Overtime
                                <small><?php echo number_format($payslip['overtime_hours'] ?? 0, 1); ?> hrs @ $<?php echo number_format($overtime_rate, 2); ?></small>
                            </td>
                            <td class="amount-cell">$<?php echo number_format($overtime_pay, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <!-- Gross -->
                        <tr class="total-row">
                            <td class="desc-cell">GROSS PAY</td>
                            <td class="amount-cell">$<?php echo number_format($gross_pay, 2); ?></td>
                        </tr>

                        <!-- Deductions -->
                        <?php if ($total_deductions > 0): ?>
                        <tr>
                            <td colspan="2" style="background: #fef5e7; padding: 4px 8px; font-size: 8px; font-weight: 700; color: var(--danger); text-transform: uppercase;">
                                Deductions
                            </td>
                        </tr>

                        <?php if ($tax_deduction > 0): ?>
                        <tr class="deduction-row">
                            <td class="desc-cell">
                                Tax (PAYE)
                                <small>Rate: <?php echo number_format($tax_rate, 1); ?>%</small>
                            </td>
                            <td class="amount-cell">$<?php echo number_format($tax_deduction, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($pension_deduction > 0): ?>
                        <tr class="deduction-row">
                            <td class="desc-cell">
                                Pension
                                <small>Rate: <?php echo number_format($pension_rate, 1); ?>%</small>
                            </td>
                            <td class="amount-cell">$<?php echo number_format($pension_deduction, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ($medical_deduction > 0): ?>
                        <tr class="deduction-row">
                            <td class="desc-cell">
                                Medical Aid
                                <small>Rate: <?php echo number_format($medical_rate, 1); ?>%</small>
                            </td>
                            <td class="amount-cell">$<?php echo number_format($medical_deduction, 2); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($custom_deductions_list as $custom): ?>
                            <?php if ($custom['amount'] > 0): ?>
                            <tr class="deduction-row">
                                <td class="desc-cell"><?php echo htmlspecialchars($custom['name']); ?></td>
                                <td class="amount-cell">$<?php echo number_format($custom['amount'], 2); ?></td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <tr class="total-row" style="background: #fde9e9;">
                            <td class="desc-cell">TOTAL DEDUCTIONS</td>
                            <td class="amount-cell" style="color: var(--danger);">-$<?php echo number_format($total_deductions, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Net Pay -->
                <div class="net-pay-box">
                    <div class="label">NET PAY</div>
                    <div class="amount">$<?php echo number_format($net_pay, 2); ?></div>
                    <div class="meta">
                        <?php echo $days_worked; ?> days × $<?php echo number_format($net_pay / max(1, $days_worked), 2); ?>/day
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="summary-cards">
                    <div class="summary-card">
                        <div class="label">Hourly Rate</div>
                        <div class="value">$<?php echo number_format($payslip['hourly_rate'] ?? 0, 2); ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="label">Hours Worked</div>
                        <div class="value"><?php echo number_format($total_hours, 1); ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="label">Gross</div>
                        <div class="value">$<?php echo number_format($gross_pay, 2); ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="label">Deducted</div>
                        <div class="value" style="color: var(--danger);">-$<?php echo number_format($total_deductions, 2); ?></div>
                    </div>
                </div>

                <!-- Status -->
                <div class="status-bar">
                    <div class="footer-item">
                        <i class="fas fa-info-circle"></i>
                        <span>Status:</span>
                        <span class="payment-status status-<?php echo $payslip['status'] ?? 'pending'; ?>">
                            <i class="fas <?php echo ($payslip['status'] ?? '') == 'paid' ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                            <?php echo strtoupper($payslip['status'] ?? 'PENDING'); ?>
                        </span>
                    </div>
                    <div class="footer-item">
                        <i class="fas fa-calendar-check"></i>
                        <span>Paid: <?php echo !empty($payslip['payment_date']) ? date('M d, Y', strtotime($payslip['payment_date'])) : 'Pending'; ?></span>
                    </div>
                </div>

                <!-- Footer -->
                <div class="payslip-footer">
                    <div class="footer-item">
                        <i class="fas fa-building"></i>
                        <span><?php echo $company['name']; ?> | Tax ID: <?php echo $company['tax_id']; ?></span>
                    </div>
                    <div class="footer-item">
                        <i class="fas fa-clock"></i>
                        <span>Generated: <?php echo date('M d, Y H:i'); ?></span>
                    </div>
                    <div class="qr-placeholder">
                        <i class="fas fa-qrcode"></i>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 10px; font-size: 7pt; color: #95a5a6;">
                    <i class="fas fa-lock"></i> This is an official computer-generated payslip. No signature required.
                </div>
            </div>
        </div>

    <?php elseif (!empty($payslips)): ?>
        <!-- Bulk Payslips -->
        <?php foreach ($payslips as $index => $payslip): 
            $days_in_month = date('t', strtotime($payslip['pay_period_end'] ?? date('Y-m-d')));
            $days_in_month = max(1, $days_in_month);
            $total_hours_worked = ($payslip['regular_hours'] ?? 0) + ($payslip['overtime_hours'] ?? 0);
            $days_worked = ceil($total_hours_worked / 8);
            $days_worked = max(0, $days_worked);
            $proportion = $days_in_month > 0 ? $days_worked / $days_in_month : 0;
            $proportion = min(1, max(0, $proportion));
            
            $monthly_basic = $payslip['monthly_basic'] ?? 0;
            $monthly_housing = $payslip['monthly_housing'] ?? 0;
            $monthly_transport = $payslip['monthly_transport'] ?? 0;
            
            $basic_pay = $monthly_basic * $proportion;
            $housing_pay = $monthly_housing * $proportion;
            $transport_pay = $monthly_transport * $proportion;
            
            $tax_deduction = ($payslip['monthly_tax'] ?? 0) * $proportion;
            $pension_deduction = ($payslip['monthly_pension'] ?? 0) * $proportion;
            $medical_deduction = ($payslip['monthly_medical'] ?? 0) * $proportion;
            
            $gross_pay = $basic_pay + $housing_pay + $transport_pay + 
                         ($payslip['regular_pay'] ?? 0) + ($payslip['overtime_pay'] ?? 0);
            $total_deductions = $tax_deduction + $pension_deduction + $medical_deduction;
            $net_pay = max(0, $gross_pay - $total_deductions);
            $total_hours = ($payslip['regular_hours'] ?? 0) + ($payslip['overtime_hours'] ?? 0);
        ?>
        <div class="bulk-payslip">
            <div class="payslip-wrapper compact">
                <div class="payslip-header">
                    <div class="company-info">
                        <div class="company-logo">
                            <h1>PayrollPro</h1>
                            <p>Salary Slip</p>
                        </div>
                        <div class="payslip-title">
                            <h2>PAYSLIP</h2>
                            <p>#<?php echo str_pad($payslip['id'] ?? 0, 6, '0', STR_PAD_LEFT); ?></p>
                        </div>
                    </div>
                </div>

                <div class="payslip-body">
                    <div class="info-bar">
                        <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($payslip['employee_name'] ?? 'N/A'); ?></span>
                        <span><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars($payslip['emp_code'] ?? 'N/A'); ?></span>
                    </div>

                    <div class="period-badge">
                        <i class="fas fa-calendar"></i>
                        <?php echo isset($payslip['pay_period_start']) ? date('M d', strtotime($payslip['pay_period_start'])) : 'N/A'; ?> - 
                        <?php echo isset($payslip['pay_period_end']) ? date('M d, Y', strtotime($payslip['pay_period_end'])) : 'N/A'; ?>
                    </div>

                    <table class="earnings-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th style="text-align: right;">$</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($basic_pay > 0): ?>
                            <tr>
                                <td class="desc-cell">Basic <small>(<?php echo $days_worked; ?>d)</small></td>
                                <td class="amount-cell"><?php echo number_format($basic_pay, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if ($housing_pay > 0): ?>
                            <tr>
                                <td class="desc-cell">Housing</td>
                                <td class="amount-cell"><?php echo number_format($housing_pay, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if ($transport_pay > 0): ?>
                            <tr>
                                <td class="desc-cell">Transport</td>
                                <td class="amount-cell"><?php echo number_format($transport_pay, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if (($payslip['regular_pay'] ?? 0) > 0): ?>
                            <tr>
                                <td class="desc-cell">Regular <small>(<?php echo number_format($payslip['regular_hours'] ?? 0, 0); ?>h)</small></td>
                                <td class="amount-cell"><?php echo number_format($payslip['regular_pay'] ?? 0, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if (($payslip['overtime_pay'] ?? 0) > 0): ?>
                            <tr>
                                <td class="desc-cell">OT <small>(<?php echo number_format($payslip['overtime_hours'] ?? 0, 0); ?>h)</small></td>
                                <td class="amount-cell"><?php echo number_format($payslip['overtime_pay'] ?? 0, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <tr class="total-row">
                                <td class="desc-cell">GROSS</td>
                                <td class="amount-cell"><?php echo number_format($gross_pay, 2); ?></td>
                            </tr>
                            
                            <?php if ($tax_deduction > 0): ?>
                            <tr class="deduction-row">
                                <td class="desc-cell">Tax</td>
                                <td class="amount-cell"><?php echo number_format($tax_deduction, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if ($pension_deduction > 0): ?>
                            <tr class="deduction-row">
                                <td class="desc-cell">Pension</td>
                                <td class="amount-cell"><?php echo number_format($pension_deduction, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <?php if ($medical_deduction > 0): ?>
                            <tr class="deduction-row">
                                <td class="desc-cell">Medical</td>
                                <td class="amount-cell"><?php echo number_format($medical_deduction, 2); ?></td>
                            </tr>
                            <?php endif; ?>
                            
                            <tr class="total-row" style="background: #fde9e9;">
                                <td class="desc-cell">DEDUCTIONS</td>
                                <td class="amount-cell" style="color: var(--danger);"><?php echo number_format($total_deductions, 2); ?></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="net-pay-box" style="padding: 12px;">
                        <div class="label" style="font-size: 9px;">NET PAY</div>
                        <div class="amount" style="font-size: 24px;">$<?php echo number_format($net_pay, 2); ?></div>
                    </div>

                    <div class="status-bar" style="padding: 8px 12px; font-size: 8pt;">
                        <span class="payment-status status-<?php echo $payslip['status'] ?? 'pending'; ?>" style="font-size: 7pt;">
                            <?php echo strtoupper($payslip['status'] ?? 'PENDING'); ?>
                        </span>
                        <span><?php echo date('M d, Y'); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle" style="font-size: 32px; margin-bottom: 10px;"></i>
            <h3>No Payslip Found</h3>
            <p>The requested payslip could not be found.</p>
            <a href="payroll.php" class="back-btn" style="margin-top: 15px;">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    <?php endif; ?>

    <script>
        // Toggle paper size
        function toggleSize(size) {
            const container = document.getElementById('payslip-container');
            const currentSizeLabel = document.getElementById('current-size');
            
            // Remove all size classes
            container.classList.remove('compact');
            
            // Update buttons
            document.querySelectorAll('.size-toggle').forEach(btn => btn.classList.remove('active'));
            document.getElementById('btn-' + size).classList.add('active');
            
            // Apply size
            if (size === 'compact') {
                container.classList.add('compact');
                currentSizeLabel.textContent = 'A5/Compact';
            } else if (size === 'thermal') {
                container.classList.add('compact');
                // Add thermal-specific styles via CSS injection
                const style = document.createElement('style');
                style.id = 'thermal-style';
                style.innerHTML = `
                    @media screen {
                        .payslip-wrapper { max-width: 320px !important; font-size: 8pt !important; }
                        .details-grid { grid-template-columns: 1fr !important; }
                        .summary-cards { grid-template-columns: 1fr 1fr !important; }
                    }
                `;
                document.head.appendChild(style);
                currentSizeLabel.textContent = 'Thermal (80mm)';
            } else {
                // Auto/A4
                const thermalStyle = document.getElementById('thermal-style');
                if (thermalStyle) thermalStyle.remove();
                currentSizeLabel.textContent = 'Auto (A4)';
            }
        }

        // Auto-print if requested
        <?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
        window.onload = function() {
            setTimeout(() => window.print(), 500);
        }
        <?php endif; ?>

        // Detect paper size from URL parameter
        <?php if (isset($_GET['size'])): ?>
        window.onload = function() {
            toggleSize('<?php echo $_GET['size']; ?>');
        }
        <?php endif; ?>

        // Keyboard shortcut
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
       
    </script>
</body>
</html>