<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TenantsController extends Controller
{
    public function index(Request $request)
    {
        // return $tenantId = $request->header('X-Tenant-ID');

        $tenants = Tenant::with('currency', 'payment_gateway')->get();
        return response()->json($tenants);
    }

public function myTenants(Request $request)
    {
        // return $tenantId = $request->header('X-Tenant-ID');

        $tenants = Tenant::with('currency', 'payment_gateway')
        ->where('ownerId', auth()->id())
        ->get();
        return response()->json($tenants);
    }

    public function store(Request $request)
    {

        $user = Auth::user();
        if (!$user->canCreateTenant()) {
            return response()->json([
                'message' => 'Sorry you can\'t add any more businesses. Upgrade to premium to add more businesses.'
            ], 403);
        }
        // Validate the request data
        $validated = $request->validate([
            'tenantName' => 'required|string|max:255',
            'tenantAddress' => 'nullable|string',
            'taxId' => 'nullable|string',
            'tenantEmail' => 'required|email',
            'tenantPhone' => 'nullable|string',
            'tenantLogo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'authorizedSignature' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            // 'countryCode' => 'required|string',
            'timezone' => 'required|string',
            'gatewayPreference' => 'nullable|integer|exists:payment_gateways,gatewayId',
            // 'companyStatus' => 'required|in:active,inactive',
            'currency' => 'required|integer|exists:currencies,currencyId',
        ]);

        // Handle logo upload
        if ($request->hasFile('tenantLogo')) {
            $logoFile = $request->file('tenantLogo');
            $logoPath = $logoFile->store('tenant-logos', 'public');
            $validated['tenantLogo'] = $logoPath;
        } else {
            $validated['tenantLogo'] = null;
        }


        // Handle signature upload
        if ($request->hasFile('authorizedSignature')) {
            $logoFile = $request->file('authorizedSignature');
            $logoPath = $logoFile->store('signatures', 'public');
            $validated['authorizedSignature'] = $logoPath;
        } else {
            $validated['authorizedSignature'] = null;
        }

        $owner = auth()->id();
        $validated['ownerId'] = $owner;
        $company = Tenant::create($validated);

        // Return a response, typically JSON
        return response()->json($company, 201); // HTTP status code 201: Created
    }


    public function show($id)
    {
        $company = Company::find($id);
        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }
        return response()->json($company);
    }


    public function myCompanies()
    {
        $company = Company::where('createdBy', auth()->id())
            ->first();
        if (!$company) {
            return response()->json(['message' => 'No companies found'], 404);
        }
        return response()->json($company);
    }

    // public function update(Request $request, $id)
    // {
    //     // Find the company
    //     $company = Company::findOrFail($id);

    //     // Validate the request data
    //     $validated = $request->validate([
    //         'companyName' => 'required|string|max:255',
    //         'companyDescription' => 'required|string',
    //         'companyAddress' => 'required|string',
    //         'companyEmail' => 'required|email',
    //         'companyPhone' => 'nullable|string',
    //         'companyWebsite' => 'nullable|url',
    //         'companyIndustry' => 'required|string',
    //         'companySize' => 'required|string',
    //         'companyLocation' => 'required|string',
    //         'companyFoundedYear' => 'nullable|integer|min:1900|max:' . date('Y'),
    //         'companyStatus' => 'required|in:active,inactive',
    //         'companyLogo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // 5MB max
    //     ]);

    //     // Handle logo upload
    //     if ($request->hasFile('companyLogo')) {
    //         // Delete old logo if exists
    //         if ($company->companyLogo) {
    //             Storage::disk('public')->delete($company->companyLogo);
    //         }

    //         $logoFile = $request->file('companyLogo');
    //         $logoPath = $logoFile->store('company-logos', 'public');
    //         $validated['companyLogo'] = $logoPath;
    //     } else {
    //         // Keep the existing logo if no new file is uploaded
    //         $validated['companyLogo'] = $company->companyLogo;
    //     }

    //     // Update the company
    //     $company->update($validated);

    //     return response()->json($company, 200);
    // }

    public function destroy($id)
    {
        $company = Company::find($id);
        if (!$company) {
            return response()->json(['message' => 'Company not found'], 404);
        }

        $company->delete();
        return response()->json(['message' => 'Company deleted successfully']);
    }



