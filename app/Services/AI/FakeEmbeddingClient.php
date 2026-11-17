<?php

namespace App\Services\AI;

class FakeEmbeddingClient implements EmbeddingClient
{
    /**
     * Generate a deterministic 768-dimensional unit vector for the text.
     *
     * @return list<float>
     */
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Generate deterministic vectors for multiple texts.
     *
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array
    {
        return array_map(fn ($text) => $this->generateVector($text), $texts);
    }

    /**
     * Generate a 768-float unit vector seeded from the text string.
     *
     * @return list<float>
     */
    private function generateVector(string $text): array
    {
        $vector = [];
        $seed = hash('sha256', $text);

        for ($i = 0; $i < self::DIMENSIONS; $i++) {
            // Take 4 hex characters and convert to float between -1.0 and 1.0
            $chunk = substr(hash('sha256', $seed.'-'.$i), 0, 4);
            $val = ((hexdec($chunk) / 65535.0) * 2.0) - 1.0;
            $vector[] = (float) $val;
        }

        // Normalize vector to unit length
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector)));
        if ($norm > 0) {
            $vector = array_map(fn ($x) => round($x / $norm, 6), $vector);
        }

        return $vector;
    }
}
