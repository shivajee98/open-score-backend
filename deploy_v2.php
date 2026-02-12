<?php
// Secure Deployment Script V2
// Handle Multiple Subdomains Correctly

$key = $_GET['key'] ?? '';
if ($key !== 'openscore_deploy_2026') {
    http_response_code(401);
    die('Unauthorized');
}

echo "<h2>Deployment V2 Started</h2>";
echo "Version: 3.1 (Migration Debug)<br>";

// 1. Pull Latest Code to Backend
chdir('/home/u910898544/domains/msmeloan.sbs/public_html/api');
echo "Git Pull: " . shell_exec('git pull origin main 2>&1') . "<br>";

// 1b. Run Database Migrations (Optional)
if (isset($_GET['migrate']) && $_GET['migrate'] === 'true') {
    echo "<h3>Executing Migrations (migrate.sh)...</h3>";
    // Ensure permission
    shell_exec('chmod +x migrate.sh');
    $output = shell_exec('bash migrate.sh 2>&1');
    echo "<pre>$output</pre>";
}

// Helper Function
function deploy_app($zipName, $targetDir) {
    $sourceZip = "/home/u910898544/domains/msmeloan.sbs/public_html/api/public/$zipName";
    
    if (!file_exists($sourceZip)) {
        echo "<p style='color:red'>❌ $zipName not found!</p>";
        return;
    }

    echo "<p>🚀 Deploying $zipName -> $targetDir...</p>";
    
    // Clear Target Directory (Safety Check: Don't delete root if empty string)
    if (!empty($targetDir) && $targetDir !== '/') {
        // Being very specific to avoid disasters
        shell_exec("rm -rf $targetDir/*");
    }
    
    $zip = new ZipArchive;
    if ($zip->open($sourceZip) === TRUE) {
        $zip->extractTo($targetDir);
        $zip->close();
        
        // Create .htaccess for SPA routing & Clean URLs & Directory fixes
        $htContent = "<IfModule mod_rewrite.c>\n  # Fix 403 Forbidden when accessing directories that coincide with .html files\n  DirectorySlash Off\n  RewriteEngine On\n  RewriteBase /\n  \n  # Remove trailing slash from directory-like URLs\n  RewriteCond %{REQUEST_FILENAME} -d\n  RewriteRule ^(.*)/$ /$1 [L,R=301]\n  \n  # Rewrite clean URLs to .html files\n  RewriteCond %{DOCUMENT_ROOT}/$1.html -f\n  RewriteRule ^(.*)$ $1.html [L]\n  \n  # SPA fallback\n  RewriteCond %{REQUEST_FILENAME} !-f\n  RewriteCond %{REQUEST_FILENAME} !-d\n  RewriteRule . /index.html [L]\n</IfModule>";
        file_put_contents("$targetDir/.htaccess", $htContent);
        
        echo "<p style='color:green'>✅ Restored $targetDir & Created Robust .htaccess</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to unzip $zipName</p>";
    }
}

// 2. Deploy Customer App (Root Domain or Subdomain?)
// Assumption: customer.msmeloan.sbs maps to public_html/customer
deploy_app('frontend_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/openscore');

// 3. Deploy Admin Panel
deploy_app('admin_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/admin');

// 4. Deploy KYC
deploy_app('kyc_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/kyc');

// 5. Deploy Support
deploy_app('support_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/support');

// 6. Deploy Agent (Sub-User)
deploy_app('agent_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/agent');

echo "<h3>✨ All Deployments Completed!</h3>";
