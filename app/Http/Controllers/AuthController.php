<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Models\Doctors;
use App\Models\Applications;
use App\Models\ApplicationType;
use App\Mail\WelcomeEmail;
use Illuminate\Support\Str;
use Tymon\JWTAuth\JWTAuth;
// use Tymon\JWTAuth\Facades\JWTAuth; // Ensure the facade is imported
use App\Models\RefreshToken;
use Carbon\Carbon;
use App\Mail\OtpEmail;
use App\Models\Company;
use App\Models\CompanyStaff;
use App\Models\Tenant;
use Tymon\JWTAuth\Exceptions\JWTException; // Uncomment if using JWTException




class AuthController extends Controller
{


    // use Illuminate\Http\Request;
    // use Illuminate\Support\Facades\Hash;
    // use Illuminate\Validation\ValidationException;
    // use App\Models\User;

    protected $jwt;

    public function __construct(JWTAuth $jwt)
    {
        $this->jwt = $jwt;
    }

    public function contacts()
    {
        $users = User::all();
        return response()->json($users);
    }

    private function attachReferralAtSignup(User $user, ?string $referralCode, Request $request): void
    {
        $code = strtoupper(trim((string) $referralCode));
        if ($code === '') {
            return;
        }

        $refCode = DB::table('referral_codes')->where('code', $code)->first();
        if (!$refCode) {
            return;
        }

        if ((string) $refCode->owner_user_id === (string) $user->id) {
            return;
        }

        DB::table('users')
            ->where('id', (string) $user->id)
            ->update([
                'referred_by_code' => (string) $refCode->code,
                'referred_by_user_id' => (string) $refCode->owner_user_id,
                'referral_attributed_at' => now(),
            ]);

        $alreadyRedeemed = DB::table('referral_events')
            ->where('event_type', 'signup')
            ->where('referred_user_id', (string) $user->id)
            ->exists();

        if ($alreadyRedeemed) {
            return;
        }

        DB::table('referral_events')->insert([
            'id' => (string) Str::uuid(),
            'referral_code_id' => (string) $refCode->id,
            'referral_code' => (string) $refCode->code,
            'event_type' => 'signup',
            'referred_user_id' => (string) $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'payload' => json_encode(new \stdClass()),
            'created_at' => now(),
        ]);

        DB::table('referral_codes')->where('id', (string) $refCode->id)->increment('uses_count');
    }

    private function issueReferralRewardForActiveVerifiedUser(User $user): void
    {
        $isActive = strtolower((string) ($user->status ?? '')) === 'active';
        $isVerified = !empty($user->email_verified_at);

        if (!$isActive || !$isVerified) {
            return;
        }

        if (empty($user->referred_by_user_id) || empty($user->referred_by_code)) {
            return;
        }

        $refCode = DB::table('referral_codes')
            ->select(['id', 'owner_user_id', 'code'])
            ->where('code', (string) $user->referred_by_code)
            ->first();

        if (!$refCode || (string) $refCode->owner_user_id !== (string) $user->referred_by_user_id) {
            return;
        }

        $alreadyIssued = DB::table('referral_rewards')
            ->where('referrer_user_id', (string) $refCode->owner_user_id)
            ->where('referred_user_id', (string) $user->id)
            ->where('status', 'issued')
            ->exists();

        if ($alreadyIssued) {
            return;
        }

        $rewardCurrency = $this->resolveReferrerRewardCurrency((string) $refCode->owner_user_id);
        $rewardAmount = $this->resolveRewardAmountForCurrency($rewardCurrency);

        DB::table('referral_rewards')->insert([
            'id' => (string) Str::uuid(),
            'referral_code_id' => (string) $refCode->id,
            'referrer_user_id' => (string) $refCode->owner_user_id,
            'referred_user_id' => (string) $user->id,
            'reward_type' => 'credit',
            'reward_amount' => $rewardAmount,
            'reward_currency' => $rewardCurrency,
            'status' => 'issued',
            'reason' => 'issued on active + verified user',
            'metadata' => json_encode(['trigger' => 'setup_password_active']),
            'created_at' => now(),
            'issued_at' => now(),
            'reversed_at' => null,
        ]);
    }

    private function resolveReferrerRewardCurrency(string $referrerUserId): string
    {
        $currencyCode = DB::table('tenants as t')
            ->leftJoin('currencies as c', 'c.currencyId', '=', 't.currency')
            ->where('t.ownerId', $referrerUserId)
            ->where('t.isDefault', 1)
            ->value('c.currencyCode');

        $fallback = strtoupper((string) env('REFERRAL_SIGNUP_REWARD_CURRENCY', 'NGN'));
        $resolved = strtoupper(trim((string) ($currencyCode ?? '')));

        return $resolved !== '' ? $resolved : $fallback;
    }

