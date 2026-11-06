<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\BarcodeRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/barcodes {barcode, product_id, replace?}
 * An unknown barcode was scanned (counter, goods receipt): the Owner or a Manager picks the
 * product it belongs to and the barcode is saved on it. The next scan finds the product.
 * 422 "replace" when the product already has another barcode and replace was not ticked.
 */
class BarcodeLinkController extends Controller
{
    public function __invoke(Request $request, BarcodeRegistry $barcodes): JsonResponse
    {
        $this->authorize('linkBarcode', Product::class);

        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:64'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'replace' => ['boolean'],
        ]);

        $product = $barcodes->link(Product::findOrFail($validated['product_id']), $validated['barcode'], (bool) ($validated['replace'] ?? false));

        return response()->json([
            'product' => ['id' => $product->id, 'short_code' => $product->short_code, 'name' => $product->name, 'barcode' => $product->barcode],
            'message' => "Barcode {$product->barcode} saved on {$product->name}.",
        ]);
    }
}
