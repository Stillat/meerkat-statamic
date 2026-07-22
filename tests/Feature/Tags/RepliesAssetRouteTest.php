<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Tags;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Tests\TestCase;

class RepliesAssetRouteTest extends TestCase
{
    #[Test]
    public function route_serves_the_exact_cacheable_bundle_and_honors_its_etag(): void
    {
        $response = $this->get(route('meerkat.assets.replies'))->assertOk();
        $this->assertSame((string) file_get_contents($this->addonPath('resources/dist/replies.js')), $response->getContent());
        $this->assertStringContainsString('javascript', strtolower((string) $response->headers->get('Content-Type')));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $etag = (string) $response->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->get(route('meerkat.assets.replies'), ['If-None-Match' => $etag])->assertStatus(304);
    }
}
