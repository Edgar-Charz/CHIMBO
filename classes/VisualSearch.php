<?php

/**
 * Visual search: finds shop products matching a photo taken by the camera or chosen from the gallery.
 *
 * How it works:
 * 1. Checks and extracts the photo (file upload or base64 string).
 * 2. Uses Gemini Flash (when GEMINI_API_KEY is configured in .env) to identify
 *    the brand, product type, and concise search terms from the image.
 * 3. Searches the CHIMBO catalog for matching products.
 *
 * How to use it:
 *   $visual_search = new VisualSearch(Database::instance());
 *   $result = $visual_search->search($request);
 *   // -> ['detected' => ['brand' => '...', 'product_name' => '...', 'keywords' => [...]], 'items' => [...], 'total' => 12]
 */
class VisualSearch
{
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024; // 8 MB
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=%s';
    private const GEMINI_TIMEOUT_SECONDS = 16;

    private ?Product $product_model = null;

    public function __construct(
        private Database $db,
        private ?string $api_key = null,
        ?Product $product_model = null,
        private ?Closure $gemini_request = null
    ) {
        $this->api_key = $api_key ?? (string) Env::get('GEMINI_API_KEY', '');
        $this->product_model = $product_model ?? new Product($this->db);
    }

    /**
     * Executes visual search from an incoming Request.
     */
    public function search(Request $request): array
    {
        $hint = trim((string) ($request->input('hint') ?? $request->query('q', '')));
        [$image_bytes, $mime_type] = $this->extractImage($request);

        $ai_result = $this->analyzeWithAi($image_bytes, $mime_type, $hint);
        $search_queries = $this->buildSearchQueries($ai_result, $hint);

        $products = $this->findProducts($search_queries, $request->allQuery());

        return [
            'detected' => [
                'brand'        => $ai_result['brand'] ?: null,
                'product_name' => $ai_result['product_name'] ?: '',
                'visible_words' => $ai_result['visible_words'],
                'search_query' => $search_queries[0] ?? $hint,
                'keywords'     => $ai_result['keywords'],
                'is_ai_active' => $ai_result['is_ai_active'],
            ],
            'items'    => $products,
            'total'    => count($products),
        ];
    }

    /**
     * Extracts and validates image bytes + mime type from file upload or base64 input.
     *
     * @return array{0: string, 1: string} [binary_bytes, mime_type]
     */
    private function extractImage(Request $request): array
    {
        // 1. Multipart file upload: $_FILES['image']
        $file = $request->file('image');
        if (is_array($file) && !empty($file['tmp_name'])) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw ApiException::validation(['image' => 'Picha haikupakiwa vizuri. Tafadhali jaribu tena.']);
            }
            if ($file['size'] > self::MAX_IMAGE_BYTES) {
                throw ApiException::validation(['image' => 'Picha ni kubwa mno. Chagua picha isiyozidi 8 MB.']);
            }

            $mime_type = mime_content_type($file['tmp_name']);
            if (!in_array($mime_type, self::ALLOWED_MIME_TYPES, true)) {
                throw ApiException::validation(['image' => 'Aina ya picha haikubaliki. Tumia JPG, PNG au WebP.']);
            }

            $bytes = file_get_contents($file['tmp_name']);
            if ($bytes === false || $bytes === '') {
                throw ApiException::validation(['image' => 'Faili la picha halisomeki.']);
            }

