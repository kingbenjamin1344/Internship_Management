<?php
// index.php
require_once 'includes/functions.php';

// If logged in, redirect to appropriate dashboard
if (isLoggedIn() && isActive()) {
    $role = getUserRole();
    $dashboard = getRoleDashboard($role);
    redirect($dashboard);
} else {
    redirect('login.php');
}
?>