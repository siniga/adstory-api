<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function generateText(string $prompt): string
    {
        $apiKey = $this->getApiKey();
        $model = config('services.gemini.text_model');

        $response = Http::timeout(120)
            ->acceptJson()
            ->post(self::BASE_URL.'/'.$model.':generateContent?key='.$apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new RuntimeException('Gemini API request failed: '.$message);
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (empty($text)) {
            $finishReason = $response->json('candidates.0.finishReason');
            $message = 'Gemini returned an empty response.';

            if ($finishReason) {
                $message .= ' Finish reason: '.$finishReason;
            }

            throw new RuntimeException($message);
        }

        return trim($text);
    }

    public function generateImage(string $prompt, ?string $negativePrompt = null): string
    {
        $apiKey = $this->getApiKey();
        $model = config('services.gemini.image_model');

        $fullPrompt = trim($prompt);

        if ($negativePrompt !== null && trim($negativePrompt) !== '') {
            $fullPrompt .= "\n\nAvoid generating: ".trim($negativePrompt).'.';
        }

        $response = Http::timeout(120)
            ->acceptJson()
            ->post(self::BASE_URL.'/'.$model.':generateContent?key='.$apiKey, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $fullPrompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE'],
                ],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();

            throw new RuntimeException('Gemini image generation failed: '.$message);
        }

        $parts = $response->json('candidates.0.content.parts', []);

        if (! is_array($parts)) {
            throw new RuntimeException('Gemini image generation failed: invalid response structure.');
        }

        foreach ($parts as $part) {
            if (! empty($part['inlineData']['data'])) {
                return (string) $part['inlineData']['data'];
            }
        }

        $finishReason = $response->json('candidates.0.finishReason');
        $message = 'Gemini image generation failed: no image data in response.';

        if ($finishReason) {
            $message .= ' Finish reason: '.$finishReason;
        }

        throw new RuntimeException($message);
    }

    private function getApiKey(): string
    {
        $apiKey = config('services.gemini.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException('Gemini API key is not configured. Set GEMINI_API_KEY in your .env file.');
        }

        return $apiKey;
    }
}
