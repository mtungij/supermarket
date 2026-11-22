<?php

namespace App\Http\Controllers\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves product images from the PRIVATE disk. {product} is resolved
 * through the tenant-scoped model, so another company's product is 404,
 * and the path comes from the database, never from the request.
 */
class ProductImageController extends Controller
{
    public function __invoke(Product $product): StreamedResponse
    {
        $this->authorize('products.view');
        $disk = Storage::disk(config('inventory.product_images.disk'));

        abort_if($product->image_path === null || ! $disk->exists($product->image_path), 404);

        return $disk->response($product->image_path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
