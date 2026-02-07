<?php

// Replicate Logic from LoanController::calculatePreview

$amount = 50000;
$tenureDays = 90; // 3 months
$frequency = '7 DAYS';
$interestRate = 10;
$cashbackPerEmi = 100;
$processingFee = 2000; // Simulated
$otherFees = 0;

// 1. Parse Frequency
$intervalDays = 7;
if (preg_match('/(\d+)\s*DAYS?/', $frequency, $matches)) {
    $intervalDays = (int)$matches[1];
}

// 2. Calculate EMIs
$numEmis = max(1, floor($tenureDays / $intervalDays));

// 3. New Interest Logic (Flat)
$totalInterest = round($amount * ($interestRate / 100));

// 4. GST Logic (On Fees + Interest)
$totalFees = $processingFee + $otherFees;
$gst = round(($totalFees + $totalInterest) * 0.18);

// 5. Total Payable
$totalPayable = $amount + $totalFees + $gst + $totalInterest;

// 6. EMI Amount
$emiAmount = round($totalPayable / $numEmis);

// 7. Cashback Calculation
$totalCashback = $numEmis * $cashbackPerEmi;

echo "--- Loan Simulation ---\n";
echo "Principal: ₹" . number_format($amount) . "\n";
echo "Tenure: $tenureDays Days\n";
echo "Frequency: Every $intervalDays Days\n";
echo "Interest Rate: $interestRate% (Flat for Tenure)\n\n";

echo "--- Breakdown ---\n";
echo "Total Interest: ₹" . number_format($totalInterest) . "\n";
echo "Processing Fees: ₹" . number_format($processingFee) . "\n";
echo "GST (18% on Interest+Fees): ₹" . number_format($gst) . "\n";
echo "-------------------\n";
echo "Total Payable: ₹" . number_format($totalPayable) . "\n";
echo "Number of EMIs: $numEmis\n";
echo "EMI Amount: ₹" . number_format($emiAmount) . "\n\n";

echo "--- Rewards ---\n";
echo "Cashback per EMI: ₹$cashbackPerEmi\n";
echo "Total Cashback Possible: ₹" . number_format($totalCashback) . "\n";
echo "Net Cost (Total - Cashback): ₹" . number_format($totalPayable - $totalCashback) . "\n";
