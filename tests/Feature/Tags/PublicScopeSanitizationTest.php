<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests\Feature\Tags;

use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;
use Statamic\Fields\Field;
use Statamic\Fields\Value;
use Statamic\View\Antlers\Language\Runtime\GlobalRuntimeState;
use Stillat\Meerkat\Comments\PublicCommentData;
use Stillat\Meerkat\Database\Models\UserMeta;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Stillat\Meerkat\Tests\TestCase;

class PublicScopeSanitizationTest extends TestCase
{
    private const TEMPLATE = '[{{ name }}|{{ email }}|{{ user_ip }}|{{ moderation_notes }}]';

    #[Test]
    public function trusted_templates_render_sensitive_comment_fields(): void
    {
        $this->createEntry(['id' => 'sanitize-thread']);
        UserMeta::create(['user_id' => 'cp-user', 'name' => 'Registered', 'email' => 'cp-secret@example.com']);
        CommentFactory::new()
            ->threadId('sanitize-thread')
            ->authorId('cp-user')
            ->requestMetadata('203.0.113.9', 'Secret Agent', 'https://secret.example.com')
            ->text('Root body')
            ->data(['comment' => 'Root body'])
            ->published()
            ->create(['moderation_status' => 'approved', 'moderation_notes' => 'internal-mod-note']);

        $result = $this->parseAntlers(
            '{{ meerkat:comments thread="sanitize-thread" }}'.self::TEMPLATE.'{{ /meerkat:comments }}',
        );

        $this->assertStringContainsString('[Registered|cp-secret@example.com|203.0.113.9|internal-mod-note]', $result);
    }

    #[Test]
    public function sensitive_fields_are_suppressed_while_evaluating_user_content(): void
    {
        $this->createEntry(['id' => 'sanitize-untrusted']);
        CommentFactory::new()
            ->threadId('sanitize-untrusted')
            ->author('Jane', 'jane-secret@example.com')
            ->requestMetadata('198.51.100.7')
            ->text('Body')
            ->data(['comment' => 'Body'])
            ->published()
            ->create(['moderation_notes' => 'untrusted-mod-note']);

        $result = (string) Antlers::parse(
            '{{ meerkat:comments thread="sanitize-untrusted" }}[{{ name }}]{{ user_bio }}{{ /meerkat:comments }}',
            ['user_bio' => $this->userContentValue('leak:{{ email }}{{ user_ip }}{{ moderation_notes }}:end')],
            trusted: true,
        );

        $this->assertStringContainsString('[Jane]', $result);
        $this->assertStringContainsString('leak::end', $result);

        foreach (['jane-secret@example.com', '198.51.100.7', 'untrusted-mod-note'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $result);
        }
    }

    private function userContentValue(string $raw): Value
    {
        $field = new Field('user_bio', ['type' => 'text', 'antlers' => true]);

        return new Value($raw, 'user_bio', $field->fieldtype());
    }

    #[Test]
    public function sensitive_fields_are_suppressed_inside_the_antlers_modifier(): void
    {
        $this->createEntry(['id' => 'sanitize-modifier']);
        CommentFactory::new()
            ->threadId('sanitize-modifier')
            ->author('Jane', 'jane-mod@example.com')
            ->requestMetadata('198.51.100.9')
            ->text('Body')
            ->data(['comment' => 'Body'])
            ->published()
            ->create(['moderation_notes' => 'modifier-mod-note']);

        $result = (string) Antlers::parse(
            '{{ meerkat:comments thread="sanitize-modifier" }}[{{ name }}]{{ raw_bio | antlers }}{{ /meerkat:comments }}',
            ['raw_bio' => 'leak:{{ email }}{{ user_ip }}{{ moderation_notes }}:end'],
            trusted: true,
        );

        $this->assertStringContainsString('[Jane]', $result);
        $this->assertStringContainsString('leak::end', $result);

        foreach (['jane-mod@example.com', '198.51.100.9', 'modifier-mod-note'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $result);
        }
    }

    #[Test]
    public function guarded_values_resolve_by_runtime_trust_state(): void
    {
        $guarded = PublicCommentData::guard([
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'user_ip' => '198.51.100.7',
        ]);

        $this->assertSame('Jane', $guarded['name']);

        $email = $guarded['email'];
        $ip = $guarded['user_ip'];
        $this->assertInstanceOf(Value::class, $email);
        $this->assertInstanceOf(Value::class, $ip);

        $previousFlag = GlobalRuntimeState::$isEvaluatingUserData;
        $previousState = GlobalRuntimeState::$userContentEvalState;

        try {
            GlobalRuntimeState::$isEvaluatingUserData = false;
            GlobalRuntimeState::$userContentEvalState = null;
            $this->assertSame('jane@example.com', $email->value());
            $this->assertSame('198.51.100.7', $ip->value());

            GlobalRuntimeState::$isEvaluatingUserData = true;
            GlobalRuntimeState::$userContentEvalState = [null, null];
            $this->assertNull($email->value());
            $this->assertNull($ip->value());
        } finally {
            GlobalRuntimeState::$isEvaluatingUserData = $previousFlag;
            GlobalRuntimeState::$userContentEvalState = $previousState;
        }
    }

    #[Test]
    public function recent_comments_and_author_history_guard_sensitive_fields(): void
    {
        $this->createEntry(['id' => 'sanitize-recent']);
        CommentFactory::new()
            ->threadId('sanitize-recent')
            ->author('Jane', 'jane-recent@example.com')
            ->requestMetadata('198.51.100.7')
            ->text('Recent body')
            ->data(['comment' => 'Recent body'])
            ->published()
            ->create();

        $template = '[{{ name }}|{{ email }}]';

        $trusted = $this->parseAntlers('{{ meerkat:recent_comments limit="5" }}'.$template.'{{ /meerkat:recent_comments }}');
        $this->assertStringContainsString('[Jane|jane-recent@example.com]', $trusted);

        $userContent = (string) Antlers::parse(
            '{{ meerkat:recent_comments limit="5" }}{{ user_bio }}{{ /meerkat:recent_comments }}',
            ['user_bio' => $this->userContentValue('leak:{{ email }}:end')],
            trusted: true,
        );
        $this->assertStringContainsString('leak::end', $userContent);
        $this->assertStringNotContainsString('jane-recent@example.com', $userContent);
    }
}
