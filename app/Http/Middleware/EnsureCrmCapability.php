<?php

namespace App\Http\Middleware;

use App\Services\CrmAccessPolicy;
use App\Services\MarketAuthorizationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class EnsureCrmCapability
{
    public function __construct(private readonly CrmAccessPolicy $policy) {}

    public function requirement(Route $route): ?string
    {
        $uri = trim($route->uri(), '/');
        $action = ltrim($route->getActionName(), '\\');
        // This market metadata also powers the permitted Lifecycle workspace.
        if ($action === 'App\\Http\\Controllers\\CRM\\DashboardController@myMarkets') {
            return null;
        }
        foreach (['payments', 'reconciliations', 'collections', 'manual-payment-bundles', 'payment-recovery-cases'] as $family) {
            if ($uri === 'api/crm/'.$family || str_starts_with($uri, 'api/crm/'.$family.'/')) {
                return 'payments_workspace';
            }
        }
        if ($uri === 'api/crm/dashboard' || str_starts_with($uri, 'api/crm/dashboard/')) {
            return 'dashboard';
        }
        [$controller, $method] = array_pad(explode('@', $action), 2, '');
        $short = substr($controller, strrpos($controller, '\\') + 1);
        if (str_starts_with($controller, 'App\\Http\\Controllers\\CRM\\') && in_array($short, [
            'PaymentQueueController', 'PaymentExportController', 'ManualPaymentBundleController',
            'PaymentRecoveryCaseController', 'PaymentReviewController', 'PaymentReconciliationController',
            'CollectionPaymentController', 'CollectionClaimCheckController', 'CollectionSalesController',
            'CollectionSalesCorrectionController', 'CollectionSubsidiaryController', 'CollectionSubscriptionLinkController',
        ], true)) {
            return 'payments_workspace';
        }
        if ($controller === 'App\\Http\\Controllers\\API\\PaymentController' && in_array($method, ['list', 'getPayments'], true)) {
            return 'payments_workspace';
        }
        if ($controller === 'App\\Http\\Controllers\\API\\DashboardController' && $method === 'summary') {
            return 'dashboard';
        }
        if (($controller === 'App\\Http\\Controllers\\API\\AuthController' && in_array($method, ['register', 'updateUser'], true))
            || ($short === 'SettingsController' && in_array($method, ['storeUser', 'updateRole'], true))
            || ($short === 'McpTokenController' && in_array($method, ['store', 'update'], true))) {
            return 'manage_staff_access';
        }
        if ($short === 'SettingsController' && $method === 'impersonationLink') {
            return 'impersonate_staff';
        }

        return null;
    }

    public function handle(Request $request, Closure $next)
    {
        $required = $this->requirement($request->route());
        if ($required === null) {
            return $next($request);
        }
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $actor = $request->attributes->get('crm_impersonator', $user);
        if (! $this->policy->allows($user, $required) || ! $this->policy->allows($actor, $required)) {
            return response()->json([
                'message' => 'Your account does not have access to this workspace or action.',
                'code' => 'crm_access_denied',
                'home_path' => $this->policy->homePath($user),
            ], 403);
        }
        if (str_starts_with($request->route()->getActionName(), 'App\\Http\\Controllers\\API\\DashboardController@')) {
            app(MarketAuthorizationService::class)->ensureRequestedPlatformIsAccessible($request);
        }

        return $next($request);
    }
}
