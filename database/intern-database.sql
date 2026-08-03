-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 03, 2026 at 03:56 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `intern-database`
--

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `address` text NOT NULL,
  `industry` varchar(100) NOT NULL,
  `contact_person` varchar(100) NOT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `supervisor_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_progress_reports`
--

CREATE TABLE `daily_progress_reports` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `time_in` time NOT NULL,
  `time_out` time NOT NULL,
  `activities` text NOT NULL,
  `accomplishments` text NOT NULL,
  `issues` text DEFAULT NULL,
  `student_feedback` text DEFAULT NULL,
  `sentiment_label` varchar(50) DEFAULT NULL,
  `sentiment_confidence` decimal(5,4) DEFAULT NULL,
  `sentiment_analyzed_at` timestamp NULL DEFAULT NULL,
  `status` enum('submitted','approved','rejected') DEFAULT 'submitted',
  `submitted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dpr_anomaly_flags`
--

CREATE TABLE `dpr_anomaly_flags` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `flag_type` enum('BULK_SUBMISSION_ANOMALY','LATE_SUBMISSION','NEGATIVE_SENTIMENT','LOW_PERFORMANCE') NOT NULL,
  `flagged_at` datetime NOT NULL DEFAULT current_timestamp(),
  `details` text DEFAULT NULL,
  `is_resolved` tinyint(1) DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dpr_entries`
--

CREATE TABLE `dpr_entries` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `tasks` text NOT NULL,
  `feedback` text DEFAULT NULL,
  `status` enum('In Progress','Completed','Pending') DEFAULT 'In Progress',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `score` int(11) DEFAULT NULL COMMENT 'Score from 1-100',
  `supervisor_feedback` text DEFAULT NULL COMMENT 'Supervisor feedback on the DPR entry',
  `evaluated_at` datetime DEFAULT NULL COMMENT 'When the evaluation was submitted'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `dpr_entries`
--
DELIMITER $$
CREATE TRIGGER `after_dpr_insert` AFTER INSERT ON `dpr_entries` FOR EACH ROW BEGIN
    DECLARE supervisor_id INT;
    DECLARE student_name VARCHAR(255);
    
    -- Get the supervisor for this student
    SELECT 
        COALESCE(c.supervisor_id, j.created_by) INTO supervisor_id
    FROM job_applications a
    INNER JOIN jobs j ON a.job_id = j.id
    INNER JOIN companies c ON j.company_id = c.id
    WHERE a.student_id = NEW.student_id AND a.status = 'committed'
    LIMIT 1;
    
    -- Get student name
    SELECT CONCAT(firstname, ' ', lastname) INTO student_name
    FROM users WHERE id = NEW.student_id;
    
    -- Create notification if supervisor exists
    IF supervisor_id IS NOT NULL THEN
        INSERT INTO notifications (user_id, sender_id, type, title, message, link, created_at, is_read)
        VALUES (
            supervisor_id,
            NEW.student_id,
            'dpr_submitted',
            'New DPR Submitted',
            CONCAT(student_name, ' has submitted a new DPR entry for ', DATE_FORMAT(NEW.date, '%b %d, %Y')),
            CONCAT('myintern.php?dpr_id=', NEW.id),
            NOW(),
            0
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `dss_classifications`
--

CREATE TABLE `dss_classifications` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `classification` enum('AT_RISK','TOP_PERFORMER','NORMAL') NOT NULL DEFAULT 'NORMAL',
  `late_count` int(11) DEFAULT 0,
  `total_dprs` int(11) DEFAULT 0,
  `anomaly_count` int(11) DEFAULT 0,
  `avg_performance` decimal(3,2) DEFAULT 0.00,
  `negative_sentiment_count` int(11) DEFAULT 0,
  `classified_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `responsibility` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `slots_available` int(11) NOT NULL DEFAULT 1,
  `slots_filled` int(11) NOT NULL DEFAULT 0,
  `duration_hours` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_applications`
--

CREATE TABLE `job_applications` (
  `id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `cv_path` varchar(255) DEFAULT NULL,
  `application_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','reviewed','shortlisted','accepted','committed','rejected','withdrawn') DEFAULT 'pending',
  `cover_letter` text DEFAULT NULL,
  `resume_path` varchar(255) DEFAULT NULL,
  `application_letter_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `interview_date` datetime DEFAULT NULL,
  `interview_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `committed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `job_applications`
