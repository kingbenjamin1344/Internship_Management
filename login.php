<?php
// login.php
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password.';
    } else {
        $result = authenticateUser($username, $password, $pdo);
        
        if (isset($result['success'])) {
            redirect(getRoleDashboard($result['role']));
        } else {
            $error = $result['error'];
        }
    }
}

// If already logged in, redirect to dashboard
if (isLoggedIn() && isActive()) {
    redirect(getRoleDashboard(getUserRole()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Internship Management System</title>
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
            align-items: center;
            justify-content: center;
            gap: 4rem;
            max-width: 1200px;
            width: 100%;
        }

        /* ---- Left Side: Branding / System Title ---- */
        .branding {
            flex: 1;
            max-width: 600px;
            text-align: left;
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
            flex: 0 0 440px;
        }

        .auth-card {
            background: #ffffff;
            border-radius: 0px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5);
            padding: 2.5rem 2.5rem 2rem;
            transition: all 0.2s ease;
        }

        @media (max-width: 480px) {
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
            border-radius: 0px;
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

        .auth-card p {
            color: #64748b;
            margin-bottom: 1.75rem;
            font-size: 0.95rem;
        }

        /* ---- Form ---- */
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            margin-bottom: 1.25rem;
        }

        .form-group label {
            font-size: 0.85rem;
            font-weight: 500;
            color: #334155;
        }

        .form-group input {
            font-family: inherit;
            font-size: 0.95rem;
            padding: 0.7rem 0.9rem;
            border: 1.5px solid #e2e8f0;
            border-radius: 0px;
            background: #fafcfd;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            width: 100%;
            color: #0f172a;
        }

        .form-group input:focus {
            outline: none;
            border-color: #0b2614;
            box-shadow: 0 0 0 4px rgba(11, 38, 20, 0.12);
            background: #ffffff;
        }

        /* ---- Button ---- */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: #ffce00;
            color: #0b2614;
            font-weight: 600;
            font-size: 1rem;
            padding: 0.85rem 1.8rem;
            border: none;
            border-radius: 0px;
            cursor: pointer;
            transition: background 0.2s ease;
            width: 100%;
            margin-top: 0.25rem;
            letter-spacing: 0.01em;
        }

        .btn-primary:hover {
            background: #e6b800;
        }

        /* ---- Auth Link ---- */
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

        /* ---- Modal Overlay ---- */
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
            color: #b91c1c;
            background: #fef2f2;
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            border-radius: 0px;
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

        .modal-box .btn-close-modal {
            background: #0b2614;
            color: #ffffff;
            border: none;
            padding: 0.6rem 2rem;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            border-radius: 0px;
            transition: background 0.2s ease;
        }

        .modal-box .btn-close-modal:hover {
            background: #1a3d26;
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
        @media (max-width: 900px) {
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
                max-width: 440px;
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

        <!-- Right Side: Login Card -->
        <div class="auth-shell">
            <div class="auth-card">
                <div class="auth-icon"><i class="fa-solid fa-lock"></i></div>
                <h2>Welcome back</h2>
                <p>Sign in to access your dashboard.</p>

                <!-- No inline error – we'll use a modal instead -->

                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <input type="text" id="username" name="username" placeholder="Enter your username or email" required autofocus>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="••••••••" required>
                    </div>
                    <button class="btn-primary" type="submit">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i> Login
                    </button>
                </form>

                <div class="auth-link">
                    Don't have an account? <a href="register.php">Register here</a>
                </div>
            </div>
        </div>

    </div>

    <!-- ===== MODAL ===== -->
    <div class="modal-overlay" id="errorModal">
        <div class="modal-box">
            <div class="modal-icon"><i class="fa-solid fa-circle-exclamation"></i></div>
            <h3>Oops!</h3>
            <p id="modalMessage"><?php echo htmlspecialchars($error); ?></p>
            <button class="btn-close-modal" id="closeModalBtn">Got it</button>
        </div>
    </div>

    <script>
        // Show the modal if there's an error message
        (function() {
            const error = <?php echo json_encode($error); ?>;
            const modal = document.getElementById('errorModal');
            const closeBtn = document.getElementById('closeModalBtn');

            if (error) {
                modal.classList.add('active');
            }

            // Close modal when button is clicked
            closeBtn.addEventListener('click', function() {
                modal.classList.remove('active');
            });

            // Close modal when clicking outside the modal box (on the overlay)
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });

            // Close modal with Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    modal.classList.remove('active');
                }
            });
        })();
    </script>

</body>
</html>