    private function resolveRewardAmountForCurrency(string $currencyCode): float
    {
        $normalizedCurrency = strtoupper(trim($currencyCode));
        $defaultAmount = (float) env('REFERRAL_SIGNUP_REWARD_NGN', 1000);
        if ($normalizedCurrency === '') {
            return $defaultAmount;
        }

        $currencyAmount = env('REFERRAL_SIGNUP_REWARD_' . $normalizedCurrency);
        if ($currencyAmount === null || $currencyAmount === '') {
            return $defaultAmount;
        }

        return (float) $currencyAmount;
    }


// public function signin(Request $request)
// {
//     $request->validate([
//         'username' => 'required',
//         'password' => 'required',
//     ]);

//     // Find user by email or phone number with staff and role relationships
//     $user = User::with(['user_role'])
//         ->where('email', $request->username)
//         ->orWhere('phoneNumber', $request->username)
//         ->first();

//     if (!$user) {
//         throw ValidationException::withMessages([
//             'username' => ['No account found with this email or phone number.'],
//         ]);
//     }

//     // Attempt JWT authentication
//     $credentials = [
//         'email' => $user->email,
//         'password' => $request->password,
//     ];

//     if (!$accessToken = auth('api')->attempt($credentials)) {
//         return response()->json(['error' => 'Invalid credentials'], 401);
//     }

//     // Generate refresh token
//     $refreshToken = Str::random(64);
//     $user = auth('api')->user()->load(['user_role']); // Reload relationships

//     // Store refresh token in database
//     RefreshToken::create([
//         'user_id' => $user->id,
//         'token' => $refreshToken,
//         'expires_at' => Carbon::now()->addDays(14),
//     ]);

//     // Hide sensitive data
//     $user->makeHidden(['password']);

//     // Return response with cookies
//     return response()->json([
//         'message' => 'Logged in',
//         'firstName' => $user->firstName ?? '',
//         'lastName' => $user->lastName ?? '',
//         'email' => $user->email ?? '',
//         'phoneNumber' => $user->phoneNumber ?? '',
//         'role' => $user->user_role->roleName ?? '',
//         'tenantId' => $user->currently_active_tenant->tenantId ?? '',
//         'profileImage' => $user->profileImage ?? '/avatar.png',
//         'coverImage' => $user->coverImage ?? '/cover_photo.jpg',

//         // 'id' => $user->id ?? '',
//         // 'access_token' => $accessToken,
//     ])
//         ->cookie('access_token', $accessToken, 60, null, null, true, true, false, 'strict')
//         ->cookie('refresh_token', $refreshToken, 14 * 24 * 60, null, null, true, true, false, 'strict');
// }


// public function signin(Request $request)
// {
//     $request->validate([
//         'username' => 'required',
//         'password' => 'nullable', // 👈 allow empty password
//     ]);

//     $user = User::with(['user_role'])
//         ->where('email', $request->username)
//         ->orWhere('phoneNumber', $request->username)
//         ->first();

//     if (!$user) {
//         return response()->json([
//             'status' => false,
//             'code' => 'USER_NOT_FOUND',
//             'message' => 'No account found',
//         ], 404);
//     }

//     /**
//      * 🟡 STATE 1: Email not verified
//      */
//     if ($user->status === 'pending') {
//         return response()->json([
//             'status' => false,
//             'requiresEmailVerification' => true,
//             'email' => $user->email,
//             'message' => 'Email not verified',
//         ], 403);
//     }

//     /**
//      * 🟡 STATE 2: Email verified but no password yet
//      */
//     if (is_null($user->password)) {
//         return response()->json([
//             'status' => false,
//             'requiresPasswordSetup' => true,
//             'email' => $user->email,
//             'message' => 'Password not set',
//         ], 403);
//     }

//     /**
//      * 🟢 STATE 3: Fully active user
//      */
//     if (!$request->password) {
//         return response()->json([
//             'status' => false,
//             'requiresPassword' => true,
//             'message' => 'Password required',
//         ], 422);
//     }

//     // Attempt login
//     if (!$accessToken = auth('api')->attempt([
//         'email' => $user->email,
//         'password' => $request->password,
//     ])) {
//         return response()->json([
//             'status' => false,
//             'message' => 'Invalid credentials',
//         ], 401);
//     }

//         // Generate refresh token
//     $refreshToken = Str::random(64);
//     $user = auth('api')->user()->load(['user_role']); // Reload relationships

//     // Store refresh token in database
//     RefreshToken::create([
//         'user_id' => $user->id,
//         'token' => $refreshToken,
//         'expires_at' => Carbon::now()->addDays(14),
//     ]);

//     $user->makeHidden(['password']);

//        return response()->json([
//         'status' => true,
//         'message' => 'Logged in',
//         'firstName' => $user->firstName ?? '',
//         'lastName' => $user->lastName ?? '',
//         'email' => $user->email ?? '',
//         'phoneNumber' => $user->phoneNumber ?? '',
//         'role' => $user->user_role->roleName ?? '',
//         'tenantId' => $user->currently_active_tenant->tenantId ?? '',
//         'profileImage' => $user->profileImage ?? '/avatar.png',
//         'coverImage' => $user->coverImage ?? '/cover_photo.jpg',

//         // 'id' => $user->id ?? '',
//         // 'access_token' => $accessToken,
//     ])
//         ->cookie('access_token', $accessToken, 60, null, null, true, true, false, 'strict')
//         ->cookie('refresh_token', $refreshToken, 14 * 24 * 60, null, null, true, true, false, 'strict');
// }


public function signin(Request $request)
{
    $request->validate([
        'username' => 'required',
        'password' => 'nullable', // Allow empty password
    ]);

    $user = User::with(['user_role'])
        ->where('email', $request->username)
        ->orWhere('phoneNumber', $request->username)
        ->first();

    if (!$user) {
        return response()->json([
            'status'  => false,
            'code'    => 'USER_NOT_FOUND',
            'message' => 'No account found',
        ], 404);
    }

    // ── STATE 1: Email not verified (pending) ──
    if ($user->status === 'pending') {
        // Generate a new OTP (adjust logic to match how you generate OTPs elsewhere)
        $otp = rand(100000, 999999); // ← Replace with your secure OTP generator

        // Save OTP (assuming you have otp & otp_expires_at columns)
        $user->otp_code = $otp;
        $user->otp_expires_at = now()->addMinutes(10);
        $user->save();

        // Send OTP email
        try {
            Mail::to($user->email)->send(new OtpEmail(
                $user->firstName,
                $user->lastName,
                $otp
            ));
            Log::info('OTP email sent successfully to ' . $user->email);
        } catch (\Exception $e) {
            Log::error('OTP email sending failed: ' . $e->getMessage());
            // Still proceed — don't fail login attempt due to email issue
        }

        return response()->json([
            'status'                  => false,
            'requiresEmailVerification' => true,
            'email'                   => $user->email,
            'message'                 => 'Email not verified. A new OTP has been sent.',
        ], 403);
    }

    // ── STATE 2: Email verified but no password yet ──
    if (is_null($user->password)) {
        return response()->json([
            'status'               => false,
            'requiresPasswordSetup' => true,
            'email'                => $user->email,
            'message'              => 'Password not set. Please complete setup.',
        ], 403);
    }

    // ── STATE 3: Fully active user ──
    if (!$request->password) {
        return response()->json([
            'status'          => false,
            'requiresPassword' => true,
            'message'         => 'Password required',
        ], 422);
    }

    // Attempt standard password login
    if (!$accessToken = auth('api')->attempt([
        'email'    => $user->email,
        'password' => $request->password,
    ])) {
        return response()->json([
            'status'  => false,
            'message' => 'Invalid credentials',
        ], 401);
    }

    // Generate refresh token
    $refreshToken = Str::random(64);
    $user = auth('api')->user()->load(['user_role']); // Reload with relations

    // Store refresh token
    RefreshToken::create([
        'user_id'    => $user->id,
        'token'      => $refreshToken,
        'expires_at' => Carbon::now()->addDays(14),
    ]);

    $user->makeHidden(['password']);

    return response()->json([
        'status'      => true,
        'message'     => 'Logged in',
        'firstName'   => $user->firstName ?? '',
        'lastName'    => $user->lastName ?? '',
        'email'       => $user->email ?? '',
        'phoneNumber' => $user->phoneNumber ?? '',
        'role'        => $user->user_role->roleName ?? '',
        'tenantId'    => $user->currently_active_tenant->tenantId ?? '',
        'profileImage'=> $user->profileImage ?? '/avatar.png',
        'coverImage'  => $user->coverImage ?? '/cover_photo.jpg',
    ])
    ->cookie('access_token', $accessToken, 60, null, null, true, true, false, 'strict')
    ->cookie('refresh_token', $refreshToken, 14 * 24 * 60, null, null, true, true, false, 'strict');
}


