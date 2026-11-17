<?php

namespace App\Services\AI;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiEmbeddingClient implements EmbeddingClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const CHUNK_SIZE = 100;

    public function __construct(
        private ?string $apiKey = null,
        private ?string $model = null,
    ) {
        $this->apiKey = $apiKey ?: (string) config('shop.gemini_api_key');
        $this->model = $model ?: (string) config('shop.ai_embed_model', 'text-embedding-004');
    }

    /**
     * Generate an embedding vector for a single text.
     *
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $batch = $this->embedBatch([$text]);

        return $batch[0] ?? [];
    }

    /**
     * Generate embedding vectors for multiple texts.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        if (empty($this->apiKey)) {
            throw new RuntimeException('Gemini API key is not configured.');
        }

        $allEmbeddings = [];
        $chunks = array_chunk($texts, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $batchResults = $this->requestChunk($chunk);
            foreach ($batchResults as $embedding) {
                $allEmbeddings[] = $embedding;
            }
        }

        return $allEmbeddings;
    }

    /**
     * Send a single batch request chunk to Gemini.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    private function requestChunk(array $texts): array
    {
        $modelName = 'models/'.$this->model;

        $requests = array_map(fn ($text) => [
            'model' => $modelName,
            'content' => [
                'parts' => [
                    ['text' => $text],
                ],
            ],
            'outputDimensionality' => self::DIMENSIONS,
        ], $texts);

        $url = self::BASE_URL.'/'.$modelName.':batchEmbedContents?key='.$this->apiKey;

        /** @var Response $response */
        $response = Http::timeout(10)
            ->retry(
                times: 3,
                sleepMilliseconds: 1000,
                when: fn ($exception, $request) => $exception instanceof ConnectionException
                    || ($response = $exception->response ?? null) && in_array($response->status(), [429, 500, 502, 503, 504]),
                throw: false,
            )
            ->post($url, [
                'requests' => $requests,
            ]);

        if (! $response->successful()) {
            $status = $response->status();
            // Never log the API key or raw book text
            Log::warning('Gemini embedding request failed', [
                'status' => $status,
                'count' => count($texts),
                'model' => $this->model,
            ]);

            throw new RuntimeException("Gemini embedding API returned HTTP {$status}.");
        }

        $data = $response->json();
        $embeddings = $data['embeddings'] ?? [];

        return array_map(function ($item) {
            return array_map('floatval', $item['values'] ?? []);
        }, $embeddings);
    }
}
