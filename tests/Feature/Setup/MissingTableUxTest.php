<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Setup;

use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Stillat\Meerkat\Support\CpErrorResponse;
use Stillat\Meerkat\Tests\TestCase;

class MissingTableUxTest extends TestCase
{
    #[Test]
    public function missing_table_renders_as_a_localized_503_in_production(): void
    {
        config(['app.debug' => false]);

        $response = CpErrorResponse::fromMissingTable($this->missingTableException());

        $this->assertSame(503, $response->status());
        $body = $this->requireObject($response->getData(true));
        $this->assertIsString($body['message']);
        $this->assertSame(__('meerkat::errors.unavailable_pending_migration'), $body['message']);
        $this->assertStringNotContainsString('SQLSTATE', $body['message']);
        $this->assertStringNotContainsString('no such table', $body['message']);
    }

    #[Test]
    public function missing_table_re_throws_in_debug_mode_so_developers_see_the_real_error(): void
    {
        config(['app.debug' => true]);

        $this->expectException(QueryException::class);
        CpErrorResponse::fromMissingTable($this->missingTableException());
    }

    #[Test]
    public function unrelated_query_exceptions_pass_through(): void
    {
        config(['app.debug' => false]);

        $exception = new QueryException(
            connectionName: 'meerkat',
            sql: 'select * from "comments"',
            bindings: [],
            previous: new \PDOException(
                'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'
            ),
        );

        $this->expectException(QueryException::class);
        CpErrorResponse::fromMissingTable($exception);
    }

    private function missingTableException(): QueryException
    {
        return new QueryException(
            connectionName: 'meerkat',
            sql: 'select * from "comment_revisions" where "comment_id" = ?',
            bindings: [42],
            previous: new \PDOException(
                'SQLSTATE[HY000]: General error: 1 no such table: comment_revisions'
            ),
        );
    }
}
