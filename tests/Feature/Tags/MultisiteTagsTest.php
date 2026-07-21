<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Tags;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Site;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class MultisiteTagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('statamic.system.multisite', true);

        Site::setSites([
            'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => 'http://localhost/'],
            'fr' => ['name' => 'French', 'locale' => 'fr_FR', 'url' => 'http://localhost/fr/'],
        ]);
    }

    private function seedSites(): void
    {
        foreach ([['Default One', 'default'], ['Default Two', 'default'], ['French One', 'fr']] as [$text, $site]) {
            CommentFactory::new()
                ->threadId('ms-thread')
                ->site($site)
                ->author($text)
                ->text($text)
                ->data(['comment' => $text])
                ->published()
                ->create();
        }

        CommentFactory::new()
            ->threadId('fr-only-thread')
            ->site('fr')
            ->text('French Only')
            ->data(['comment' => 'French Only'])
            ->published()
            ->create();
    }

    #[Test]
    public function comment_count_defaults_to_the_current_site_and_star_opts_out(): void
    {
        $this->seedSites();

        $this->assertSame('2', trim($this->parseAntlers('{{ meerkat:comment_count thread="ms-thread" }}')));
        $this->assertSame('3', trim($this->parseAntlers('{{ meerkat:comment_count thread="ms-thread" site="*" }}')));
        $this->assertSame('1', trim($this->parseAntlers('{{ meerkat:comment_count thread="ms-thread" site="fr" }}')));
    }

    #[Test]
    public function recent_comments_and_top_threads_default_to_the_current_site(): void
    {
        $this->seedSites();

        $recent = $this->parseAntlers('{{ meerkat:recent_comments limit="10" }}[{{ comment_text }}]{{ /meerkat:recent_comments }}');
        $recentAll = $this->parseAntlers('{{ meerkat:recent_comments limit="10" site="*" }}[{{ comment_text }}]{{ /meerkat:recent_comments }}');
        $top = $this->parseAntlers('{{ meerkat:top_threads limit="5" }}[{{ thread_id }}:{{ comment_count }}]{{ /meerkat:top_threads }}');
        $topAll = $this->parseAntlers('{{ meerkat:top_threads limit="5" site="*" }}[{{ thread_id }}:{{ comment_count }}]{{ /meerkat:top_threads }}');

        $this->assertStringContainsString('[Default One]', $recent);
        $this->assertStringNotContainsString('French', $recent);
        $this->assertStringContainsString('[French One]', $recentAll);
        $this->assertStringContainsString('[ms-thread:2]', $top);
        $this->assertStringNotContainsString('fr-only-thread', $top);
        $this->assertStringContainsString('[ms-thread:3]', $topAll);
        $this->assertStringContainsString('[fr-only-thread:1]', $topAll);
    }

    #[Test]
    public function author_history_and_thread_stats_default_to_the_current_site(): void
    {
        foreach (['default', 'fr'] as $site) {
            CommentFactory::new()
                ->threadId('ms-history')
                ->site($site)
                ->author('Jane', 'jane@example.com')
                ->text('Body '.$site)
                ->data(['comment' => 'Body '.$site])
                ->published()
                ->create();
        }

        $history = $this->parseAntlers('{{ meerkat:author_history identifier="jane@example.com" }}[{{ comment_text }}]{{ /meerkat:author_history }}');
        $historyAll = $this->parseAntlers('{{ meerkat:author_history identifier="jane@example.com" site="*" }}[{{ comment_text }}]{{ /meerkat:author_history }}');
        $stats = $this->parseAntlers('{{ meerkat:thread_stats thread="ms-history" }}total={{ total_comments }}{{ /meerkat:thread_stats }}');
        $statsAll = $this->parseAntlers('{{ meerkat:thread_stats thread="ms-history" site="*" }}total={{ total_comments }}{{ /meerkat:thread_stats }}');

        $this->assertStringContainsString('[Body default]', $history);
        $this->assertStringNotContainsString('Body fr', $history);
        $this->assertStringContainsString('[Body fr]', $historyAll);
        $this->assertStringContainsString('total=1', $stats);
        $this->assertStringContainsString('total=2', $statsAll);
    }
}
