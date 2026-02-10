<?php
echo "<h2>Frontend Deployment Diagnostics</h2>";
$base = '/home/u910898544/domains/msmeloan.sbs/public_html/openscore';

// 1. Check directory exists
echo "<h3>1. Directory Check</h3>";
if (is_dir($base)) {
    echo "✅ openscore directory exists<br>";
    $files = scandir($base);
    echo "Root files: " . implode(", ", array_slice($files, 2, 15)) . "<br>";
} else {
    echo "❌ openscore directory NOT found<br>";
}

// 2. Check _next directory
echo "<h3>2. _next Directory</h3>";
$nextDir = "$base/_next";
if (is_dir($nextDir)) {
    echo "✅ _next exists<br>";
    $nextFiles = scandir($nextDir);
    echo "Contents: " . implode(", ", array_slice($nextFiles, 2)) . "<br>";
    
    // Check static
    if (is_dir("$nextDir/static")) {
        echo "✅ _next/static exists<br>";
        $staticFiles = scandir("$nextDir/static");
        echo "Static contents: " . implode(", ", array_slice($staticFiles, 2, 5)) . "<br>";
        
        // Check chunks
        if (is_dir("$nextDir/static/chunks")) {
            $chunks = scandir("$nextDir/static/chunks");
            echo "Chunks count: " . (count($chunks) - 2) . "<br>";
            echo "Sample chunks: " . implode(", ", array_slice($chunks, 2, 5)) . "<br>";
        }
    }
} else {
    echo "❌ _next directory NOT found!<br>";
}

// 3. Check .htaccess
echo "<h3>3. .htaccess Contents</h3>";
$htaccess = "$base/.htaccess";
if (file_exists($htaccess)) {
    echo "<pre>" . htmlspecialchars(file_get_contents($htaccess)) . "</pre>";
} else {
    echo "No .htaccess found<br>";
}

// 4. Check parent .htaccess (might redirect)
echo "<h3>4. Parent .htaccess (public_html)</h3>";
$parentHtaccess = '/home/u910898544/domains/msmeloan.sbs/public_html/.htaccess';
if (file_exists($parentHtaccess)) {
    echo "<pre>" . htmlspecialchars(file_get_contents($parentHtaccess)) . "</pre>";
} else {
    echo "No parent .htaccess<br>";
}

// 5. Check customer/index.html to see what paths JS is looking for
echo "<h3>5. Frontend index.html head (JS paths)</h3>";
$indexHtml = "$base/customer/index.html";
if (file_exists($indexHtml)) {
    $content = file_get_contents($indexHtml);
    // Extract script tags
    preg_match_all('/<script[^>]*src="([^"]*)"[^>]*>/i', $content, $matches);
    if (!empty($matches[1])) {
        echo "Script paths found:<br>";
        foreach ($matches[1] as $src) {
            echo "  - $src";
            // Check if file exists
            $fullPath = $base . $src;
            echo file_exists($fullPath) ? " ✅" : " ❌ MISSING";
            echo "<br>";
        }
    }
    // Extract link tags for CSS
    preg_match_all('/<link[^>]*href="([^"]*\.css)"[^>]*>/i', $content, $cssMatches);
    if (!empty($cssMatches[1])) {
        echo "CSS paths found:<br>";
        foreach ($cssMatches[1] as $href) {
            echo "  - $href";
            $fullPath = $base . $href;
            echo file_exists($fullPath) ? " ✅" : " ❌ MISSING";
            echo "<br>";
        }
    }
} else {
    echo "❌ customer/index.html not found<br>";
    // Check if index.html exists at root
    if (file_exists("$base/index.html")) {
        echo "But index.html exists at root<br>";
    }
}

echo "<h3>6. Server deploy_v2.php check</h3>";
$deployFile = '/home/u910898544/domains/msmeloan.sbs/public_html/api/deploy_v2.php';
if (file_exists($deployFile)) {
    $content = file_get_contents($deployFile);
    preg_match("/deploy_app\('frontend_dist\.zip'.*?\)/", $content, $match);
    echo "Frontend deploy target: " . ($match[0] ?? 'NOT FOUND') . "<br>";
}
?>
