<?php
declare(strict_types=1);

// Exercise each endpoint's actual pre-dispatch gate in a separate PHP process,
// with real session/CSRF code. No database or network calls are made.
if (($argv[1] ?? '') === '--case') {
    [$endpoint, $method, $tool, $action, $token] = array_slice($argv, 2);
    require dirname(__DIR__) . '/security/csrf/csrf.php';
    ini_set('session.save_path', sys_get_temp_dir());
    session_start();
    $_SESSION['csrf_token'] = 'expected';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    $_SERVER['REQUEST_METHOD'] = $method;
    $tool = (string)$tool;
    $action = (string)$action;
    function json_fail(string $message, int $status = 400): never {
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }
    ob_start();
    register_shutdown_function(static function (): void {
        $output = ob_get_clean();
        echo json_encode(['status' => http_response_code() ?: 200, 'body' => $output]);
    });
    $source = file_get_contents(dirname(__DIR__) . '/API/collab/' . $endpoint);
    $start = strpos($source, '$method =');
    $end = strpos($source, '$uid ', $start);
    eval(substr($source, $start, $end - $start));
    echo 'accepted';
    exit;
}

$checks = 0;
function gate(string $endpoint, string $method, string $tool, string $action, string $token, int $expected): void {
    global $checks;
    $args = array_map('escapeshellarg', [PHP_BINARY, '-n', __FILE__, '--case', $endpoint, $method, $tool, $action, $token]);
    $result = json_decode((string)shell_exec(implode(' ', $args)), true, 512, JSON_THROW_ON_ERROR);
    if ($result['status'] !== $expected) {
        throw new RuntimeException("$endpoint $method $tool/$action: expected $expected, got " . json_encode($result));
    }
    if ($expected === 200 && $result['body'] !== 'accepted') throw new RuntimeException('Gate did not accept valid request');
    if ($expected !== 200 && $result['body'] === 'accepted') throw new RuntimeException('Rejected request reached dispatch');
    ++$checks;
}
foreach (['collab.php' => ['timer', 'reset', 'get'], 'collab-extra.php' => ['summary', 'generate', 'list']] as $endpoint => [$tool, $write, $read]) {
    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        gate($endpoint, $method, $tool, $write, '', 403);
        gate($endpoint, $method, $tool, $write, 'wrong', 403);
        gate($endpoint, $method, $tool, $write, 'expected', 200);
    }
    gate($endpoint, 'GET', $tool, $write, '', 405);
    gate($endpoint, 'GET', $tool, $read, '', 200);
    gate($endpoint, 'OPTIONS', $tool, $write, 'expected', 405);
    gate($endpoint, 'HEAD', $tool, $write, 'expected', 405);
}
echo "PASS: $checks collaboration method/CSRF checks; no database access\n";
