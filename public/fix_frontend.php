<?php

$baseDir = __DIR__ . '/frontend';
$sourceDir = $baseDir . '/out';

echo "<h2>Fixing Frontend Structure</h2>";

if (!is_dir($sourceDir)) {
    die("<p>Source folder 'out' not found in $baseDir</p>");
}

// Scan files in out
$files = scandir($sourceDir);

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;

    $src = $sourceDir . '/' . $file;
    $dest = $baseDir . '/' . $file;

    if (rename($src, $dest)) {
        echo "Moved: $file <br>";
    } else {
        echo "Failed to move: $file <br>";
    }
}

// Try to remove the now empty out dir
if (rmdir($sourceDir)) {
    echo "<p>Removed empty 'out' directory.</p>";
} else {
    echo "<p>Could not remove 'out' directory (might not be empty).</p>";
}

echo "<h3>Current Frontend Files:</h3>";
$current = scandir($baseDir);
echo "<pre>" . print_r($current, true) . "</pre>";
