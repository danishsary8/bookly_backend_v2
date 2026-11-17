<?php

namespace App\Services\AI;

interface EmbeddingClient
{
    /**
     * Dimension size of generated embeddings.
     */
    public const DIMENSIONS = 768;

    /**
     * Generate an embedding vector for a single text.
     *
     * @return list<float> 768-dimensional float vector.
     */
    public function embed(string $text): array;

    /**
     * Generate embedding vectors for multiple texts in a batch.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;
}
