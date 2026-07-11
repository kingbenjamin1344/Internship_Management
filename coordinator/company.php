<?php
// coordinator/dashboard.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is coordinator
checkAccess('coordinator');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Coordinator';
$role = getUserRole();

// Database connection
global $pdo;

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_company') {
        $company_name = trim($_POST['company_name']);
        $address = trim($_POST['address']);
        $industry = trim($_POST['industry']);
        $contact_person = trim($_POST['contact_person']);
        $contact_email = trim($_POST['contact_email']);
        $contact_number = trim($_POST['contact_number']);
        
        $stmt = $pdo->prepare("INSERT INTO companies (company_name, address, industry, contact_person, contact_email, contact_number) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$company_name, $address, $industry, $contact_person, $contact_email, $contact_number]);
        
        $_SESSION['success'] = "Company added successfully!";
    } elseif ($_POST['action'] === 'edit_company') {
        $id = (int)$_POST['company_id'];
        $company_name = trim($_POST['company_name']);
        $address = trim($_POST['address']);
        $industry = trim($_POST['industry']);
        $contact_person = trim($_POST['contact_person']);
        $contact_email = trim($_POST['contact_email']);
        $contact_number = trim($_POST['contact_number']);
        
        $stmt = $pdo->prepare("UPDATE companies SET company_name = ?, address = ?, industry = ?, contact_person = ?, contact_email = ?, contact_number = ? WHERE id = ?");
        $stmt->execute([$company_name, $address, $industry, $contact_person, $contact_email, $contact_number, $id]);
        
        $_SESSION['success'] = "Company updated successfully!";
    } elseif ($_POST['action'] === 'assign_supervisor') {
        $companyId = (int)($_POST['company_id'] ?? 0);
        $supervisorId = isset($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : 0;
        $searchQuery = trim($_POST['search'] ?? '');

        if ($companyId > 0) {
            $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch();

            if (!$company) {
                $_SESSION['error'] = 'Invalid company selected.';
            } else {
                if ($supervisorId > 0) {
                    $supStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'supervisor'");
                    $supStmt->execute([$supervisorId]);
                    $supervisor = $supStmt->fetch();

                    if ($supervisor) {
                        $unassignStmt = $pdo->prepare("UPDATE companies SET supervisor_id = NULL WHERE supervisor_id = ? AND id != ?");
                        $unassignStmt->execute([$supervisorId, $companyId]);

                        $assignStmt = $pdo->prepare("UPDATE companies SET supervisor_id = ? WHERE id = ?");
                        if ($assignStmt->execute([$supervisorId, $companyId])) {
                            $_SESSION['success'] = 'Supervisor assigned to company successfully.';
                        } else {
                            $_SESSION['error'] = 'Unable to assign supervisor at this time.';
                        }
                    } else {
                        $_SESSION['error'] = 'Invalid supervisor selected.';
                    }
                } else {
                    $assignStmt = $pdo->prepare("UPDATE companies SET supervisor_id = NULL WHERE id = ?");
                    if ($assignStmt->execute([$companyId])) {
                        $_SESSION['success'] = 'Supervisor unassigned from company successfully.';
                    } else {
                        $_SESSION['error'] = 'Unable to unassign supervisor at this time.';
                    }
                }
            }
        } else {
            $_SESSION['error'] = 'Please select a valid company.';
        }

        $redirectUrl = 'company.php';
        if ($searchQuery !== '') {
            $redirectUrl .= '?search=' . urlencode($searchQuery);
        }
        header("Location: $redirectUrl");
        exit;
    }

    if ($_POST['action'] !== 'assign_supervisor') {
        header("Location: company.php");
        exit;
    }
}

// Handle Delete Company
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM companies WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['success'] = "Company deleted successfully!";
    header("Location: company.php");
    exit;
}

// Fetch single company for editing (via AJAX)
if (isset($_GET['get_company'])) {
    $id = (int)$_GET['get_company'];
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($company);
    exit;
}

$search = trim($_GET['search'] ?? '');

// Fetch supervisor list for assignment
$supervisors = $pdo->prepare("SELECT id, firstname, middlename, lastname, suffix FROM users WHERE role = 'supervisor' AND status = 'active' ORDER BY firstname, lastname");
$supervisors->execute();
$supervisors = $supervisors->fetchAll();

