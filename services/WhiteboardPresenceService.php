<?php
 declare(strict_types=1);
/** Ephemeral, connection-bound Yjs awareness states. No drawing or database writes. */
final class WhiteboardPresenceService
{
    private array $rooms = [];
    public function publish(string $room, int $connection, array $packet, array $user, ?float $now = null, string $surface = 'whiteboard'): ?array
    {
        $now ??= microtime(true);
        $id = $packet['client_id'] ?? null; $clock = $packet['clock'] ?? null;
        if (!is_int($id) || $id < 0 || $id > 4294967295 || !is_int($clock) || $clock < 0 || $clock > 4294967295) throw new InvalidArgumentException('Invalid awareness identity.');
        $existing = $this->rooms[$room][$connection] ?? null;
        if ($existing && $existing['client_id'] !== $id) throw new InvalidArgumentException('Awareness identity is bound to this connection.');
        foreach ($this->rooms[$room] ?? [] as $rid => $entry) if ($rid !== $connection && $entry['client_id'] === $id) throw new InvalidArgumentException('Awareness identity already in use.');
        if ($existing && $clock <= $existing['clock']) return null;
        $state = $packet['state'] ?? null;
        if ($state !== null && !is_array($state)) throw new InvalidArgumentException('Invalid awareness state.');
        if ($state !== null) {
            $pointer = $state['pointer'] ?? null;
            if ($pointer !== null) {
                if (!is_array($pointer) || !is_numeric($pointer['x'] ?? null) || !is_numeric($pointer['y'] ?? null)
                    || !is_finite((float)$pointer['x']) || !is_finite((float)$pointer['y']) || abs((float)$pointer['x']) > 1e7 || abs((float)$pointer['y']) > 1e7) throw new InvalidArgumentException('Invalid cursor.');
                $pointer = ['x'=>(float)$pointer['x'], 'y'=>(float)$pointer['y'], 'tool'=>'pointer'];
            }
            $selection = [];
            foreach (array_slice(is_array($state['selectedElementIds'] ?? null) ? $state['selectedElementIds'] : [], 0, 100, true) as $key => $value) {
                if (is_string($key) && strlen($key) <= 128 && $value === true) $selection[$key] = true;
            }
            $colors = ['#a855f7','#3b82f6','#ec4899','#14b8a6','#f59e0b','#22c55e'];
            $uid = (int)$user['id'];
            $state = ['user'=>['id'=>$uid,'name'=>(string)$user['name'],'color'=>$colors[$uid % count($colors)]],
                'pointer'=>$pointer,'button'=>($state['button'] ?? '') === 'down' ? 'down' : 'up',
                'selectedElementIds'=>(object)$selection, 'status'=>($state['status'] ?? '') === 'idle' ? 'idle' : 'active',
                'typing'=>$surface==='chat' && ($state['typing']??false)===true];
            if($surface==='chat')$state=['user'=>$state['user'],'status'=>$state['status'],'typing'=>$state['typing']];
        }
        $entry = ['client_id'=>$id,'clock'=>$clock,'state'=>$state,'updated_at'=>$now];
        $this->rooms[$room][$connection] = $entry;
        return $entry;
    }
    public function snapshot(string $room, ?float $now = null): array
    {
        $now ??= microtime(true);
        return array_values(array_filter($this->rooms[$room] ?? [], fn($entry)=>$entry['state'] !== null && $now-$entry['updated_at'] < 30));
    }
    public function leave(string $room, int $connection): ?int
    {
        $id = $this->rooms[$room][$connection]['client_id'] ?? null;
        unset($this->rooms[$room][$connection]);
        if (empty($this->rooms[$room])) unset($this->rooms[$room]);
        return $id;
    }
}
