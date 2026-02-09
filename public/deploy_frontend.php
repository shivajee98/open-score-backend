<?php

$zipFile = __DIR__ . '/frontend_dist.zip';
$extractPath = __DIR__ . '/frontend';

echo "<h2>Frontend Deployment Script</h2>";

// 1. Check if Zip exists
if (!file_exists($zipFile)) {
    die("<p style='color:red'>Error: frontend_dist.zip not found in " . __DIR__ . "</p>");
}

// 2. Create Target Directory
if (!is_dir($extractPath)) {
    mkdir($extractPath, 0755, true);
    echo "<p>Created directory: $extractPath</p>";
} else {
    echo "<p>Directory exists: $extractPath (Overwriting...)</p>";
}

// 3. Unzip
$zip = new ZipArchive;
if ($zip->open($zipFile) === TRUE) {
    $zip->extractTo($extractPath);
    $zip->close();
    echo "<p style='color:green'><strong>Success!</strong> Extracted to $extractPath</p>";
    
    // 4. Verify Index
    if (file_exists($extractPath . '/index.html')) {
        echo "<p>Verification: index.html found.</p>";
        echo "<p><a href='/frontend/' target='_blank'>OPEN FRONTEND</a></p>";
    } else {
         echo "<p style='color:orange'>Warning: index.html not found inside frontend folder. Check if zip has a nested folder?</p>";
         // Debug listing
         $files = scandir($extractPath);
         echo "<pre>" . print_r($files, true) . "</pre>";
    }
    
} else {
    echo "<p style='color:red'>Error: Failed to open zip file.</p>";
}
