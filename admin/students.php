<?php
// admin/students.php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin_notifications.php';

// Only admins may access
checkAccess('admin');

$fullname = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';
$role = getUserRole();
$userId = getUserId();

// Fetch students
try {
    $stmt = $pdo->prepare("SELECT id, firstname, middlename, lastname, email, profile_picture, status, created_at FROM users WHERE role = 'student' ORDER BY lastname, firstname");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $students = [];
}

// List files in admin folder
$adminFiles = [];
foreach (scandir(__DIR__) as $f) {
    if ($f === '.' || $f === '..') continue;
    if ($f === basename(__FILE__)) continue;
    if (is_file(__DIR__ . '/' . $f)) {
        $adminFiles[] = $f;
    }
}

function fullNameFromRow($row) {
    $name = trim(($row['firstname'] ?? '') . ' ' . ($row['middlename'] ?? '') . ' ' . ($row['lastname'] ?? ''));
    return $name ?: 'Student';
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Students — Admin</title>
    <link rel="stylesheet" href="../assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
</head>
<body class="app-shell">
    <?php // Minimal header area ?>
    <div class="main-content">
        <div class="app-header">
            <div class="app-header-copy">
                <h1>Students</h1>
                <p>All registered student accounts.</p>
            </div>
            <div class="app-header-actions">
                <?php renderAdminNotificationBell($userId); ?>
                <div class="profile-chip">
                    <div class="profile-avatar"><?php echo strtoupper(substr($fullname,0,1)); ?></div>
                    <div class="profile-meta"><strong><?php echo htmlspecialchars($fullname); ?></strong><span><?php echo htmlspecialchars($role); ?></span></div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:20px;align-items:flex-start;">
            <div style="flex:1;">
                <div class="page-card">
                    <h2>Student Directory</h2>
                    <p>Below is a list of all student accounts in the system.</p>

                    <div class="table-container" style="margin-top:16px;">
                        <table class="applicant-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Avatar</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($students)): ?>
                                    <tr><td colspan="7">No students found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($students as $i => $s): ?>
                                        <tr>
                                            <td><?php echo $i+1; ?></td>
                                            <td style="width:64px;">
                                                <?php if (!empty($s['profile_picture']) && file_exists(__DIR__ . '/../assets/uploads/avatars/' . $s['profile_picture'])): ?>
                                                    <img src="../assets/uploads/avatars/<?php echo htmlspecialchars($s['profile_picture']); ?>" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:50%;" />
                                                <?php else: ?>
                                                    <div style="width:44px;height:44px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#475569;font-weight:700;"><?php echo strtoupper(substr($s['firstname'] ?? 'S',0,1)); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(fullNameFromRow($s)); ?></td>
                                            <td><?php echo htmlspecialchars($s['email'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($s['status'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($s['created_at'] ?? ''); ?></td>
                                            <td>
                                                <a class="btn-view" href="edit_user.php?id=<?php echo (int)$s['id']; ?>">Edit</a>
                                                <a class="btn-view" href="../student/dashboard.php?as_student=<?php echo (int)$s['id']; ?>" style="margin-left:8px;">Impersonate</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <aside style="width:320px;">
                <div class="page-card">
                    <h2>Admin Files</h2>
                    <p>Quick links to files in the <strong>admin/</strong> folder.</p>
                    <ul style="margin-top:12px;list-style:none;padding:0;">
                        <?php foreach ($adminFiles as $file): ?>
                            <li style="margin-bottom:8px;"><a href="<?php echo htmlspecialchars($file); ?>"><?php echo htmlspecialchars($file); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        </div>
    </div>
</body>
</html>
