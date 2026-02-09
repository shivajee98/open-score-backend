<?php
// Secure Deployment Script V2
// Handle Multiple Subdomains Correctly

$key = $_GET['key'] ?? '';
if ($key !== 'openscore_deploy_2026') {
    http_response_code(401);
    die('Unauthorized');
}

echo "<h2>Deployment V2 Started</h2>";

// 1. Pull Latest Code to Backend
chdir('/home/u910898544/domains/msmeloan.sbs/public_html/api');
echo "Git Pull: " . shell_exec('git pull origin main 2>&1') . "<br>";

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
        echo "<p style='color:green'>✅ Restored $targetDir</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to unzip $zipName</p>";
        return;
    }

    // Create .htaccess for SPA routing
    $htaccess = <<<HTACCESS
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /
  RewriteRule ^index\.html$ - [L]
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME} !-l
  RewriteRule . /index.html [L]
</IfModule>
HTACCESS;
    file_put_contents("$targetDir/.htaccess", $htaccess);
    echo "<p>📄 Created .htaccess in $targetDir</p>";
}

// 2. Deploy Customer App (Root Domain or Subdomain?)
// Assumption: customer.msmeloan.sbs maps to public_html/customer
deploy_app('frontend_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/customer');

// 3. Deploy Admin Panel
deploy_app('admin_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/admin');

// 4. Deploy KYC
deploy_app('kyc_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/kyc');

// 5. Deploy Support
deploy_app('support_dist.zip', '/home/u910898544/domains/msmeloan.sbs/public_html/support');

echo "<h3>✨ All Deployments Completed!</h3>";
