<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;


class ImageProxyController extends Controller
{
    public function show(Request $request)
    {
        $path = $request->query('path');
        $thumb = $request->boolean('thumb');
        $width = $request->integer('width');
        $height = $request->integer('height');
        $fit = $request->query('fit', 'contain');

        if (!$path || !Str::endsWith(Str::lower($path), ['.jpg', '.jpeg', '.png'])) {
            return response()->json(['error' => 'Ruta inválida'], 422);
        }

        $disk = Storage::disk('wasabi');
        $originalExists = $disk->exists($path);

        if (!$originalExists) {
            return response()->file(public_path('images/fallback.png'));
        }

        if ($thumb && $width && $height) {
            $thumbPath = "thumbnails/{$width}x{$height}_" . Str::afterLast($path, '/');

            if (!$disk->exists($thumbPath)) {
                $tmp = tmpfile();
                $meta = stream_get_meta_data($tmp);
                $tmpPath = $meta['uri'];

                // Descargar imagen temporalmente
                file_put_contents($tmpPath, $disk->get($path));

                $manager = new ImageManager('gd'); // o 'imagick' si prefieres

                $image = $manager->read($tmpPath);

                if ($fit === 'crop') {
                    $image->cover($width, $height);
                } else {
                    $image->resize($width, $height, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                }

                $thumbData = (string) $image->encode('jpg', 80);
                $disk->put($thumbPath, $thumbData);
            }

            return response($disk->get($thumbPath), 200, [
                'Content-Type' => 'image/jpeg'
            ]);
        }

        // Imagen original
        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $disk->mimeType($path),
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
        ]);
    }
}

