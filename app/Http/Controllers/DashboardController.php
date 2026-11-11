<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Forecast\SmaForecaster;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $email = strtolower($user->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter?->name ?? 'A team member',
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        $teamSlug = $request->route('current_team');
        $team = null;
        if ($teamSlug) {
            $team = Team::where('slug', $teamSlug)->first();
        }
        if (! $team) {
            $team = $user->currentTeam ?: $user->teams()->first();
        }

        $stats = [
            'totalProducts' => 0,
            'lowStockCount' => 0,
            'todaySales' => 0,
            'topSupplier' => [
                'name' => 'N/A',
                'score' => '0.000',
            ],
        ];

        $recentActivity = [];
        $forecastSeries = null;

        if ($team) {
            $curYear = (int) today()->year;
            $forecastSeries = app(SmaForecaster::class)->series(
                $team->id,
                Carbon::create($curYear, 1, 1),
                Carbon::create($curYear, 12, 31),
                SmaForecaster::DEFAULT_WINDOW,
            );

            $productCounts = Product::where('team_id', $team->id)
                ->where('is_active', true)
                ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'Low Stock' THEN 1 ELSE 0 END) as low_stock")
                ->first();
            $stats['totalProducts'] = (int) ($productCounts->total ?? 0);
            $stats['lowStockCount'] = (int) ($productCounts->low_stock ?? 0);
            // Confirmed orders only: unpaid or cancelled orders are not sales yet.
            $stats['todaySales'] = (float) Sale::where('team_id', $team->id)->whereIn('status', SalesOrderStatus::countedInSales())->whereDate('sale_date', today())->sum('grand_total');

            // Highest record-based Supplier Evaluation among active suppliers with complete data.
            $top = collect(app(SupplierEvaluator::class)->forTeam($team->id))
                ->filter(fn ($e) => $e['complete'] && $e['supplier']['status'] === 'Active')
                ->sortByDesc('score')
                ->first();
            if ($top) {
                $stats['topSupplier'] = [
                    'name' => $top['supplier']['name'],
                    'score' => number_format($top['score'], 2).' / 100 ('.$top['classification'].')',
                ];
            }

            // Recent activity feed: merge recent quotations, sales, and stock movements
            $recentQuotations = Quotation::where('team_id', $team->id)
                ->latest()
                ->take(5)
                ->get(['id', 'quotation_number', 'status', 'created_at'])
                ->map(fn ($q) => [
                    'id' => $q->quotation_number,
                    'type' => 'Quotation',
                    'status' => $q->status?->label() ?? '',
                    'created_at' => $q->created_at,
                ]);

            $recentMovements = StockMovement::where('team_id', $team->id)
                ->latest('movement_date')
                ->take(5)
                ->get(['id', 'reference_number', 'type', 'created_at'])
                ->map(fn ($m) => [
                    'id' => $m->reference_number ?: ('MOV-'.$m->id),
                    'type' => $m->formatted_type,
                    'status' => 'Completed',
                    'created_at' => $m->created_at,
                ]);

            $recentSales = Sale::where('team_id', $team->id)
                ->latest()
                ->take(5)
                ->get(['id', 'order_number', 'invoice_number', 'status', 'created_at'])
                ->map(fn ($s) => [
                    'id' => $s->order_number ?: $s->invoice_number,
                    'type' => 'Sales Order',
                    'status' => $s->status?->label() ?? '',
                    'created_at' => $s->created_at,
                ]);

            $recentActivity = $recentQuotations
                ->concat($recentSales)
                ->concat($recentMovements)
                ->sortByDesc('created_at')
                ->take(5)
                ->values()
                ->map(function ($item) {
                    $status = $item['status'];
                    $colorClass = match ($status) {
                        'Approved', 'Accepted', 'Converted to Sales Order', 'Confirmed', 'Completed', 'In Stock' => 'bg-green-500/10 text-green-500 border-green-500/20',
                        'Pending', 'Pending Review', 'For Approval', 'Pending Payment' => 'bg-[#F8B803]/10 text-[#F8B803] border-[#F8B803]/20',
                        'In Review', 'Under Review', 'Sent to Customer', 'Processing' => 'bg-blue-500/10 text-blue-500 border-blue-500/20',
                        'Draft', 'Under Preparation' => 'bg-gray-500/10 text-gray-400 border-gray-500/20',
                        'Rejected', 'Cancelled', 'Expired', 'Out of Stock' => 'bg-red-500/10 text-red-500 border-red-500/20',
                        default => 'bg-gray-500/10 text-gray-500 border-gray-500/20',
                    };

                    return [
                        'id' => $item['id'],
                        'type' => $item['type'],
                        'status' => $item['status'],
                        'class' => $colorClass,
                    ];
                })
                ->all();
        }

        $isFirstLogin = (bool) session('is_first_login', false);
        if (! $isFirstLogin && $user) {
            $loginCount = $user->auditLogs()->where('action_type', 'auth.login.success')->count();
            if ($loginCount <= 1 && ($user->last_login_at === null || ($user->created_at && $user->created_at->diffInHours(now()) < 24))) {
                $isFirstLogin = true;
            }
        }

        return Inertia::render('Dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'forecastSeries' => $forecastSeries,
            'isFirstLogin' => $isFirstLogin,
        ]);
    }
}
