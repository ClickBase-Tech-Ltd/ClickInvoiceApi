<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    /**
     * Return basic totals for dashboard.
     */
    public function dashboardCounts()
    {
        $totalUsers = User::count();
        $totalInvoices = Invoice::count();
        $totalReceipts = Invoice::whereNotNull('receiptId')->count();
        $totalBusinesses = Tenant::count();
        $activeBusinesses = Tenant::where('status', 'active')->count();
        $inactiveBusinesses = Tenant::where('status', 'inactive')->count();
        $subscriptionRevenueUsd = Subscription::with('plan')
            ->whereIn('status', ['active', 'cancelled', 'expired'])
            ->get()
            ->sum(function ($sub) {
                $metaAmount = data_get($sub->metadata, 'amount_paid');
                if ($metaAmount !== null && $metaAmount !== '') {
                    return (float) $metaAmount;
                }
                return (float) ($sub->plan->price ?? 0);
            });

        return response()->json([
            'totalUsers' => $totalUsers,
            'totalInvoices' => $totalInvoices,
            'totalReceipts' => $totalReceipts,
            'totalBusinesses' => $totalBusinesses,
            'activeBusinesses' => $activeBusinesses,
            'inactiveBusinesses' => $inactiveBusinesses,
            'subscriptionRevenueUsd' => round($subscriptionRevenueUsd, 2),
        ]);
    }

    /**
     * Return Users details for KPI modal.
     */
    public function usersDetails()
    {
        try {
            $weekly = [];
            for ($i = 3; $i >= 0; $i--) {
                $start = Carbon::now()->subWeeks($i + 1)->startOfWeek();
                $end = Carbon::now()->subWeeks($i)->endOfWeek();
                $weekly[] = User::whereBetween('created_at', [$start, $end])->count();
            }

            $monthly = [];
            for ($i = 2; $i >= 0; $i--) {
                $start = Carbon::now()->subMonths($i + 1)->startOfMonth();
                $end = Carbon::now()->subMonths($i)->endOfMonth();
                $monthly[] = User::whereBetween('created_at', [$start, $end])->count();
            }

            $recentItems = User::orderBy('created_at', 'desc')
                ->take(15)
                ->get()
                ->map(fn($user) => trim($user->firstName . ' ' . $user->lastName . ' ' . ($user->otherNames ?? '')))
                ->toArray();

            $newUsers = User::where('created_at', '>=', now()->subMonth())->count();
            $returningUsers = User::count() - $newUsers;

            $activeUsers = User::where('status', 'active')->count();
            $inactiveUsers = User::where('status', 'inactive')->count();

            return response()->json([
                'weekly' => $weekly,
                'monthly' => $monthly,
                'recentItems' => $recentItems,
                'newUsers' => $newUsers,
                'returningUsers' => $returningUsers,
                'activeUsers' => $activeUsers,
                'inactiveUsers' => $inactiveUsers,
            ]);
        } catch (\Throwable $e) {
            \Log::error('usersDetails error: ' . $e->getMessage());
            return response()->json([
                'weekly' => [0,0,0,0],
                'monthly' => [0,0,0],
                'recentItems' => [],
                'newUsers' => 0,
                'returningUsers' => 0,
                'activeUsers' => 0,
                'inactiveUsers' => 0,
            ], 200);
        }
    }

    /**
     * Return Invoices details for KPI modal.
     */
