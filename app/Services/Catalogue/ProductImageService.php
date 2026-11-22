<?php

namespace App\Services\Catalogue;

use App\Exceptions\BusinessRuleException;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Product images are:
 * - validated by MIME sniffing + extension + size (form layer) AND decoded
 *   here with GD; anything GD cannot decode as an image is rejected
 * - re-encoded to WebP (strips metadata and any embedded payload such as
 *   PHP/HTML polyglots) and resized to fit config('inventory.product_images.max_dimension')
 * - stored on a PRIVATE disk under companies/{company-uuid}/products/
 *   and served only through an authorised, tenant-scoped route
 */
class ProductImageService
{
    public function store(Product $product, UploadedFile $file): string
    {
        $config = config('inventory.product_images');

        if ($file->getSize() > $config['max_kilobytes'] * 1024) {
            throw BusinessRuleException::make('image', __('The image may not be larger than :kb KB.', ['kb' => $config['max_kilobytes']]));
        }

        $info = @getimagesize($file->getRealPath());
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

        if ($info === false || ! in_array($info[2], $allowed, true) || $info[0] > 8000 || $info[1] > 8000) {
            throw BusinessRuleException::make('image', __('Upload a JPG, PNG or WebP image.'));
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($source === false) {
            throw BusinessRuleException::make('image', __('The image could not be read.'));
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, $config['max_dimension'] / max($width, $height));
        $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        ob_start();
        imagewebp($target, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        $company = Company::query()->findOrFail($product->company_id);
        $path = sprintf('companies/%s/products/%s-%s.webp', $company->uuid, $product->uuid, Str::lower(Str::random(12)));

        $disk = Storage::disk($config['disk']);
        $disk->put($path, $bytes, ['visibility' => 'private']);

        $old = $product->image_path;
        $product->forceFill(['image_path' => $path])->save();

        if ($old !== null && $old !== $path) {
            $disk->delete($old);
        }

        return $path;
    }

    public function remove(Product $product): void
    {
        if ($product->image_path !== null) {
            Storage::disk(config('inventory.product_images.disk'))->delete($product->image_path);
            $product->forceFill(['image_path' => null])->save();
        }
    }
}