--
DELIMITER $$
CREATE TRIGGER `after_job_application_commit` AFTER UPDATE ON `job_applications` FOR EACH ROW BEGIN
    DECLARE supervisor_id INT;
    DECLARE student_name VARCHAR(255);
    DECLARE job_title VARCHAR(255);
    
    -- Only proceed if status changed to 'committed'
    IF NEW.status = 'committed' AND OLD.status != 'committed' THEN
        -- Get the supervisor and job details
        SELECT 
            COALESCE(c.supervisor_id, j.created_by),
            j.title INTO supervisor_id, job_title
        FROM jobs j
        INNER JOIN companies c ON j.company_id = c.id
        WHERE j.id = NEW.job_id;
        
        -- Get student name
        SELECT CONCAT(firstname, ' ', lastname) INTO student_name
        FROM users WHERE id = NEW.student_id;
        
        -- Create notification if supervisor exists
        IF supervisor_id IS NOT NULL THEN
            INSERT INTO notifications (user_id, sender_id, type, title, message, link, created_at, is_read)
            VALUES (
                supervisor_id,
                NEW.student_id,
                'job_committed',
                'Student Committed to Job',
                CONCAT(student_name, ' has committed to the job "', job_title, '"'),
                CONCAT('myintern.php?student_id=', NEW.student_id),
                NOW(),
                0
            );
        END IF;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_job_application_insert` AFTER INSERT ON `job_applications` FOR EACH ROW BEGIN
    DECLARE supervisor_id INT;
    DECLARE student_name VARCHAR(255);
    DECLARE job_title VARCHAR(255);
    
    -- Get the supervisor and job details
    SELECT 
        COALESCE(c.supervisor_id, j.created_by),
        j.title INTO supervisor_id, job_title
    FROM jobs j
    INNER JOIN companies c ON j.company_id = c.id
    WHERE j.id = NEW.job_id;
    
    -- Get student name
    SELECT CONCAT(firstname, ' ', lastname) INTO student_name
    FROM users WHERE id = NEW.student_id;
    
    -- Create notification if supervisor exists and application is pending
    IF supervisor_id IS NOT NULL AND NEW.status = 'pending' THEN
        INSERT INTO notifications (user_id, sender_id, type, title, message, link, created_at, is_read)
        VALUES (
            supervisor_id,
            NEW.student_id,
            'job_applied',
            'New Job Application',
            CONCAT(student_name, ' has applied for the job "', job_title, '"'),
            CONCAT('applicant.php?application_id=', NEW.id),
            NOW(),
            0
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(500) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_feedback`
--

CREATE TABLE `student_feedback` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `feedback` text NOT NULL,
  `submitted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supervisor_evaluations`
--

