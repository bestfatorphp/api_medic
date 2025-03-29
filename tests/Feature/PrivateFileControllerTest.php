<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Команда: php artisan test --filter PrivateFileControllerTest
 */
//todo: дописать тесты, когда будем доделывать апи
class PrivateFileControllerTest extends TestCase
{
    private ?string $accessToken;
    protected function setUp(): void
    {
        parent::setUp();
        //настраиваем фейковое хранилище
        Storage::fake('private');
        $this->accessToken = env('PRIVATE_DISK_API_TOKEN');
    }

    /** @test */
    public function it_requires_authentication()
    {
        $response = $this->get('/api/private/protected-file.txt');

        $response->assertStatus(403);
    }

    /** @test */
    public function it_throws_exception_for_missing_file()
    {
        $fileName = 'non-existent-file.txt';

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken
        ])->get("/api/private/{$fileName}");

        $response->assertStatus(404);
        $response->assertJson([
            'message' => "Файл не найден: {$fileName}"
        ]);
    }
}
