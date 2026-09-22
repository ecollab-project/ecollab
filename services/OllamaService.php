<?php
declare(strict_types=1);

final class OllamaService
{
    private string $baseUrl;
    private string $model;

    public function __construct(?string $baseUrl = null, ?string $model = null)
    {
        $this->baseUrl = rtrim($baseUrl ?: (getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434'), '/');
        $this->model = $model ?: (getenv('OLLAMA_MODEL') ?: 'qwen3:1.7b');
    }

    public function generate(array $messages, string $systemPrompt = '', int $maxTokens = 400): array
    {
        $payloadMessages = [];
        if ($systemPrompt !== '') {
            $payloadMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? 'user');
            if (!in_array($role, ['system', 'user', 'assistant'], true)) {
                $role = 'user';
            }

            $payloadMessages[] = [
                'role' => $role,
                'content' => (string)($message['content'] ?? ''),
            ];
        }

        $payload = [
            'model' => $this->model,
            'messages' => $payloadMessages,
            'stream' => false,
            'think' => false,
            'options' => [
                'temperature' => 0.4,
                'num_predict' => max(1, $maxTokens),
            ],
        ];

        $ch = curl_init($this->baseUrl . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 120,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Ollama request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Ollama returned HTTP ' . $status);
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return [
            'text' => (string)($data['message']['content'] ?? ''),
            'input_tokens' => $data['prompt_eval_count'] ?? null,
            'output_tokens' => $data['eval_count'] ?? null,
            'model' => (string)($data['model'] ?? $this->model),
        ];
    }
}
