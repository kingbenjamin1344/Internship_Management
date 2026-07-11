<?php
// student/dashboard.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is student
checkAccess('student');

$username = $_SESSION['username'] ?? 'Student';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <i class="fa-solid fa-graduation-cap"></i>
                <h2>RBAC<span>Student Portal</span></h2>
            </div>
            <nav class="nav-section">
                <a class="nav-item " href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                <a class="nav-item " href="apply.php"><i class="fa-solid fa-gauge-high"></i> Apply Job</a>
                <a class="nav-item " href="applications.php"><i class="fa-solid fa-gauge-high"></i> My Applications</a>
                <a class="nav-item active" href="dpr.php"><i class="fa-solid fa-gauge-high"></i> Daily Progress Report</a>

            </nav>
            <div class="sidebar-footer">
                <div class="user-chip">
                    <i class="fa-solid fa-user-circle"></i>
                    <div>
                        <div class="name"><?php echo htmlspecialchars($username); ?></div>
                        <div class="role-label">Student</div>
                    </div>
                </div>
                <a class="logout-btn-side" href="../logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out</a>
            </div>
        </aside>

        <main class="main-content">
            <div class="top-bar">
            </div>

            <div class="page-card">
                <h2>Welcome to your workspace</h2>
                <p>You have student-level access to view your courses and grades.</p>
            </div>
        </main>
    </div>
</body>
</html>