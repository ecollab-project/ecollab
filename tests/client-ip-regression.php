<?php
declare(strict_types=1);
require dirname(__DIR__).'/security/ClientIp.php';
function check(string $expected,array $server):void {if(ClientIp::resolve($server)!==$expected)throw new RuntimeException('Client IP trust test failed.');}
putenv('TRUSTED_PROXIES');
$spoof=['REMOTE_ADDR'=>'198.51.100.2','HTTP_X_FORWARDED_FOR'=>'203.0.113.10','HTTP_CF_CONNECTING_IP'=>'203.0.113.11','HTTP_X_REAL_IP'=>'203.0.113.12'];
check('198.51.100.2',$spoof);check('0.0.0.0',['REMOTE_ADDR'=>['bad']]);
putenv('TRUSTED_PROXIES=127.0.0.1,10.20.0.0/16,2001:db8:abcd::/48');
check('198.51.100.2',$spoof);
check('203.0.113.4',['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'1.2.3.4, 203.0.113.4, 10.20.1.3']);
check('127.0.0.1',['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'not-an-ip']);
check('203.0.113.4',['REMOTE_ADDR'=>'2001:db8:abcd::2','HTTP_X_FORWARDED_FOR'=>'203.0.113.4']);
check('2001:db8:abce::2',['REMOTE_ADDR'=>'2001:db8:abce::2','HTTP_X_FORWARDED_FOR'=>'203.0.113.4']);
putenv('TRUSTED_PROXIES=invalid/0,10.0.0.0/99');check('198.51.100.2',$spoof);
putenv('TRUSTED_PROXIES');
echo "PASS: direct peer, spoofed headers, trusted proxy chain, malformed input and IPv4/IPv6 CIDRs\n";
