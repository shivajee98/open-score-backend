<?php

$baseDir = __DIR__ . '/frontend';
$sourceDir = $baseDir . '/out';

echo "<h2>Fixing Frontend Structure (Force Overwrite)</h2>";

if (!is_dir($sourceDir)) {
    die("<p>Source folder 'out' not found in $baseDir</p>");
}

function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . DIRECTORY_SEPARATOR . $object) && !is_link($dir . DIRECTORY_SEPARATOR . $object))
                    rrmdir($dir . DIRECTORY_SEPARATOR . $object);
                else
                    unlink($dir . DIRECTORY_SEPARATOR . $object);
            }
        }
        rmdir($dir);
    }
}

// Scan files in out
$files = scandir($sourceDir);

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;

    $src = $sourceDir . '/' . $file;
    $dest = $baseDir . '/' . $file;

    if (file_exists($dest)) {
        if (is_dir($dest)) {
            rrmdir($dest);
        } else {
            unlink($dest);
        }
    }

    if (rename($src, $dest)) {
        echo "Moved: $file <br>";
    } else {
        echo "Failed to move: $file <br>";
    }
}

// Try to remove the now empty out dir
if (is_dir($sourceDir)) {
    @rmdir($sourceDir);
    echo "<p>Removed 'out' directory.</p>";
}

echo "<h3>Current Frontend Files:</h3>";
$current = scandir($baseDir);
echo "<pre>" . print_r($current, true) . "</pre>";
