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
    <title>Register - RBAC System</title>
    <link rel="stylesheet" href="assets/styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card" style="width:min(100%, 720px);">
            <div class="auth-icon"><i class="fa-solid fa-user-plus"></i></div>
            <h2>Create your account</h2>
            <p>Join the internship RBAC system and request access to your dashboard.</p>
            <?php if ($error): ?>
                <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <form method="POST" action="">
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

                <div class="form-grid">
                    <div class="form-group">
                        <label for="password">Password <span class="required">*</span></label>
                        <input type="password" id="password" name="password" required>
                        <small style="color: #64748b; font-size: 12px; display:block; margin-top:6px;">Minimum 6 characters</small>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>

                <button class="btn-primary" type="submit">Register</button>
            </form>
            <div class="auth-link">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </div>
    </div>
</body>
</html>