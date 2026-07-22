<?php

declare(strict_types=1);

namespace Stillat\Meerkat\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use LogicException;
use ReflectionClass;
use ReflectionException;
use Statamic\Facades\Antlers;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Path;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Stillat\Meerkat\Configuration\Settings;
use Stillat\Meerkat\Contracts\CommentRepository;
use Stillat\Meerkat\Database\Models\Comment;
use Stillat\Meerkat\Database\Models\Thread;
use Stillat\Meerkat\ServiceProvider;
use Stillat\Meerkat\Support\ContextSigner;
use Stillat\Meerkat\Testing\Factories\CommentFactory;
use Symfony\Component\HttpFoundation\Response;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk, RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected string $fakeStacheDirectory;

    /**
     * @var array<string>
     */
    protected array $connectionsToTransact = ['sqlite', 'meerkat'];

    private int $errorHandlerDepth = 0;

    private int $exceptionHandlerDepth = 0;

    private static int $userCounter = 0;

    /**
     * @var array<string>
     */
    private array $temporaryPaths = [];

    protected function addonPath(string $path = ''): string
    {
        $root = dirname(__DIR__);

        return $path === ''
            ? $root
            : $root.DIRECTORY_SEPARATOR.ltrim($path, '\\/');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Settings::flush();
        Blink::flush();
        CommentFactory::resetCounter();

        if (($token = getenv('TEST_TOKEN')) !== false && $token !== '') {
            $oldRoot = rtrim($this->fakeStacheDirectory, '/\\');
            $newRoot = $oldRoot.'-'.$token;

            Stache::stores()->each(function ($store) use ($oldRoot, $newRoot) {
                $dir = $store->directory();
                if (str_contains($dir, $oldRoot)) {
                    $store->directory(str_replace($oldRoot, $newRoot, $dir));
                }
            });

            $this->fakeStacheDirectory = $newRoot;

            app('files')->ensureDirectoryExists($newRoot);
        }

        $this->errorHandlerDepth = $this->errorHandlerStackDepth();
        $this->exceptionHandlerDepth = $this->exceptionHandlerStackDepth();
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());

        $this->runLaravelMigrations();
        $this->runMeerkatMigrations();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        $files = app('files');
        foreach (array_reverse($this->temporaryPaths) as $path) {
            $files->isDirectory($path)
                ? $files->deleteDirectory($path)
                : $files->delete($path);
        }
        $this->temporaryPaths = [];

        while ($this->errorHandlerStackDepth() > $this->errorHandlerDepth) {
            restore_error_handler();
        }

        while ($this->exceptionHandlerStackDepth() > $this->exceptionHandlerDepth) {
            restore_exception_handler();
        }

        parent::tearDown();
    }

    protected function deleteFakeStacheDirectory(): void
    {
        if (! isset($this->fakeStacheDirectory) || $this->fakeStacheDirectory === '') {
            return;
        }

        $files = app('files');
        $files->deleteDirectory($this->fakeStacheDirectory);
        $files->ensureDirectoryExists($this->fakeStacheDirectory);

        $marker = $this->fakeStacheDirectory.'/.gitkeep';

        if (! $files->exists($marker)) {
            $files->put($marker, '');
        }
    }

    private function errorHandlerStackDepth(): int
    {
        $stack = [];
        while (true) {
            $previous = set_error_handler(static fn () => false);
            if ($previous === null) {
                restore_error_handler();
                break;
            }
            restore_error_handler();
            restore_error_handler();
            $stack[] = $previous;
        }
        foreach (array_reverse($stack) as $handler) {
            set_error_handler($handler);
        }

        return count($stack);
    }

    private function exceptionHandlerStackDepth(): int
    {
        $stack = [];
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();

            if ($previous === null) {
                break;
            }

            restore_exception_handler();
            $stack[] = $previous;
        }
        foreach (array_reverse($stack) as $handler) {
            set_exception_handler($handler);
        }

        return count($stack);
    }

    protected function runMeerkatMigrations(): void
    {
        $migrationPath = __DIR__.'/../migrations';
        $files = glob($migrationPath.'/*.php');

        if ($files === false) {
            throw new LogicException('Unable to enumerate Meerkat migrations.');
        }

        $originalConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('meerkat');

        try {
            foreach ($files as $file) {
                $migration = include $file;

                if (! $migration instanceof Migration) {
                    throw new LogicException("Migration [{$file}] did not return a migration instance.");
                }

                $up = [$migration, 'up'];

                if (! is_callable($up)) {
                    throw new LogicException("Migration [{$file}] does not define an up method.");
                }

                $up();
            }
        } finally {
            DB::setDefaultConnection($originalConnection);
        }
    }

    /**
     * @throws ReflectionException
     */
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('database.connections.meerkat', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'meerkat_',
        ]);

        $app['config']->set('meerkat.database.connection', 'meerkat');
        $app['config']->set('meerkat.mirror.enabled', false);
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.system.blueprints_path', $this->addonPath('resources/blueprints'));

        $app['config']->set('statamic.sites.sites', [
            'default' => [
                'name' => 'Default',
                'locale' => 'en_US',
                'url' => 'http://localhost/',
            ],
        ]);

        if (! class_exists($this->addonServiceProvider)) {
            throw new LogicException("Addon service provider [{$this->addonServiceProvider}] does not exist.");
        }

        $reflector = new ReflectionClass($this->addonServiceProvider);
        $providerFile = $reflector->getFileName();

        if ($providerFile === false) {
            throw new LogicException('Unable to locate the addon service provider.');
        }

        $fakeDir = Path::resolve(
            dirname($providerFile).'/../tests/__fixtures__/dev-null'
        );

        if (($token = getenv('TEST_TOKEN')) !== false && $token !== '') {
            $fakeDir .= '-'.$token;
        }

        $app['files']->ensureDirectoryExists($fakeDir);

        $app['config']->set('statamic.users.repositories.file.paths.roles', $fakeDir.'/roles.yaml');
        $app['config']->set('statamic.users.repositories.file.paths.groups', $fakeDir.'/groups.yaml');
    }

    /** @param array<string, mixed> $attributes */
    protected function createEntry(array $attributes = []): \Statamic\Entries\Entry
    {
        $collection = $this->makeStatamicCollection('blog');
        $collection->title('Blog');
        $collection->save();

        $entry = $this->makeStatamicEntry();

        $entry->collection('blog');
        $entry->slug($attributes['slug'] ?? 'test-entry');
        $entry->data(array_merge([
            'title' => 'Test Entry',
            'content' => 'This is a test entry.',
        ], $attributes));
        $entry->id($attributes['id'] ?? 'test-entry-id');

        $entry->save();

        return $entry;
    }

    protected function makeStatamicCollection(string $handle): \Statamic\Entries\Collection
    {
        return Collection::make($handle);
    }

    protected function createStatamicCollection(string $handle, string $title): \Statamic\Entries\Collection
    {
        $collection = $this->makeStatamicCollection($handle);
        $collection->title($title);
        $collection->save();

        return $collection;
    }

    protected function runMigrationUp(mixed $migration): void
    {
        $up = is_object($migration) ? [$migration, 'up'] : null;

        if (! is_callable($up)) {
            throw new LogicException('Expected a migration with an up method.');
        }

        $up();
    }

    protected function makeStatamicEntry(): \Statamic\Entries\Entry
    {
        $entry = Entry::make();

        if (! $entry instanceof \Statamic\Entries\Entry) {
            throw new LogicException('Statamic did not create an entry.');
        }

        return $entry;
    }

    protected function makeStatamicUser(): \Statamic\Auth\File\User|\Statamic\Auth\Eloquent\User
    {
        $user = User::make();

        if (! $user instanceof \Statamic\Auth\File\User && ! $user instanceof \Statamic\Auth\Eloquent\User) {
            throw new LogicException('Statamic did not create a user.');
        }

        return $user;
    }

    protected function makeStatamicRole(): \Statamic\Auth\Role
    {
        $role = Role::make();

        if (! $role instanceof \Statamic\Auth\Role) {
            throw new LogicException('Statamic did not create a role.');
        }

        return $role;
    }

    /** @param array<string, mixed> $attributes */
    protected function createComment(array $attributes = []): Comment
    {
        return CommentFactory::new()->create($attributes);
    }

    protected function createThread(string $threadId = 'test-entry-id', string $title = 'Test Entry'): Thread
    {
        return Thread::firstOrCreate(
            ['thread_id' => $threadId],
            ['cached_title' => $title]
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    protected function submitComment(array $data = []): TestResponse
    {
        $defaults = [
            '_meerkat_context' => 'test-entry-id',
            'comment' => 'This is a test comment.',
            'name' => 'Test Author',
            'email' => 'test@example.com',
        ];

        $merged = array_merge($defaults, $data);

        if (! array_key_exists('_meerkat_context_signature', $merged)
            && ! empty($merged['_meerkat_context'])) {
            $context = $merged['_meerkat_context'];

            if (! is_string($context) && ! is_int($context)) {
                throw new LogicException('Meerkat test contexts must be string or integer identifiers.');
            }

            $merged['_meerkat_context_signature'] = ContextSigner::sign(
                (string) $context
            );
        }

        return $this->post(route('meerkat.comment-create'), $merged);
    }

    protected function userWithPermissions(string ...$permissions): \Statamic\Contracts\Auth\User
    {
        self::$userCounter++;
        $counter = self::$userCounter;

        $role = $this->makeStatamicRole();

        $role->handle('perm-test-role-'.$counter);
        $role->title('Perm Test Role '.$counter);
        $role->permissions(array_values(array_unique(array_merge(['access cp'], $permissions))));
        $role->save();

        $user = $this->makeStatamicUser();

        $user->id('perm-test-user-'.$counter);
        $user->email('perm-user-'.$counter.'@example.com');
        $user->save();
        $user->assignRole($role);
        $user->saveQuietly();

        return $user;
    }

    protected function repo(): CommentRepository
    {
        return app(CommentRepository::class);
    }

    /**
     * @template TValue
     *
     * @param  TValue|null  $value
     * @return TValue
     */
    protected function requireValue(mixed $value): mixed
    {
        $this->assertNotNull($value);

        return $value;
    }

    /** @return array<string, mixed> */
    protected function requireObject(mixed $value): array
    {
        $this->assertIsArray($value);
        $object = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[$key] = $item;
            }
        }

        return $object;
    }

    /** @return list<array<string, mixed>> */
    protected function requireRows(mixed $value): array
    {
        $this->assertIsArray($value);
        $rows = [];

        foreach ($value as $row) {
            $rows[] = $this->requireObject($row);
        }

        return $rows;
    }

    /** @return list<mixed> */
    protected function requireList(mixed $value): array
    {
        $this->assertIsArray($value);

        return array_values($value);
    }

    /**
     * @param  iterable<mixed>  $values
     * @return list<int>
     */
    protected function requireIntegerList(iterable $values): array
    {
        $integers = [];

        foreach ($values as $value) {
            if (is_int($value)) {
                $integers[] = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $integers[] = (int) $value;
            } else {
                $this->fail('Expected every value to be an integer.');
            }
        }

        return $integers;
    }

    protected function requireString(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new LogicException('Expected a string-compatible value.');
        }

        return (string) $value;
    }

    /** @return \Illuminate\Support\Collection<int, Comment> */
    protected function emptyCommentCollection(): \Illuminate\Support\Collection
    {
        return collect([new Comment])->take(0);
    }

    /** @param array<string, mixed> $parameters */
    protected function pendingArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            throw new LogicException("Artisan command [{$command}] completed before test expectations could be registered.");
        }

        return $pending;
    }

    protected function fixtureRoot(): string
    {
        return $this->addonPath('tests/Fixtures/mirror/comments');
    }

    protected function statamicDate(Carbon $when): string
    {
        return $when->format('Y-m-d\TH:i:s.v\Z');
    }

    protected function resetStatamicHooks(): void
    {
        app()->instance('statamic.hooks', collect());
    }

    protected function parseAntlers(string $template): string
    {
        return (string) Antlers::parse($template, [], true);
    }

    protected function temporaryDirectory(string $prefix = 'meerkat-test-'): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(8));

        app('files')->ensureDirectoryExists($path);

        $this->temporaryPaths[] = $path;

        return $path;
    }

    protected function temporaryFilePath(string $prefix = 'meerkat-test-', string $suffix = ''): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(8)).$suffix;
        $this->temporaryPaths[] = $path;

        return $path;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function makeAdmin(string $id = 'admin', ?string $email = null, bool $actingAs = true, array $data = []): \Statamic\Contracts\Auth\User
    {
        $user = $this->makeStatamicUser();

        $user->id($id);
        $user->email($email ?? $id.'@example.com');

        if ($data !== []) {
            $user->data($data);
        }

        $user->makeSuper();
        $user->save();

        if ($actingAs) {
            $this->actingAs($user);
        }

        return $user;
    }
}