    public function refresh(Request $request)
    {
        $refreshToken = $request->cookie('refresh_token');

        if (!$refreshToken) {
            return response()->json(['error' => 'Refresh token missing'], 401);
        }

        // Verify refresh token
        $tokenRecord = RefreshToken::where('token', $refreshToken)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$tokenRecord) {
            return response()->json(['error' => 'Invalid or expired refresh token'], 401);
        }

        // Generate new access token
        $user = User::find($tokenRecord->user_id);
        // $newAccessToken = JWTAuth::fromUser($user);
        $newAccessToken = $this->jwt->fromUser($user);


        // Optionally, issue a new refresh token and invalidate the old one
        $newRefreshToken = Str::random(64);
        $tokenRecord->update([
            'token' => $newRefreshToken,
            'expires_at' => Carbon::now()->addDays(14),
        ]);

        return response()->json(['message' => 'Token refreshed'])
            ->cookie('access_token', $newAccessToken, 60, null, null, true, true, false, 'strict')
            ->cookie('refresh_token', $newRefreshToken, 14 * 24 * 60, null, null, true, true, false, 'strict');
    }


    public function logout(Request $request)
    {
        $refreshToken = $request->cookie('refresh_token');

        if ($refreshToken) {
            RefreshToken::where('token', $refreshToken)->delete();
        }

        return response()->json(['message' => 'Logged out'])
            ->cookie('access_token', '', -1)
            ->cookie('refresh_token', '', -1);
    }

    // Get authenticated user
    public function user(Request $request)
    {
        return response()->json($request->user());
    }



    // public function signup(Request $request)
    // {
    //         // Create user
    //     $user = User::create([
    //         'firstName' => $request->firstName,
    //         'lastName' => $request->lastName,
    //         'otherNames' => $request->otherNames,
    //         'phoneNumber' => $request->phoneNumber,
    //         'email' => $request->email,
    //         'password' => Hash::make($request->password),
    //         'role' =>$request->role,
    //     ]);

    //     Log::info('User created:', ['email' => $user->email]);

    //     // Send email
    //     try {
    //         Mail::to($user->email)->send(new WelcomeEmail($user->firstName, $user->lastName, $user->email, $user->phoneNumber));
    //         Log::info('Email sent successfully to ' . $user->email);
    //     } catch (\Exception $e) {
    //         Log::error('Email sending failed: ' . $e->getMessage());
    //     }

    //     // Return response
    //     return response()->json([
    //         'message' => "User successfully created",
    //         // 'password' => $default_password,
    //     ]);
    // }

    public function signup2(Request $request)
{
    try {
        $request->merge([
        'currencyId' => (int) $request->input('currencyId', 0), // Converts "1" → 1, "abc" → 0
    ]);
        // Validate request data
        $validated = $request->validate([
            'fullName' => 'nullable|string|max:255',
            'phoneNumber' => 'nullable|string|unique:users,phoneNumber|max:14|regex:/^\+?\d{10,15}$/',
            'email' => 'required|email|unique:users,email|max:255',
            'currencyId' => 'required|integer|exists:currencies,currencyId',
            'companyName' => 'nullable|string|max:255',
            'referralCode' => 'nullable|string|min:4|max:32',
            // 'role' => 'required|integer|exists:roles,roleId',
        ]);

        // Generate OTP (6 digits)
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpExpiresAt = now()->addMinutes(10); // OTP valid for 10 minutes

        // Generate a temporary password (you can keep this or remove it)
        $tempPassword = strtoupper(Str::random(8));

        // Create user with OTP fields
        // Assuming Laravel controller method



// Break down fullName into firstName, lastName, and otherNames
$fullName = trim($validated['fullName']);
$nameParts = preg_split('/\s+/', $fullName); // Split by whitespace

$firstName = $nameParts[0] ?? '';
$lastName = count($nameParts) > 1 ? array_pop($nameParts) : ''; // Last word as lastName
$otherNames = count($nameParts) > 1 ? implode(' ', $nameParts) : ''; // Everything in between

// Fallback if only one name
if (empty($lastName)) {
    $lastName = $firstName;
    $firstName = '';
    $otherNames = '';
}


// Create the user
$user = User::create([
    'firstName' => $firstName,
    'lastName' => $lastName,
    'otherNames' => $otherNames,
    'phoneNumber' => $validated['phoneNumber'],
    'email' => $validated['email'],
    'password' => Hash::make($validated['password'] ?? 'default_temp_password'), // Use sent password or fallback
    'role' => 1,
    'currentPlan' => 1,
    'otp_code' => $otp, // Assume $otp and $otpExpiresAt are generated earlier
    'otp_expires_at' => $otpExpiresAt,
    'email_verified_at' => null,
    'status' => 'pending',
    // Add company if needed
    // 'company' => $validated['companyName'],
]);

        try {
            $this->attachReferralAtSignup($user, $request->input('referralCode'), $request);
        } catch (\Throwable $e) {
            Log::warning('Referral attribution on signup failed: ' . $e->getMessage());
        }

        $company = Tenant::create([
        'tenantName' => $validated['companyName'],
        'currency' => $validated['currencyId'],
        'tenantEmail' => $validated['email'],
        'tenantPhone' => $validated['phoneNumber'],
        'ownerId' => $user->id,
        'status' => 'active',
        'isDefault' => 1,
        ]);

        // $companyStaff = CompanyStaff::create([
        // 'companyId' => $company->companyId,
        // 'userId' => $user->id
        // ]);

        Log::info('User created:', ['email' => $user->email]);

        // Send OTP email instead of welcome email
        try {
            Mail::to($user->email)->send(new OtpEmail(
                $user->firstName,
                $user->lastName,
                $otp
            ));
            Log::info('OTP email sent successfully to ' . $user->email);
        } catch (\Exception $e) {
            Log::error('OTP email sending failed: ' . $e->getMessage());
            // Note: Not failing the request due to email error
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Signup successful! Please check your email for the verification code.',
            'user' => [
                'id' => $user->id,
            ],
        ], 201);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed.',
            'errors' => $e->errors(),
        ], 422);
    } catch (\Exception $e) {
        Log::error('Registration failed: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Signup failed due to an unexpected error. Please try again later.',
        ], 500);
    }
}

