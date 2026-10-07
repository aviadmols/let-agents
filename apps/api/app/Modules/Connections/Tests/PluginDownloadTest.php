<?php

namespace App\Modules\Connections\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Connections\Support\PluginPackage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PluginDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'let-agents-plugin-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        config(['upsell.plugin.path' => $this->directory]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_the_newest_version_is_picked_by_version_not_by_name(): void
    {
        foreach (['let-agents-0.9.0.zip', 'let-agents-0.10.0.zip', 'let-agents-0.2.1.zip', 'other.zip'] as $name) {
            file_put_contents($this->directory.DIRECTORY_SEPARATOR.$name, 'PK');
        }

        $this->assertSame('0.10.0', PluginPackage::latest()?->version);
        $this->assertSame('let-agents-0.10.0.zip', PluginPackage::latest()?->filename);
    }

    public function test_an_operator_downloads_the_zip(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'let-agents-0.1.0.zip', 'PK-zip-bytes');

        $response = $this->actingAs(User::factory()->operator()->create())->get('/operator/plugin/download');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('let-agents-0.1.0.zip', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('PK-zip-bytes', $response->streamedContent() ?: file_get_contents($response->getFile()->getPathname()));
    }

    public function test_only_operators_can_download(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'let-agents-0.1.0.zip', 'PK');

        $this->get('/operator/plugin/download')->assertRedirect('/operator/login');
        $this->actingAs(User::factory()->create())->get('/operator/plugin/download')->assertForbidden();
    }

    public function test_a_missing_file_is_a_404_and_the_page_explains_it(): void
    {
        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)->get('/operator/plugin/download')->assertNotFound();
        $this->actingAs($operator)->get('/operator/store-plugin')->assertOk()->assertSee('connections:bundle-plugin');
    }

    public function test_the_page_shows_version_and_install_steps_in_both_languages(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'let-agents-0.1.0.zip', str_repeat('x', 2048));
        $operator = User::factory()->operator()->create();

        foreach (['he', 'en'] as $locale) {
            $this->actingAs($operator)
                ->withHeader('Accept-Language', $locale)
                ->get('/operator/store-plugin')
                ->assertOk()
                ->assertSee('let-agents-0.1.0.zip')
                ->assertSee(__('connections::plugin.download_version', ['version' => '0.1.0'], $locale))
                ->assertSee(__('connections::plugin.steps.token', [], $locale))
                ->assertSee(route('connections.plugin.download'), false);
        }
    }
}
