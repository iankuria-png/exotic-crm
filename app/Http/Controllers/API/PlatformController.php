<?php

namespace App\Http\Controllers\API;

use App\Billing\Support\MarketBillingMethodPolicy;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Platform;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Helpers\LogHelper;

class PlatformController extends Controller
{
    public function __construct(
        private readonly MarketBillingMethodPolicy $marketBillingMethodPolicy
    ) {
    }

    // Create a platform (Admin only)
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
            'name' => 'required|string',
            'domain' => 'required|url|unique:platforms',
            'country' => 'required|string',
            'product_id' => 'nullable|exists:products,id',
            'db_host' => 'nullable|string',
            'db_name' => 'nullable|string',
            'db_user' => 'nullable|string',
            'db_pass' => 'nullable|string',
            'db_prefix' => 'nullable|string',
        ]);

            $platform = Platform::create($validated);

            // Log successful platform creation
            LogHelper::record(
                Auth::user(),
                'platform_created',
                $request,
                [
                    'platform_id' => $platform->id,
                    'name' => $platform->name,
                    'domain' => $platform->domain
                ]
            );

            return response()->json([
                'status' => 201,
                'message' => 'Platform created successfully',
                'platform' => $this->decoratePlatform($platform)
            ], 201);

        } catch (ValidationException $e) {
            // Log validation failure
            LogHelper::record(
                Auth::user(),
                'platform_validation_failed',
                $request,
                ['errors' => $e->errors()]
            );

            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            // Log platform creation failure
            LogHelper::record(
                Auth::user(),
                'platform_creation_failed',
                $request,
                [
                    'input' => $request->except(['db_pass']), // Exclude sensitive data
                    'error' => $e->getMessage()
                ]
            );

            Log::error('Platform creation failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to create platform',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    // List all platforms
    public function platform()
    {
        try {
            // Remove the duplicate call to Platform::all()
            $platforms = Platform::with('product')->get();
    
            // Log successful platform listing
            LogHelper::record(
                Auth::user(),
                'platforms_listed',
                request(),
                ['count' => $platforms->count()]
            );
    
            return response()->json([
                'status' => 200,
                'message' => 'Platforms retrieved successfully',
                'platforms' => $platforms->map(fn (Platform $platform) => $this->decoratePlatform($platform))->values()
            ], 200);
    
        } catch (\Exception $e) {
            // Log platform listing failure
            LogHelper::record(
                Auth::user(),
                'platforms_list_failed',
                request(),
                ['error' => $e->getMessage()]
            );
    
            Log::error('Failed to fetch platforms: ' . $e->getMessage());
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve platforms',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
        
    // Show platform for editing
    public function edit($id)
    {
        try {
            $platform = Platform::with('product')->findOrFail($id);
            
            // Log successful platform retrieval for editing
            LogHelper::record(
                Auth::user(),
                'platform_edit_viewed',
                request(),
                ['platform_id' => $platform->id]
            );
    
            return response()->json([
                'status' => 200,
                'message' => 'Platform retrieved for editing',
                'platform' => $this->decoratePlatform($platform)
            ], 200);
    
        } catch (\Exception $e) {
            // Log platform edit view failure
            LogHelper::record(
                Auth::user(),
                'platform_edit_failed',
                request(),
                [
                    'platform_id' => $id,
                    'error' => $e->getMessage()
                ]
            );
    
            return response()->json([
                'status' => 404,
                'message' => 'Platform not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Not found'
            ], 404);
        }
    }

    // Update a platform (Admin only)
    public function update(Request $request, $id)
    {
        try {
            $platform = Platform::findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'sometimes|required|string',
                'domain' => 'sometimes|required|url|unique:platforms,domain,' . $id,
                'country' => 'sometimes|required|string',
                'product_id' => 'nullable|exists:products,id',
                'db_host' => 'nullable|string',
                'db_name' => 'nullable|string',
                'db_user' => 'nullable|string',
                'db_pass' => 'nullable|string',
                'db_prefix' => 'nullable|string',
            ]);
    
            $platform->update($validated);
    
            // Log successful platform update
            LogHelper::record(
                Auth::user(),
                'platform_updated',
                $request,
                [
                    'platform_id' => $platform->id,
                    'changes' => $validated
                ]
            );
    
            return response()->json([
                'status' => 200,
                'message' => 'Platform updated successfully',
                'platform' => $this->decoratePlatform($platform)
            ], 200);
    
        } catch (ValidationException $e) {
            // Log validation failure
            LogHelper::record(
                Auth::user(),
                'platform_update_validation_failed',
                $request,
                [
                    'platform_id' => $id,
                    'errors' => $e->errors()
                ]
            );
    
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
    
        } catch (\Exception $e) {
            // Log platform update failure
            LogHelper::record(
                Auth::user(),
                'platform_update_failed',
                $request,
                [
                    'platform_id' => $id,
                    'input' => $request->except(['db_pass']),
                    'error' => $e->getMessage()
                ]
            );
    
            Log::error('Platform update failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update platform',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    // Delete a platform (Admin only)
    public function destroy($id)
    {
        try {
            $platform = Platform::findOrFail($id);
            $platform->delete();
    
            // Log successful platform deletion
            LogHelper::record(
                Auth::user(),
                'platform_deleted',
                request(),
                [
                    'platform_id' => $id,
                    'name' => $platform->name,
                    'domain' => $platform->domain
                ]
            );
    
            return response()->json([
                'status' => 200,
                'message' => 'Platform deleted successfully'
            ], 200);
    
        } catch (\Exception $e) {
            // Log platform deletion failure
            LogHelper::record(
                Auth::user(),
                'platform_deletion_failed',
                request(),
                [
                    'platform_id' => $id,
                    'error' => $e->getMessage()
                ]
            );
    
            Log::error('Platform deletion failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete platform',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    private function decoratePlatform(Platform $platform): array
    {
        $payload = $platform->toArray();
        unset($payload['supported_currencies']);
        $payload['billing_method_policy'] = $this->marketBillingMethodPolicy->contract($platform);
        $payload['effective_currencies'] = $platform->effectiveCurrencies();
        $payload['multi_currency_wallet_enabled'] = $platform->isMultiCurrencyWalletEnabled();

        return $payload;
    }
}