CREATE TABLE `supervisor_evaluations` (
  `id` int(11) NOT NULL,
  `dpr_id` int(11) NOT NULL,
  `supervisor_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `performance_score` int(11) NOT NULL CHECK (`performance_score` >= 1 and `performance_score` <= 5),
  `communication_score` int(11) NOT NULL CHECK (`communication_score` >= 1 and `communication_score` <= 5),
  `technical_score` int(11) NOT NULL CHECK (`technical_score` >= 1 and `technical_score` <= 5),
  `overall_score` decimal(3,2) NOT NULL,
  `supervisor_feedback` text NOT NULL,
  `feedback_sentiment` varchar(50) DEFAULT NULL,
  `feedback_confidence` decimal(5,4) DEFAULT NULL,
  `evaluated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `task_description` text NOT NULL,
  `hours` decimal(5,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `firstname` varchar(50) NOT NULL,
  `middlename` varchar(50) DEFAULT NULL,
  `lastname` varchar(50) NOT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `role` enum('admin','coordinator','supervisor','student') DEFAULT 'student',
  `status` enum('pending','active','inactive') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `profile_picture`, `password`, `firstname`, `middlename`, `lastname`, `suffix`, `phone`, `address`, `birthdate`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'admin@example.com', NULL, '$2y$10$UkcXrgRE/naJw.K5gzI8yOwBsO8ottjiuKlsnj5rCha1t3pd59jYW', 'John', 'Doe', 'Donur', NULL, '09103456780', 'P-5 Barangay Marcos Agusan Del Norte', NULL, 'admin', 'active', '2026-07-06 14:59:51', '2026-08-03 01:38:22'),
(3, 'jason.hechanova', 'jason.hechanova@csucc.edu.ph', 'user_3_1785409040.jpg', '$2y$10$fvsmNknPT7GeMLy8oAUKwuht87Go1VY7ZShxgh2iJ0HVJfth7z8o2', 'Jason', 'Gay', 'Hechanova', '', '09123458833', 'P-5 Barangay Marcos Magallanes Agusan Del Norte', '2004-02-09', 'coordinator', 'active', '2026-07-07 02:44:07', '2026-08-03 01:33:04'),
(34, 'rene.butter', 'rene.butter@gmail.com', 'user_34_1785721808.jpg', '$2y$10$6XcKMC0iOA1dZJl3OgZ8k.FlTmSJJxOBStrSzLZYej5tpL/nrA6ma', 'Rene', '', 'Butterbornia', '', '0910 888 6768', 'Magallanes Agusan del Norte', '1999-01-01', 'student', 'active', '2026-08-01 07:40:00', '2026-08-03 01:50:08');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `daily_progress_reports`
--
ALTER TABLE `daily_progress_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dpr_user` (`user_id`),
  ADD KEY `idx_dpr_date` (`report_date`),
  ADD KEY `idx_dpr_submitted` (`submitted_at`),
  ADD KEY `idx_dpr_sentiment` (`sentiment_label`);

--
-- Indexes for table `dpr_anomaly_flags`
--
ALTER TABLE `dpr_anomaly_flags`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_af_student` (`student_id`),
  ADD KEY `idx_af_type` (`flag_type`),
  ADD KEY `idx_af_flagged` (`flagged_at`);

--
-- Indexes for table `dpr_entries`
--
ALTER TABLE `dpr_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student_date` (`student_id`,`date`);

--
-- Indexes for table `dss_classifications`
--
ALTER TABLE `dss_classifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dss_student` (`student_id`),
  ADD KEY `idx_dss_class` (`classification`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `idx_company_id` (`company_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_application` (`job_id`,`student_id`),
  ADD KEY `idx_applications_job` (`job_id`),
  ADD KEY `idx_applications_student` (`student_id`),
  ADD KEY `idx_applications_status` (`status`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `student_feedback`
--
ALTER TABLE `student_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `supervisor_evaluations`
--
ALTER TABLE `supervisor_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_eval_dpr` (`dpr_id`),
  ADD KEY `idx_eval_supervisor` (`supervisor_id`),
  ADD KEY `idx_eval_student` (`student_id`),
  ADD KEY `idx_eval_date` (`evaluated_at`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `daily_progress_reports`
--
ALTER TABLE `daily_progress_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `dpr_anomaly_flags`
--
ALTER TABLE `dpr_anomaly_flags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dpr_entries`
--
ALTER TABLE `dpr_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `dss_classifications`
--
ALTER TABLE `dss_classifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=120;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_feedback`
--
ALTER TABLE `student_feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supervisor_evaluations`
--
ALTER TABLE `supervisor_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `jobs`
--
ALTER TABLE `jobs`
  ADD CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `jobs_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `jobs_ibfk_3` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `fk_applications_job` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_applications_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_feedback`
--
ALTER TABLE `student_feedback`
  ADD CONSTRAINT `student_feedback_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `supervisor_evaluations`
--
ALTER TABLE `supervisor_evaluations`
  ADD CONSTRAINT `fk_eval_dpr` FOREIGN KEY (`dpr_id`) REFERENCES `daily_progress_reports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_eval_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_eval_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