$supervisorNames = [];
foreach ($supervisors as $supervisor) {
    $supervisorNames[$supervisor['id']] = getFullName($supervisor);
}

// Fetch all active supervisor assignments to companies
$assignmentsStmt = $pdo->query("SELECT id, supervisor_id FROM companies WHERE supervisor_id IS NOT NULL");
$assignedSupervisors = [];
while ($row = $assignmentsStmt->fetch()) {
    $assignedSupervisors[(int)$row['supervisor_id']] = (int)$row['id'];
}

// Fetch companies with optional search filter
$companySql = "SELECT * FROM companies";
$params = [];
if ($search !== '') {
    $companySql .= " WHERE company_name LIKE ?";
    $params[] = '%' . $search . '%';
}
$companySql .= " ORDER BY created_at DESC";
$stmt = $pdo->prepare($companySql);
$stmt->execute($params);
$companies = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordinator Dashboard</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-users-gear"></i>
                <h2>System<span>Coordinator Desk</span></h2>
            </div>
            <nav class="nav-section">
                <a class="nav-item " href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item active" href="company.php"><i class="fa-solid fa-building"></i>  Company Management</a>
                <a class="nav-item " href="intern.php"><i class="fa-solid fa-business-time"></i>  Internship Management</a>
                
            </nav>
            <div class="sidebar-footer">
                <div class="user-chip">
                    <i class="fa-solid fa-user-circle"></i>
                    <div>
                        <div class="name"><?php echo htmlspecialchars($fullname); ?></div>
                        <div class="role-label"><?php echo htmlspecialchars(getRoleDisplayName($role)); ?></div>
                    </div>
                </div>
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <main class="main-content">
            <div class="top-bar">
              
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success" style="padding: 12px 18px; border-radius: 12px; background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger" style="padding: 12px 18px; border-radius: 12px; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="page-card">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #e9edf2; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <h3 style="font-weight: 600; color: #0f172a; font-size: 18px; margin: 0;">
                            <i class="fa-solid fa-search"></i> Search Companies
                        </h3>
                        <p style="margin: 8px 0 0; color: #64748b;">Search by company name and assign a supervisor to a company.</p>
                    </div>
                    <div style="display: inline-flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <form action="company.php" method="get" style="display: inline-flex; gap: 8px; align-items: center;">
                            <input type="search" name="search" placeholder="Search company name" value="<?php echo htmlspecialchars($search); ?>" style="padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 999px; min-width: 240px;" aria-label="Search companies" />
                            <button type="submit" style="padding: 10px 18px; border-radius: 999px; background: #2563eb; color: #fff; border: none; cursor: pointer;">Search</button>
                        </form>
                        <button onclick="openAddModal()" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 999px; font-weight: 600; font-size: 14px; background: #2563eb; color: #ffffff; border: none; cursor: pointer; transition: all 0.25s ease;">
                            <i class="fa-solid fa-plus"></i> Add Company
                        </button>
                    </div>
                </div>

                <div style="overflow-x: auto; padding: 8px;">
                    <?php if (count($companies) > 0): ?>
                        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                            <thead>
                                <tr>
                                    <th style="text-align: left; padding: 14px 16px; color: #64748b; font-weight: 600; background: #fafcff; border-bottom: 2px solid #e9edf2; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;">ID</th>
                                    <th style="text-align: left; padding: 14px 16px; color: #64748b; font-weight: 600; background: #fafcff; border-bottom: 2px solid #e9edf2; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;">Company Name</th>
                                    <th style="text-align: left; padding: 14px 16px; color: #64748b; font-weight: 600; background: #fafcff; border-bottom: 2px solid #e9edf2; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;">Industry</th>
                                    <th style="text-align: left; padding: 14px 16px; color: #64748b; font-weight: 600; background: #fafcff; border-bottom: 2px solid #e9edf2; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;">Contact Person</th>
                                    <th style="text-align: left; padding: 14px 16px; color: #64748b; font-weight: 600; background: #fafcff; border-bottom: 2px solid #e9edf2; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;">Email</th>
                                    <th style="text-align: left; padding: 14px 16px; color: #64748b; font-weight: 600; background: #fafcff; border-bottom: 2px solid #e9edf2; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;">Phone</th>
                                    <th style="text-align: left; padding: 14px 16px; color: #64748b; font-weight: 600; background: #fafcff; border-bottom: 2px solid #e9edf2; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;">Supervisor</th>
                                    <th style="text-align: center; padding: 14px 16px; color: #64748b; font-weight: 600; background: #fafcff; border-bottom: 2px solid #e9edf2; font-size: 12px; text-transform: uppercase; letter-spacing: 0.3px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($companies as $company): ?>
                                    <tr style="transition: background 0.15s ease;">
                                        <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #64748b; font-weight: 500;">#<?php echo $company['id']; ?></td>
                                        <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b;">
                                            <strong><?php echo htmlspecialchars($company['company_name']); ?></strong>
                                            <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                                <?php echo htmlspecialchars(substr($company['address'], 0, 50)) . (strlen($company['address']) > 50 ? '...' : ''); ?>
                                            </div>
                                        </td>
                                        <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9;">
                                            <span style="background: #f1f5f9; padding: 4px 14px; border-radius: 40px; font-size: 12px; font-weight: 500; color: #334155;"><?php echo htmlspecialchars($company['industry']); ?></span>
                                        </td>
                                        <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b;"><?php echo htmlspecialchars($company['contact_person']); ?></td>
                                        <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b;"><?php echo htmlspecialchars($company['contact_email'] ?? '-'); ?></td>
                                        <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b;"><?php echo htmlspecialchars($company['contact_number'] ?? '-'); ?></td>
                                        <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; color: #1e293b;">
                                            <span style="padding: 6px 12px; border-radius: 999px; background: #eef2ff; color: #3730a3; font-size: 13px; display: inline-flex; align-items: center;">
                                                <?php echo !empty($company['supervisor_id']) && isset($supervisorNames[$company['supervisor_id']]) ? htmlspecialchars($supervisorNames[$company['supervisor_id']]) : 'Unassigned'; ?>
                                            </span>
                                        </td>
                                        <td style="padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;">
                                            <div style="display: flex; justify-content: center; gap: 8px; align-items: center; flex-wrap: wrap;">
                                                <button type="button" class="assign-supervisor-btn" data-company-id="<?php echo htmlspecialchars($company['id'], ENT_QUOTES); ?>" data-company-name="<?php echo htmlspecialchars($company['company_name'], ENT_QUOTES); ?>" data-supervisor-id="<?php echo htmlspecialchars($company['supervisor_id'] ?? '', ENT_QUOTES); ?>" style="padding: 9px 16px; border-radius: 999px; background: #2563eb; color: #fff; border: none; cursor: pointer;">Assign</button>
                                                <button type="button" onclick="openEditModal(<?php echo $company['id']; ?>)" style="color: #2563eb; background: none; border: none; cursor: pointer; padding: 6px 10px; border-radius: 8px; transition: all 0.2s ease; font-size: 15px;">
                                                    <i class="fa-solid fa-edit"></i>
                                                </button>
                                                <a href="?delete=<?php echo $company['id']; ?>" style="color: #dc2626; background: none; border: none; cursor: pointer; padding: 6px 10px; border-radius: 8px; transition: all 0.2s ease; font-size: 15px; text-decoration: none; display: inline-flex; align-items: center;" onclick="return confirm('Are you sure you want to delete this company?')">
                                                    <i class="fa-solid fa-trash-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="text-align: center; padding: 60px 20px; color: #94a3b8;">
                            <i class="fa-solid fa-building-circle-exclamation" style="font-size: 48px; color: #cbd5e1; margin-bottom: 16px; display: block;"></i>
                            <p style="font-size: 16px;">No companies added yet. Click "Add Company" to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- ADD COMPANY MODAL -->
    <div id="addModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); z-index: 1000; justify-content: center; align-items: center; padding: 20px;">
        <div style="background: #ffffff; border-radius: 24px; max-width: 560px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 24px 64px rgba(0, 0, 0, 0.15); animation: modalSlideIn 0.3s ease;">
            <!-- Modal Header - Fixed -->
            <div style="padding: 24px 28px 16px 28px; border-bottom: 1px solid #f1f5f9; flex-shrink: 0;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size: 22px; font-weight: 600; color: #0f172a; margin: 0;">
                        <i class="fa-solid fa-building" style="color: #2563eb; margin-right: 10px;"></i> Add New Company
                    </h2>
                    <button onclick="closeAddModal()" style="background: none; border: none; font-size: 28px; color: #94a3b8; cursor: pointer; transition: 0.15s; padding: 4px 8px; border-radius: 8px; line-height: 1;">&times;</button>
                </div>
            </div>
            
            <!-- Modal Body - Scrollable -->
            <div style="padding: 20px 28px; overflow-y: auto; flex: 1;">
                <form method="POST" action="" id="addCompanyForm">
                    <input type="hidden" name="action" value="add_company">
                    
                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Company Name <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="company_name" required placeholder="Enter company name" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                    </div>
                    
                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Address <span style="color: #dc2626;">*</span></label>
                        <textarea name="address" required placeholder="Enter full address" rows="3" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; resize: vertical; min-height: 80px; box-sizing: border-box;"></textarea>
                    </div>
                    
                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Industry <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="industry" required placeholder="e.g., Technology, Healthcare, Finance" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                    </div>
                    
                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Contact Person <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="contact_person" required placeholder="Full name" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                        <div>
                            <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Contact Email</label>
                            <input type="email" name="contact_email" placeholder="email@company.com" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                        </div>
                        <div>
                            <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Contact Number</label>
                            <input type="text" name="contact_number" placeholder="+63 912 345 6789" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Modal Footer - Fixed -->
            <div style="padding: 16px 28px 24px 28px; border-top: 1px solid #f1f5f9; flex-shrink: 0;">
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeAddModal()" style="padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 14px; background: #f1f5f9; color: #475569; border: none; cursor: pointer; transition: all 0.2s ease;">Cancel</button>
                    <button type="submit" form="addCompanyForm" style="padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 14px; background: #2563eb; color: #ffffff; border: none; cursor: pointer; transition: all 0.25s ease;"><i class="fa-solid fa-save"></i> Save Company</button>
                </div>
            </div>
        </div>
    </div>

    <!-- EDIT COMPANY MODAL -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); z-index: 1000; justify-content: center; align-items: center; padding: 20px;">
        <div style="background: #ffffff; border-radius: 24px; max-width: 560px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 24px 64px rgba(0, 0, 0, 0.15); animation: modalSlideIn 0.3s ease;">
            <!-- Modal Header - Fixed -->
            <div style="padding: 24px 28px 16px 28px; border-bottom: 1px solid #f1f5f9; flex-shrink: 0;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size: 22px; font-weight: 600; color: #0f172a; margin: 0;">
                        <i class="fa-solid fa-edit" style="color: #2563eb; margin-right: 10px;"></i> Edit Company
                    </h2>
                    <button onclick="closeEditModal()" style="background: none; border: none; font-size: 28px; color: #94a3b8; cursor: pointer; transition: 0.15s; padding: 4px 8px; border-radius: 8px; line-height: 1;">&times;</button>
                </div>
            </div>
            
            <!-- Modal Body - Scrollable -->
            <div style="padding: 20px 28px; overflow-y: auto; flex: 1;">
                <form method="POST" action="" id="editCompanyForm">
                    <input type="hidden" name="action" value="edit_company">
                    <input type="hidden" name="company_id" id="edit_company_id" value="">
                    
                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Company Name <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="company_name" id="edit_company_name" required placeholder="Enter company name" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                    </div>
                    
                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Address <span style="color: #dc2626;">*</span></label>
                        <textarea name="address" id="edit_address" required placeholder="Enter full address" rows="3" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; resize: vertical; min-height: 80px; box-sizing: border-box;"></textarea>
                    </div>
                    
                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Industry <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="industry" id="edit_industry" required placeholder="e.g., Technology, Healthcare, Finance" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                    </div>
                    
                    <div style="margin-bottom: 18px;">
                        <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Contact Person <span style="color: #dc2626;">*</span></label>
                        <input type="text" name="contact_person" id="edit_contact_person" required placeholder="Full name" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                        <div>
                            <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Contact Email</label>
                            <input type="email" name="contact_email" id="edit_contact_email" placeholder="email@company.com" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                        </div>
                        <div>
                            <label style="display: block; font-weight: 500; color: #334155; font-size: 14px; margin-bottom: 6px;">Contact Number</label>
                            <input type="text" name="contact_number" id="edit_contact_number" placeholder="+63 912 345 6789" style="width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 14px; font-family: inherit; transition: all 0.2s ease; background: #f8fafc; box-sizing: border-box;" />
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Modal Footer - Fixed -->
            <div style="padding: 16px 28px 24px 28px; border-top: 1px solid #f1f5f9; flex-shrink: 0;">
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeEditModal()" style="padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 14px; background: #f1f5f9; color: #475569; border: none; cursor: pointer; transition: all 0.2s ease;">Cancel</button>
                    <button type="submit" form="editCompanyForm" style="padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 14px; background: #2563eb; color: #ffffff; border: none; cursor: pointer; transition: all 0.25s ease;"><i class="fa-solid fa-save"></i> Update Company</button>
                </div>
            </div>
        </div>
    </div>

    <div id="assignModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(2px); z-index: 1100; justify-content: center; align-items: center; padding: 20px;">
        <div style="background: #ffffff; border-radius: 24px; max-width: 640px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 24px 64px rgba(0, 0, 0, 0.18); overflow: hidden;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 22px 24px; border-bottom: 1px solid #e2e8f0;">
                <div>
                    <h2 style="font-size: 22px; font-weight: 700; color: #0f172a; margin: 0;">Assign Supervisor</h2>
                    <p style="margin: 6px 0 0; color: #64748b; font-size: 14px;">Search supervisors and select one to assign.</p>
                    <div id="assignModalCompanyName" style="margin-top: 8px; color: #334155; font-size: 13px;"></div>
                </div>
                <button type="button" onclick="closeAssignModal()" style="background: none; border: none; font-size: 28px; color: #94a3b8; cursor: pointer;">&times;</button>
            </div>
            <div style="padding: 18px 24px 0;">
                <input id="assignSupervisorSearch" type="search" placeholder="Search supervisors" style="width: 100%; padding: 12px 16px; border: 1px solid #d1d5db; border-radius: 14px; font-size: 14px;" aria-label="Search supervisors" />
            </div>
            <div style="overflow-y: auto; flex: 1; padding: 16px 24px;">
                <form id="assignSupervisorForm" method="post" action="company.php">
                    <input type="hidden" name="action" value="assign_supervisor" />
                    <input type="hidden" name="company_id" id="assign_company_id" value="" />
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>" />
                    <div id="supervisorList" style="display: grid; gap: 10px;">
                        <?php foreach ($supervisors as $supervisor): ?>
                            <label class="supervisor-item" data-name="<?php echo htmlspecialchars(strtolower(getFullName($supervisor))); ?>" style="display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 1px solid #e2e8f0; border-radius: 18px; cursor: pointer; transition: background 0.2s ease;">
                                <input type="checkbox" name="supervisor_id" value="<?php echo $supervisor['id']; ?>" class="supervisor-checkbox" style="width: 18px; height: 18px; accent: #2563eb;" />
                                <div>
                                    <div style="font-weight: 600; color: #0f172a;"><?php echo htmlspecialchars(getFullName($supervisor)); ?></div>
                                    <div style="color: #64748b; font-size: 13px;">Supervisor</div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                        <div id="noSupervisorsMessage" style="display: none; text-align: center; padding: 30px 0; color: #64748b;">No unassigned supervisors available.</div>
                        <?php if (count($supervisors) === 0): ?>
                            <div style="text-align: center; padding: 30px 0; color: #64748b;">No active supervisors available.</div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div style="padding: 16px 24px 24px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeAssignModal()" style="padding: 10px 22px; border-radius: 999px; border: 1px solid #cbd5e1; background: #fff; color: #475569; cursor: pointer;">Cancel</button>
                <button type="button" onclick="submitAssignSupervisor()" style="padding: 10px 22px; border-radius: 999px; background: #2563eb; color: #fff; border: none; cursor: pointer;">Save</button>
            </div>
        </div>
    </div>

    <style>
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        button:hover {
            opacity: 0.9;
        }
        
        input:focus, textarea:focus {
            outline: none;
            border-color: #2563eb !important;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.08);
            background: #ffffff !important;
        }
        
        /* Custom scrollbar for modal body */
        #addModal div[style*="overflow-y: auto"]::-webkit-scrollbar,
        #editModal div[style*="overflow-y: auto"]::-webkit-scrollbar {
            width: 6px;
        }
        
        #addModal div[style*="overflow-y: auto"]::-webkit-scrollbar-track,
        #editModal div[style*="overflow-y: auto"]::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        
        #addModal div[style*="overflow-y: auto"]::-webkit-scrollbar-thumb,
        #editModal div[style*="overflow-y: auto"]::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        #addModal div[style*="overflow-y: auto"]::-webkit-scrollbar-thumb:hover,
        #editModal div[style*="overflow-y: auto"]::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>

    <script>
        // Add Modal Functions
        function openAddModal() {
            document.getElementById('addModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        // Edit Modal Functions
        function openEditModal(companyId) {
            fetch('?get_company=' + companyId)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_company_id').value = data.id;
                    document.getElementById('edit_company_name').value = data.company_name;
                    document.getElementById('edit_address').value = data.address;
                    document.getElementById('edit_industry').value = data.industry;
                    document.getElementById('edit_contact_person').value = data.contact_person;
                    document.getElementById('edit_contact_email').value = data.contact_email || '';
                    document.getElementById('edit_contact_number').value = data.contact_number || '';
                    
                    document.getElementById('editModal').style.display = 'flex';
                    document.body.style.overflow = 'hidden';
                })
                .catch(error => {
                    alert('Error loading company data. Please try again.');
                });
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        const assignedSupervisorsMap = <?php echo json_encode($assignedSupervisors); ?>;

        window.openAssignModal = function(companyId, companyName, supervisorId) {
            document.getElementById('assign_company_id').value = companyId;
            document.getElementById('assignModalCompanyName').textContent = companyName;
            const modal = document.getElementById('assignModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';

            document.querySelectorAll('.supervisor-checkbox').forEach(function(checkbox) {
                checkbox.checked = checkbox.value === String(supervisorId);
            });
            document.getElementById('assignSupervisorSearch').value = '';
            filterSupervisorList('');
        };

        window.closeAssignModal = function() {
            document.getElementById('assignModal').style.display = 'none';
            document.body.style.overflow = '';
        };

        window.submitAssignSupervisor = function() {
            document.getElementById('assignSupervisorForm').submit();
        };

        document.querySelectorAll('.assign-supervisor-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const companyId = this.dataset.companyId;
                const companyName = this.dataset.companyName;
                const supervisorId = this.dataset.supervisorId || '';
                openAssignModal(companyId, companyName, supervisorId);
            });
        });

        function filterSupervisorList(query) {
            query = query.trim().toLowerCase();
            const currentCompanyId = parseInt(document.getElementById('assign_company_id').value) || 0;
            let visibleCount = 0;

            document.querySelectorAll('#supervisorList .supervisor-item').forEach(function(item) {
                const checkbox = item.querySelector('.supervisor-checkbox');
                const supervisorId = parseInt(checkbox.value);
                const assignedCompanyId = assignedSupervisorsMap[supervisorId];

                // If this supervisor is assigned to another company, hide them
                if (assignedCompanyId && assignedCompanyId !== currentCompanyId) {
                    item.style.display = 'none';
                } else {
                    const name = item.getAttribute('data-name');
                    const matchesSearch = name.includes(query);
                    if (matchesSearch) {
                        item.style.display = 'flex';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                }
            });

            const noMessage = document.getElementById('noSupervisorsMessage');
            if (noMessage) {
                noMessage.style.display = (visibleCount === 0) ? 'block' : 'none';
            }
        }

        document.getElementById('assignSupervisorSearch').addEventListener('input', function() {
            filterSupervisorList(this.value);
        });

        document.querySelectorAll('.supervisor-checkbox').forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    document.querySelectorAll('.supervisor-checkbox').forEach(function(other) {
                        if (other !== checkbox) {
                            other.checked = false;
                        }
                    });
                }
            });
        });

        // Close modals on overlay click
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddModal();
            }
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        document.getElementById('assignModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAssignModal();
            }
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeEditModal();
                closeAssignModal();
            }
        });
    </script>
</body>
</html>