<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Http\Controllers;

use Illuminate\Http\Request;
use Stillat\Meerkat\Support\FrontendAssets;
use Symfony\Component\HttpFoundation\Response;

class AssetController
{
    public function replies(Request $request): Response
    {
        $path = FrontendAssets::repliesPath();

        abort_unless(is_file($path), 404);

        $response = response()->make((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);

        $response->setEtag(FrontendAssets::repliesVersion());

        $response->isNotModified($request);

        return $response;
    }
}
