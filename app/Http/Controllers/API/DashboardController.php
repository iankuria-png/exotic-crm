<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WordpressPost;
use Illuminate\Support\Carbon;
use App\Models\Platform;
use App\Models\WordpressUser;
use App\Helpers\LogHelper;
use App\Services\DynamicDatabaseService;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $platformId = $request->input('platform_id');
        $days = $request->input('days');

        if (!$platformId) {
            return response()->json(['error' => 'Platform ID is required'], 422);
        }

        $platform = Platform::find($platformId);
        if (!$platform) {
            return response()->json(['error' => 'Platform not found'], 404);
        }

        $connectionName = 'platform_' . $platform->id;
        DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

        $postsQuery = WordpressPost::on($connectionName)->where('post_type', 'escort');

        // Apply platform-specific exclusions
        $exclusions = config('platform_exclusions', []);
        if (isset($exclusions[$platformId])) {
            $platformExclusions = $exclusions[$platformId];

            if (!empty($platformExclusions['user_ids'])) {
                $postsQuery->whereNotIn('post_author', $platformExclusions['user_ids']);
            }

            if (!empty($platformExclusions['post_ids'])) {
                $postsQuery->whereNotIn('ID', $platformExclusions['post_ids']);
            }
        }

        if ($days && is_numeric($days)) {
            $postsQuery->where('post_date', '>=', now()->subDays($days));
        }

        $active = (clone $postsQuery)->where('post_status', 'publish')->count();
        $inactive = (clone $postsQuery)->where('post_status', 'private')->count();
        $dormant = (clone $postsQuery)
            ->where('post_status', '!=', 'publish')
            ->where('post_date', '<', now()->subDays(60))
            ->count();

        // ✅ Log the summary activity
        LogHelper::record(
            $request->user(),
            'dashboard_summary',
            $request,
            [
                'platform_id' => $platformId,
                'days' => $days,
                'results' => [
                    'active' => $active,
                    'inactive' => $inactive,
                    'dormant' => $dormant
                ]
            ]
        );

        return response()->json([
            'platform' => $platform->name,
            'active_profiles' => $active,
            'deactivated_profiles' => $inactive,
            'dormant_accounts' => $dormant
        ]);
    }

    public function escortPosts(Request $request)
    {
        $platformId = $request->input('platform_id');
        $days = $request->input('days');

        if (!$platformId) {
            return response()->json(['error' => 'Platform ID is required'], 422);
        }

        $platform = Platform::find($platformId);
        if (!$platform) {
            return response()->json(['error' => 'Platform not found'], 404);
        }

        $connectionName = 'platform_' . $platform->id;
        DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

        $postsQuery = WordpressPost::on($connectionName)->where('post_type', 'escort');

        $exclusions = config('platform_exclusions', []);
        if (isset($exclusions[$platformId])) {
            $platformExclusions = $exclusions[$platformId];

            if (!empty($platformExclusions['user_ids'])) {
                $postsQuery->whereNotIn('post_author', $platformExclusions['user_ids']);
            }

            if (!empty($platformExclusions['post_ids'])) {
                $postsQuery->whereNotIn('ID', $platformExclusions['post_ids']);
            }
        }

        if ($days && is_numeric($days)) {
            $postsQuery->where('post_date', '>=', now()->subDays($days));
        }

     $escortPosts = $postsQuery
    ->with('phone') // eager load phone meta
    ->get(['ID', 'post_author', 'post_date', 'post_title', 'post_status', 'guid']);

$authorIds = $escortPosts->pluck('post_author')->unique();
$users = WordpressUser::on($connectionName)
    ->whereIn('ID', $authorIds)
    ->get(['ID', 'user_login', 'user_email']);

$userMap = $users->keyBy('ID');

$escortPosts = $escortPosts->map(function ($post) use ($userMap) {
    $post->user_id = $post->post_author;
    $post->user_login = optional($userMap->get($post->post_author))->user_login;
    $post->phone = optional($post->phone)->meta_value;
    return $post;
});


        // ✅ Log the escort posts activity
        LogHelper::record(
            $request->user(),
            'dashboard_escort_posts',
            $request,
            [
                'platform_id' => $platformId,
                'days' => $days,
                'post_count' => $escortPosts->count()
            ]
        );

        return response()->json([
            'platform' => $platform->name,
            'escort_posts' => $escortPosts
        ]);
    }

    public function recentUsers(Request $request)
    {
        $platformId = $request->input('platform_id');
        $days = $request->input('days', 7);

        if (!$platformId) {
            return response()->json(['error' => 'Platform ID is required'], 422);
        }

        $platform = Platform::find($platformId);
        if (!$platform) {
            return response()->json(['error' => 'Platform not found'], 404);
        }

        $connectionName = 'platform_' . $platform->id;
        DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

        $usersQuery = WordpressUser::on($connectionName)
            ->where('user_registered', '>=', now()->subDays($days));

        $exclusions = config('platform_exclusions', []);
        if (isset($exclusions[$platformId]) && !empty($exclusions[$platformId]['user_ids'])) {
            $usersQuery->whereNotIn('ID', $exclusions[$platformId]['user_ids']);
        }

        $recent = $usersQuery->orderByDesc('user_registered')
            ->get(['ID', 'user_login', 'user_email', 'user_registered']);

        // ✅ Log the recent users activity
        LogHelper::record(
            $request->user(),
            'dashboard_recent_users',
            $request,
            [
                'platform_id' => $platformId,
                'days' => $days,
                'user_count' => $recent->count()
            ]
        );

        return response()->json([
            'platform' => $platform->name,
            'days' => $days,
            'new_users' => $recent
        ]);
    }
}