public function update(Request $request, $tenantId)
{
    // Handle method spoofing (for file uploads)
    if ($request->has('_method') && strtoupper($request->_method) === 'PUT') {
        // Laravel will now parse files correctly because it's treated as POST
    }

    $validated = $request->validate([
        'tenantName' => 'required|string|max:255',
        'tenantEmail' => 'required|email|max:255',
        'tenantAddress' => 'nullable|string',
        'taxId' => 'nullable|string',
        'tenantPhone' => 'nullable|string|max:20',
         'tenantLogo' => 'sometimes|file|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
'authorizedSignature' => 'sometimes|file|image|mimes:png,jpg,jpeg,svg|max:2048',
'timezone' => 'required|string|max:100',
        // 'currency' => 'required|exists:currencies,currencyId',
        'gatewayPreference' => 'required|exists:payment_gateways,gatewayId',
        'status' => 'sometimes|in:active,inactive',
    ]);

    $user = auth()->user();

    $tenant = Tenant::where('tenantId', $tenantId)
        ->where('ownerId', $user->id)
        ->firstOrFail();

    $updateData = $validated;

    // Handle logo
    if ($request->hasFile('tenantLogo')) {
        if ($tenant->tenantLogo) {
            Storage::disk('public')->delete($tenant->tenantLogo);
        }
        $updateData['tenantLogo'] = $request->file('tenantLogo')->store('tenant-logos', 'public');
        
    }

    // Handle signature
    if ($request->hasFile('authorizedSignature')) {
        if ($tenant->authorizedSignature) {
            Storage::disk('public')->delete($tenant->authorizedSignature);
        }
        $updateData['authorizedSignature'] = $request->file('authorizedSignature')->store('signatures', 'public');
    }

    $tenant->update($updateData);
    $tenant->load('currency', 'payment_gateway');

    return response()->json([
        'message' => 'Business updated successfully',
        'tenant' => $tenant,
    ]);
}


public function setDefaultTenant(Request $request, $tenantId)
{
    $request->validate([
        // Optional: add any validation if sending JSON body
    ]);

    $user = Auth::user();
    $tenantId = (int) $tenantId;

    // Start a database transaction for atomicity
    return DB::transaction(function () use ($user, $tenantId) {
        // Check if the tenant belongs to the authenticated user
        $tenant = $user->default_tenant()->where('tenantId', $tenantId)->first();

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found or you do not have access to it.'
            ], 404);
        }

        if ($tenant->isDefault === 1) {
            return response()->json([
                'message' => 'This tenant is already the default.',
                'tenant' => $tenant
            ], 200);
        }

        // Unset current default tenant
        $user->default_tenant()->where('isDefault', 1)->update(['isDefault' => 0]);

        // Set the new one as default
        $tenant->update(['isDefault' => 1]);

        return response()->json([
            'message' => 'Default tenant switched successfully.',
            'tenant' => $tenant->makeHidden(['authorizedSignature']) // optional: hide sensitive fields
        ], 200);
    });
}

public function toggleTenantStatus(Request $request, $tenantId)
{
    $validated = $request->validate([
        'status' => 'required|in:active,inactive',
    ]);

    $user = auth()->user();

    $tenant = Tenant::where('tenantId', $tenantId)
        ->where('ownerId', $user->id)
        ->firstOrFail();

    // 🚫 Prevent disabling the default tenant
    if ($tenant->isDefault == 1 && $request->status === 'inactive') {
        return response()->json([
            'message' => 'You cannot deactivate the default business. Switch to another business or add a new one and make it the default before disabling this',
        ], 422);
    }

    $tenant->update([
        'status' => $request->status,
    ]);

    return response()->json([
        'message' => 'Tenant status updated successfully',
        'tenant' => $tenant,
    ]);
}


}
