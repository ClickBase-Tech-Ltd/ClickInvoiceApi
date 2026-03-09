<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OtpController extends Controller
{
    /**
     * Resend OTP to user's email
     */
    public function resendOtp(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email'
            ]);

            // Find the user
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.'
                ], 404);
            }

            // Generate new OTP (6 digits)
            $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpExpiresAt = Carbon::now()->addMinutes(10); // OTP valid for 10 minutes

            // Update user with new OTP
            $user->update([
                'otp_code' => $otp,
                'otp_expires_at' => $otpExpiresAt,
                'email_verified_at' => null // Reset verification status if needed
            ]);

            // Send OTP via email
            $this->sendOtpEmail($user->email, $otp);

            return response()->json([
                'status' => 'success',
                'message' => 'OTP has been resent to your email.'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            \Log::error('OTP Resend Error: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to resend OTP. Please try again.'
            ], 500);
        }
    }

    /**
     * Send OTP email
     */
    private function sendOtpEmail($email, $otp)
    {
        $data = [
            'otp' => $otp,
            'expires_in' => '10 minutes' // You can make this configurable
        ];

        Mail::send('emails.otp', $data, function($message) use ($email) {
            $message->to($email)
                    ->subject('Your ClickInvoice Verification Code');
        });
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(Request $request)
{
    try {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|size:6'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.'
            ], 404);
        }

        // Check if OTP matches and is not expired
        if ($user->otp_code !== $request->otp) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid OTP code.'
            ], 400);
        }

        if (Carbon::now()->gt($user->otp_expires_at)) {
            return response()->json([
                'status' => 'error',
                'message' => 'OTP has expired. Please request a new one.'
            ], 400);
        }

        // Mark email as verified (but don't clear OTP yet - wait for password setup)
        $user->update([
            'email_verified_at' => Carbon::now(),
            // Don't clear OTP yet - we'll do that after password setup
        ]);

        try {
            $isActive = strtolower((string) ($user->status ?? '')) === 'active';
            if ($isActive && !empty($user->referred_by_user_id) && !empty($user->referred_by_code)) {
                $refCode = DB::table('referral_codes')
                    ->select(['id', 'owner_user_id', 'code'])
                    ->where('code', (string) $user->referred_by_code)
                    ->first();

                if ($refCode && (string) $refCode->owner_user_id === (string) $user->referred_by_user_id) {
                    $alreadyIssued = DB::table('referral_rewards')
                        ->where('referrer_user_id', (string) $refCode->owner_user_id)
                        ->where('referred_user_id', (string) $user->id)
                        ->where('status', 'issued')
                        ->exists();

                    if (!$alreadyIssued) {
                        $rewardAmount = (float) env('REFERRAL_SIGNUP_REWARD_NGN', 1000);
                        DB::table('referral_rewards')->insert([
                            'id' => (string) Str::uuid(),
                            'referral_code_id' => (string) $refCode->id,
                            'referrer_user_id' => (string) $refCode->owner_user_id,
                            'referred_user_id' => (string) $user->id,
                            'reward_type' => 'credit',
                            'reward_amount' => $rewardAmount,
                            'reward_currency' => 'NGN',
                            'status' => 'issued',
                            'reason' => 'issued on active + verified signup',
                            'metadata' => json_encode(['trigger' => 'otp_verified']),
                            'created_at' => now(),
                            'issued_at' => now(),
                            'reversed_at' => null,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Referral reward issuance after OTP verify failed: ' . $e->getMessage());
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Email verified successfully! Please set your password.',
        ]);

    } catch (\Exception $e) {
        Log::error('OTP Verification Error: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'OTP verification failed. Please try again.'
        ], 500);
    }
}

}