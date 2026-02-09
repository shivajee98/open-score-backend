<?php
// Simple deployment script for CLIENT
$zipFile = __DIR__ . '/frontend_dist.zip';
$extractPath = __DIR__ . '/frontend';

echo "<h2>Frontend Deployment Script v1</h2>";

if (!file_exists($zipFile)) {
    die("<p style='color:red'>Error: Zip file '$zipFile' not found.</p>");
}

// Create destination if needed
if (!is_dir($extractPath)) {
    mkdir($extractPath, 0755, true);
    echo "<p>Created directory: $extractPath</p>";
}

$zip = new ZipArchive;
if ($zip->open($zipFile) === TRUE) {
    // Clear existing files
    echo "<p>Clearing old files...</p>";
    $files = glob($extractPath . '/*');
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
    // Note: Leaving subdirectories for now to avoid complexity, or recursive delete?
    // Recursive delete is safer to ensure clean state.
    $it = new RecursiveDirectoryIterator($extractPath, RecursiveDirectoryIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file) {
        if ($file->isDir()){
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    $zip->extractTo($extractPath);
    $zip->close();
    echo "<p style='color:green'><strong>Success!</strong> Extracted to $extractPath</p>";
    echo "<p>Verification: index.html " . (file_exists($extractPath . '/index.html') ? "found." : "NOT found.") . "</p>";
    echo "<p><a href='/frontend/' target='_blank'>OPEN FRONTEND</a></p>";
} else {
    echo "<p style='color:red'><strong>Error:</strong> Failed to open zip file.</p>";
}
?>
