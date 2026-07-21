<?php
// includes/functions.php

function redirect($url) {
    header("Location: $url");
    exit();
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['role'] ?? null;
}

function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

function isActive() {
    return isset($_SESSION['status']) && $_SESSION['status'] === 'active';
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function getRoleDisplayName($role) {
    $roles = [
        'admin' => 'Administrator',
        'coordinator' => 'Coordinator',
        'supervisor' => 'Supervisor',
        'student' => 'Student'
    ];
    return $roles[$role] ?? ucfirst($role);
}

function getStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'active' => '<span class="badge badge-success">Active</span>',
        'inactive' => '<span class="badge badge-danger">Inactive</span>'
    ];
    return $badges[$status] ?? $status;
}

function getFullName($user) {
    $name = $user['firstname'] ?? '';
    if (!empty($user['middlename'])) {
        $name .= ' ' . $user['middlename'];
    }
    $name .= ' ' . ($user['lastname'] ?? '');
    if (!empty($user['suffix'])) {
        $name .= ' ' . $user['suffix'];
    }
    return trim($name);
}

function formatDate($date) {
    if (!$date) return 'N/A';
    return date('F d, Y', strtotime($date));
}

function validateBirthdate($birthdate) {
    $date = DateTime::createFromFormat('Y-m-d', $birthdate);
    if (!$date) return false;
    
    $today = new DateTime();
    $diff = $today->diff($date);
    $age = $diff->y;
    
    // Check if person is at least 15 years old
    return $age >= 15;
}

/**
 * Ensure DPR-related tables exist (daily_progress_reports, dpr_anomaly_flags, dss_classifications).
 * Called at the top of any page that works with DPRs.
 */
function ensureDprTables($pdo) {
    // Main DPR table
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS daily_progress_reports (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       INT NOT NULL,
            report_date   DATE NOT NULL,
            time_in       TIME NOT NULL,
            time_out      TIME NOT NULL,
            activities    TEXT NOT NULL,
            accomplishments TEXT NOT NULL,
            issues        TEXT NULL,
            status        ENUM('submitted','approved','rejected') DEFAULT 'submitted',
            submitted_at  DATETIME NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_dpr_user (user_id),
            INDEX idx_dpr_date (report_date),
            INDEX idx_dpr_submitted (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    // Anomaly flags for Mv (bulk/late submissions)
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS dpr_anomaly_flags (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            student_id    INT NOT NULL,
            flag_type     ENUM('BULK_SUBMISSION_ANOMALY','LATE_SUBMISSION') NOT NULL,
            flagged_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            details       TEXT NULL,
            is_resolved   TINYINT(1) DEFAULT 0,
            resolved_at   DATETIME NULL,
            resolved_by   INT NULL,
            INDEX idx_af_student (student_id),
            INDEX idx_af_type (flag_type),
            INDEX idx_af_flagged (flagged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    // DSS classification table
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS dss_classifications (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            student_id      INT NOT NULL,
            classification  ENUM('AT_RISK','TOP_PERFORMER','NORMAL') NOT NULL DEFAULT 'NORMAL',
            late_count      INT DEFAULT 0,
            total_dprs      INT DEFAULT 0,
            anomaly_count   INT DEFAULT 0,
            classified_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            notes           TEXT NULL,
            UNIQUE KEY uq_dss_student (student_id),
            INDEX idx_dss_class (classification)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function ensureInternshipTables($pdo) {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            requirements TEXT,
            qualifications TEXT,
            slots_available INT DEFAULT 1,
            slots_filled INT DEFAULT 0,
            status ENUM('active', 'inactive', 'filled', 'expired') DEFAULT 'active',
            start_date DATE NULL,
            end_date DATE NULL,
            duration_hours INT DEFAULT 0,
            application_deadline DATE NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by INT NULL,
            updated_by INT NULL,
            CONSTRAINT fk_jobs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            CONSTRAINT fk_jobs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_jobs_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_jobs_company (company_id),
            INDEX idx_jobs_status (status),
            INDEX idx_jobs_dates (start_date, end_date),
            INDEX idx_jobs_deadline (application_deadline)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS job_applications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id INT NOT NULL,
            student_id INT NOT NULL,
            application_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'reviewed', 'shortlisted', 'accepted', 'committed', 'rejected', 'withdrawn') DEFAULT 'pending',
            cover_letter TEXT,
            cv_path VARCHAR(255) NULL,
            resume_path VARCHAR(255) NULL,
            application_letter_path VARCHAR(255) NULL,
            notes TEXT,
            interview_date DATETIME NULL,
            interview_notes TEXT,
            committed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_applications_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
            CONSTRAINT fk_applications_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_application (job_id, student_id),
            INDEX idx_applications_job (job_id),
            INDEX idx_applications_student (student_id),
            INDEX idx_applications_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    // Ensure columns exist in case the table was created before
    try {
        $colsStmt = $pdo->query("SHOW COLUMNS FROM job_applications");
        $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
        $statusStmt = $pdo->query("SHOW COLUMNS FROM job_applications LIKE 'status'");
        $statusColumn = $statusStmt->fetch(PDO::FETCH_ASSOC);
        if ($statusColumn && strpos($statusColumn['Type'], "'committed'") === false) {
            $pdo->exec("ALTER TABLE job_applications MODIFY status ENUM('pending', 'reviewed', 'shortlisted', 'accepted', 'committed', 'rejected', 'withdrawn') DEFAULT 'pending'");
        }
        if (!in_array('cv_path', $cols)) {
            $pdo->exec("ALTER TABLE job_applications ADD COLUMN cv_path VARCHAR(255) NULL AFTER student_id");
        }
        if (!in_array('resume_path', $cols)) {
            $pdo->exec("ALTER TABLE job_applications ADD COLUMN resume_path VARCHAR(255) NULL AFTER cv_path");
        }
        if (!in_array('application_letter_path', $cols)) {
            $pdo->exec("ALTER TABLE job_applications ADD COLUMN application_letter_path VARCHAR(255) NULL AFTER resume_path");
        }
        if (!in_array('committed_at', $cols)) {
            $pdo->exec("ALTER TABLE job_applications ADD COLUMN committed_at DATETIME NULL");
        }
    } catch (Exception $e) {
        // Fallback or ignore
    }
}
?>