<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Changelog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ChangelogsController extends Controller
{
    /**
     * Get all changelogs with optional filters and pagination
     */
    public function index(Request $request)
    {
        try {
            $query = Changelog::with(['user', 'productionOrder']);

            // Filter by action type
            if ($request->has('action_type') && $request->action_type !== 'All') {
                $query->byActionType($request->action_type);
            }

            // Filter by user role
            if ($request->has('user_role') && $request->user_role !== 'All') {
                $query->byRole($request->user_role);
            }

            // Filter by user NIK
            if ($request->has('user_nik')) {
                $query->byUser($request->user_nik);
            }

            // Filter by date range
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
            } elseif ($request->has('period')) {
                // Quick filters: today, this_week, this_month
                switch ($request->period) {
                    case 'today':
                        $query->today();
                        break;
                    case 'this_week':
                        $query->thisWeek();
                        break;
                    case 'this_month':
                        $query->thisMonth();
                        break;
                    case 'recent':
                        $query->recent($request->input('days', 30));
                        break;
                }
            }

            // Filter by batch number
            if ($request->has('batch_number') && !empty($request->batch_number)) {
                $query->byBatch($request->batch_number);
            }

            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $query->search($request->search);
            }

            // Order by most recent first
            $query->latest();

            // Pagination
            $perPage = $request->input('per_page', 50);
            if ($request->has('paginate') && $request->paginate === 'false') {
                $changelogs = $query->get();
            } else {
                $changelogs = $query->paginate($perPage);
            }

            return response()->json([
                'success' => true,
                'data' => $changelogs,
                'message' => 'Changelogs retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching changelogs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch changelogs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new changelog entry (manual logging)
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'action_type' => 'required|string',
                'description' => 'required|string',
                'user_nik' => 'required|string',
                'batch_number' => 'nullable|string',
                'additional_data' => 'nullable|array',
            ]);

            $changelog = Changelog::log(
                $validated['action_type'],
                $validated['description'],
                $validated['user_nik'],
                $validated['batch_number'] ?? null,
                $validated['additional_data'] ?? []
            );

            return response()->json([
                'success' => true,
                'data' => $changelog,
                'message' => 'Changelog created successfully'
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating changelog: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create changelog: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get changelog by ID with related data
     */
    public function show($id)
    {
        try {
            $changelog = Changelog::with(['user', 'productionOrder'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $changelog,
                'message' => 'Changelog retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Changelog not found'
            ], 404);
        }
    }

    /**
     * Get changelogs for a specific batch with timeline view
     */
    public function getByBatch($batchNumber)
    {
        try {
            $changelogs = Changelog::with(['user'])
                ->byBatch($batchNumber)
                ->latest()
                ->get()
                ->map(function($log) {
                    return [
                        'id' => $log->id,
                        'timestamp' => $log->timestamp,
                        'formatted_timestamp' => $log->formatted_timestamp,
                        'time_ago' => $log->time_ago,
                        'action_type' => $log->action_type,
                        'action_color' => $log->action_color,
                        'description' => $log->description,
                        'user_name' => $log->user_name,
                        'user_nik' => $log->user_nik,
                        'user_role' => $log->user_role,
                        'batch_number' => $log->batch_number,
                        'additional_data' => $log->additional_data,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $changelogs,
                'count' => $changelogs->count(),
                'message' => 'Changelogs for batch retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching changelogs for batch: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch changelogs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get changelogs for a specific user
     */
    public function getByUser($userNik)
    {
        try {
            $changelogs = Changelog::with(['productionOrder'])
                ->byUser($userNik)
                ->latest()
                ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $changelogs,
                'message' => 'Changelogs for user retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching changelogs for user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch changelogs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get changelog statistics and analytics
     */
    public function getStatistics(Request $request)
    {
        try {
            $days = $request->input('days', 30);
            $stats = Changelog::getStatistics($days);

            // Additional custom statistics
            $additionalStats = [
                'daily_activity' => $this->getDailyActivity($days),
                'top_batches' => $this->getTopBatches($days),
                'action_trends' => $this->getActionTrends($days),
            ];

            return response()->json([
                'success' => true,
                'data' => array_merge($stats, $additionalStats),
                'period' => "{$days} days",
                'message' => 'Statistics retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get daily activity breakdown
     */
    private function getDailyActivity($days)
    {
        return Changelog::select(
                DB::raw('DATE(timestamp) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->where('timestamp', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * Get top most active batches
     */
    private function getTopBatches($days)
    {
        return Changelog::select('batch_number', DB::raw('COUNT(*) as activity_count'))
            ->where('timestamp', '>=', now()->subDays($days))
            ->whereNotNull('batch_number')
            ->where('batch_number', '!=', '')
            ->groupBy('batch_number')
            ->orderBy('activity_count', 'desc')
            ->limit(10)
            ->get();
    }

    /**
     * Get action type trends over time
     */
    private function getActionTrends($days)
    {
        return Changelog::select(
                'action_type',
                DB::raw('COUNT(*) as count'),
                DB::raw('DATE(timestamp) as date')
            )
            ->where('timestamp', '>=', now()->subDays($days))
            ->groupBy('action_type', 'date')
            ->orderBy('date', 'desc')
            ->get()
            ->groupBy('action_type');
    }

    /**
     * Get available action types
     */
    public function getActionTypes()
    {
        try {
            $actionTypes = Changelog::getActionTypes();

            return response()->json([
                'success' => true,
                'data' => $actionTypes,
                'message' => 'Action types retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch action types'
            ], 500);
        }
    }

    /**
     * Get user activity summary
     */
    public function getUserActivitySummary(Request $request)
    {
        try {
            $days = $request->input('days', 30);
            $userNik = $request->input('user_nik');

            $query = Changelog::where('timestamp', '>=', now()->subDays($days));
            
            if ($userNik) {
                $query->byUser($userNik);
            }

            $summary = [
                'total_actions' => $query->count(),
                'by_action_type' => $query->select('action_type', DB::raw('COUNT(*) as count'))
                    ->groupBy('action_type')
                    ->pluck('count', 'action_type'),
                'batches_worked_on' => $query->whereNotNull('batch_number')
                    ->distinct('batch_number')
                    ->count('batch_number'),
                'most_active_day' => $query->select(
                        DB::raw('DATE(timestamp) as date'),
                        DB::raw('COUNT(*) as count')
                    )
                    ->groupBy('date')
                    ->orderBy('count', 'desc')
                    ->first(),
            ];

            return response()->json([
                'success' => true,
                'data' => $summary,
                'period' => "{$days} days",
                'message' => 'User activity summary retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching user activity: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch user activity: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export changelogs to CSV
     */
    public function export(Request $request)
    {
        try {
            $query = Changelog::query();

            // Apply same filters as index
            if ($request->has('action_type') && $request->action_type !== 'All') {
                $query->byActionType($request->action_type);
            }
            if ($request->has('user_role') && $request->user_role !== 'All') {
                $query->byRole($request->user_role);
            }
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->dateRange($request->start_date, $request->end_date);
            }
            if ($request->has('batch_number') && !empty($request->batch_number)) {
                $query->byBatch($request->batch_number);
            }

            $changelogs = $query->latest()->get();

            // Generate CSV content
            $csv = "Timestamp,Action Type,Description,User Name,User NIK,User Role,Batch Number\n";
            
            foreach ($changelogs as $log) {
                $csv .= sprintf(
                    '"%s","%s","%s","%s","%s","%s","%s"' . "\n",
                    $log->formatted_timestamp,
                    $log->action_type,
                    str_replace('"', '""', $log->description),
                    $log->user_name,
                    $log->user_nik,
                    $log->user_role,
                    $log->batch_number ?? ''
                );
            }

            $filename = 'changelogs_' . now()->format('Y-m-d_His') . '.csv';

            return response($csv, 200)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");

        } catch (\Exception $e) {
            Log::error('Error exporting changelogs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export changelogs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Search changelogs with advanced options
     */
    public function search(Request $request)
    {
        try {
            $validated = $request->validate([
                'query' => 'required|string|min:2',
                'action_type' => 'nullable|string',
                'user_role' => 'nullable|string',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);

            $query = Changelog::search($validated['query']);

            if (isset($validated['action_type'])) {
                $query->byActionType($validated['action_type']);
            }
            if (isset($validated['user_role'])) {
                $query->byRole($validated['user_role']);
            }
            if (isset($validated['start_date']) && isset($validated['end_date'])) {
                $query->dateRange($validated['start_date'], $validated['end_date']);
            }

            $results = $query->latest()->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => 'Search completed successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error searching changelogs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete old changelogs (for maintenance)
     */
    public function deleteOldLogs(Request $request)
    {
        try {
            $validated = $request->validate([
                'days' => 'nullable|integer|min:1',
                'confirm' => 'required|boolean|accepted'
            ]);

            $days = $validated['days'] ?? 90;
            $date = now()->subDays($days);

            $deleted = Changelog::where('timestamp', '<', $date)->delete();

            Log::info("Deleted {$deleted} changelogs older than {$days} days");

            return response()->json([
                'success' => true,
                'data' => ['deleted_count' => $deleted, 'cutoff_date' => $date],
                'message' => "Deleted {$deleted} changelogs older than {$days} days"
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error deleting old changelogs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete old changelogs: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent activity feed (for dashboard)
     */
    public function getRecentActivity(Request $request)
    {
        try {
            $limit = $request->input('limit', 20);
            
            $activities = Changelog::with(['user'])
                ->latest()
                ->limit($limit)
                ->get()
                ->map(function($log) {
                    return [
                        'id' => $log->id,
                        'time_ago' => $log->time_ago,
                        'action_type' => $log->action_type,
                        'action_color' => $log->action_color,
                        'description' => $log->description,
                        'user_name' => $log->user_name,
                        'batch_number' => $log->batch_number,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $activities,
                'message' => 'Recent activity retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching recent activity: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch recent activity: ' . $e->getMessage()
            ], 500);
        }
    }
}