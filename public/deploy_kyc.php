<?php
// KYC Deployment Script
// Extracts kyc_dist.zip to public/kyc

header('Content-Type: text/html');

$zipFile = __DIR__ . '/kyc_dist.zip';
$extractPath = __DIR__ . '/kyc';

echo "<h2>KYC Deployment Script v1</h2>";

if (!file_exists($zipFile)) {
    die("<p style='color:red'>Error: kyc_dist.zip not found in public directory.</p>");
}

if (!is_dir($extractPath)) {
    if (!mkdir($extractPath, 0755, true)) {
        die("<p style='color:red'>Error: Failed to create kyc directory.</p>");
    }
} else {
    echo "<p>Directory exists: $extractPath</p>";
}

$zip = new ZipArchive;
if ($zip->open($zipFile) === TRUE) {
    // Clear existing files
    $files = glob($extractPath . '/*'); 
    foreach($files as $file){
        if(is_file($file)) {
            unlink($file);
        } elseif(is_dir($file)) {
            $it = new RecursiveDirectoryIterator($file, RecursiveDirectoryIterator::SKIP_DOTS);
            $files_sub = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
            foreach($files_sub as $f) {
                if ($f->isDir()){
                    rmdir($f->getRealPath());
                } else {
                    unlink($f->getRealPath());
                }
            }
            rmdir($file);
        }
    }
    
    $zip->extractTo($extractPath);
    $zip->close();
    echo "<p style='color:green'><strong>Success!</strong> Extracted to $extractPath</p>";
    
    if (file_exists($extractPath . '/index.html')) {
        echo "<p>Verification: index.html found.</p>";
        echo "<p><a href='/kyc/' target='_blank'>OPEN KYC PORTAL</a></p>";
    } else {
        echo "<p style='color:orange'>Warning: index.html not found after extraction.</p>";
    }
    
} else {
    echo "<p style='color:red'>Error: Failed to open zip file.</p>";
}
?>