public function invoicesDetails()
{
    try {
        $weeksCount = 4;
        $monthsCount = 3;

        // Weekly counts using created_at
        $weekly = [];
        for ($i = $weeksCount - 1; $i >= 0; $i--) {
            $start = Carbon::now()->subWeeks($i + 1)->startOfWeek();
            $end = Carbon::now()->subWeeks($i)->endOfWeek();
            $weekly[] = Invoice::whereBetween('created_at', [$start, $end])->count();
        }

        // Monthly counts using created_at
        $monthly = [];
        for ($i = $monthsCount - 1; $i >= 0; $i--) {
            $start = Carbon::now()->subMonths($i + 1)->startOfMonth();
            $end = Carbon::now()->subMonths($i)->endOfMonth();
            $monthly[] = Invoice::whereBetween('created_at', [$start, $end])->count();
        }

        // Last 15 invoices
        $recentItems = Invoice::orderBy('created_at', 'desc')
            ->take(15)
            ->pluck('invoiceId')
            ->toArray();

        // Totals
        $paidInvoices = Invoice::where('status', 'paid')->count() ?? 0;
        $unpaidInvoices = Invoice::where('status', '!=', 'paid')->count() ?? 0;
        $totalRevenue = Invoice::sum('amountPaid') ?? 0;

        // Averages
        $weeklyAvg = count($weekly) ? array_sum($weekly) / count($weekly) : 0;
        $monthlyAvg = count($monthly) ? array_sum($monthly) / count($monthly) : 0;

        return response()->json([
            'weekly' => $weekly,
            'monthly' => $monthly,
            'recentItems' => $recentItems,
            'paidInvoices' => $paidInvoices,
            'unpaidInvoices' => $unpaidInvoices,
            'totalRevenue' => $totalRevenue,
            'weeklyAvg' => round($weeklyAvg, 2),
            'monthlyAvg' => round($monthlyAvg, 2),
        ]);
    } catch (\Throwable $e) {
        \Log::error('invoicesDetails error: ' . $e->getMessage());
        return response()->json([
            'weekly' => [0,0,0,0],
            'monthly' => [0,0,0],
            'recentItems' => [],
            'paidInvoices' => 0,
            'unpaidInvoices' => 0,
            'totalRevenue' => 0,
            'weeklyAvg' => 0,
            'monthlyAvg' => 0,
        ], 200);
    }
}


    /**
     * Return Receipts details for KPI modal.
     */
    public function receiptsDetails()
    {
        try {
            $weeksCount = 4;
            $monthsCount = 3;

            // Weekly counts
            $weekly = [];
            for ($i = $weeksCount - 1; $i >= 0; $i--) {
                $start = Carbon::now()->subWeeks($i + 1)->startOfWeek();
                $end = Carbon::now()->subWeeks($i)->endOfWeek();
                $weekly[] = Invoice::whereNotNull('receiptId')
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            }

            // Monthly counts
            $monthly = [];
            for ($i = $monthsCount - 1; $i >= 0; $i--) {
                $start = Carbon::now()->subMonths($i + 1)->startOfMonth();
                $end = Carbon::now()->subMonths($i)->endOfMonth();
                $monthly[] = Invoice::whereNotNull('receiptId')
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            }

            // Last 15 receipts
            $recentItems = Invoice::whereNotNull('receiptId')
                ->orderBy('created_at', 'desc')
                ->take(15)
                ->pluck('receiptId')
                ->toArray();

            // Weekly & monthly averages
            $weeklyAvg = count($weekly) ? array_sum($weekly) / count($weekly) : 0;
            $monthlyAvg = count($monthly) ? array_sum($monthly) / count($monthly) : 0;

            return response()->json([
                'weekly' => $weekly,
                'monthly' => $monthly,
                'recentItems' => $recentItems,
                'weeklyAvg' => round($weeklyAvg, 2),
                'monthlyAvg' => round($monthlyAvg, 2),
            ]);
        } catch (\Throwable $e) {
            \Log::error('receiptsDetails error: ' . $e->getMessage());

            $recentItems = Invoice::whereNotNull('receiptId')
                ->orderBy('created_at', 'desc')
                ->take(15)
                ->pluck('receiptId')
                ->toArray();

            return response()->json([
                'weekly' => [0,0,0,0],
                'monthly' => [0,0,0],
                'recentItems' => $recentItems,
                'weeklyAvg' => 0,
                'monthlyAvg' => 0,
            ], 200);
        }
    }

    /**
     * Return Businesses (Tenants) details for KPI modal.
     */
    public function businessesDetails()
    {
        try {
            $weekly = [];
            for ($i = 3; $i >= 0; $i--) {
                $start = Carbon::now()->subWeeks($i + 1)->startOfWeek();
                $end = Carbon::now()->subWeeks($i)->endOfWeek();
                $weekly[] = Tenant::whereBetween('created_at', [$start, $end])->count();
            }

            $monthly = [];
            for ($i = 2; $i >= 0; $i--) {
                $start = Carbon::now()->subMonths($i + 1)->startOfMonth();
                $end = Carbon::now()->subMonths($i)->endOfMonth();
                $monthly[] = Tenant::whereBetween('created_at', [$start, $end])->count();
            }

            $recentItems = Tenant::latest()
                ->take(15)
                ->get()
                ->map(fn($t) => $t->tenantName)
                ->toArray();

            $totalBusinesses = Tenant::count();
            $activeBusinesses = Tenant::where('status', 'active')->count();
            $inactiveBusinesses = Tenant::where('status', 'inactive')->count();
            $newBusinesses = Tenant::where('created_at', '>=', now()->subMonth())->count();

            return response()->json([
                'weekly' => $weekly,
                'monthly' => $monthly,
                'recentItems' => $recentItems,
                'totalBusinesses' => $totalBusinesses,
                'activeBusinesses' => $activeBusinesses,
                'inactiveBusinesses' => $inactiveBusinesses,
                'newBusinesses' => $newBusinesses,
            ]);
        } catch (\Throwable $e) {
            \Log::error('businessesDetails error: ' . $e->getMessage());
            return response()->json([
                'weekly' => [0,0,0,0],
                'monthly' => [0,0,0],
                'recentItems' => [],
                'totalBusinesses' => 0,
                'activeBusinesses' => 0,
                'inactiveBusinesses' => 0,
                'newBusinesses' => 0,
            ], 200);
        }
    }
}
