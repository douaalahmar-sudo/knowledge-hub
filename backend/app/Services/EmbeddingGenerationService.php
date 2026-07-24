<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmbeddingGenerationService
{
    public function generate(string $text): string
    {
        $apiKey = config('services.openai.key');

        if (! $apiKey) {
            return $this->fallbackVector($text);
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => config('services.openai.embedding_model', 'text-embedding-3-small'),
                    'input' => $text,
                ])
                ->throw()
                ->json();

            $embedding = data_get($response, 'data.0.embedding');

            if (! is_array($embedding) || $embedding === []) {
                return $this->fallbackVector($text);
            }

            return $this->formatVector($embedding);
        } catch (Throwable $exception) {
            Log::warning('OpenAI embedding generation failed, using fallback vector.', [
                'message' => $exception->getMessage(),
            ]);

            return $this->fallbackVector($text);
        }
    }

    private function formatVector(array $embedding): string
    {
        return '[' . implode(',', array_map(static fn ($value) => number_format((float) $value, 6, '.', ''), $embedding)) . ']';
    }

    private function fallbackVector(string $text): string
    {
        $seed = hash('sha256', $text, true);
        $seedLength = strlen($seed);
        $values = [];

        for ($index = 0; $index < 1536; $index++) {
            $byte = ord($seed[$index % $seedLength]);
            $values[] = number_format(($byte / 255) * 2 - 1, 6, '.', '');
        }

        return '[' . implode(',', $values) . ']';
    }
}