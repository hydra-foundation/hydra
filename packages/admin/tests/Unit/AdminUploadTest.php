<?php

declare(strict_types=1);

namespace Hydra\Admin\Tests\Unit;

use Hydra\Admin\AdminController;
use Hydra\Admin\Events\RowUpdated;
use Hydra\Admin\Tests\Support\AdminHarness;
use Hydra\Admin\Tests\Support\AvatarUsersModule;
use Hydra\Admin\Tests\Support\CrudUserSource;
use Hydra\Admin\Tests\Support\PublicCoverModule;
use Hydra\Admin\Tests\Support\TemporaryDisks;
use Hydra\Admin\Tests\Support\ToggledUsersModule;
use Hydra\Admin\Uploads;
use Hydra\Filesystem\Disks;
use LogicException;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * A file control end to end: what reaches the source, what is left on the
 * disk, and what is left there when something goes wrong partway.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(Uploads::class)]
final class AdminUploadTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private TemporaryDisks $disks;
    private CrudUserSource $source;
    private AdminHarness $admin;

    protected function setUp(): void
    {
        $this->disks = new TemporaryDisks;
        $this->source = new CrudUserSource;
        $this->admin = new AdminHarness(
            [AvatarUsersModule::class => new AvatarUsersModule, CrudUserSource::class => $this->source],
            [AvatarUsersModule::class],
            uploads: new Uploads($this->disks->disks),
        );
    }

    protected function tearDown(): void
    {
        $this->disks->remove();
    }

    public function test_an_upload_is_stored_and_the_source_gets_its_key(): void
    {
        $response = $this->update('2', ['username' => 'grace'], ['avatar' => $this->png()]);

        // Saved, so the list comes back by redirect rather than a re-rendered form.
        $this->assertSame(302, $response->getStatusCode());
        $key = (string) ($this->source->find('2')['avatar'] ?? '');
        $this->assertMatchesRegularExpression('#^private:avatars/[0-9a-f]{32}\.png$#', $key);
        $this->assertSame(1, $this->disks->count());
        $this->assertTrue($this->stored($key));
    }

    public function test_a_create_stores_the_upload_too(): void
    {
        $request = $this->admin->request('POST', '/admin/users/new', [], ['username' => 'linus'])
            ->withUploadedFiles(['avatar' => $this->png()]);

        $this->admin->controller->store($request);

        $row = $this->source->find((string) ($this->source->nextId - 1));
        $this->assertStringStartsWith('private:avatars/', (string) ($row['avatar'] ?? ''));
    }

    public function test_leaving_the_file_input_empty_keeps_the_stored_file(): void
    {
        $key = $this->seed('2');

        $this->update('2', ['username' => 'grace'], ['avatar' => $this->nothing()]);

        $this->assertSame($key, $this->source->find('2')['avatar'] ?? null);
        $this->assertTrue($this->stored($key));
    }

    public function test_a_request_with_no_file_part_at_all_keeps_it_too(): void
    {
        $key = $this->seed('2');

        $this->update('2', ['username' => 'grace']);

        $this->assertSame($key, $this->source->find('2')['avatar'] ?? null);
    }

    public function test_replacing_a_file_deletes_the_old_one(): void
    {
        $old = $this->seed('2');

        $this->update('2', ['username' => 'grace'], ['avatar' => $this->png()]);

        $new = (string) ($this->source->find('2')['avatar'] ?? '');
        $this->assertNotSame($old, $new);
        $this->assertFalse($this->stored($old));
        $this->assertTrue($this->stored($new));
        $this->assertSame(1, $this->disks->count());
    }

    public function test_ticking_remove_clears_the_column_and_deletes_the_file(): void
    {
        $old = $this->seed('2');

        $this->update('2', ['username' => 'grace', 'avatar_remove' => '1'], ['avatar' => $this->nothing()]);

        $row = $this->source->find('2') ?? [];
        $this->assertArrayHasKey('avatar', $row);
        $this->assertNull($row['avatar']);
        $this->assertFalse($this->stored($old));
        $this->assertSame(0, $this->disks->count());
    }

    public function test_a_new_upload_wins_over_a_ticked_remove_box(): void
    {
        $old = $this->seed('2');

        $this->update('2', ['username' => 'grace', 'avatar_remove' => '1'], ['avatar' => $this->png()]);

        $this->assertStringStartsWith('private:avatars/', (string) ($this->source->find('2')['avatar'] ?? ''));
        $this->assertFalse($this->stored($old));
    }

    public function test_the_wrong_type_is_refused_and_nothing_is_stored(): void
    {
        $script = new UploadedFile(Stream::create("<?php echo 1;"), 13, UPLOAD_ERR_OK, 'a.png', 'image/png');

        $response = $this->update('2', ['username' => 'grace'], ['avatar' => $script]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('The file must be a PNG or JPEG image.', (string) $response->getBody());
        $this->assertSame(0, $this->disks->count());
        $this->assertArrayNotHasKey('avatar', $this->source->find('2') ?? []);
    }

    public function test_a_file_over_the_limit_is_refused(): void
    {
        $big = new UploadedFile(Stream::create(str_repeat('x', 2048)), 2048, UPLOAD_ERR_OK, 'a.png', 'image/png');

        $response = $this->update('2', ['username' => 'grace'], ['avatar' => $big]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, $this->disks->count());
    }

    public function test_a_failed_upload_says_why(): void
    {
        $partial = new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_PARTIAL, 'a.png', 'image/png');

        $response = $this->update('2', ['username' => 'grace'], ['avatar' => $partial]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('The upload was interrupted. Try again.', (string) $response->getBody());
    }

    public function test_a_form_refused_for_another_field_keeps_offering_the_stored_file(): void
    {
        $this->seed('2');

        $response = $this->update('2', ['username' => ''], ['avatar' => $this->nothing()]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('name="avatar_remove"', (string) $response->getBody());
    }

    public function test_a_write_the_source_rejects_takes_its_new_file_with_it(): void
    {
        $old = $this->seed('2');

        $response = $this->update('2', ['username' => 'taken'], ['avatar' => $this->png()]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame($old, $this->source->find('2')['avatar'] ?? null);
        $this->assertTrue($this->stored($old));
        $this->assertSame(1, $this->disks->count());
    }

    public function test_a_create_the_source_rejects_leaves_nothing_behind(): void
    {
        $request = $this->admin->request('POST', '/admin/users/new', [], ['username' => 'taken'])
            ->withUploadedFiles(['avatar' => $this->png()]);

        $this->assertSame(422, $this->admin->controller->store($request)->getStatusCode());
        $this->assertSame(0, $this->disks->count());
    }

    public function test_the_update_event_carries_the_key_not_the_upload(): void
    {
        $this->update('2', ['username' => 'grace'], ['avatar' => $this->png()]);

        $events = array_values(array_filter(
            $this->admin->events->dispatched(),
            static fn (object $event): bool => $event instanceof RowUpdated,
        ));

        $this->assertCount(1, $events);
        $this->assertIsString($events[0]->values['avatar']);
    }

    public function test_a_public_file_is_stored_on_the_public_disk(): void
    {
        $disks = new TemporaryDisks;
        $source = new CrudUserSource;
        $admin = new AdminHarness(
            [PublicCoverModule::class => new PublicCoverModule, CrudUserSource::class => $source],
            [PublicCoverModule::class],
            uploads: new Uploads($disks->disks),
        );

        $admin->controller->update(
            $admin->request('POST', '/admin/users/2/edit', [], ['username' => 'grace'])
                ->withUploadedFiles(['cover' => $this->png()]),
        );

        $key = (string) ($source->find('2')['cover'] ?? '');
        $this->assertStringStartsWith('public:blog/', $key);
        [$disk, $path] = $disks->disks->locate($key);
        $this->assertTrue($disk->exists($path));
        $disks->remove();
    }

    public function test_a_file_control_with_nowhere_to_store_it_is_a_wiring_mistake(): void
    {
        $admin = new AdminHarness(
            [AvatarUsersModule::class => new AvatarUsersModule, CrudUserSource::class => new CrudUserSource],
            [AvatarUsersModule::class],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FilesystemServiceProvider');

        $admin->controller->update(
            $admin->request('POST', '/admin/users/2/edit', [], ['username' => 'grace'])
                ->withUploadedFiles(['avatar' => $this->png()]),
        );
    }

    public function test_a_module_without_file_controls_needs_no_disks(): void
    {
        $source = new CrudUserSource;
        $admin = new AdminHarness(
            [ToggledUsersModule::class => new ToggledUsersModule, CrudUserSource::class => $source],
            [ToggledUsersModule::class],
        );

        $admin->controller->update($admin->request('POST', '/admin/users/2/edit', [], ['username' => 'gracie']));

        $this->assertSame('gracie', $source->find('2')['username'] ?? null);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, UploadedFile> $files
     */
    private function update(string $id, array $body, array $files = []): ResponseInterface
    {
        $request = $this->admin->request('POST', "/admin/users/{$id}/edit", [], $body)->withUploadedFiles($files);

        return $this->admin->controller->update($request);
    }

    /** A stored avatar on row $id, put there the way an earlier save would have. */
    private function seed(string $id): string
    {
        $key = $this->disks->disks->qualify(
            Disks::PRIVATE,
            $this->disks->disks->private()->put('avatars', Stream::create(base64_decode(self::PNG))),
        );
        $this->source->update($id, ['avatar' => $key]);

        return $key;
    }

    private function stored(string $qualified): bool
    {
        [$disk, $key] = $this->disks->disks->locate($qualified);

        return $disk->exists($key);
    }

    private function png(): UploadedFile
    {
        $bytes = base64_decode(self::PNG);

        return new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, 'me.png', 'image/png');
    }

    /** What a browser sends for a file input nobody touched. */
    private function nothing(): UploadedFile
    {
        return new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_NO_FILE, '', '');
    }
}