            return [$bytes, $mime_type];
        }

        // 2. Base64 encoded image string in request body: {"image": "..."}
        $raw_image = $request->input('image');
        if (is_string($raw_image) && trim($raw_image) !== '') {
            $raw_image = trim($raw_image);
            $mime_type = 'image/jpeg';

            // Check for Data URL prefix (data:image/jpeg;base64,...)
            if (preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#i', $raw_image, $matches)) {
                $mime_type = strtolower($matches[1]);
                $raw_image = $matches[2];
            }

            $bytes = base64_decode($raw_image, true);
            if ($bytes === false || $bytes === '') {
                throw ApiException::validation(['image' => 'Data ya picha ya base64 si sahihi.']);
            }
            if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
                throw ApiException::validation(['image' => 'Picha ni kubwa mno. Chagua picha isiyozidi 8 MB.']);
            }

            // Verify real MIME type from bytes
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detected_mime = finfo_buffer($finfo, $bytes);
                finfo_close($finfo);
                if ($detected_mime) {
                    $mime_type = $detected_mime;
                }
            }

            if (!in_array($mime_type, self::ALLOWED_MIME_TYPES, true)) {
                throw ApiException::validation(['image' => 'Aina ya picha haikubaliki. Tumia JPG, PNG au WebP.']);
            }

            return [$bytes, $mime_type];
        }

        throw ApiException::validation(['image' => 'Tafadhali weka au piga picha ya bidhaa unayotafuta.']);
    }

    /**
     * Identifies the product from the image using Gemini Flash API, or falls back gracefully.
     *
     * @return array{brand: string, product_name: string, visible_words: string[], keywords: string[], is_ai_active: bool}
     */
    private function analyzeWithAi(string $image_bytes, string $mime_type, string $hint): array
    {
        if ($this->api_key === '') {
            if ($hint === '') {
                throw new ApiException(
                    503,
                    'VISUAL_SEARCH_UNAVAILABLE',
                    'Utafutaji wa picha haupatikani kwa sasa. Tafadhali jaribu tena baadaye.'
                );
            }

            $fallback_words = array_values(array_filter(preg_split('/\s+/', $hint) ?: []));
            return [
                'brand'        => '',
                'product_name' => $hint,
                'visible_words' => [],
                'keywords'     => $fallback_words,
                'is_ai_active' => false,
            ];
        }

        $base64_data = base64_encode($image_bytes);
        $prompt = 'You are a product search assistant for CHIMBO, a Tanzanian B2B wholesale marketplace for cosmetics, personal care, beauty products, perfumes, and jewelry. Examine this product photo. Read the printed words on its packaging carefully. Identify:
1. "brand": Brand name visibly printed on the packaging, or null.
2. "product_name": Concise product name/type from the packaging, max 5 words.
3. "visible_words": Up to 12 clearly readable words printed on the packaging, preserving spelling. Include brand/product words and meaningful label text; do not invent obscured text.
4. "keywords": 2 to 4 useful catalog search terms based on the image.
The visible_words are important: CHIMBO will search the catalog separately for each word, and a single word match can return a product.
Respond with JSON only: {"brand": string|null, "product_name": string, "visible_words": string[], "keywords": string[]}';

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mime_type,
                                'data'      => $base64_data,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'temperature'        => 0.2,
            ],
        ];

        try {
            $response = $this->gemini_request !== null
                ? ($this->gemini_request)($payload)
                : $this->callGeminiApi($payload);
            $parsed = json_decode($response, true);
            if (!is_array($parsed)) {
                throw new RuntimeException('Gemini returned invalid JSON for visual search.');
            }

            $brand = trim((string) ($parsed['brand'] ?? ''));
            $product_name = trim((string) ($parsed['product_name'] ?? ''));
            $keywords = array_values(array_filter(array_map('trim', (array) ($parsed['keywords'] ?? []))));
            $visible_words = array_values(array_filter(array_map('trim', (array) ($parsed['visible_words'] ?? []))));

            return [
                'brand'         => $brand,
                'product_name'  => $product_name,
                'visible_words' => $visible_words,
                'keywords'      => $keywords,
                'is_ai_active' => true,
            ];
        } catch (RuntimeException $e) {
            Logger::warning('Visual search AI analysis failed: ' . $e->getMessage());
            throw new ApiException(
                503,
                'VISUAL_SEARCH_UNAVAILABLE',
                'Utafutaji wa picha haupatikani kwa sasa. Tafadhali jaribu tena baadaye.'
            );
        }
    }

    /**
     * HTTP call to Gemini Flash API.
     */
    private function callGeminiApi(array $payload): string
    {
        $url = sprintf(self::GEMINI_API_URL, rawurlencode($this->api_key));
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => self::GEMINI_TIMEOUT_SECONDS,
        ]);

        $raw_response = curl_exec($handle);
        $http_status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($raw_response === false) {
            $error_number = curl_errno($handle);
            $elapsed_seconds = (int) round(curl_getinfo($handle, CURLINFO_TOTAL_TIME));
            curl_close($handle);
            $reason = $error_number === CURLE_OPERATION_TIMEDOUT
                ? "operation timed out after {$elapsed_seconds} seconds"
                : "cURL error {$error_number}";
            throw new RuntimeException('Gemini network request failed: ' . $reason . '.');
        }
        curl_close($handle);

        if ($http_status < 200 || $http_status >= 300) {
            $error_response = json_decode($raw_response, true);
            $api_error = is_array($error_response) ? ($error_response['error'] ?? null) : null;
            $api_error_status = is_array($api_error) ? ($api_error['status'] ?? '') : '';
            if (!is_string($api_error_status) || !preg_match('/^[A-Z_]+$/', $api_error_status)) {
                $api_error_status = '';
            }
            $status_detail = $api_error_status !== '' ? " ({$api_error_status})" : '';
            throw new RuntimeException("Gemini API request failed with HTTP {$http_status}{$status_detail}.");
        }

        $decoded = json_decode($raw_response, true);
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            throw new RuntimeException('Gemini returned an empty response.');
        }

        return $text;
    }

    /**
     * Builds ranked candidate queries from the AI analysis and user hint.
     */
    private function buildSearchQueries(array $ai_result, string $hint): array
    {
        $queries = [];
        $brand = trim($ai_result['brand']);
        $name = trim($ai_result['product_name']);

        if ($brand !== '' && $name !== '') {
            $queries[] = "{$brand} {$name}";
        }
        if ($name !== '') {
            $queries[] = $name;
        }
        if ($brand !== '') {
            $queries[] = $brand;
        }

        // Search exact label phrases first, then individual words so one catalog match is enough.
        $phrases = array_merge(
            (array) ($ai_result['visible_words'] ?? []),
            (array) ($ai_result['keywords'] ?? []),
            $hint !== '' ? [$hint] : []
        );
        foreach ($phrases as $phrase) {
            $phrase = trim((string) $phrase);
            if ($phrase !== '') {
                $queries[] = $phrase;
                foreach (preg_split('/[^\p{L}\p{N}]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    if (strlen($word) >= 3 || preg_match('/\d/', $word)) {
                        $queries[] = $word;
                    }
                }
            }
        }

        return array_slice(array_values(array_unique(array_filter($queries))), 0, 30);
    }

    /**
     * Searches catalog products using ranked queries until matches are found.
     */
    private function findProducts(array $queries, array $extra_filters): array
    {
        if ($queries === []) {
            return [];
        }

        $matched_products = [];
        $seen_ids = [];

        // Try the top queries in order of precision
        foreach ($queries as $query) {
            $filters = array_merge($extra_filters, ['q' => $query, 'page' => 1, 'per_page' => 20]);
            try {
                $result = $this->product_model->getProducts($filters);
                foreach ($result['items'] as $product) {
                    $product_id = (int) $product['product_id'];
                    if (!isset($seen_ids[$product_id])) {
                        $seen_ids[$product_id] = true;
                        $matched_products[] = $product;
                    }
                }
            } catch (Throwable) {
                // Continue to next query if query fails
            }

            // Keep searching recognized label words, but cap the response size.
            if (count($matched_products) >= 20) {
                break;
            }
        }

        return $matched_products;
    }
}
