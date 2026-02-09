<?php

$zipFile = __DIR__ . '/frontend_dist.zip';
$extractPath = __DIR__ . '/frontend';

echo "<h2>Frontend Deployment Script v2</h2>";

// 1. Check if Zip exists
if (!file_exists($zipFile)) {
    die("<p style='color:red'>Error: frontend_dist.zip not found in " . __DIR__ . "</p>");
}

// 2. Create Target Directory if not exists
if (!is_dir($extractPath)) {
    mkdir($extractPath, 0755, true);
    echo "<p>Created directory: $extractPath</p>";
} else {
    echo "<p>Directory exists: $extractPath</p>";
}

// 3. Unzip
$zip = new ZipArchive;
if ($zip->open($zipFile) === TRUE) {
    
    // Clear existing content to avoid stale files?
    // WARNING: This clears User uploads if they are inside frontend (unlikely for static site)
    // Let's just unzip over it.
    
    $zip->extractTo($extractPath);
    $zip->close();
    echo "<p style='color:green'><strong>Success!</strong> Extracted to $extractPath</p>";
    
    // 4. Fix Structure (Move out/* to frontend/)
    $outDir = $extractPath . '/out';
    if (is_dir($outDir)) {
        echo "<p>Found nested 'out' directory. Moving files up...</p>";
        
        $files = scandir($outDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $src = $outDir . '/' . $file;
            $dest = $extractPath . '/' . $file;
            
            // Delete destination if directory (to allow overwrite)
            if (is_dir($dest)) {
                // Recursive delete function
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dest, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    $todo($fileinfo->getRealPath());
                }
                rmdir($dest);
            }
            
            rename($src, $dest);
        }
        
        // Remove empty out
        @rmdir($outDir);
        echo "<p>Files moved from 'out' to root.</p>";
    }
    
    // 5. Verify Index
    if (file_exists($extractPath . '/index.html')) {
        echo "<p>Verification: index.html found.</p>";
        $indexContent = file_get_contents($extractPath . '/index.html');
        // Simple check for config
        if (strpos($indexContent, 'api.msmeloan.sbs') !== false) {
             echo "<p style='color:green'>API URL found in index.html (or JS chunks linked).</p>";
        }
        echo "<p><a href='/frontend/' target='_blank'>OPEN FRONTEND</a></p>";
    } else {
         echo "<p style='color:orange'>Warning: index.html not found inside frontend folder.</p>";
         $files = scandir($extractPath);
         echo "<pre>" . print_r($files, true) . "</pre>";
    }
    
} else {
    echo "<p style='color:red'>Error: Failed to open zip file.</p>";
}
