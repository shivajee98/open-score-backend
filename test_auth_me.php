<?php
// Simulate Api Request to me()

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Http\Controllers\AuthController;
use App\Models\User;
use App\Services\AuthService;
use Mockery;
use Illuminate\Support\Facades\Auth;

echo "--- Testing AuthController::me Response ---\n";

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
$authService = Mockery::mock(AuthService::class);
$controller = new AuthController($authService);

// Mock Auth Login
Auth::shouldReceive('guard')->with('api')->andReturnSelf();
Auth::shouldReceive('user')->andReturn($user);

// Create Request
echo "Calling me()...\n";
try {
    $response = $controller->me();
    
    if ($response->status() !== 200) {
        echo "Response Error: " . $response->status() . "\n";
    } else {
        $content = $response->getData(true);
        // me() usually returns User object directly or wrapped?
        // Code shows: `return response()->json($user);` so it's direct properties.
        
        echo "Response User Code: " . ($content['my_referral_code'] ?? 'NULL') . "\n";
        
        $user->refresh();
        echo "DB User Code: " . ($user->my_referral_code ?? 'NULL') . "\n";
        
        if (!empty($content['my_referral_code'])) {
            echo "PASS: Code generated and returned.\n";
        } else {
            echo "FAIL: Code missing in response.\n";
        }
    }

} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
