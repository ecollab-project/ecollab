<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
umask(0077);
require_once dirname(__DIR__).'/config.php';require_once ROOT_PATH.'/database/config/db.php';
$seconds=filter_var($argv[1]??480,FILTER_VALIDATE_INT);if(!$seconds||$seconds<10||$seconds>1800){fwrite(STDERR,"Use 10–1800 observation seconds.\n");exit(1);}
function cpu():array{$parts=preg_split('/\s+/',trim(explode("\n",file_get_contents('/proc/stat'))[0]));array_shift($parts);$n=array_map('intval',$parts);return ['total'=>array_sum(array_slice($n,0,8)),'idle'=>$n[3]+$n[4],'steal'=>$n[7],'iowait'=>$n[4]];}
function netbytes():array{$rx=0;$tx=0;foreach(file('/proc/net/dev')as$line){if(!str_contains($line,':'))continue;[$iface,$values]=explode(':',$line,2);if(trim($iface)==='lo')continue;$p=preg_split('/\s+/',trim($values));$rx+=(int)$p[0];$tx+=(int)$p[8];}return[$rx,$tx];}
$folder=dirname(ROOT_PATH).'/ecollab-load-tests';if(!is_dir($folder))mkdir($folder,0700,true);
$filename=$folder.'/host-capacity-'.gmdate('Ymd-His').'.json';$samples=[];$previous=cpu();$network=netbytes();$before=microtime(true);$end=$before+$seconds;
$db=Database::getInstance();
try{
    do{
        usleep((int)(min(5,max(0,$end-microtime(true)))*1000000));$now=microtime(true);$current=cpu();$delta=max(1,$current['total']-$previous['total']);$mem=file_get_contents('/proc/meminfo');preg_match('/MemAvailable:\s+(\d+)/',$mem,$available);preg_match('/SwapFree:\s+(\d+)/',$mem,$swap);
        $traffic=netbytes();$duration=max(.01,$now-$before);
        $queue=$db->query("SELECT COUNT(*) AS pending,SUM(state='running') AS running,COALESCE(MAX(TIMESTAMPDIFF(SECOND,created_at,UTC_TIMESTAMP())),0) AS oldest_seconds FROM code_lab_jobs WHERE state IN ('queued','running')")->fetch(PDO::FETCH_ASSOC);
        $sample=['sampled_at'=>gmdate('c'),'cpu_busy_pct'=>round(100*(1-($current['idle']-$previous['idle'])/$delta),1),'cpu_steal_pct'=>round(100*($current['steal']-$previous['steal'])/$delta,1),'io_wait_pct'=>round(100*($current['iowait']-$previous['iowait'])/$delta,1),'available_memory_kib'=>(int)($available[1]??0),'swap_free_kib'=>(int)($swap[1]??0),'network_rx_mbps'=>round(($traffic[0]-$network[0])*8/$duration/1000000,2),'network_tx_mbps'=>round(($traffic[1]-$network[1])*8/$duration/1000000,2),'code_queue'=>$queue];
        $samples[]=$sample;echo json_encode($sample)."\n";$previous=$current;$network=$traffic;$before=$now;
        file_put_contents($filename,json_encode(['scope'=>'Host-wide counters; network includes container bridges and must not be interpreted as public egress alone. No user content.','samples'=>$samples],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }while(microtime(true)<$end);
    echo "Host observations saved: $filename\n";
}catch(Throwable $e){fwrite(STDERR,"Observer failed. Verify Linux /proc access, database connectivity and Code Lab migration.\n");exit(1);}
