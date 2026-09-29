<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/PeerMatchingService.php';
require_once __DIR__ . '/../services/PeerSemanticClient.php';

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
class StubSemanticClient extends PeerSemanticClient {
    public array $reply = [];
    public bool $throws = false;
    public int $calls = 0;
    protected function request(array $payload): array {
        $this->calls++;
        if ($this->throws) throw new RuntimeException('timeout');
        return $this->reply;
    }
}

$service = new PeerMatchingService();
$profile = ['subjects'=>[['subject_id'=>1,'name'=>'PHP']],
    'interests'=>[['interest_id'=>1,'name'=>'Databases']],
    'hobbies'=>[], 'email'=>'private@example.com', 'bio'=>'private bio'];
$rules = $service->scoreProfiles($profile, $profile);
check($rules['engine'] === 'rules-v1', 'Default must be deterministic');
check($rules['semantic'] === null, 'Missing ML is not zero');
foreach ([null, NAN, INF, -1., 101.] as $invalid) {
    check($service->scoreProfiles($profile, $profile, $invalid) === $rules, 'Invalid ML must preserve fallback exactly');
}
$hybrid = $service->scoreProfiles($profile, $profile, 80.);
check(abs($hybrid['total'] - round($rules['total']*.75+20,2)) < .011, 'Hybrid weighting');
check(array_sum($hybrid['weights']) === 100.0, 'Weights must sum to 100');
check($hybrid['subjects'] === $rules['subjects'], 'Explainable components retained');
check(PeerSemanticClient::profileText($profile) === 'Databases; PHP', 'Only allowed labels leave PHP');
check(PeerSemanticClient::profileText(['subjects'=>[['subject_id'=>9]]]) === '', 'IDs are not semantic text');
$client = new StubSemanticClient();
$_ENV['PEER_ML_ENABLED'] = 'false';
check($client->scores($profile, [$profile]) === [null] && $client->calls === 0, 'Disabled means no request');
$_ENV['PEER_ML_ENABLED'] = 'true';
$client->reply = ['version'=>'semantic-v1','scores'=>[80,20]];
check($client->scores($profile, [$profile,$profile]) === [80.,20.], 'Batch order preserved');
foreach ([['version'=>'wrong','scores'=>[80]], ['version'=>'semantic-v1','scores'=>[]],
          ['version'=>'semantic-v1','scores'=>['80']], ['version'=>'semantic-v1','scores'=>[101]],
          ['version'=>'semantic-v1','scores'=>[NAN]]] as $reply) {
    $client->reply=$reply;
    check($client->scores($profile, [$profile]) === [null], 'Malformed response fallback');
}
$client->reply=['version'=>'semantic-v1','scores'=>[80]];
check($client->scores($profile, [[]]) === [null], 'Empty candidate has no ML score');
$client->throws=true;
check($client->scores($profile, [$profile]) === [null], 'Timeout fallback');
unset($_ENV['PEER_ML_ENABLED']);
echo "Peer ML regression checks passed\n";
