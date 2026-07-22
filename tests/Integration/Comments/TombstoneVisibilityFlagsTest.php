<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Integration\Comments;

use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Facades\Comments;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class TombstoneVisibilityFlagsTest extends TestCase
{
    #[Test]
    public function inclusion_flags_control_tombstones_and_their_descendants_independently(): void
    {
        $root = CommentFactory::new()->threadId('flag-matrix')->depth(0)->published()->create();
        $child = CommentFactory::new()->threadId('flag-matrix')->parent($root->id)->depth(1)->published()->create();
        $grandchild = CommentFactory::new()->threadId('flag-matrix')->parent($child->id)->depth(2)->published()->create();
        Comments::deleteComment($root->id);

        $this->assertEqualsCanonicalizing(
            [$root->id, $child->id, $grandchild->id],
            Comments::hiddenSubtreeIds('flag-matrix', false, false),
        );

        $this->assertEqualsCanonicalizing(
            [$child->id, $grandchild->id],
            Comments::hiddenSubtreeIds('flag-matrix', true, false),
        );

        $this->assertSame(
            [$root->id],
            Comments::hiddenSubtreeIds('flag-matrix', false, true),
        );

        $this->assertSame([], Comments::hiddenSubtreeIds('flag-matrix', true, true));
    }
}
