<?php
/**
 * Add profile_picture column to users table
 */
require_once __DIR__ . '/config/database.php';

echo "=================================================================\n";
echo "  ADD PROFILE_PICTURE COLUMN TO USERS TABLE\n";
echo "=================================================================\n\n";

try {
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    $columnExists = $stmt->rowCount() > 0;
    
    if ($columnExists) {
        echo "✅ Column 'profile_picture' already exists in users table.\n";
        echo "   No changes needed.\n\n";
    } else {
        echo "Adding 'profile_picture' column to users table...\n";
        
        $pdo->exec("
            ALTER TABLE users 
            ADD COLUMN profile_picture VARCHAR(255) NULL 
            AFTER email
        ");
        
        echo "✅ SUCCESS! Column 'profile_picture' has been added.\n\n";
        echo "Column details:\n";
        echo "  - Name: profile_picture\n";
        echo "  - Type: VARCHAR(255)\n";
        echo "  - Nullable: YES\n";
        echo "  - Default: NULL\n";
        echo "  - Position: After 'email' column\n\n";
    }
    
    // Verify the column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    $column = $stmt->fetch();
    
    if ($column) {
        echo "✅ VERIFIED: Column exists and is ready to use.\n\n";
        echo "Column structure:\n";
        print_r($column);
    }
    
    echo "\n=================================================================\n";
    echo "COMPLETE! The users table now supports profile pictures.\n";
    echo "=================================================================\n\n";
    
} catch (PDOException $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n\n";
    echo "Please make sure:\n";
    echo "  1. Database connection is working\n";
    echo "  2. You have ALTER TABLE permissions\n";
    echo "  3. The users table exists\n\n";
}
?>
