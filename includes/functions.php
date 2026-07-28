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
    // Main DPR table with sentiment analysis fields
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS daily_progress_reports (
            id                   INT AUTO_INCREMENT PRIMARY KEY,
            user_id              INT NOT NULL,
            report_date          DATE NOT NULL,
            time_in              TIME NOT NULL,
            time_out             TIME NOT NULL,
            activities           TEXT NOT NULL,
            accomplishments      TEXT NOT NULL,
            issues               TEXT NULL,
            student_feedback     TEXT NULL,
            sentiment_label      VARCHAR(50) NULL,
            sentiment_confidence DECIMAL(5,4) NULL,
            sentiment_analyzed_at TIMESTAMP NULL,
            status               ENUM('submitted','approved','rejected') DEFAULT 'submitted',
            submitted_at         DATETIME NULL,
            created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_dpr_user (user_id),
            INDEX idx_dpr_date (report_date),
            INDEX idx_dpr_submitted (submitted_at),
            INDEX idx_dpr_sentiment (sentiment_label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    // Supervisor evaluations table
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS supervisor_evaluations (
            id                     INT AUTO_INCREMENT PRIMARY KEY,
            dpr_id                 INT NOT NULL,
            supervisor_id          INT NOT NULL,
            student_id             INT NOT NULL,
            performance_score      INT NOT NULL CHECK (performance_score >= 1 AND performance_score <= 5),
            communication_score    INT NOT NULL CHECK (communication_score >= 1 AND communication_score <= 5),
            technical_score        INT NOT NULL CHECK (technical_score >= 1 AND technical_score <= 5),
            overall_score          DECIMAL(3,2) NOT NULL,
            supervisor_feedback    TEXT NOT NULL,
            feedback_sentiment     VARCHAR(50) NULL,
            feedback_confidence    DECIMAL(5,4) NULL,
            evaluated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_eval_dpr FOREIGN KEY (dpr_id) REFERENCES daily_progress_reports(id) ON DELETE CASCADE,
            CONSTRAINT fk_eval_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_eval_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_eval_dpr (dpr_id),
            INDEX idx_eval_supervisor (supervisor_id),
            INDEX idx_eval_student (student_id),
            INDEX idx_eval_date (evaluated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    // Anomaly flags for Mv (bulk/late submissions)
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS dpr_anomaly_flags (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            student_id    INT NOT NULL,
            flag_type     ENUM('BULK_SUBMISSION_ANOMALY','LATE_SUBMISSION','NEGATIVE_SENTIMENT','LOW_PERFORMANCE') NOT NULL,
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
            avg_performance DECIMAL(3,2) DEFAULT 0.00,
            negative_sentiment_count INT DEFAULT 0,
            classified_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            notes           TEXT NULL,
            UNIQUE KEY uq_dss_student (student_id),
            INDEX idx_dss_class (classification)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function ensureNotificationTables($pdo) {
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            sender_id INT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'general',
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_notifications_user (user_id),
            INDEX idx_notifications_read (is_read),
            INDEX idx_notifications_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function createSystemNotification($pdo, $user_id, $sender_id, $type, $title, $message, $link = null) {
    if (empty($user_id)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, sender_id, type, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
        );
        return $stmt->execute([$user_id, $sender_id, $type, $title, $message, $link]);
    } catch (Exception $e) {
        error_log("Create notification error: " . $e->getMessage());
        return false;
    }
}

function findSupervisorForJob($pdo, $job_id) {
    if (empty($job_id)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT c.supervisor_id FROM jobs j INNER JOIN companies c ON j.company_id = c.id WHERE j.id = ? LIMIT 1"
        );
        $stmt->execute([$job_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['supervisor_id'] ? (int)$row['supervisor_id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function findSupervisorForStudent($pdo, $student_id) {
    if (empty($student_id)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT c.supervisor_id FROM job_applications a INNER JOIN jobs j ON a.job_id = j.id INNER JOIN companies c ON j.company_id = c.id WHERE a.student_id = ? ORDER BY a.committed_at DESC, a.updated_at DESC, a.created_at DESC LIMIT 1"
        );
        $stmt->execute([$student_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['supervisor_id'] ? (int)$row['supervisor_id'] : null;
    } catch (Exception $e) {
        return null;
    }
}

function ensureInternshipTables($pdo) {
    ensureNotificationTables($pdo);

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

/**
 * Check if a student has committed to a job
 * @param PDO $pdo Database connection
 * @param int $studentId Student ID to check
 * @return array|false Returns job details if committed, false otherwise
 */
function getStudentCommittedJob($pdo, $studentId) {
    try {
        $stmt = $pdo->prepare('
            SELECT 
                a.id as application_id,
                a.job_id,
                a.committed_at,
                j.title as job_title,
                c.company_name,
                c.id as company_id
            FROM job_applications a
            INNER JOIN jobs j ON a.job_id = j.id  
            INNER JOIN companies c ON j.company_id = c.id
            WHERE a.student_id = ? AND a.status = "committed"
            LIMIT 1
        ');
        $stmt->execute([$studentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if a student has committed to any job (simple boolean check)
 * @param PDO $pdo Database connection
 * @param int $studentId Student ID to check
 * @return bool True if student has committed to a job, false otherwise
 */
function hasCommittedJob($pdo, $studentId) {
    return (bool)getStudentCommittedJob($pdo, $studentId);
}

/**
 * Analyze sentiment of text using the Python sentiment service
 * @param string $text Text to analyze
 * @return array|false Returns array with sentiment and confidence, false on error
 */
function analyzeSentiment($text) {
    if (empty(trim($text))) {
        return false;
    }

    $serviceUrl = 'http://localhost:8000/analyze-sentiment';
    $postData = json_encode(['text' => $text]);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $postData,
            'timeout' => 10
        ]
    ]);

    try {
        $response = @file_get_contents($serviceUrl, false, $context);
        if ($response === false) {
            error_log("Sentiment analysis service unavailable");
            return false;
        }

        $result = json_decode($response, true);
        if (isset($result['sentiment']) && isset($result['confidence'])) {
            return [
                'sentiment' => $result['sentiment'],
                'confidence' => (float)$result['confidence']
            ];
        }
    } catch (Exception $e) {
        error_log("Sentiment analysis error: " . $e->getMessage());
    }

    return false;
}

/**
 * Process DPR submission with sentiment analysis
 * @param PDO $pdo Database connection
 * @param int $userId Student user ID
 * @param array $dprData DPR data array
 * @return array Result array with success status and message
 */
function processDprSubmission($pdo, $userId, $dprData) {
    try {
        $pdo->beginTransaction();

        // First, insert the DPR entry
        $stmt = $pdo->prepare("
            INSERT INTO daily_progress_reports (
                user_id, report_date, time_in, time_out, activities, 
                accomplishments, issues, student_feedback, submitted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $dprData['report_date'],
            $dprData['time_in'],
            $dprData['time_out'],
            $dprData['activities'],
            $dprData['accomplishments'],
            $dprData['issues'] ?? null,
            $dprData['student_feedback'] ?? null
        ]);

        $dprId = $pdo->lastInsertId();

        // Analyze sentiment if feedback provided
        if (!empty($dprData['student_feedback'])) {
            $sentimentResult = analyzeSentiment($dprData['student_feedback']);
            if ($sentimentResult) {
                $updateStmt = $pdo->prepare("
                    UPDATE daily_progress_reports 
                    SET sentiment_label = ?, sentiment_confidence = ?, sentiment_analyzed_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $sentimentResult['sentiment'],
                    $sentimentResult['confidence'],
                    $dprId
                ]);

                // Flag negative sentiment if needed
                if (strtolower($sentimentResult['sentiment']) === 'negative' || 
                    strtolower($sentimentResult['sentiment']) === 'very negative') {
                    flagNegativeSentiment($pdo, $userId, $dprId, $sentimentResult);
                }
            }
        }

        $pdo->commit();
        return ['success' => true, 'message' => 'DPR submitted successfully', 'dpr_id' => $dprId];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("DPR submission error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to submit DPR'];
    }
}

/**
 * Process supervisor evaluation with sentiment analysis
 * @param PDO $pdo Database connection
 * @param array $evaluationData Evaluation data
 * @return array Result array
 */
function processSupervisorEvaluation($pdo, $evaluationData) {
    try {
        $pdo->beginTransaction();

        // Calculate overall score
        $overallScore = ($evaluationData['performance_score'] + 
                        $evaluationData['communication_score'] + 
                        $evaluationData['technical_score']) / 3.0;

        // Insert evaluation
        $stmt = $pdo->prepare("
            INSERT INTO supervisor_evaluations (
                dpr_id, supervisor_id, student_id, performance_score,
                communication_score, technical_score, overall_score, supervisor_feedback
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $evaluationData['dpr_id'],
            $evaluationData['supervisor_id'],
            $evaluationData['student_id'],
            $evaluationData['performance_score'],
            $evaluationData['communication_score'],
            $evaluationData['technical_score'],
            $overallScore,
            $evaluationData['supervisor_feedback']
        ]);

        $evaluationId = $pdo->lastInsertId();

        // Analyze sentiment of supervisor feedback
        if (!empty($evaluationData['supervisor_feedback'])) {
            $sentimentResult = analyzeSentiment($evaluationData['supervisor_feedback']);
            if ($sentimentResult) {
                $updateStmt = $pdo->prepare("
                    UPDATE supervisor_evaluations 
                    SET feedback_sentiment = ?, feedback_confidence = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $sentimentResult['sentiment'],
                    $sentimentResult['confidence'],
                    $evaluationId
                ]);
            }
        }

        // Check for low performance and flag anomalies
        if ($overallScore < 2.0) {
            flagLowPerformance($pdo, $evaluationData['student_id'], $evaluationId, $overallScore);
        }

        // Update DSS classification
        updateDssClassification($pdo, $evaluationData['student_id']);

        $pdo->commit();
        return ['success' => true, 'message' => 'Evaluation submitted successfully'];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Evaluation submission error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to submit evaluation'];
    }
}

/**
 * Flag negative sentiment
 */
function flagNegativeSentiment($pdo, $studentId, $dprId, $sentimentResult) {
    $details = "Negative sentiment detected in DPR #{$dprId}: {$sentimentResult['sentiment']} (confidence: " . 
               round($sentimentResult['confidence'] * 100, 2) . "%)";
    
    $stmt = $pdo->prepare("
        INSERT INTO dpr_anomaly_flags (student_id, flag_type, details)
        VALUES (?, 'NEGATIVE_SENTIMENT', ?)
    ");
    $stmt->execute([$studentId, $details]);

    // Notify coordinators
    $userStmt = $pdo->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
    $userStmt->execute([$studentId]);
    $user = $userStmt->fetch();
    $studentName = ($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '');

    require_once __DIR__ . '/notifications.php';
    notifyCoordinators($pdo, 'negative_sentiment', 
        "Negative sentiment detected in DPR by {$studentName}",
        '../coordinator/evaluation.php');
}

/**
 * Flag low performance
 */
function flagLowPerformance($pdo, $studentId, $evaluationId, $score) {
    $details = "Low performance score detected in evaluation #{$evaluationId}: " . round($score, 2) . "/5.0";
    
    $stmt = $pdo->prepare("
        INSERT INTO dpr_anomaly_flags (student_id, flag_type, details)
        VALUES (?, 'LOW_PERFORMANCE', ?)
    ");
    $stmt->execute([$studentId, $details]);

    // Notify coordinators
    $userStmt = $pdo->prepare("SELECT firstname, lastname FROM users WHERE id = ?");
    $userStmt->execute([$studentId]);
    $user = $userStmt->fetch();
    $studentName = ($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '');

    require_once __DIR__ . '/notifications.php';
    notifyCoordinators($pdo, 'low_performance',
        "Low performance score detected for {$studentName}: " . round($score, 2) . "/5.0",
        '../coordinator/evaluation.php');
}

/**
 * Update DSS classification based on all metrics
 */
function updateDssClassification($pdo, $studentId) {
    // Get metrics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_dprs,
            AVG(CASE WHEN se.overall_score IS NOT NULL THEN se.overall_score ELSE 3.0 END) as avg_performance
        FROM daily_progress_reports dpr
        LEFT JOIN supervisor_evaluations se ON dpr.id = se.dpr_id
        WHERE dpr.user_id = ?
    ");
    $stmt->execute([$studentId]);
    $metrics = $stmt->fetch();

    // Count anomalies
    $anomalyStmt = $pdo->prepare("
        SELECT COUNT(*) as anomaly_count FROM dpr_anomaly_flags 
        WHERE student_id = ? AND flag_type IN ('NEGATIVE_SENTIMENT', 'LOW_PERFORMANCE')
    ");
    $anomalyStmt->execute([$studentId]);
    $anomalies = $anomalyStmt->fetch();

    // Count late submissions
    $lateStmt = $pdo->prepare("
        SELECT COUNT(*) as late_count FROM dpr_anomaly_flags 
        WHERE student_id = ? AND flag_type = 'LATE_SUBMISSION'
    ");
    $lateStmt->execute([$studentId]);
    $late = $lateStmt->fetch();

    // Count negative sentiments
    $negSentimentStmt = $pdo->prepare("
        SELECT COUNT(*) as neg_count FROM daily_progress_reports 
        WHERE user_id = ? AND sentiment_label IN ('Negative', 'Very Negative')
    ");
    $negSentimentStmt->execute([$studentId]);
    $negSentiment = $negSentimentStmt->fetch();

    // Classification logic
    $totalDprs = (int)$metrics['total_dprs'];
    $avgPerformance = (float)$metrics['avg_performance'];
    $anomalyCount = (int)$anomalies['anomaly_count'];
    $lateCount = (int)$late['late_count'];
    $negSentimentCount = (int)$negSentiment['neg_count'];

    if ($lateCount > 3 || $anomalyCount > 2 || $avgPerformance < 2.5 || $negSentimentCount > 3) {
        $classification = 'AT_RISK';
    } elseif ($lateCount === 0 && $anomalyCount === 0 && $avgPerformance >= 4.0 && $totalDprs >= 5) {
        $classification = 'TOP_PERFORMER';
    } else {
        $classification = 'NORMAL';
    }

    // Update classification
    $updateStmt = $pdo->prepare("
        INSERT INTO dss_classifications (
            student_id, classification, late_count, total_dprs, 
            anomaly_count, avg_performance, negative_sentiment_count
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            classification = VALUES(classification),
            late_count = VALUES(late_count),
            total_dprs = VALUES(total_dprs),
            anomaly_count = VALUES(anomaly_count),
            avg_performance = VALUES(avg_performance),
            negative_sentiment_count = VALUES(negative_sentiment_count),
            updated_at = NOW()
    ");

    $updateStmt->execute([
        $studentId, $classification, $lateCount, $totalDprs, 
        $anomalyCount, $avgPerformance, $negSentimentCount
    ]);

    return $classification;
}
?>