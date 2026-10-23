<?php
declare(strict_types=1);

final class OllamaService
{
    private string $baseUrl;
    private string $model;
    public const JSON_INPUT_MAX_BYTES = 8192;
    private const RESPONSE_MAX_BYTES = 1048576;

    public function __construct(?string $baseUrl = null, ?string $model = null, private int $timeout = 90)
    {
        if ($timeout < 1 || $timeout > 90) throw new InvalidArgumentException('Invalid local provider timeout.');
        $this->baseUrl = rtrim($baseUrl ?: (getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434'), '/');
        $this->model = $model ?: (getenv('OLLAMA_MODEL') ?: 'qwen3:1.7b');
        $parts = parse_url($this->baseUrl);
        if (!$parts || ($parts['scheme'] ?? '') !== 'http' || !in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
            || !in_array($parts['path'] ?? '', ['', '/'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Ollama must use a local HTTP endpoint.');
        }
    }

    /** Load the local model without reading a conversation or generating a reply. */
    public function warmUp(): array
    {
        $started = microtime(true);
        $data = $this->requestChat(['model'=>$this->model,'messages'=>[],'stream'=>false,'keep_alive'=>'10m',
            'options'=>['num_ctx'=>4096,'num_thread'=>max(1,min(2,(int)(getenv('OLLAMA_NUM_THREADS') ?: 1)))]]);
        return ['elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
            'load_ms'=>(int)round(($data['load_duration']??0)/1000000)];
    }

    public function chatWithTools(array $messages, string $systemPrompt, array $tools, callable $executor, int $maxTokens = 400, int $maxRounds = 3): array
    {
        $payloadMessages = [];
        if ($systemPrompt !== '') {
            $payloadMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? 'user');
            if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) $role = 'user';
            $item = ['role' => $role, 'content' => (string)($message['content'] ?? '')];
            if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) $item['tool_calls'] = $message['tool_calls'];
            $payloadMessages[] = $item;
        }

        $inputTokens = 0;
        $outputTokens = 0;
        $rounds = max(1, min(5, $maxRounds));

        for ($round = 0; $round < $rounds; $round++) {
            $payload = [
                'model' => $this->model,
                'messages' => $payloadMessages,
                'tools' => $tools,
                'stream' => false,
                'keep_alive' => '10m',
                'think' => false,
                'options' => ['temperature' => 0.3, 'num_thread' => max(1, min(2, (int)(getenv('OLLAMA_NUM_THREADS') ?: 1))),
                'num_ctx' => 4096,
                'num_predict' => max(1, min(1024, $maxTokens))],
            ];
            $data = $this->requestChat($payload);
            $inputTokens += (int)($data['prompt_eval_count'] ?? 0);
            $outputTokens += (int)($data['eval_count'] ?? 0);
            $message = is_array($data['message'] ?? null) ? $data['message'] : [];
            $toolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

            if (!$toolCalls) {
                return [
                    'text' => (string)($message['content'] ?? ''),
                    'input_tokens' => $inputTokens ?: null,
                    'output_tokens' => $outputTokens ?: null,
                    'model' => (string)($data['model'] ?? $this->model),
            'timing_ms'=>['load'=>(int)round(($data['load_duration']??0)/1000000),'prompt'=>(int)round(($data['prompt_eval_duration']??0)/1000000),'generation'=>(int)round(($data['eval_duration']??0)/1000000)],
                ];
            }

            $payloadMessages[] = [
                'role' => 'assistant',
                'content' => (string)($message['content'] ?? ''),
                'tool_calls' => $toolCalls,
            ];

            foreach ($toolCalls as $call) {
                $function = is_array($call['function'] ?? null) ? $call['function'] : [];
                $name = (string)($function['name'] ?? '');
                $arguments = $function['arguments'] ?? [];
                if (is_string($arguments)) {
                    $decoded = json_decode($arguments, true);
                    $arguments = is_array($decoded) ? $decoded : [];
                }
                if (!is_array($arguments)) $arguments = [];

                try {
                    $toolResult = $executor($name, $arguments);
                    $content = json_encode(['ok' => true, 'result' => $toolResult], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                } catch (Throwable $e) {
                    $content = json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                $payloadMessages[] = ['role' => 'tool', 'content' => $content];
            }
        }

        throw new RuntimeException('Jarred exceeded the maximum tool-call rounds');
    }

    /** Explicit shared files must be provisioned outside PrivateTmp; never fall back. */
    private function inferenceLock()
    {
        $shared = (string)(getenv('OLLAMA_INFERENCE_LOCK_FILE') ?: '');
        $path = $shared !== '' ? $shared : sys_get_temp_dir() . '/ecollab-ollama-inference.lock';
        if ($shared !== '' && (!str_starts_with($path, '/') || !is_file($path) || is_link($path)
            || ((int)fileperms($path) & 0007) !== 0 || ((int)fileperms(dirname($path)) & 0002) !== 0)) {
            throw new RuntimeException('Shared inference coordination is unavailable.', 503);
        }
        $lock = @fopen($path, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            throw new RuntimeException('Jarred is busy or inference coordination is unavailable.', 503);
        }
        $opened = fstat($lock); $named = @lstat($path);
        if (!$opened || !$named || ($opened['mode'] & 0170000) !== 0100000
            || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
            flock($lock, LOCK_UN); fclose($lock);
            throw new RuntimeException('Shared inference coordination is unavailable.', 503);
        }
        return $lock;
    }

    private function requestChat(array $payload, ?int $deadline = null): array
    {
        $lock = $this->inferenceLock();
        $ch = null;
        try {
            $raw = '';
            $ch = curl_init($this->baseUrl . '/api/chat');
            if ($ch === false) { $ch=null; throw new RuntimeException('Local model is unavailable.',503); }
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => static function($handle, string $chunk) use (&$raw): int {
                    if (strlen($raw) + strlen($chunk) > self::RESPONSE_MAX_BYTES) return 0;
                    $raw .= $chunk; return strlen($chunk);
                },
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => min($this->timeout, $deadline ?? 90),
            ]);
            $ok = curl_exec($ch);
            if ($ok === false) throw new RuntimeException('Local model request unavailable or response limit exceeded.', 503);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) throw new RuntimeException('Local model request unavailable.', 503);
            try { $data=json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new RuntimeException('Local model returned an invalid response.',503); }
            if(!is_array($data)||isset($data['error']))throw new RuntimeException('Local model returned an invalid response.',503);
            return $data;
        } finally {
            if ($ch !== null) curl_close($ch);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /** Structured, bounded inference; consumers must still validate domain semantics. */
    public function generateJson(array $messages): array
    {
        if (strlen(json_encode($messages, JSON_THROW_ON_ERROR)) > self::JSON_INPUT_MAX_BYTES) {
            throw new RuntimeException('Grouping input exceeds the safe provider envelope; no candidates were omitted.', 422);
        }
        $data = $this->requestChat([
            'model'=>$this->model, 'messages'=>$messages, 'stream'=>false, 'format'=>'json',
            'truncate'=>false, 'shift'=>false, 'think'=>false, 'keep_alive'=>'10m',
            'options'=>['temperature'=>0.2, 'num_ctx'=>4096,
                'num_thread'=>max(1,min(2,(int)(getenv('OLLAMA_NUM_THREADS') ?: 1))), 'num_predict'=>1024],
        ], 45);
        if (($data['done'] ?? null) !== true || ($data['done_reason'] ?? '') !== 'stop') {
            throw new RuntimeException('Local model did not complete the grouping response.', 502);
        }
        if (!is_array($data['message']??null)) throw new RuntimeException('Invalid grouping response.',502);
        $text = $data['message']['content'] ?? null;
        if (!is_string($text) || !str_starts_with(ltrim($text), '{')) throw new RuntimeException('Invalid grouping response.',502);
        try { $parsed=json_decode($text,true,32,JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('Invalid grouping response.',502); }
        if (!is_array($parsed) || array_is_list($parsed)) throw new RuntimeException('Invalid grouping response.',502);
        return ['data'=>$parsed, 'model'=>$this->model];
    }

    public function generate(array $messages, string $systemPrompt = '', int $maxTokens = 400, bool $requireComplete = false): array
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
                'keep_alive' => '10m',
            'think' => false,
            'options' => [
                'temperature' => 0.25,
                'num_thread' => max(1, min(2, (int)(getenv('OLLAMA_NUM_THREADS') ?: 1))),
                'num_ctx' => 4096,
                'num_predict' => max(1, min(1024, $maxTokens)),
            ],
        ];

        $data = $this->requestChat($payload);
        if ($requireComplete && (($data['done'] ?? null) !== true || ($data['done_reason'] ?? '') !== 'stop'
            || !is_array($data['message'] ?? null) || !is_string($data['message']['content'] ?? null))) {
            throw new RuntimeException('Local model did not complete the response.',503);
        }

        return [
            'text' => (string)($data['message']['content'] ?? ''),
            'input_tokens' => $data['prompt_eval_count'] ?? null,
            'output_tokens' => $data['eval_count'] ?? null,
            'model' => (string)($data['model'] ?? $this->model),
            'timing_ms'=>['load'=>(int)round(($data['load_duration']??0)/1000000),'prompt'=>(int)round(($data['prompt_eval_duration']??0)/1000000),'generation'=>(int)round(($data['eval_duration']??0)/1000000)],
        ];
    }
}

