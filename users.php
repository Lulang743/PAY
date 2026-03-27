<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | PayrollPro</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* Reuse the same styling from the original dashboard, but with additional user management specific styles */
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

        .sidebar-header h3 {
            margin: 0;
            font-weight: 800;
            font-size: 26px;
            background: linear-gradient(135deg, #fff 0%, #c7d2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .sidebar-header p {
            margin: 8px 0 0;
            font-size: 12px;
            opacity: 0.7;
            color: #cbd5e1;
            font-weight: 500;
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
        }

        .page-title p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
        }

        .user-dropdown {
            display: flex;
            align-items: center;
            gap: 20px;
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

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            object-fit: cover;
            border: 2px solid #fff;
        }

        .user-name {
            font-weight: 700;
            color: var(--dark);
            font-size: 14px;
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
        }

        /* User Management Card */
        .user-management-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.6);
        }

        .card-header-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            font-weight: 600;
            transition: var(--transition);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .search-box {
            position: relative;
            width: 300px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 14px;
            background: rgba(255,255,255,0.9);
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }

        .search-box i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .users-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 12px;
        }

        .users-table th {
            padding: 12px 20px;
            color: #64748b;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid rgba(0,0,0,0.05);
        }

        .users-table td {
            padding: 18px 20px;
            background: rgba(248, 250, 252, 0.6);
            font-size: 14px;
            color: var(--dark);
            font-weight: 500;
            border: none;
            transition: var(--transition);
        }

        .users-table tr:hover td {
            background: rgba(99, 102, 241, 0.05);
            transform: scale(1.01);
        }

        .users-table td:first-child {
            border-radius: 16px 0 0 16px;
        }

        .users-table td:last-child {
            border-radius: 0 16px 16px 0;
        }

        .user-avatar-sm {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }

        .role-select {
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid rgba(0,0,0,0.1);
            background: white;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            background: rgba(0,0,0,0.05);
            color: #64748b;
            border: none;
        }

        .btn-icon:hover {
            transform: translateY(-2px);
        }

        .btn-edit:hover {
            background: rgba(99, 102, 241, 0.2);
            color: var(--primary);
        }

        .btn-delete:hover {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .badge-role {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-role.admin { background: rgba(99, 102, 241, 0.1); color: var(--primary); }
        .badge-role.manager { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .badge-role.hr { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .badge-role.employee { background: rgba(6, 182, 212, 0.1); color: var(--info); }

        .modal-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border: none;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 20px 28px;
        }

        .modal-body {
            padding: 28px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            padding: 12px 16px;
            border: 1px solid rgba(0,0,0,0.1);
            font-weight: 500;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }

        .pagination {
            margin-top: 24px;
            justify-content: flex-end;
        }

        .page-link {
            border-radius: 10px;
            margin: 0 4px;
            color: var(--dark);
            font-weight: 600;
            border: 1px solid rgba(0,0,0,0.05);
            background: rgba(255,255,255,0.5);
        }

        .page-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .status-badge {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }

        .status-active { background: var(--success); box-shadow: 0 0 0 2px rgba(16,185,129,0.2); }
        .status-inactive { background: var(--danger); }

        @media (max-width: 768px) {
            .sidebar { margin-left: calc(-1 * var(--sidebar-width)); }
            .content { margin-left: 0; padding: 20px; }
            .search-box { width: 100%; }
            .card-header-custom { flex-direction: column; align-items: stretch; }
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
                <a href="dashboard.php">
                    <i class="fas fa-chart-pie"></i>
                    <span>Dashboard</span>
                </a>
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
                <a href="user_management.php" class="active">
                    <i class="fas fa-user-shield"></i>
                    <span>User Management</span>
                </a>
                <a href="settings.php">
                    <i class="fas fa-sliders-h"></i>
                    <span>Settings</span>
                </a>
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
                    <h4>User Management</h4>
                    <p><i class="fas fa-user-shield"></i> Manage system users, roles, and permissions</p>
                </div>
                
                <div class="user-dropdown">
                    <div class="dropdown">
                        <div class="user-profile" data-bs-toggle="dropdown">
                            <img src="https://ui-avatars.com/api/?name=Admin&background=6366f1&color=fff&size=128&bold=true" alt="Profile" class="user-avatar">
                            <div class="user-info d-none d-md-block">
                                <div class="user-name">Admin User</div>
                                <div class="role-badge">Administrator</div>
                            </div>
                        </div>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-circle"></i>My Profile</a></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-power-off"></i>Sign Out</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- User Management Card -->
            <div class="user-management-card">
                <div class="card-header-custom">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search by name, email or role..." onkeyup="filterUsers()">
                    </div>
                    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-plus-circle me-2"></i>Add New User
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="users-table" id="usersTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <!-- Users will be dynamically loaded here -->
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="pagination" id="paginationControls">
                    <!-- Pagination will be dynamically loaded -->
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2" style="color: var(--primary);"></i>Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addUserForm">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name</label>
                            <input type="text" class="form-control" name="fullname" required placeholder="Enter full name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" class="form-control" name="email" required placeholder="Enter email address">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" class="form-control" name="username" required placeholder="Enter username">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Password</label>
                            <input type="password" class="form-control" name="password" required placeholder="Enter password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">User Role</label>
                            <select class="form-select" name="role" required>
                                <option value="employee">Employee</option>
                                <option value="hr">HR</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary-custom w-100 mt-2">Create User</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2" style="color: var(--primary);"></i>Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editUserForm">
                        <input type="hidden" name="userId" id="editUserId">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name</label>
                            <input type="text" class="form-control" name="fullname" id="editFullname" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" class="form-control" name="email" id="editEmail" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" class="form-control" name="username" id="editUsername" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">User Role</label>
                            <select class="form-select" name="role" id="editRole" required>
                                <option value="employee">Employee</option>
                                <option value="hr">HR</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status" id="editStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password (leave blank to keep current)</label>
                            <input type="password" class="form-control" name="password" placeholder="Enter new password">
                        </div>
                        <button type="submit" class="btn btn-primary-custom w-100 mt-2">Update User</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash-alt me-2" style="color: var(--danger);"></i>Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this user? This action cannot be undone.</p>
                    <input type="hidden" id="deleteUserId">
                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete User</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Mock users data for demonstration
        let users = [
            { id: 1, fullname: "John Doe", email: "john@payrollpro.com", username: "johndoe", role: "admin", status: "active", lastLogin: "2024-03-25 09:30:15" },
            { id: 2, fullname: "Jane Smith", email: "jane@payrollpro.com", username: "janesmith", role: "hr", status: "active", lastLogin: "2024-03-24 14:20:32" },
            { id: 3, fullname: "Mike Johnson", email: "mike@payrollpro.com", username: "mikej", role: "manager", status: "active", lastLogin: "2024-03-25 08:45:10" },
            { id: 4, fullname: "Sarah Williams", email: "sarah@payrollpro.com", username: "sarahw", role: "employee", status: "inactive", lastLogin: "2024-03-20 17:10:22" },
            { id: 5, fullname: "Robert Brown", email: "robert@payrollpro.com", username: "robertb", role: "employee", status: "active", lastLogin: "2024-03-25 10:15:40" },
            { id: 6, fullname: "Emily Davis", email: "emily@payrollpro.com", username: "emilyd", role: "hr", status: "active", lastLogin: "2024-03-24 11:30:00" }
        ];

        let currentPage = 1;
        let rowsPerPage = 5;
        let filteredUsers = [...users];

        function renderTable() {
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const paginatedUsers = filteredUsers.slice(start, end);
            const tbody = document.getElementById('usersTableBody');
            
            if (paginatedUsers.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-5"><i class="fas fa-users-slash fa-2x mb-3 d-block" style="color: #cbd5e1;"></i>No users found</td></tr>`;
                return;
            }

            tbody.innerHTML = paginatedUsers.map(user => `
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <div class="user-avatar-sm">${user.fullname.split(' ').map(n => n[0]).join('').toUpperCase()}</div>
                            <div>
                                <div class="fw-bold">${escapeHtml(user.fullname)}</div>
                                <div class="small text-secondary">@${escapeHtml(user.username)}</div>
                            </div>
                        </div>
                    </td>
                    <td>${escapeHtml(user.email)}</td>
                    <td><span class="badge-role ${user.role}"><i class="fas ${getRoleIcon(user.role)} me-1"></i>${user.role.charAt(0).toUpperCase() + user.role.slice(1)}</span></td>
                    <td><span class="status-badge status-${user.status}"></span>${user.status.charAt(0).toUpperCase() + user.status.slice(1)}</td>
                    <td class="small">${user.lastLogin || 'Never'}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn-icon btn-edit" onclick="editUser(${user.id})"><i class="fas fa-edit"></i></button>
                            <button class="btn-icon btn-delete" onclick="deleteUserPrompt(${user.id})"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </td>
                </tr>
            `).join('');
            
            renderPagination();
        }

        function getRoleIcon(role) {
            const icons = { admin: 'fa-crown', manager: 'fa-chart-line', hr: 'fa-users', employee: 'fa-user' };
            return icons[role] || 'fa-user';
        }

        function renderPagination() {
            const totalPages = Math.ceil(filteredUsers.length / rowsPerPage);
            const paginationDiv = document.getElementById('paginationControls');
            
            if (totalPages <= 1) {
                paginationDiv.innerHTML = '';
                return;
            }
            
            let html = `<nav><ul class="pagination">`;
            html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}"><a class="page-link" href="#" onclick="changePage(${currentPage - 1}); return false;">Previous</a></li>`;
            
            for (let i = 1; i <= totalPages; i++) {
                html += `<li class="page-item ${currentPage === i ? 'active' : ''}"><a class="page-link" href="#" onclick="changePage(${i}); return false;">${i}</a></li>`;
            }
            
            html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}"><a class="page-link" href="#" onclick="changePage(${currentPage + 1}); return false;">Next</a></li>`;
            html += `</ul></nav>`;
            paginationDiv.innerHTML = html;
        }

        function changePage(page) {
            currentPage = page;
            renderTable();
        }

        function filterUsers() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            filteredUsers = users.filter(user => 
                user.fullname.toLowerCase().includes(searchTerm) ||
                user.email.toLowerCase().includes(searchTerm) ||
                user.role.toLowerCase().includes(searchTerm)
            );
            currentPage = 1;
            renderTable();
        }

        function editUser(id) {
            const user = users.find(u => u.id === id);
            if (user) {
                document.getElementById('editUserId').value = user.id;
                document.getElementById('editFullname').value = user.fullname;
                document.getElementById('editEmail').value = user.email;
                document.getElementById('editUsername').value = user.username;
                document.getElementById('editRole').value = user.role;
                document.getElementById('editStatus').value = user.status;
                new bootstrap.Modal(document.getElementById('editUserModal')).show();
            }
        }

        function deleteUserPrompt(id) {
            document.getElementById('deleteUserId').value = id;
            new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
        }

        function deleteUser() {
            const id = parseInt(document.getElementById('deleteUserId').value);
            users = users.filter(u => u.id !== id);
            filteredUsers = [...users];
            if (filteredUsers.length === 0 && currentPage > 1) currentPage--;
            renderTable();
            bootstrap.Modal.getInstance(document.getElementById('deleteUserModal')).hide();
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', deleteUser);

        document.getElementById('addUserForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const newUser = {
                id: users.length + 1,
                fullname: formData.get('fullname'),
                email: formData.get('email'),
                username: formData.get('username'),
                role: formData.get('role'),
                status: formData.get('status'),
                lastLogin: 'Never'
            };
            users.push(newUser);
            filteredUsers = [...users];
            renderTable();
            bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
            this.reset();
        });

        document.getElementById('editUserForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const id = parseInt(document.getElementById('editUserId').value);
            const userIndex = users.findIndex(u => u.id === id);
            if (userIndex !== -1) {
                users[userIndex] = {
                    ...users[userIndex],
                    fullname: document.getElementById('editFullname').value,
                    email: document.getElementById('editEmail').value,
                    username: document.getElementById('editUsername').value,
                    role: document.getElementById('editRole').value,
                    status: document.getElementById('editStatus').value
                };
                filteredUsers = [...users];
                renderTable();
            }
            bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
        });

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // Initial render
        renderTable();

        // Real-time clock update
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString();
            const dateString = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            const timeElements = document.querySelectorAll('.current-time-display');
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>