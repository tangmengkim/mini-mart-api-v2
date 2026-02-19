<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use App\Models\Section;
use App\Models\Shelf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{
    /**
     * Display a listing of products with advanced filtering and search
     */
    public function index(Request $request)
    {
        try {
            $query = Product::with(['category', 'section', 'shelf']);

            // Only show active products by default
            if (!$request->has('include_inactive')) {
                $query->where('is_active', true);
            }

            // Search functionality - improved to search in category and location
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('category', function ($categoryQuery) use ($search) {
                            $categoryQuery->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('section', function ($sectionQuery) use ($search) {
                            $sectionQuery->where('name', 'like', "%{$search}%");
                        });
                });
            }

            // Filter by category
            if ($request->has('category_id') && !empty($request->category_id)) {
                $query->where('category_id', $request->category_id);
            }

            // Filter by section
            if ($request->has('section_id') && !empty($request->section_id)) {
                $query->where('section_id', $request->section_id);
            }

            // Filter by shelf
            if ($request->has('shelf_id') && !empty($request->shelf_id)) {
                $query->where('shelf_id', $request->shelf_id);
            }

            // Filter by low stock
            if ($request->has('low_stock') && $request->low_stock == '1') {
                $query->whereColumn('stock_quantity', '<=', 'min_stock_level');
            }

            // Filter by stock status
            if ($request->has('out_of_stock') && $request->out_of_stock == '1') {
                $query->where('stock_quantity', 0);
            }

            // Price range filter
            if ($request->has('min_price') && !empty($request->min_price)) {
                $query->where('price', '>=', $request->min_price);
            }
            if ($request->has('max_price') && !empty($request->max_price)) {
                $query->where('price', '<=', $request->max_price);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');

            $allowedSortFields = ['name', 'price', 'stock_quantity', 'created_at', 'updated_at'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('name', 'asc');
            }

            // Get pagination limit from request (default 15, max 100)
            $perPage = min($request->get('per_page', 15), 100);
            $products = $query->paginate($perPage);

            // Add additional computed fields
            $products->getCollection()->transform(function ($product) {
                $product->is_low_stock = $product->stock_quantity <= $product->min_stock_level;
                $product->is_out_of_stock = $product->stock_quantity == 0;
                $product->location_full = $product->section->name . ' - ' . $product->shelf->name . ' (Level ' . $product->shelf->level . ')';
                $product->image_url = $product->image ? asset('storage/' . $product->image) : null;
                return $product;
            });

            return response()->json([
                'success' => true,
                'message' => 'Products retrieved successfully',
                'data' => $products,
                'filters_applied' => [
                    'search' => $request->search ?? null,
                    'category_id' => $request->category_id ?? null,
                    'section_id' => $request->section_id ?? null,
                    'low_stock' => $request->low_stock ?? null,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve products',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Store a newly created product
     */
    public function store(Request $request)
    {
        if (!$request->user()->isShopOwner()) {
            return response()->json([
                'success' => false,
                'message' => 'Only shop owners can add products'
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'barcode' => 'nullable|string|unique:products',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'required|integer|min:0',
            'min_stock_level' => 'required|integer|min:0',
            'category_id' => 'required|exists:categories,id',
            'section_id' => 'required|exists:sections,id',
            'shelf_id' => 'required|exists:shelves,id',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'image_url' => 'nullable|url|max:500', // External image URL
        ]);

        $productData = $request->except(['image']);

        // Handle uploaded image
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
            $productData['image'] = $imagePath;
        }

        // Handle image URL (external link)
        if ($request->filled('image_url')) {
            $productData['image_url'] = $request->image_url;
        }

        $product = Product::create($productData);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product->load(['category', 'section', 'shelf'])
        ], 201);
    }

    /**
     * Display the specified product
     */
    public function show($id)
    {
        try {
            $product = Product::with(['category', 'section', 'shelf'])
                ->findOrFail($id);

            // Add computed fields
            $product->is_low_stock = $product->stock_quantity <= $product->min_stock_level;
            $product->is_out_of_stock = $product->stock_quantity == 0;
            $product->location_full = $product->section->name . ' - ' . $product->shelf->name . ' (Level ' . $product->shelf->level . ')';
            // $product->image_url = $product->image ? asset('storage/' . $product->image) : null;

            return response()->json([
                'success' => true,
                'message' => 'Product retrieved successfully',
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Product not found'
            ], 404);
        }
    }

    /**
     * Update the specified product
     */
    public function update(Request $request, $id)
    {
        try {
            // Check if user is shop owner
            if (!$request->user()->isShopOwner()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only shop owners can update products'
                ], 403);
            }

            $product = Product::findOrFail($id);

            // Validation
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'barcode' => 'nullable|string|unique:products,barcode,' . $product->id,
                'description' => 'nullable|string|max:1000',
                'price' => 'required|numeric|min:0.01',
                'cost_price' => 'nullable|numeric|min:0',
                'stock_quantity' => 'required|integer|min:0',
                'min_stock_level' => 'required|integer|min:0',
                'category_id' => 'required|exists:categories,id',
                'section_id' => 'required|exists:sections,id',
                'shelf_id' => 'required|exists:shelves,id',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,webp|max:5120',
                'is_active' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify shelf belongs to section
            $shelf = Shelf::where('id', $request->shelf_id)
                ->where('section_id', $request->section_id)
                ->first();

            if (!$shelf) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected shelf does not belong to the selected section'
                ], 422);
            }

            DB::beginTransaction();

            $productData = $request->except('image');
            $oldImagePath = $product->image;

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image
                if ($oldImagePath && Storage::disk('public')->exists($oldImagePath)) {
                    Storage::disk('public')->delete($oldImagePath);
                }

                $image = $request->file('image');
                $imageName = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                $imagePath = $image->storeAs('products', $imageName, 'public');
                $productData['image'] = $imagePath;
            }

            $product->update($productData);

            DB::commit();

            $product->load(['category', 'section', 'shelf']);
            $product->image_url = $product->image ? asset('storage/' . $product->image) : null;

            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => $product
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Remove the specified product (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        try {
            // Check if user is shop owner
            if (!$request->user()->isShopOwner()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only shop owners can delete products'
                ], 403);
            }

            $product = Product::findOrFail($id);

            // Check if product has sales history
            if ($product->saleItems()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete product with sales history. You can deactivate it instead.'
                ], 400);
            }

            DB::beginTransaction();

            // Soft delete the product
            $product->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get all categories for dropdown
     */
    public function getCategories()
    {
        try {
            $categories = Category::where('is_active', true)
                ->select('id', 'name', 'description')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Categories retrieved successfully',
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get all sections with their shelves
     */
    public function getSections()
    {
        try {
            $sections = Section::where('is_active', true)
                ->with(['shelves' => function ($query) {
                    $query->where('is_active', true)->orderBy('level');
                }])
                ->orderBy('position')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Sections retrieved successfully',
                'data' => $sections
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sections',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get shelves by section ID
     */
    public function getShelvesBySection($sectionId)
    {
        try {
            $section = Section::findOrFail($sectionId);

            $shelves = Shelf::where('section_id', $sectionId)
                ->where('is_active', true)
                ->orderBy('level')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Shelves retrieved successfully',
                'data' => [
                    'section' => $section,
                    'shelves' => $shelves
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve shelves',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get products by barcode (for scanning)
     */
    public function getByBarcode(Request $request)
    {
        try {
            $request->validate([
                'barcode' => 'required|string'
            ]);

            $product = Product::with(['category', 'section', 'shelf'])
                ->where('barcode', $request->barcode)
                ->where('is_active', true)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found with this barcode'
                ], 404);
            }

            // Add computed fields
            $product->is_low_stock = $product->stock_quantity <= $product->min_stock_level;
            $product->is_out_of_stock = $product->stock_quantity == 0;
            $product->location_full = $product->section->name . ' - ' . $product->shelf->name . ' (Level ' . $product->shelf->level . ')';
            $product->image_url = $product->image ? asset('storage/' . $product->image) : null;

            return response()->json([
                'success' => true,
                'message' => 'Product found',
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to find product',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update product stock
     */
    public function updateStock(Request $request, $id)
    {
        try {
            // Check if user is shop owner
            if (!$request->user()->isShopOwner()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only shop owners can update stock'
                ], 403);
            }

            $product = Product::findOrFail($id);

            $request->validate([
                'stock_quantity' => 'required|integer|min:0',
                'operation' => 'nullable|in:add,subtract,set', // add, subtract, or set
                'reason' => 'nullable|string|max:255'
            ]);

            $operation = $request->get('operation', 'set');
            $newQuantity = $request->stock_quantity;

            DB::beginTransaction();

            switch ($operation) {
                case 'add':
                    $product->increment('stock_quantity', $newQuantity);
                    break;
                case 'subtract':
                    if ($product->stock_quantity < $newQuantity) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot subtract more than current stock'
                        ], 400);
                    }
                    $product->decrement('stock_quantity', $newQuantity);
                    break;
                case 'set':
                default:
                    $product->update(['stock_quantity' => $newQuantity]);
                    break;
            }

            DB::commit();

            $product->load(['category', 'section', 'shelf']);

            return response()->json([
                'success' => true,
                'message' => 'Stock updated successfully',
                'data' => [
                    'product' => $product,
                    'operation' => $operation,
                    'previous_stock' => $product->getOriginal('stock_quantity'),
                    'current_stock' => $product->stock_quantity
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update stock',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get low stock products
     */
    public function getLowStockProducts(Request $request)
    {
        try {
            $products = Product::with(['category', 'section', 'shelf'])
                ->whereColumn('stock_quantity', '<=', 'min_stock_level')
                ->where('is_active', true)
                ->orderBy('stock_quantity', 'asc')
                ->get();

            $products->transform(function ($product) {
                $product->is_low_stock = true;
                $product->is_out_of_stock = $product->stock_quantity == 0;
                $product->location_full = $product->section->name . ' - ' . $product->shelf->name . ' (Level ' . $product->shelf->level . ')';
                $product->image_url = $product->image ? asset('storage/' . $product->image) : null;
                return $product;
            });

            return response()->json([
                'success' => true,
                'message' => 'Low stock products retrieved successfully',
                'data' => $products,
                'count' => $products->count()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve low stock products',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Toggle product active status
     */
    public function toggleStatus(Request $request, $id)
    {
        try {
            // Check if user is shop owner
            if (!$request->user()->isShopOwner()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only shop owners can change product status'
                ], 403);
            }

            $product = Product::findOrFail($id);
            $product->update(['is_active' => !$product->is_active]);

            return response()->json([
                'success' => true,
                'message' => 'Product status updated successfully',
                'data' => [
                    'product_id' => $product->id,
                    'is_active' => $product->is_active,
                    'status' => $product->is_active ? 'activated' : 'deactivated'
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Bulk operations on products
     */
    public function bulkAction(Request $request)
    {
        try {
            // Check if user is shop owner
            if (!$request->user()->isShopOwner()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only shop owners can perform bulk actions'
                ], 403);
            }

            $request->validate([
                'product_ids' => 'required|array|min:1',
                'product_ids.*' => 'integer|exists:products,id',
                'action' => 'required|in:activate,deactivate,delete,update_category',
                'category_id' => 'required_if:action,update_category|exists:categories,id'
            ]);

            $productIds = $request->product_ids;
            $action = $request->action;
            $affectedCount = 0;

            DB::beginTransaction();

            switch ($action) {
                case 'activate':
                    $affectedCount = Product::whereIn('id', $productIds)->update(['is_active' => true]);
                    break;

                case 'deactivate':
                    $affectedCount = Product::whereIn('id', $productIds)->update(['is_active' => false]);
                    break;

                case 'delete':
                    // Check if any products have sales history
                    $productsWithSales = Product::whereIn('id', $productIds)
                        ->whereHas('saleItems')
                        ->count();

                    if ($productsWithSales > 0) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Some products have sales history and cannot be deleted'
                        ], 400);
                    }

                    $affectedCount = Product::whereIn('id', $productIds)->delete();
                    break;

                case 'update_category':
                    $affectedCount = Product::whereIn('id', $productIds)
                        ->update(['category_id' => $request->category_id]);
                    break;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Bulk {$action} completed successfully",
                'data' => [
                    'action' => $action,
                    'affected_count' => $affectedCount,
                    'product_ids' => $productIds
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to perform bulk action',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
