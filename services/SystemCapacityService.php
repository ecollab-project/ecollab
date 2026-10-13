<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/database/config/db.php';

final class SystemCapacityService
{
    public function __construct(private ?PDO $db = null) { $this->db ??= Database::getInstance(); }

    public static function validateRecord(array $data): array
    {
        foreach (['tested_on','workload','notes'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) throw new InvalidArgumentException('Test date, workload and evidence must be text.');
        }
        $users = filter_var($data['tested_users'] ?? null, FILTER_VALIDATE_INT);
        $hold = filter_var($data['hold_seconds'] ?? null, FILTER_VALIDATE_INT);
        $result = $data['result'] ?? '';
        $completed = ($data['completed'] ?? false) === true;
        $date = (string)($data['tested_on'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
        $workload = trim((string)($data['workload'] ?? ''));
        $notes = trim((string)($data['notes'] ?? ''));
        if (!$users || $users < 1 || $users > 1000000 || $hold === false || $hold < 1 || $hold > 86400
            || !in_array($result, ['passed','failed','untested'], true)
            || !$parsed || $parsed->format('Y-m-d') !== $date
            || $date > (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d')
            || $workload === '' || strlen($workload) > 160 || $notes === '' || strlen($notes) > 5000
            || ($result === 'passed' && !$completed)) {
            throw new InvalidArgumentException('Enter valid users, date, workload, hold duration and evidence. A passed test must have completed.');
        }
        $row = ['tested_users'=>$users,'hold_seconds'=>$hold,'result'=>$result,'completed'=>(int)$completed,
            'tested_on'=>$date,'workload'=>$workload,'notes'=>$notes];
        foreach (['api_success_percent','http_p95_ms','delivery_p95_ms'] as $field) {
            $value = $data[$field] ?? null;
            if ($value === '' || $value === null) { $row[$field] = null; continue; }
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0
                || (float)$value > ($field === 'api_success_percent' ? 100 : 86400000)) {
                throw new InvalidArgumentException('Invalid success percentage or latency.');
            }
            $row[$field] = (float)$value;
        }
        return $row;
    }

    public function current(): array
    {
        // Compare timestamps in the DB's clock/timezone, matching existing presence writes.
        $now = new DateTimeImmutable((string)$this->db->query('SELECT NOW()')->fetchColumn());
        $active = $now->modify('-2 minutes')->format('Y-m-d H:i:s');
        $recent = $now->modify('-10 minutes')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare("SELECT
            COALESCE(SUM(CASE WHEN last_active_at >= ? OR last_seen_at >= ? THEN 1 ELSE 0 END),0) AS active_users,
            COUNT(*) AS presence_users FROM users
            WHERE deleted_at IS NULL AND is_online=1
              AND status NOT IN ('banned','deactivated','suspended')
              AND (last_active_at >= ? OR last_seen_at >= ?)
              AND email NOT LIKE 'k6_%@example.invalid'");
        $stmt->execute([$active,$active,$recent,$recent]);
        $row = $stmt->fetch();
        return ['active_users'=>(int)$row['active_users'],'presence_users'=>(int)$row['presence_users']];
    }

    public function collect(): array
    {
        $row = $this->current();
        $slot = gmdate('Y-m-d H:i:00');
        // One retained sample per minute, even when dashboard refresh and cron overlap.
        try {
            $stmt = $this->db->prepare('INSERT INTO capacity_samples (sampled_at,active_users,presence_users) VALUES (?,?,?)');
            $stmt->execute([$slot,$row['active_users'],$row['presence_users']]);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? '') !== '23000') throw $e;
        }
        $this->db->prepare('DELETE FROM capacity_samples WHERE sampled_at < ?')
            ->execute([gmdate('Y-m-d H:i:s', time()-90*86400)]);
        return $row;
    }

    public function overview(): array
    {
        $current = $this->current();
        $settings = $this->db->query('SELECT * FROM capacity_settings WHERE id=1')->fetch();
        if (!$settings) throw new RuntimeException('Capacity settings missing; apply migration 053.');
        $baseline = null;
        if ($settings['baseline_test_id']) {
            $stmt = $this->db->prepare("SELECT * FROM capacity_test_records WHERE id=? AND result='passed' AND completed=1");
            $stmt->execute([$settings['baseline_test_id']]); $baseline = $stmt->fetch() ?: null;
        }
        $tz = new DateTimeZone('Asia/Manila');
        $start = (new DateTimeImmutable('today', $tz))->setTimezone(new DateTimeZone('UTC'));
        $end = $start->modify('+1 day');
        $stmt = $this->db->prepare('SELECT * FROM capacity_samples WHERE sampled_at >= ? AND sampled_at < ? ORDER BY sampled_at');
        $stmt->execute([$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
        $hourly = array_fill(0,24,null); $samples = array_fill(0,24,0); $peak = null; $peakAt = null; $last = null;
        foreach ($stmt->fetchAll() as $sample) {
            $at = (new DateTimeImmutable($sample['sampled_at'],new DateTimeZone('UTC')))->setTimezone($tz);
            $hour = (int)$at->format('G'); $value = (int)$sample['active_users'];
            $hourly[$hour] = max($hourly[$hour] ?? 0,$value); $samples[$hour]++;
            if ($peak === null || $value > $peak) { $peak=$value; $peakAt=$at->format(DATE_ATOM); }
            $last=$at->format(DATE_ATOM);
        }
        $count = array_sum($samples); $tested = $baseline ? (int)$baseline['tested_users'] : null;
        return ['current'=>$current,'baseline'=>$baseline,'settings'=>['version'=>(int)$settings['version'],
                'operational_target'=>$settings['operational_target'] === null ? null : (int)$settings['operational_target']],
            'capacity_percent'=>$tested ? round($current['active_users']/$tested*100,1) : null,
            'history'=>['timezone'=>'Asia/Manila','hourly_peaks'=>$hourly,'hourly_samples'=>$samples,
                'peak_users'=>$peak,'peak_at'=>$peakAt,'last_sample_at'=>$last,'samples_today'=>$count],
            'records'=>$this->db->query('SELECT * FROM capacity_test_records ORDER BY id DESC LIMIT 50')->fetchAll(),
            'audit'=>$this->db->query('SELECT * FROM capacity_settings_history ORDER BY id DESC LIMIT 20')->fetchAll(),
            'checked_at'=>gmdate(DATE_ATOM)];
    }

    public function save(array $data, int $actor): void
    {
        $action = $data['action'] ?? '';
        $record = $action === 'record' ? self::validateRecord($data) : null;
        if (!in_array($action,['record','settings'],true)) throw new InvalidArgumentException('Invalid action.');
        $this->db->beginTransaction();
        try {
            $settings = $this->db->query('SELECT * FROM capacity_settings WHERE id=1')->fetch();
            if (!$settings || (int)($data['version'] ?? 0) !== (int)$settings['version']) {
                throw new RuntimeException('Capacity settings changed. Refresh before saving.',409);
            }
            $baseline = $settings['baseline_test_id']; $target = $settings['operational_target'];
            if ($record) {
                $fields = array_keys($record); $values = array_values($record);
                $fields[]='created_by'; $values[]=$actor;
                $stmt=$this->db->prepare('INSERT INTO capacity_test_records ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
                $stmt->execute($values);
                if (($data['set_baseline'] ?? false) === true) {
                    if ($record['result'] !== 'passed' || !$record['completed']) throw new InvalidArgumentException('Only completed, passed tests can be a capacity reference.');
                    $baseline = (int)$this->db->lastInsertId();
                }
            } else {
                $raw = $data['operational_target'] ?? null;
                $target = $raw === null || $raw === '' ? null : filter_var($raw,FILTER_VALIDATE_INT);
                if ($target !== null && ($target === false || $target < 1 || $target > 1000000)) throw new InvalidArgumentException('Enter a positive operational target.');
            }
            $stmt=$this->db->prepare('SELECT tested_users FROM capacity_test_records WHERE id=?');
            $stmt->execute([$baseline]); $tested=(int)$stmt->fetchColumn();
            if ($target !== null && (!$tested || $target > $tested)) throw new InvalidArgumentException('Operational target must not exceed the tested capacity reference.');
            $stmt=$this->db->prepare('UPDATE capacity_settings SET baseline_test_id=?,operational_target=?,version=version+1 WHERE id=1 AND version=?');
            $stmt->execute([$baseline,$target,$settings['version']]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Capacity settings changed. Refresh before saving.',409);
            $this->db->prepare('INSERT INTO capacity_settings_history (actor_id,baseline_test_id,operational_target) VALUES (?,?,?)')->execute([$actor,$baseline,$target]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
}
