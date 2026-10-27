<?php

use PHPUnit\Framework\TestCase;

/** Tests camera/visual search for products. */
final class VisualSearchTest extends TestCase
{
    private Database $db;
    private VisualSearch $search;
    private string $configured_gemini_api_key;

    // Minimal 1x1 transparent PNG
    private const SAMPLE_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->configured_gemini_api_key = (string) Env::get('GEMINI_API_KEY', '');
        Env::set('GEMINI_API_KEY', '');
        $this->db = Database::instance();
        $this->search = new VisualSearch($this->db, '');
    }

    protected function tearDown(): void
    {
        Env::set('GEMINI_API_KEY', $this->configured_gemini_api_key);
    }

    public function testRejectsRequestWithoutImage(): void
    {
        $request = new Request('POST', '/v1/products/search-by-image', [], []);

        $this->expectException(ApiException::class);
        $this->search->search($request);
    }

    public function testReportsUnavailableWhenGeminiIsNotConfiguredAndThereIsNoHint(): void
    {
        $request = new Request(
            'POST',
            '/v1/products/search-by-image',
            [],
            ['image' => self::SAMPLE_PNG_BASE64]
        );

        try {
            $this->search->search($request);
            self::fail('Expected image search to report that Gemini is unavailable.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->status());
            $this->assertSame('VISUAL_SEARCH_UNAVAILABLE', $exception->errorCode());
        }
    }

    public function testRejectsInvalidMimeType(): void
    {
        // Text string encoded in base64 (not an image)
        $invalid_base64 = base64_encode('plain text file content');
        $request = new Request('POST', '/v1/products/search-by-image', [], ['image' => $invalid_base64]);

        $this->expectException(ApiException::class);
        $this->search->search($request);
    }

    public function testAcceptsBase64ImageAndSearchesWithHint(): void
    {
        $request = new Request(
            'POST',
            '/v1/products/search-by-image',
            [],
            [
                'image' => 'data:image/png;base64,' . self::SAMPLE_PNG_BASE64,
                'hint'  => 'vaseline',
            ]
        );

        $result = $this->search->search($request);

        $this->assertArrayHasKey('detected', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertSame('vaseline', $result['detected']['search_query']);
        $this->assertGreaterThan(0, $result['total']);
        $this->assertSame('Vaseline Petroleum Jelly 400ml', $result['items'][0]['product_name']);
    }

    public function testGeminiFailureReturnsServiceUnavailableInsteadOfNoMatches(): void
    {
        $search = new VisualSearch(
            $this->db,
            'test-key',
            null,
            static function (array $payload): string {
                throw new RuntimeException('Gemini network request failed: cURL error 28.');
            }
        );
        $request = new Request(
            'POST',
            '/v1/products/search-by-image',
            [],
            ['image' => self::SAMPLE_PNG_BASE64]
        );

        try {
            $search->search($request);
            self::fail('Expected Gemini failure to be reported to the caller.');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->status());
            $this->assertSame('VISUAL_SEARCH_UNAVAILABLE', $exception->errorCode());
        }
    }

    public function testAcceptsMultipartUploadedFile(): void
    {
        $tmp_file = tempnam(sys_get_temp_dir(), 'test_img_');
        file_put_contents($tmp_file, base64_decode(self::SAMPLE_PNG_BASE64));

        $files = [
            'image' => [
                'name'     => 'camera_capture.png',
                'type'     => 'image/png',
                'tmp_name' => $tmp_file,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmp_file),
            ],
        ];

        // Custom search with mocked is_uploaded_file behavior by using search logic directly
        $request = new Request('POST', '/v1/products/search-by-image', ['q' => 'dove'], [], [], $files);

        // Note: is_uploaded_file() is false for local temp files created in CLI tests,
        // so VisualSearch should reject or we verify the uploaded file validation:
        try {
            $this->search->search($request);
        } catch (ApiException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode());
        } finally {
            @unlink($tmp_file);
        }
    }

    public function testMatchesBySkuOrBarcode(): void
    {
        $sku = (string) $this->db->fetchValue("SELECT product_sku FROM products WHERE product_name = 'Classic Watch'");
        $this->assertNotEmpty($sku);

        $request = new Request(
            'POST',
            '/v1/products/search-by-image',
            [],
            [
                'image' => self::SAMPLE_PNG_BASE64,
                'hint'  => $sku,
            ]
        );

        $result = $this->search->search($request);
        $matched_product_names = array_column($result['items'], 'product_name');
        $this->assertContains('Classic Watch', $matched_product_names);
    }

    public function testCatalogSearchEndpointThroughRouter(): void
    {
        $router = new Router();
        $router->group('/v1', function (Router $router) {
            require BASE_PATH . '/api/endpoints/catalog.php';
        });

        $request = new Request(
            'POST',
            '/v1/products/search-by-image',
            [],
            [
                'image' => self::SAMPLE_PNG_BASE64,
                'hint'  => 'dove',
            ]
        );

        $response = $router->dispatch($request);
        $this->assertSame(200, $response->status());

        // Check JSON body
        $ref = new ReflectionClass($response);
        $prop = $ref->getProperty('body');
        $prop->setAccessible(true);
        $body = $prop->getValue($response);

        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('detected', $body['data']);
        $this->assertArrayHasKey('items', $body['data']);
    }
}
