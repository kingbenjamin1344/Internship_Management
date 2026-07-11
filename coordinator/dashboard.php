<?php
// coordinator/dashboard.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is coordinator
checkAccess('coordinator');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Coordinator';
$role = getUserRole();
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
                <a class="nav-item active" href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item " href="company.php"><i class="fa-solid fa-building"></i>  Company Management</a>
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

            <div class="page-card">
                <h2>Welcome to your coordinator workspace</h2>
                <p>You have coordinator-level access to manage your department.</p>
            </div>
        </main>
    </div>
</body>
</html>