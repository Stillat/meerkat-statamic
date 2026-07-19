<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Database;

class CustomTablePrefixTest extends TablePrefixTestCase
{
    protected function tablePrefix(): string
    {
        return 'cmt_';
    }
}
