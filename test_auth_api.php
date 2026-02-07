<?php
// Simulate Api Request to verifyOtp

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Services\AuthService;
use Mockery;

echo "--- Testing AuthController::verifyOtp Response ---\n";

$mobile = '9430083275';
$user = User::where('mobile_number', $mobile)->first();
if (!$user) {
    echo "User not found! Test invalid.\n";
    exit;
}

// Reset referral code for test
$user->my_referral_code = null;
$user->save();
echo "Referral Code Reset: " . ($user->my_referral_code ? 'FAIL' : 'OK') . "\n";

// Create Controller Instance
// AuthService mock not necessary if not used in verifyOtp, but constructor requires it
$authService = Mockery::mock(AuthService::class);
$controller = new AuthController($authService);

// Create Request
$request = Request::create('/api/auth/verify', 'POST', [
    'mobile_number' => $mobile,
    'otp' => '123456', // Dummy OTP
    'role' => 'CUSTOMER' // Assuming frontend sends role
]);

echo "Sending Request...\n";
try {
    $response = $controller->verifyOtp($request);
    
    if ($response->status() !== 200) {
        echo "Response Error: " . $response->status() . "\n";
        echo json_encode($response->getData(), JSON_PRETTY_PRINT) . "\n";
    } else {
        $content = $response->getData(true);
        $resUser = $content['user'];
        echo "Response User Code: " . ($resUser['my_referral_code'] ?? 'NULL') . "\n";
        
        $user->refresh();
        echo "DB User Code: " . ($user->my_referral_code ?? 'NULL') . "\n";
        
        if (!empty($resUser['my_referral_code'])) {
            echo "PASS: Code generated and returned.\n";
        } else {
            echo "FAIL: Code missing in response.\n";
        }
    }

} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
