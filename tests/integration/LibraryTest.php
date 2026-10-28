<?php

declare(strict_types=1);

namespace Local\MediaLibrary\Tests\integration;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\UploadedFile;
use Local\MediaLibrary\Asset;
use Local\MediaLibrary\Library;
use Local\MediaLibrary\PrivateStorage;
use Local\MediaLibrary\PruneCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class LibraryTest extends TestCase
{
    private array $createdIds = [];

    protected function request(string $method, string $path, array $options = []): \Psr\Http\Message\ServerRequestInterface
    {
        // The testing helper constructs a PSR request without populating query params from its URI.
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        return parent::request($method, $path, $options)->withQueryParams($query);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('flarum-tags.min_primary_tags', 0);
        $this->extend((new \Flarum\Extend\ThrottleApi())->remove('postTimeout'));
        $this->extension('local-media-library', 'flarum-markdown', 'flarum-tags', 'flarum-flags', 'flarum-approval');
        $this->prepareDatabase([
            'users' => [
                ['id' => 2, 'username' => 'owner', 'email' => 'owner@example.test', 'is_email_confirmed' => 1, 'joined_at' => '2026-01-01'],
                ['id' => 3, 'username' => 'reader', 'email' => 'reader@example.test', 'is_email_confirmed' => 1, 'joined_at' => '2026-01-01'],
            ],
            'group_permission' => [
                ['group_id' => 2, 'permission' => 'viewForum'],
                ['group_id' => 3, 'permission' => 'startDiscussion'],
                ['group_id' => 3, 'permission' => 'discussion.reply'],
                ['group_id' => 3, 'permission' => 'discussion.editPosts'],
                ['group_id' => 3, 'permission' => 'discussion.postWithoutApproval'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            foreach ($this->createdIds as $uuid) {
                $this->app()->getContainer()->make(PrivateStorage::class)->remove($uuid);
            }
        }
        parent::tearDown();
    }

    private function library(): Library
    {
        return $this->app()->getContainer()->make(Library::class);
    }

    private function file(string $bytes, string $name = 'sample.png'): UploadedFile
    {
        $stream = new Stream('php://temp', 'w+b');
        $stream->write($bytes);
        $stream->rewind();
        return new UploadedFile($stream, strlen($bytes), UPLOAD_ERR_OK, $name);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(8, 6);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    private function asset(array $tags = [], int $owner = 2): Asset
    {
        $asset = $this->library()->upload(User::findOrFail($owner), $this->file($this->png()), $tags);
        $this->createdIds[] = $asset->uuid;
        return $asset;
    }

    private function discussion(string $content, int $actor = 2)
    {
        return $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => $actor,
            'json' => ['data' => ['attributes' => ['title' => 'Attachment test', 'content' => $content]]],
        ]));
    }

    private function postId($response): int
    {
        $this->assertSame(201, $response->getStatusCode(), $this->errorSummary($response));
        $data = json_decode((string) $response->getBody(), true);
        return (int) $data['data']['relationships']['firstPost']['data']['id'];
    }

    private function read(Asset $asset, ?int $actor, ?int $post = null, bool $thumbnail = false)
    {
        $query = http_build_query(array_filter(['post' => $post, 'thumbnail' => $thumbnail ? 1 : null], fn ($value) => $value !== null));
        return $this->send($this->request('GET', '/api/media-library/files/'.$asset->uuid.($query ? '?'.$query : ''), [
            'authenticatedAs' => $actor,
        ]));
    }

    private function errorSummary($response): string
    {
        $body = json_decode((string) $response->getBody(), true);
        return strtok((string) ($body['errors'][0]['detail'] ?? ''), "\n") ?: '';
    }

    public function test_upload_endpoint_and_immutable_files_with_metadata_updates(): void
    {
        $request = $this->request('POST', '/api/media-library/assets', ['authenticatedAs' => 2])
            ->withUploadedFiles(['file' => $this->file($this->png())])
            ->withParsedBody(['tags' => '["public","emoji","猫"]']);
        $response = $this->send($request);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $json = json_decode((string) $response->getBody(), true);
        $this->createdIds[] = $json['id'];
        $asset = Asset::where('uuid', $json['id'])->firstOrFail();
        $this->assertSame(hash('sha256', $this->png()), $asset->sha256);
        $this->assertSame(['猫', 'public', 'emoji'], $json['tags']);
        $this->assertSame('image/webp', $this->read($asset, 2, null, true)->getHeaderLine('Content-Type'));
        $updated = $this->library()->update(User::find(2), $asset->uuid, [
            'version' => 1, 'name' => 'renamed.png', 'tags' => ['旅行'],
        ]);
        $this->assertSame('renamed.png', $updated->name);
        $this->assertFalse($updated->is_public);
        $this->assertSame($asset->sha256, hash('sha256', (string) $this->read($updated, 2)->getBody()));
        $this->assertSame($asset->size, (int) User::find(2)->local_media_bytes);
    }

    public function test_personal_id_cannot_be_read_or_reused_by_another_user(): void
    {
        $asset = $this->asset(['emoji']);
        $this->assertSame(404, $this->read($asset, 3)->getStatusCode());
        $this->assertSame(404, $this->read($asset, null)->getStatusCode());
        $before = $this->database()->table('discussions')->count();
        $response = $this->discussion('[memoji='.$asset->uuid.']', 3);
        $this->assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame($before, $this->database()->table('discussions')->count());
        $this->assertSame(0, $this->database()->table('local_media_post_refs')->count());
    }

    public function test_post_readers_can_view_personal_files_but_unrelated_posts_do_not_unlock_them(): void
    {
        $asset = $this->asset();
        $post = $this->postId($this->discussion('[media='.$asset->uuid.']'));
        $unrelated = $this->postId($this->discussion('No attachment here'));
        $this->assertSame(200, $this->read($asset, 3, $post)->getStatusCode());
        $this->assertSame(200, $this->read($asset, null, $post)->getStatusCode());
        $this->assertSame(404, $this->read($asset, 3, $unrelated)->getStatusCode());
        $this->database()->table('posts')->where('id', $post)->update(['hidden_at' => Carbon::now()]);
        $this->assertSame(404, $this->read($asset, 3, $post)->getStatusCode());
        $this->assertSame(404, $this->read($asset, null, $post, true)->getStatusCode());
    }

    public function test_private_discussion_permissions_also_protect_the_image(): void
    {
        $asset = $this->asset();
        $post = $this->postId($this->discussion('[media='.$asset->uuid.']'));
        $discussion = Post::find($post)->discussion_id;
        $this->database()->table('tags')->insert([
            'id' => 20, 'name' => 'Staff', 'slug' => 'staff', 'is_restricted' => true,
        ]);
        $this->database()->table('discussion_tag')->insert(['discussion_id' => $discussion, 'tag_id' => 20]);
        $this->assertSame(404, $this->read($asset, 3, $post)->getStatusCode());
        $this->assertSame(404, $this->read($asset, null, $post)->getStatusCode());
    }

    public function test_shared_emoji_is_listed_and_reusable_only_for_signed_in_users(): void
    {
        $shared = $this->asset(['public', 'emoji', '猫']);
        $this->asset(['emoji']);
        $this->asset(['public']);
        $response = $this->send($this->request('GET', '/api/media-library/assets?scope=shared&emoji=1&tag=猫', ['authenticatedAs' => 3]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$shared->uuid], array_column(json_decode((string) $response->getBody(), true)['items'], 'id'));
        $this->assertSame(401, $this->send($this->request('GET', '/api/media-library/assets?scope=shared'))->getStatusCode());
        $this->assertSame(200, $this->read($shared, 3)->getStatusCode());
        $this->assertSame(404, $this->read($shared, null)->getStatusCode());
        $post = $this->postId($this->discussion('[memoji='.$shared->uuid.']', 3));
        $this->assertSame(200, $this->read($shared, null, $post)->getStatusCode());
        $html = Post::find($post)->formatContent();
        $this->assertStringContainsString('MediaLibrary-emoji', $html);
        $this->assertStringContainsString('post='.$post, $html);
    }

    public function test_revocation_preserves_old_references_but_blocks_new_reuse(): void
    {
        $asset = $this->asset(['public', 'emoji']);
        $post = $this->postId($this->discussion('[memoji='.$asset->uuid.']', 3));
        $this->library()->update(User::find(2), $asset->uuid, ['version' => 1, 'tags' => []]);
        $this->assertSame(404, $this->read($asset, 3)->getStatusCode());
        $this->assertSame(200, $this->read($asset, 3, $post)->getStatusCode());
        $response = $this->send($this->request('PATCH', '/api/posts/'.$post, [
            'authenticatedAs' => 3,
            'json' => ['data' => ['attributes' => ['content' => '[memoji='.$asset->uuid.'] edited']]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(422, $this->discussion('[media='.$asset->uuid.']', 3)->getStatusCode());
    }

    public function test_archive_restore_and_removing_post_reference(): void
    {
        $asset = $this->asset();
        $post = $this->postId($this->discussion('[media='.$asset->uuid.']'));
        $archived = $this->library()->update(User::find(2), $asset->uuid, ['version' => 1], true);
        $this->assertSame('archived', $archived->status);
        $this->assertSame(200, $this->read($asset, 3, $post)->getStatusCode());
        $this->assertSame(422, $this->discussion('[media='.$asset->uuid.']')->getStatusCode());
        $restored = $this->library()->update(User::find(2), $asset->uuid, ['version' => 2, 'restore' => true]);
        $this->assertSame('active', $restored->status);
        $response = $this->send($this->request('PATCH', '/api/posts/'.$post, [
            'authenticatedAs' => 2, 'json' => ['data' => ['attributes' => ['content' => 'Reference removed']]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(0, $this->database()->table('local_media_post_refs')->where('post_id', $post)->count());
        $this->assertSame(404, $this->read($asset, 3, $post)->getStatusCode());
    }

    public function test_cursor_pagination_has_no_duplicates_and_filters_categories(): void
    {
        $this->asset(['猫']);
        $this->asset(['猫']);
        $this->asset(['旅行']);
        $first = $this->library()->page(User::find(2), ['tag' => '猫', 'limit' => 1]);
        $second = $this->library()->page(User::find(2), ['tag' => '猫', 'limit' => 1, 'before' => $first['next']]);
        $this->assertCount(1, $first['items']);
        $this->assertCount(1, $second['items']);
        $this->assertNotSame($first['items'][0]['id'], $second['items'][0]['id']);
        $this->assertNull($second['next']);
        $this->assertCount(0, $this->library()->page(User::find(3), [])['items']);
        $this->assertCount(1, $this->library()->page(User::find(2), ['q' => $first['items'][0]['id']])['items']);
    }

    public function test_unified_search_combines_tags_and_names_without_crossing_scope_or_page_boundaries(): void
    {
        $byTag = $this->asset(['cats']);
        $byName = $this->asset(['travel']);
        $byName->name = 'cats-trip.png';
        $byName->save();
        $both = $this->asset(['cats']);
        $both->name = 'cats-both.png';
        $both->save();
        $this->asset(['cats'], 3); // Another user's personal file must stay private.
        $shared = $this->asset(['cats', 'public'], 3);
        $archived = $this->asset(['cats']);
        $this->library()->update(User::find(2), $archived->uuid, ['version' => 1], true);

        $first = $this->library()->page(User::find(2), ['q' => ' cats ', 'limit' => 2]);
        $this->assertSame([$both->uuid, $byName->uuid], array_column($first['items'], 'id'));
        $second = $this->library()->page(User::find(2), ['q' => 'cats', 'limit' => 2, 'before' => $first['next']]);
        $this->assertSame([$byTag->uuid], array_column($second['items'], 'id'));
        $this->assertNull($second['next']);
        $this->assertSame([$shared->uuid], array_column($this->library()->page(User::find(2), ['scope' => 'shared', 'q' => 'cats'])['items'], 'id'));
        $this->assertSame([$archived->uuid], array_column($this->library()->page(User::find(2), ['scope' => 'archived', 'q' => 'cats'])['items'], 'id'));

        $path = '/api/media-library/images?q=cats&limit=2';
        $response = $this->send($this->request('GET', $path, ['authenticatedAs' => 2]));
        $this->assertSame(200, $response->getStatusCode());
        $first = json_decode((string) $response->getBody(), true);
        $this->assertSame([$shared->uuid, $both->uuid], array_column($first['items'], 'id'));
        $response = $this->send($this->request('GET', $path.'&before='.$first['next'], ['authenticatedAs' => 2]));
        $second = json_decode((string) $response->getBody(), true);
        $this->assertSame([$byName->uuid, $byTag->uuid], array_column($second['items'], 'id'));
        $this->assertNull($second['next']);
    }

    public function test_unified_search_accepts_flags_and_ids_but_escapes_name_wildcards(): void
    {
        $flagged = $this->asset(['public', 'emoji']);
        $literal = $this->asset();
        $literal->name = '100%_photo.png';
        $literal->save();
        $other = $this->asset();
        $other->name = '1000-photo.png';
        $other->save();
        foreach (['public', 'EMOJI', strtoupper($flagged->uuid)] as $search) {
            $this->assertSame([$flagged->uuid], array_column($this->library()->page(User::find(2), ['q' => $search])['items'], 'id'));
        }
        $this->assertSame([$literal->uuid], array_column($this->library()->page(User::find(2), ['q' => '100%_'])['items'], 'id'));
        $this->assertSame([], $this->library()->page(User::find(3), ['q' => $literal->uuid])['items']);
    }

    public function test_stale_versions_and_other_owners_cannot_overwrite_metadata(): void
    {
        $asset = $this->asset();
        $response = $this->send($this->request('PATCH', '/api/media-library/assets/'.$asset->uuid, [
            'authenticatedAs' => 3, 'json' => ['version' => 1, 'tags' => ['public']],
        ]));
        $this->assertSame(403, $response->getStatusCode());
        $this->library()->update(User::find(2), $asset->uuid, ['version' => 1, 'tags' => ['public']]);
        $this->expectException(ValidationException::class);
        $this->library()->update(User::find(2), $asset->uuid, ['version' => 1, 'name' => 'stale.png']);
    }

    public function test_invalid_types_non_image_emoji_and_quota_fail_without_accounting_leaks(): void
    {
        foreach ([
            [$this->file('<html><script>alert(1)</script></html>', 'fake.png'), []],
            [$this->file('plain text', 'note.txt'), ['emoji']],
        ] as [$file, $tags]) {
            try {
                $this->library()->upload(User::find(2), $file, $tags);
                $this->fail('Invalid upload accepted');
            } catch (ValidationException $expected) {
                $this->assertSame(0, (int) User::find(2)->local_media_count);
            }
        }
        $this->setting('local-media-library.maxFiles', 1);
        $this->app()->getContainer()->make(\Flarum\Settings\SettingsRepositoryInterface::class)->set('local-media-library.maxFiles', 1);
        $this->asset();
        $this->expectException(ValidationException::class);
        $this->asset();
    }

    public function test_identical_bytes_have_independent_owners_and_sharing_flags(): void
    {
        $personal = $this->asset();
        $shared = $this->asset(['public'], 3);
        $this->assertSame($personal->sha256, $shared->sha256);
        $this->assertNotSame($personal->uuid, $shared->uuid);
        $this->assertFalse($personal->is_public);
        $this->assertSame(404, $this->read($personal, 3)->getStatusCode());
    }

    public function test_prune_is_dry_by_default_keeps_references_and_releases_quota(): void
    {
        $unused = $this->asset();
        $used = $this->asset();
        $this->postId($this->discussion('[media='.$used->uuid.']'));
        foreach ([$unused, $used] as $asset) {
            $this->library()->update(User::find(2), $asset->uuid, ['version' => 1], true);
            $this->database()->table('local_media_assets')->where('id', $asset->id)->update(['archived_at' => Carbon::now()->subDays(8)]);
        }
        $command = new CommandTester($this->app()->getContainer()->make(PruneCommand::class));
        $command->execute([]);
        $this->assertNotNull(Asset::find($unused->id));
        $command->execute(['--apply' => true]);
        $this->assertNull(Asset::find($unused->id));
        $this->assertNotNull(Asset::find($used->id));
        $this->assertSame($used->size, (int) User::find(2)->local_media_bytes);
        $this->assertSame(1, (int) User::find(2)->local_media_count);
        $this->assertFileDoesNotExist($this->app()->getContainer()->make(PrivateStorage::class)->path($unused->uuid));
    }

    public function test_code_examples_do_not_create_references(): void
    {
        $asset = $this->asset();
        $content = "Example:\n\n".chr(96).chr(96).chr(96)."\n[media=".$asset->uuid."]\n".chr(96).chr(96).chr(96);
        $post = $this->postId($this->discussion($content));
        $this->assertSame(0, $this->database()->table('local_media_post_refs')->where('post_id', $post)->count());
    }

    public function test_listing_uses_a_constant_query_count_instead_of_one_query_per_file(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->asset(['猫']);
        }
        $actor = User::find(2);
        $this->database()->enableQueryLog();
        $this->database()->flushQueryLog();
        $page = $this->library()->page($actor, ['limit' => 6]);
        $queries = $this->database()->getQueryLog();
        $this->database()->disableQueryLog();
        $this->assertCount(6, $page['items']);
        $this->assertLessThanOrEqual(3, count($queries));
    }

    public function test_orphan_cleanup_keeps_registered_and_recent_files(): void
    {
        $registered = $this->asset();
        $storage = $this->app()->getContainer()->make(PrivateStorage::class);
        $orphan = bin2hex(random_bytes(16));
        $recent = bin2hex(random_bytes(16));
        foreach ([$orphan, $recent] as $uuid) {
            $this->createdIds[] = $uuid;
            mkdir(dirname($storage->path($uuid)), 0700, true);
            file_put_contents($storage->path($uuid).'.part', 'interrupted upload');
        }
        touch($storage->path($orphan).'.part', time() - 90000);
        $command = new CommandTester($this->app()->getContainer()->make(\Local\MediaLibrary\OrphansCommand::class));
        $command->execute([]);
        $this->assertFileExists($storage->path($orphan).'.part');
        $command->execute(['--apply' => true]);
        $this->assertFileDoesNotExist($storage->path($orphan).'.part');
        $this->assertFileExists($storage->path($recent).'.part');
        $this->assertFileExists($storage->path($registered->uuid));
    }

    public function test_purge_can_resume_after_files_were_already_removed(): void
    {
        $asset = $this->asset();
        $this->database()->table('local_media_assets')->where('id', $asset->id)->update(['status' => 'purging']);
        $this->app()->getContainer()->make(PrivateStorage::class)->remove($asset->uuid);
        $command = new CommandTester($this->app()->getContainer()->make(PruneCommand::class));
        $command->execute(['--apply' => true]);
        $this->assertNull(Asset::find($asset->id));
        $this->assertSame(0, (int) User::find(2)->local_media_bytes);
    }

    public function test_copying_a_preview_url_cannot_bypass_reuse_permission(): void
    {
        $asset = $this->asset();
        $post = $this->postId($this->discussion('[media='.$asset->uuid.']'));
        $url = $this->library()->serialize($asset, User::find(2))['url'].'?post='.$post;
        $this->assertSame(422, $this->discussion('![copied]('.$url.')', 3)->getStatusCode());
        $indirect = str_replace('/media-library/', '/example/../media-library/', $url);
        $this->assertSame(422, $this->discussion('![copied]('.$indirect.')', 3)->getStatusCode());
    }

    public function test_image_picker_filters_permissions_file_types_and_tags_with_a_shared_cursor(): void
    {
        $own = $this->asset(['猫']);
        $shared = $this->asset(['猫', 'public'], 3);
        $private = $this->asset(['猫'], 3);
        $this->asset(['旅行', 'public'], 3);
        $document = $this->library()->upload(User::find(2), $this->file('not an image', 'note.txt'), ['猫']);
        $this->createdIds[] = $document->uuid;
        $path = '/api/media-library/images?tag='.rawurlencode('猫').'&limit=1';
        $firstResponse = $this->send($this->request('GET', $path, ['authenticatedAs' => 2]));
        $this->assertSame(200, $firstResponse->getStatusCode());
        $first = json_decode((string) $firstResponse->getBody(), true);
        $this->assertSame([$shared->uuid], array_column($first['items'], 'id'));
        $this->assertArrayNotHasKey('canManage', $first['items'][0]);
        $this->assertArrayNotHasKey('referenceCount', $first['items'][0]);
        $secondResponse = $this->send($this->request('GET', $path.'&before='.$first['next'], ['authenticatedAs' => 2]));
        $second = json_decode((string) $secondResponse->getBody(), true);
        $this->assertSame([$own->uuid], array_column($second['items'], 'id'));
        $this->assertNull($second['next']);
        $this->assertSame(404, $this->send($this->request('GET', '/api/media-library/images/'.$private->uuid, ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(404, $this->send($this->request('GET', '/api/media-library/images/'.$document->uuid, ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(200, $this->send($this->request('GET', '/api/media-library/images/'.$own->uuid, ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(401, $this->send($this->request('GET', '/api/media-library/images'))->getStatusCode());
        $this->library()->update(User::find(3), $shared->uuid, ['version' => 1], true);
        $this->assertSame(404, $this->send($this->request('GET', '/api/media-library/images/'.$shared->uuid, ['authenticatedAs' => 2]))->getStatusCode());
    }

    public function test_external_image_url_markup_remains_supported(): void
    {
        $post = $this->postId($this->discussion('![](<https://example.test/image(1).png?size=2&x=3>)'));
        $html = Post::find($post)->formatContent();
        $this->assertStringContainsString('<img', $html);
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NONET);
        $this->assertSame(
            'https://example.test/image(1).png?size=2&x=3',
            rawurldecode($document->getElementsByTagName('img')->item(0)->getAttribute('src'))
        );
    }
}
