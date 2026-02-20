<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Template;
use App\Models\User;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function templates(Request $request)
    {
        $query = Template::query()->with('platform');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        return response()->json(
            $query->orderByDesc('updated_at')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function updateTemplate(Request $request, Template $template)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'category' => 'sometimes|in:payment,renewal,follow_up,welcome,win_back',
            'channel' => 'sometimes|in:email,sms',
            'subject' => 'nullable|string|max:255',
            'body' => 'sometimes|string|max:10000',
            'status' => 'sometimes|in:active,draft',
            'variables' => 'nullable|array',
        ]);

        $template->update($validated);
        $template->load('platform');

        return response()->json($template);
    }

    public function webhookLogs(Request $request)
    {
        $query = AuditLog::query()
            ->with('actor:id,name,email')
            ->whereIn('action', [
                'deal_activated',
                'deal_deactivated',
                'deal_extended',
                'payment_auto_matched',
                'payment_match_confirmed',
            ]);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($builder) use ($search) {
                $builder->where('action', 'like', "%{$search}%")
                    ->orWhere('entity_type', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $query->orderByDesc('created_at')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function roles()
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'status', 'assigned_market_ids'])
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        $summary = [
            'admins' => $users->where('role', 'admin')->count(),
            'sub_admins' => $users->where('role', 'sub_admin')->count(),
            'sales' => $users->where('role', 'sales')->count(),
            'inactive' => $users->where('status', 'inactive')->count(),
        ];

        return response()->json([
            'summary' => $summary,
            'users' => $users,
        ]);
    }
}
