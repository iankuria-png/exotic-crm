<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Helpers\LogHelper;

class ProductController extends Controller
{
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|unique:products',
                'monthly_price' => 'required|numeric|min:0',
                'biweekly_price' => 'required|numeric|min:0',
                'weekly_price' => 'required|numeric|min:0',
                'currency' => 'required|string|size:3',
            ]);

            // Validate that biweekly price is half of monthly price
            if ($validated['biweekly_price'] != $validated['monthly_price'] / 2 ||
                $validated['weekly_price'] != $validated['monthly_price'] / 4) {
            
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'price_ratio' => ['Biweekly must be half and weekly must be quarter of monthly price.']
                    ],
                ], 422);
            }

            $product = Product::create($validated);

            LogHelper::record(
                $request->user(),
                'product_created',
                $request,
                [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'monthly_price' => $product->monthly_price,
                    'currency' => $product->currency
                ]
            );

            return response()->json([
                'status' => 201,
                'message' => 'Product created successfully',
                'product' => $product,
            ], 201);

        } catch (ValidationException $e) {
            LogHelper::record(
                $request->user(),
                'product_validation_failed',
                $request,
                ['errors' => $e->errors()]
            );
            
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            LogHelper::record(
                $request->user(),
                'product_creation_failed',
                $request,
                ['error' => $e->getMessage()]
            );
            
            Log::error('Product creation failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to create product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'platform_id' => 'nullable|integer|exists:platforms,id',
            ]);

            $query = Product::query()
                ->where('is_active', true)
                ->where('is_archived', false)
                ->with('activePrices');

            if (!empty($validated['platform_id'])) {
                $query->where('platform_id', (int) $validated['platform_id']);
            }

            $products = $query
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            
            LogHelper::record(
                $request->user(),
                'products_listed',
                $request,
                [
                    'count' => $products->count(),
                    'platform_id' => $validated['platform_id'] ?? null,
                ]
            );

            return response()->json([
                'status' => 200,
                'message' => 'Products retrieved successfully',
                'products' => $products,
            ], 200);

        } catch (\Exception $e) {
            LogHelper::record(
                request()->user(),
                'products_list_failed',
                request(),
                ['error' => $e->getMessage()]
            );
            
            Log::error('Product fetch failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to retrieve products',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $product = Product::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|unique:products,name,' . $id,
                'monthly_price' => 'required|numeric|min:0',
                'biweekly_price' => 'required|numeric|min:0',
                'weekly_price' => 'required|numeric|min:0',
                'currency' => 'required|string|size:3',
            ]);

            // Validate that biweekly price is half of monthly price
           if ($validated['biweekly_price'] != $validated['monthly_price'] / 2 ||
                $validated['weekly_price'] != $validated['monthly_price'] / 4) {
            
                return response()->json([
                    'status' => 422,
                    'message' => 'Validation failed',
                    'errors' => [
                        'price_ratio' => ['Biweekly must be half and weekly must be quarter of monthly price.']
                    ],
                ], 422);
            }

            $originalData = $product->getOriginal();
            $product->update($validated);

            LogHelper::record(
                $request->user(),
                'product_updated',
                $request,
                [
                    'product_id' => $id,
                    'changes' => [
                        'before' => [
                            'name' => $originalData['name'],
                            'monthly_price' => $originalData['monthly_price'],
                            'biweekly_price' => $originalData['biweekly_price'],
                            'weekly_price' => $originalData['weekly_price'],
                            'currency' => $originalData['currency']
                        ],
                        'after' => [
                            'name' => $validated['name'],
                            'monthly_price' => $validated['monthly_price'],
                            'biweekly_price' => $validated['biweekly_price'],
                            'weekly_price' => $validated['weekly_price'],
                            'currency' => $validated['currency']
                        ]
                    ]
                ]
            );

            return response()->json([
                'status' => 200,
                'message' => 'Product updated successfully',
                'product' => $product,
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            LogHelper::record(
                $request->user(),
                'product_not_found',
                $request,
                ['product_id' => $id]
            );
            
            return response()->json([
                'status' => 404,
                'message' => 'Product not found',
                'error' => 'The requested product does not exist',
            ], 404);

        } catch (ValidationException $e) {
            LogHelper::record(
                $request->user(),
                'product_validation_failed',
                $request,
                [
                    'product_id' => $id,
                    'errors' => $e->errors()
                ]
            );
            
            return response()->json([
                'status' => 422,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            LogHelper::record(
                $request->user(),
                'product_update_failed',
                $request,
                [
                    'product_id' => $id,
                    'error' => $e->getMessage()
                ]
            );
            
            Log::error('Product update failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            $productData = $product->toArray();
            $product->delete();

            LogHelper::record(
                request()->user(),
                'product_deleted',
                request(),
                [
                    'product_id' => $id,
                    'product_data' => $productData
                ]
            );

            return response()->json([
                'status' => 200,
                'message' => 'Product deleted successfully',
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            LogHelper::record(
                request()->user(),
                'product_not_found',
                request(),
                ['product_id' => $id]
            );
            
            return response()->json([
                'status' => 404,
                'message' => 'Product not found',
                'error' => 'The requested product does not exist',
            ], 404);

        } catch (\Exception $e) {
            LogHelper::record(
                request()->user(),
                'product_deletion_failed',
                request(),
                [
                    'product_id' => $id,
                    'error' => $e->getMessage()
                ]
            );
            
            Log::error('Product deletion failed: ' . $e->getMessage());
            
            return response()->json([
                'status' => 500,
                'message' => 'Failed to delete product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