public function setupPassword(Request $request)
{
    try {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:6',
        ]);

        // Find user
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.',
            ], 404);
        }

        // Check if email is verified
        if (!$user->email_verified_at) {
            return response()->json([
                'status' => 'error',
                'message' => 'Email not verified. Please verify your email first.',
            ], 400);
        }

        // Update password
        $user->update([
            'password' => Hash::make($request->password),
            'status' => 'active', // Activate the user
        ]);

        // Clear OTP data
        $user->update([
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        try {
            $user->refresh();
            $this->issueReferralRewardForActiveVerifiedUser($user);
        } catch (\Throwable $e) {
            Log::warning('Referral reward issuance on setup-password failed: ' . $e->getMessage());
        }

        // Send welcome email
        try {
            $this->sendWelcomeEmail($user);
        } catch (\Exception $e) {
            Log::error('Welcome email failed to send: ' . $e->getMessage());
            // Continue execution - don't fail the password setup if email fails
        }

        Log::info('Password setup completed for user:', ['email' => $user->email]);

        return response()->json([
            'status' => 'success',
            'message' => 'Password set successfully. Welcome email sent.',
        ]);

    } catch (ValidationException $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Validation failed.',
            'errors' => $e->errors(),
        ], 422);
    } catch (\Exception $e) {
        Log::error('Password setup failed: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Password setup failed. Please try again.',
        ], 500);
    }
}

/**
 * Send welcome email to user
 */
private function sendWelcomeEmail(User $user)
{
    // Option 1: Using Laravel Mailable
    Mail::to($user->email)->send(new WelcomeEmail($user));

    // Option 2: Queue the email (recommended for better performance)
    // Mail::to($user->email)->queue(new WelcomeEmail($user));
}

    public function changePassword(Request $request)
    {
        // Validate input
        $request->validate([
            'currentPassword' => 'required',
            'newPassword' => 'required|min:6', // 'confirmed' ensures newPassword_confirmation is also sent
        ]);

        $user = Auth::user();

        // Check if the current password matches
        if (!Hash::check($request->currentPassword, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }


        // Update the user's password
        $user->password = Hash::make($request->newPassword);
        $user->save();

        return response()->json(['message' => 'Password changed successfully.']);
    }



    public function updateProfile(Request $request)
    {
        // Find the patient by ID
        $user = User::where('email', $request->email)->first();


        if (!$user) {
            return response()->json([
                'error' => 'User not found',
            ], 404); // HTTP status code 404: Not Found
        }


        $data = $request->all();


        $user->update($data);


        return response()->json([
            'message' => 'User updated successfully',
            'data' => $user,
        ], 200); // HTTP status code 200: OK
    }
}
