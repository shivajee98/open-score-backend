<?php
// Secure Key Check
if (($_GET['key'] ?? '') !== 'openscore_deploy_2026') {
    http_response_code(401);
    die('Unauthorized Check');
}

// Helper to run commands
function run($cmd) {
    echo "Processing: $cmd\n";
    echo shell_exec("$cmd 2>&1");
    echo "\n----------------\n";
}

header('Content-Type: text/plain');
echo "🚀 Starting Deployment V2...\n";
echo "📂 Current Directory: " . __DIR__ . "\n";

// 1. Git Pull in Backend Root
chdir(__DIR__ . '/..'); // Move to backend root
echo "📂 Switched to Backend Root: " . getcwd() . "\n";

echo "\n⬇️  Running Git Pull...\n";
run("git pull origin main");

// 2. Define Frontend Target Paths
// We try to guess the frontend directory based on common structures
$possiblePaths = [
    __DIR__ . '/frontend',                      // Monolith Style (backend/public/frontend)
    '../../openscore.msmeloan.sbs/public_html', // Hostinger Subdomain
    '../../openscore',                          // Shared Hosting Sibling
    '../openscore',                             // Sibling in same root
    '/home/u956950239/domains/msmeloan.sbs/public_html/openscore', // Absolute (Guess)
];

$frontendPath = null;
foreach ($possiblePaths as $path) {
    if (is_dir($path)) {
        $frontendPath = realpath($path);
        break;
    }
}

// 3. Unzip Frontend
$zipFile = __DIR__ . '/public/frontend_dist.zip';
if (!file_exists($zipFile)) {
    // If likely running from public root via web request
    $zipFile = __DIR__ . '/frontend_dist.zip';
}

if (file_exists($zipFile)) {
    if ($frontendPath) {
        echo "📦 Found Frontend Zip. Extracting to: $frontendPath\n";
        // Unzip overwriting existing files
        run("unzip -o $zipFile -d $frontendPath");
        
        // Ensure .htaccess is writable/copied (unzip should handle it)
        echo "✅ Frontend Extraction Complete.\n";
    } else {
        echo "⚠️  Frontend Target Directory NOT FOUND. Checked:\n";
        print_r($possiblePaths);
        echo "Listing sibling directories for debugging:\n";
        run("ls -d ../../*");
    }
} else {
    echo "⚠️  frontend_dist.zip not found in " . __DIR__ . "\n";
}

// 4. Run Migrations (Optional, based on need)
// run("php artisan migrate --force");

echo "\n✨ Deployment Script Finished.\n";
