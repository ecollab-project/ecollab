<?php

declare(strict_types=1);

/**
 * Small dependency-free Gemini API client for Ecollab.
 * API keys must remain server-side in .env.
 */
final class GeminiService
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gemini-flash-latest',
        private readonly int $timeout = 45,
    ) {
        if ($this->apiKey === '' || $this->apiKey === 'your_gemini_api_key_here') {
            throw new RuntimeException('Gemini API is not configured.', 503);
        }
    }

    /** @param array<int, array{role:string,content:string}> $messages */
    public function generate(array $messages, string $systemPrompt = '', int $maxOutputTokens = 700): array
    {
        $contents = [];
        foreach ($messages as $message) {
            $role = ($message['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => (string)($message['content'] ?? '')]],
            ];
        }

        $payload = ['contents' => $contents];
        if ($systemPrompt !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }
        $payload['generationConfig'] = [
            'maxOutputTokens' => $maxOutputTokens,
            'temperature' => 0.7,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode Gemini request.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($this->model) . ':generateContent';
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Gemini request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Gemini request failed: ' . ($curlError ?: 'network error'), 502);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('Gemini returned invalid JSON.', 502);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException((string)($data['error']['message'] ?? 'Gemini API error.'), 502);
        }

        $text = trim((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('Gemini returned an empty response.', 502);
        }

        return [
            'text' => $text,
            'input_tokens' => (int)($data['usageMetadata']['promptTokenCount'] ?? 0),
            'output_tokens' => (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0),
        ];
    }
}
