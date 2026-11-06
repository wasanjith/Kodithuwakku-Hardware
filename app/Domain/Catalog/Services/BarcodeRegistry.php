<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Maker's barcodes: one per product, never shared. Barcodes are learned while working:
 * typed or scanned on the product form, the product import, or linked to a product when
 * an unknown barcode is scanned at the counter or on a goods receipt.
 */
class BarcodeRegistry
{
    /**
     * Printable characters only, no spaces (EAN/UPC digits, Code 128 letters and symbols).
     */
    public const PATTERN = '/^[\x21-\x7E]{3,64}$/';

    /**
     * What the scanner sent, tidied: surrounding spaces and control characters removed.
     */
    public static function normalise(?string $barcode): ?string
    {
        $barcode = trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $barcode) ?? '');

        return $barcode === '' ? null : $barcode;
    }

    /**
     * The product that already has this barcode (deleted products give theirs up).
     */
    public function owner(string $barcode, ?int $ignoreProductId = null): ?Product
    {
        return Product::query()
            ->where('barcode', $barcode)
            ->when($ignoreProductId, fn ($query) => $query->whereKeyNot($ignoreProductId))
            ->first();
    }

    /**
     * Why this barcode cannot be given to the product, or null when it can.
     */
    public function problem(string $barcode, ?int $productId = null): ?string
    {
        if (preg_match(self::PATTERN, $barcode) !== 1) {
            return 'A barcode is 3 to 64 letters, digits or symbols, without spaces.';
        }

        if (($owner = $this->owner($barcode, $productId)) !== null) {
            return "Barcode {$barcode} already belongs to {$owner->short_code} · {$owner->name}.";
        }

        // The counter looks up barcodes before short codes, so a barcode may not hide another product's code.
        $codeOwner = Product::query()->where('short_code', mb_strtoupper($barcode))
            ->when($productId, fn ($query) => $query->whereKeyNot($productId))
            ->first(['id', 'short_code', 'name']);

        if ($codeOwner !== null) {
            return "{$barcode} is the short code of {$codeOwner->name}. Use a different barcode.";
        }

        return null;
    }

    /**
     * Give the product this barcode (an unknown barcode scanned at the counter or on a GRN).
     * A product that already has another barcode keeps it unless $replace is true.
     *
     * @throws ValidationException
     */
    public function link(Product $product, string $barcode, bool $replace = false): Product
    {
        $barcode = self::normalise($barcode) ?? '';

        if (($problem = $this->problem($barcode, $product->id)) !== null) {
            throw ValidationException::withMessages(['barcode' => $problem]);
        }

        if ($product->barcode !== null && $product->barcode !== $barcode && ! $replace) {
            throw ValidationException::withMessages([
                'replace' => "{$product->name} already has barcode {$product->barcode}. Replace it with {$barcode}?",
            ]);
        }

        // The product's activity log records the change (old → new barcode, who).
        $product->barcode = $barcode;
        $product->save();

        return $product;
    }
}
