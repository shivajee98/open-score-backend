<?php
// Admin Panel Deployment Script
// Extracts admin_dist.zip to public/admin

header('Content-Type: text/html');

$zipFile = __DIR__ . '/admin_dist.zip';
$extractPath = __DIR__ . '/admin';

echo "<h2>Admin Deployment Script v1</h2>";

if (!file_exists($zipFile)) {
    die("<p style='color:red'>Error: admin_dist.zip not found in public directory.</p>");
}

if (!is_dir($extractPath)) {
    if (!mkdir($extractPath, 0755, true)) {
        die("<p style='color:red'>Error: Failed to create admin directory.</p>");
    }
} else {
    echo "<p>Directory exists: $extractPath</p>";
}

$zip = new ZipArchive;
if ($zip->open($zipFile) === TRUE) {
    // Clear existing files? Ideally yes, but let's just overwrite for now.
    
    $zip->extractTo($extractPath);
    $zip->close();
    echo "<p style='color:green'><strong>Success!</strong> Extracted to $extractPath</p>";
    
    // Check if there is a nested 'out' folder and move contents up
    $nestedOut = $extractPath . '/out';
    if (is_dir($nestedOut)) {
        echo "<p>Found nested 'out' directory. Moving files up...</p>";
        $files = scandir($nestedOut);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                rename($nestedOut . '/' . $file, $extractPath . '/' . $file);
            }
        }
        rmdir($nestedOut);
        echo "<p>Files moved from 'out' to root.</p>";
    }
    
    if (file_exists($extractPath . '/index.html')) {
        echo "<p>Verification: index.html found.</p>";
        echo "<p><a href='/admin/' target='_blank'>OPEN ADMIN PANEL</a></p>";
    } else {
        echo "<p style='color:orange'>Warning: index.html not found after extraction.</p>";
    }
    
} else {
    echo "<p style='color:red'>Error: Failed to open zip file.</p>";
}
?>
