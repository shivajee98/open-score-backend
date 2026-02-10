<?php
echo "<h2>Path Verification</h2>";
echo "Current Script Dir: " . __DIR__ . "<br>";

$expectedPath = '/home/u910898544/domains/msmeloan.sbs/public_html/admin';
echo "Checking Expected Path: $expectedPath<br>";

if (is_dir($expectedPath)) {
    echo "<b style='color:green'>✅ Directory Exists!</b><br>";
    $files = scandir($expectedPath);
    echo "Files: " . implode(", ", array_slice($files, 0, 10)) . "...<br>";
} else {
    echo "<b style='color:red'>❌ Directory NOT Found!</b><br>";
}

$wrongPath = __DIR__ . '/admin';
echo "Checking Wrong Path (api/public/admin): $wrongPath<br>";
if (is_dir($wrongPath)) {
    echo "<b style='color:orange'>⚠️ Found Old/Wrong Directory Here!</b><br>";
    $files = scandir($wrongPath);
    echo "Files: " . implode(", ", array_slice($files, 0, 10)) . "...<br>";
} else {
    echo "Good: No wrong directory here.<br>";
}
?>
