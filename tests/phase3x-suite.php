<?php
declare(strict_types=1);

// Invoked only inside classroom-performance.py's newly created loopback database.
$dsn = getenv('CLASSROOM_DSN');
if (!$dsn || !preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D', $dsn)) {
    throw new RuntimeException('Owned disposable suite database required');
}
$root = dirname(__DIR__);
$php = getenv('PHP_BIN') ?: PHP_BINARY;
$base = getenv('PHASE3X_BASE_SUITE') ?: throw new RuntimeException('Exact-base checkout required');
$db = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$out = ['scope' => 'Full-schema service integration; no HTTP/browser or external mail', 'runs' => []];
$out['clock_alignment']=['php_zone'=>date_default_timezone_get(),'php_epoch'=>time(),'database'=>$db->query('SELECT NOW() AS db_now, UTC_TIMESTAMP() AS db_utc, UNIX_TIMESTAMP() AS db_epoch, @@session.time_zone AS db_zone')->fetch(PDO::FETCH_ASSOC)];
if ($out['clock_alignment']['php_zone']!=='UTC' || $out['clock_alignment']['database']['db_now']!==$out['clock_alignment']['database']['db_utc'] || abs(time()-(int)$out['clock_alignment']['database']['db_epoch'])>5) throw new RuntimeException('Owned suite requires aligned UTC clocks');
function command(array $cmd, string $cwd, array $environment): array {
    $pipes = [];
    $p = proc_open($cmd, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, $cwd, $environment);
    if (!is_resource($p)) throw new RuntimeException('Cannot start test command');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['command'=>$cmd, 'exit_code'=>proc_close($p), 'stdout'=>$stdout, 'stderr'=>$stderr];
}
$env = getenv();
$expectedFailures = [
    'testRegisterCreatesUserAndReturnsSuccess', 'testRegisterRejectsDuplicateEmail',
    'testRegisterSeedsPmUserStudyPrefs', 'testRegisterFallsBackToDefaultsWhenStyleGoalUnrecognized',
    'testLoginSucceedsWithCorrectCredentials',
];
$ok = true;
try {
    foreach ([['baseline', $base, []], ['current', $root, []],
              ['reverse', $root, ['--order-by=reverse']],
              ['random-31028', $root, ['--order-by=random', '--random-order-seed=31028']]] as [$label,$suite,$args]) {
        // Independent fresh schema for each ordering: no cross-run authentication/lockout state.
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $db->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
        $schema = command([$php,$root.'/tests/phase3w-schema.php'], $root, array_replace($env,['PHASE3W_FULL_PHPUNIT'=>'0']));
        $schemaData = json_decode($schema['stdout'], true, 512, JSON_THROW_ON_ERROR);
        if ($schema['exit_code'] !== 0 || $schemaData['full_migration_status'] !== 'PASS') throw new RuntimeException('Migration failure');
        $xmlPath = tempnam(sys_get_temp_dir(), 'phase3x-junit-');
        try {
            $run = command([$php,'-d','date.timezone=UTC','-d','auto_prepend_file='.$root.'/tests/phase3w-phpunit-bootstrap.php',
                '-d','session.save_path=/tmp','-d','output_buffering=4096', $suite.'/vendor/bin/phpunit',
                '--log-junit',$xmlPath,...$args], $suite, array_replace($env,['PHASE3W_SUITE_ROOT'=>$suite]));
            $run['junit'] = file_get_contents($xmlPath);
        } finally { unlink($xmlPath); }
        $xml = simplexml_load_string($run['junit']);
        if (!$xml || !isset($xml->testsuite)) throw new RuntimeException('No completed JUnit suite');
        $attrs = $xml->testsuite->attributes();
        $run['counts'] = [];
        foreach (['tests','assertions','errors','failures','skipped'] as $key) $run['counts'][$key]=(int)$attrs[$key];
        $run['failed_cases'] = [];
        foreach ($xml->xpath('//testcase[failure or error]') as $case) $run['failed_cases'][]=(string)$case['name'];
        $run['schema'] = $schema; // Includes exact DDL, migration source hashes and runner exits.
        $run['schema_identity'] = $schemaData['schema_identity'];
        $run['tables_before_suite'] = count($schemaData['derived_schema']);
        $leftovers=$db->query("SELECT COUNT(*) FROM users WHERE email LIKE 'phpunit\\_%@fatima.edu.ph'")->fetchColumn();
        $run['remaining_synthetic_auth_users'] = (int)$leftovers;
        $run['orphan_otp_rows'] = (int)$db->query('SELECT COUNT(*) FROM otp_codes o LEFT JOIN users u ON u.id=o.user_id WHERE u.id IS NULL')->fetchColumn();
        if ($label==='baseline') {
            $actual=$run['failed_cases']; sort($actual); $wanted=$expectedFailures; sort($wanted);
            $pass=$run['exit_code']===1 && $actual===$wanted && $run['counts']['tests']===46 && $run['counts']['errors']===0;
            $run['status']='FAIL (expected inherited baseline)';
        } else {
            $pass=$run['exit_code']===0 && $run['counts']['tests']>=57 && $run['counts']['failures']===0 && $run['counts']['errors']===0 && $run['counts']['skipped']===0 && (int)$leftovers===0 && $run['orphan_otp_rows']===0;
            $run['status']=$pass?'PASS':'FAIL';
        }
        $ok=$ok&&$pass; $run['acceptance_check']=$pass?'PASS':'FAIL'; $out['runs'][$label]=$run;
    }
} catch (Throwable $e) { $ok=false; $out['error']=$e->getMessage(); }
$out['status']=$ok?'PASS':'FAIL';
echo json_encode($out, JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
exit($ok?0:1);
