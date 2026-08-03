<?php
// register.php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$error = '';
$success = '';

// Initialize form data
$formData = [
    'username' => '',
    'email' => '',
    'firstname' => '',
    'middlename' => '',
    'lastname' => '',
    'suffix' => '',
    'phone' => '',
    'address' => '',
    'birthdate' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'username' => sanitize($_POST['username'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'firstname' => sanitize($_POST['firstname'] ?? ''),
        'middlename' => sanitize($_POST['middlename'] ?? ''),
        'lastname' => sanitize($_POST['lastname'] ?? ''),
        'suffix' => sanitize($_POST['suffix'] ?? ''),
        'phone' => sanitize($_POST['phone'] ?? ''),
        'address' => sanitize($_POST['address'] ?? ''),
        'birthdate' => sanitize($_POST['birthdate'] ?? '')
    ];
    
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($formData['username'])) $errors[] = 'Username is required.';
    if (empty($formData['email'])) $errors[] = 'Email is required.';
    if (empty($formData['firstname'])) $errors[] = 'First name is required.';
    if (empty($formData['lastname'])) $errors[] = 'Last name is required.';
    if (empty($password)) $errors[] = 'Password is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters long.';
    if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (!empty($formData['phone']) && !preg_match('/^[0-9+\-\s()]+$/', $formData['phone'])) {
        $errors[] = 'Invalid phone number format.';
    }
    if (!empty($formData['birthdate']) && !validateBirthdate($formData['birthdate'])) {
        $errors[] = 'You must be at least 15 years old.';
    }
    
    if (empty($errors)) {
        $result = registerUser(
            $formData['username'],
            $formData['email'],
            $password,
            $formData['firstname'],
            $formData['lastname'],
            $formData['middlename'] ?: null,
            $formData['suffix'] ?: null,
            $formData['phone'] ?: null,
            $formData['address'] ?: null,
            $formData['birthdate'] ?: null,
            $pdo
        );
        
        if (isset($result['success'])) {
            $success = $result['message'];
            // Clear form data
            $formData = array_map(function() { return ''; }, $formData);
        } else {
            $error = $result['error'];
        }
    } else {
        $error = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Internship Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
    <style>
        /* ---- Reset & Base ---- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #0b2614; /* Dark Green */
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            line-height: 1.5;
        }

        /* ---- Side-by-Side Container ---- */
        .container {
            display: flex;
            flex-direction: row;
            align-items: flex-start;
            justify-content: center;
            gap: 4rem;
            max-width: 1200px;
            width: 100%;
        }

        /* ---- Left Side: Branding / System Title ---- */
        .branding {
            flex: 1;
            max-width: 500px;
            text-align: left;
            padding-top: 2rem; /* align top with card */
        }

        .branding .flag-stripe {
            width: 100%;
            height: 8px;
            background: #ffce00; /* Yellow */
            margin-bottom: 1.5rem;
        }

        .branding h1 {
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 300;
            letter-spacing: -0.01em;
            line-height: 1.5;
        }

        .branding h1 strong {
            color: #ffce00;
            font-weight: 600;
        }

        /* ---- Right Side: Auth Shell / Card ---- */
        .auth-shell {
            flex: 0 0 700px; /* wide enough for registration form */
            width: 100%;
        }

        .auth-card {
            background: #ffffff;
            border-radius: 0px; /* No edges */
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
            padding: 2.5rem 2.5rem 2rem;
            transition: all 0.2s ease;
        }

        @media (max-width: 600px) {
            .auth-card {
                padding: 1.75rem 1.25rem;
            }
        }

        /* ---- Header (Inside Card) ---- */
        .auth-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #ffce00;
            color: #0b2614;
            width: 64px;
            height: 64px;
            border-radius: 0px; /* No edges */
            font-size: 2rem;
            margin-bottom: 1.25rem;
        }

        .auth-card h2 {
            font-size: 1.75rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            margin-bottom: 0.25rem;
            color: #0b2614;
        }

        .auth-card .subhead {
            color: #64748b;
            margin-bottom: 1.75rem;
            font-size: 0.95rem;
        }

        /* ---- Progress Bar (Sharp) ---- */
        .progress-wrapper {
            margin-bottom: 2rem;
        }

        .progress-steps {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 0.5rem;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 10%;
            right: 10%;
            height: 3px;
            background: #e2e8f0;
            transform: translateY(-50%);
            z-index: 1;
        }

        .step-indicator {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 2;
            background: #ffffff;
            padding: 0 0.5rem;
        }

        .step-box {
            width: 36px;
            height: 36px;
            border-radius: 0px; /* No edges - square */
            background: #e2e8f0;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            transition: background 0.3s, color 0.3s, border 0.3s;
            border: 3px solid transparent;
        }

        .step-box.active {
            background: #ffce00;
            color: #0b2614;
            border-color: #ffce00;
            box-shadow: 0 4px 10px rgba(255, 206, 0, 0.3);
        }

        .step-box.completed {
            background: #10b981;
            color: #fff;
            border-color: #10b981;
        }

        .step-label {
            font-size: 0.7rem;
            margin-top: 0.3rem;
            color: #94a3b8;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .step-label.active {
            color: #0b2614;
            font-weight: 600;
        }

        /* ---- Steps Container ---- */
        .step-content {
            display: none;
            animation: fadeIn 0.25s ease;
        }

        .step-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ---- Form Elements ---- */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem 1.5rem;
        }

        @media (max-width: 520px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-bottom: 0.25rem;
        }

        .form-group label {
            font-size: 0.85rem;
            font-weight: 500;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 0.2rem;
        }

        .form-group label .required {
            color: #ef4444;
            font-weight: 600;
            margin-left: 0.1rem;
        }

        .form-group input,
        .form-group textarea {
            font-family: inherit;
            font-size: 0.95rem;
            padding: 0.7rem 0.9rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 0px; /* No edges */
            background: #fafcfd;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            width: 100%;
            color: #0f172a;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0b2614;
            box-shadow: 0 0 0 4px rgba(11, 38, 20, 0.12);
            background: #ffffff;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #94a3b8;
            font-weight: 300;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 60px;
        }

        .form-group small {
            color: #64748b;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }

        /* ---- Navigation Buttons ---- */
        .step-actions {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 1.75rem;
        }

        .btn-secondary,
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.8rem;
            border: none;
            border-radius: 0px; /* No edges */
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: background 0.15s ease, transform 0.1s ease, box-shadow 0.15s;
            min-width: 100px;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #334155;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .btn-primary {
            background: #ffce00; /* Yellow */
            color: #0b2614; /* Dark Green text */
            box-shadow: 0 4px 12px rgba(255, 206, 0, 0.3);
        }

        .btn-primary:hover {
            background: #e6b800;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 206, 0, 0.35);
        }

        .btn-primary:active,
        .btn-secondary:active {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-primary .fa-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ---- Auth Link (below form) ---- */
        .auth-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.95rem;
            color: #475569;
        }

        .auth-link a {
            color: #0b2614;
            font-weight: 600;
            text-decoration: none;
            border-bottom: 2px solid #ffce00;
            padding-bottom: 1px;
            transition: all 0.15s;
        }

        .auth-link a:hover {
            color: #ffce00;
            border-bottom-color: #0b2614;
        }

        /* ---- Modal Overlay (common for both error and success) ---- */
        .modal-overlay {
            display: none; /* Hidden by default, shown via JS */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.25s ease;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            max-width: 440px;
            width: 90%;
            padding: 2rem 2rem 1.75rem;
            border-radius: 0px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            text-align: center;
            animation: slideUp 0.3s ease;
        }

        .modal-box .modal-icon {
            font-size: 2.5rem;
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            border-radius: 0px;
        }

        .modal-box .modal-icon.error-icon {
            color: #b91c1c;
            background: #fef2f2;
        }

        .modal-box .modal-icon.success-icon {
            color: #065f46;
            background: #ecfdf5;
        }

        .modal-box h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: #0b2614;
            margin-bottom: 0.5rem;
        }

        .modal-box p {
            color: #475569;
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
        }

        .modal-box .btn-modal {
            background: #0b2614;
            color: #ffffff;
            border: none;
            padding: 0.6rem 2rem;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            border-radius: 0px;
            transition: background 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }

        .modal-box .btn-modal:hover {
            background: #1a3d26;
        }

        .modal-box .btn-modal.primary {
            background: #ffce00;
            color: #0b2614;
        }

        .modal-box .btn-modal.primary:hover {
            background: #e6b800;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* ---- Responsive ---- */
        @media (max-width: 1024px) {
            .container {
                flex-direction: column;
                gap: 2.5rem;
                align-items: center;
            }
            .branding {
                text-align: center;
                max-width: 100%;
            }
            .auth-shell {
                flex: 1;
                width: 100%;
                max-width: 700px;
            }
        }
    </style>
</head>
<body>
    
    <div class="container">
        
        <!-- Left Side: System Title -->
        <div class="branding">
            <div class="flag-stripe"></div>
            <h1><strong>Role-Based Secure Internship Management</strong> and Decision Support System with Anomaly Detection for Evaluation and Performance Monitoring.</h1>
        </div>

        <!-- Right Side: Registration Card -->
        <div class="auth-shell">
            <div class="auth-card">
                <div class="auth-icon"><i class="fa-solid fa-user-plus"></i></div>
                <h2>Create your account</h2>
                <p class="subhead">Join the internship RBAC system – complete all steps to register.</p>

                <!-- No inline alerts – we use a modal instead -->

                <!-- Progress Bar -->
                <div class="progress-wrapper">
                    <div class="progress-steps" id="progressSteps">
                        <div class="step-indicator" data-step="1">
                            <div class="step-box active" id="box1">1</div>
                            <span class="step-label active" id="label1">Personal</span>
                        </div>
                        <div class="step-indicator" data-step="2">
                            <div class="step-box" id="box2">2</div>
                            <span class="step-label" id="label2">Contact</span>
                        </div>
                        <div class="step-indicator" data-step="3">
                            <div class="step-box" id="box3">3</div>
                            <span class="step-label" id="label3">Account</span>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" id="registerForm">
                    <!-- Step 1: Personal Information -->
                    <div class="step-content active" data-step="1">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="firstname">First Name <span class="required">*</span></label>
                                <input type="text" id="firstname" name="firstname" value="<?php echo htmlspecialchars($formData['firstname']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="middlename">Middle Name</label>
                                <input type="text" id="middlename" name="middlename" value="<?php echo htmlspecialchars($formData['middlename']); ?>">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="lastname">Last Name <span class="required">*</span></label>
                                <input type="text" id="lastname" name="lastname" value="<?php echo htmlspecialchars($formData['lastname']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="suffix">Suffix</label>
                                <input type="text" id="suffix" name="suffix" value="<?php echo htmlspecialchars($formData['suffix']); ?>" placeholder="Jr., Sr., II, III">
                            </div>
                        </div>
                        <div class="step-actions">
                            <span></span> <!-- empty for alignment -->
                            <button type="button" class="btn-primary next-step">Next <i class="fa-solid fa-arrow-right"></i></button>
                        </div>
                    </div>

                    <!-- Step 2: Contact & Details -->
                    <div class="step-content" data-step="2">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($formData['phone']); ?>" placeholder="+63 912 345 6789">
                            </div>
                            <div class="form-group">
                                <label for="birthdate">Birthdate <span class="required">*</span></label>
                                <input type="date" id="birthdate" name="birthdate" value="<?php echo htmlspecialchars($formData['birthdate']); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" rows="2"><?php echo htmlspecialchars($formData['address']); ?></textarea>
                        </div>
                        <div class="step-actions">
                            <button type="button" class="btn-secondary prev-step"><i class="fa-solid fa-arrow-left"></i> Previous</button>
                            <button type="button" class="btn-primary next-step">Next <i class="fa-solid fa-arrow-right"></i></button>
                        </div>
                    </div>

                    <!-- Step 3: Account Setup -->
                    <div class="step-content" data-step="3">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="username">Username <span class="required">*</span></label>
                                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($formData['username']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email <span class="required">*</span></label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>" required>
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="password">Password <span class="required">*</span></label>
                                <input type="password" id="password" name="password" required>
                                <small>Minimum 6 characters</small>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>
                        <div class="step-actions">
                            <button type="button" class="btn-secondary prev-step"><i class="fa-solid fa-arrow-left"></i> Previous</button>
                            <button type="submit" class="btn-primary" id="submitBtn">
                                <i class="fa-regular fa-paper-plane"></i> Register
                            </button>
                        </div>
                    </div>
                </form>

                <div class="auth-link">
                    Already have an account? <a href="login.php">Login here</a>
                </div>
            </div>
        </div>

    </div>

    <!-- ===== MODAL (for both error and success) ===== -->
    <div class="modal-overlay" id="messageModal">
        <div class="modal-box">
            <div class="modal-icon" id="modalIcon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <h3 id="modalTitle">Oops!</h3>
            <p id="modalMessage">Something went wrong.</p>
            <!-- The button will be changed dynamically -->
            <button class="btn-modal" id="modalButton">Got it</button>
        </div>
    </div>

    <script>
        (function() {
            // ----- Step navigation (unchanged) -----
            const form = document.getElementById('registerForm');
            const steps = document.querySelectorAll('.step-content');
            const boxes = [
                document.getElementById('box1'),
                document.getElementById('box2'),
                document.getElementById('box3')
            ];
            const labels = [
                document.getElementById('label1'),
                document.getElementById('label2'),
                document.getElementById('label3')
            ];

            let currentStep = 1; // 1-3

            function showStep(step) {
                steps.forEach((el, index) => {
                    const stepNum = index + 1;
                    if (stepNum === step) {
                        el.classList.add('active');
                    } else {
                        el.classList.remove('active');
                    }
                });

                boxes.forEach((box, idx) => {
                    const num = idx + 1;
                    box.classList.remove('active', 'completed');
                    labels[idx].classList.remove('active');
                    if (num < step) {
                        box.classList.add('completed');
                    } else if (num === step) {
                        box.classList.add('active');
                        labels[idx].classList.add('active');
                    }
                });

                currentStep = step;
                const card = document.querySelector('.auth-card');
                if (window.innerWidth <= 600) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }

            function nextStep() {
                if (currentStep < 3) showStep(currentStep + 1);
            }

            function prevStep() {
                if (currentStep > 1) showStep(currentStep - 1);
            }

            document.querySelectorAll('.next-step').forEach(btn => btn.addEventListener('click', nextStep));
            document.querySelectorAll('.prev-step').forEach(btn => btn.addEventListener('click', prevStep));

            // If there's a message (error or success), show the modal and auto-jump to step 3 if needed
            const error = <?php echo json_encode($error); ?>;
            const success = <?php echo json_encode($success); ?>;

            // ---- Modal logic ----
            const modal = document.getElementById('messageModal');
            const modalIcon = document.getElementById('modalIcon');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const modalButton = document.getElementById('modalButton');

            function showModal(type, message) {
                // type: 'error' or 'success'
                if (type === 'error') {
                    modalIcon.className = 'modal-icon error-icon';
                    modalIcon.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i>';
                    modalTitle.textContent = 'Oops!';
                    modalButton.textContent = 'Got it';
                    modalButton.className = 'btn-modal';
                    modalButton.onclick = function() {
                        modal.classList.remove('active');
                    };
                } else { // success
                    modalIcon.className = 'modal-icon success-icon';
                    modalIcon.innerHTML = '<i class="fa-regular fa-circle-check"></i>';
                    modalTitle.textContent = 'Success!';
                    modalButton.textContent = 'Go to Admin';
                    modalButton.className = 'btn-modal primary';
                    modalButton.onclick = function() {
                        window.location.href = 'login.php'; // redirect to login page
                    };
                }
                modalMessage.innerHTML = message; // may contain <br> tags
                modal.classList.add('active');
            }

            // Show modal if there's an error or success
            if (error) {
                showModal('error', error);
                // After error, keep the form as is; step might be 3 if submitted
                // We'll let the step logic below handle that
            } else if (success) {
                showModal('success', success);
                // Clear form data (already cleared by PHP)
            }

            // Set initial step: if there was a message (submitted), go to step 3; else step 1
            if (error || success) {
                showStep(3);
            } else {
                showStep(1);
            }

            // Close modal when clicking outside (overlay) – only for error modals (success modal has a primary button)
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    // Only close if it's an error modal (button text is "Got it")
                    if (modalButton.textContent === 'Got it') {
                        modal.classList.remove('active');
                    }
                }
            });

            // Close with Escape key – same condition
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    if (modalButton.textContent === 'Got it') {
                        modal.classList.remove('active');
                    }
                }
            });

        })();
    </script>

</body>
</html>