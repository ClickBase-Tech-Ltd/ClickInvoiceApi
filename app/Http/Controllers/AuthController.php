<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
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


public function signin(Request $request)
{
    $request->validate([
        'username' => 'required',
        'password' => 'required',
    ]);

    // Find user by email or phone number with staff and role relationships
    $user = User::with(['user_role'])
        ->where('email', $request->username)
        ->orWhere('phoneNumber', $request->username)
        ->first();

    if (!$user) {
        throw ValidationException::withMessages([
            'username' => ['No account found with this email or phone number.'],
        ]);
    }

    // Attempt JWT authentication
    $credentials = [
        'email' => $user->email,
        'password' => $request->password,
    ];

    if (!$accessToken = auth('api')->attempt($credentials)) {
        return response()->json(['error' => 'Invalid credentials'], 401);
    }

    // Generate refresh token
    $refreshToken = Str::random(64);
    $user = auth('api')->user()->load(['user_role']); // Reload relationships

    // Store refresh token in database
    RefreshToken::create([
        'user_id' => $user->id,
        'token' => $refreshToken,
        'expires_at' => Carbon::now()->addDays(14),
    ]);

    // Hide sensitive data
    $user->makeHidden(['password']);

    // Return response with cookies
    return response()->json([
        'message' => 'Logged in',
        'firstName' => $user->firstName ?? '',
        'lastName' => $user->lastName ?? '',
        'email' => $user->email ?? '',
        'phoneNumber' => $user->phoneNumber ?? '',
        'role' => $user->user_role->roleName ?? '',
        'tenantId' => $user->currently_active_tenant->tenantId ?? '',
        'profileImage' => $user->profileImage ?? '/avatar.png',
        'coverImage' => $user->coverImage ?? '/cover_photo.jpg',

        // 'id' => $user->id ?? '',
        // 'access_token' => $accessToken,
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
