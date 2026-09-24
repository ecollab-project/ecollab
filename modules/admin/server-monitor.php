<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/services/ServerMonitoringService.php';
AuthMiddleware::startSession();
$user=AuthMiddleware::requireAuth();
$scope=(string)($_GET['scope']??'admin');
$serverId=filter_input(INPUT_GET,'server_id',FILTER_VALIDATE_INT);
if(!$serverId){http_response_code(400);exit('Invalid server.');}
$svc=new ServerMonitoringService();
if($scope==='facilitator'){
  RoleMiddleware::requireRole(['facilitator','admin','super_admin','moderator']);
  $data=$svc->getForFacilitator((int)$serverId,(int)$user['id']);
  $back=BASE_URL.'/modules/facilitator/dashboard.php';
}else{
  RoleMiddleware::requireRole(['admin','super_admin']);
  $data=$svc->getForAdmin((int)$serverId);
  $back=BASE_URL.'/modules/admin/dashboard.php?page=servers';
}
if(!$data){http_response_code(403);exit('You do not have access to this server monitor.');}
$s=$data['server'];$stats=$data['stats'];$daily=$data['daily'];$channels=$data['channels'];$members=$data['members'];$reports=$data['reports'];$recent=$data['recent'];$heat=$data['heatmap'];
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($s['name'])?> — Server Monitor</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<?php if($scope==='facilitator'):?><link rel="stylesheet" href="<?=BASE_URL?>/assets/css/desktop/facilitator-dashboard.css"><?php else:?><link rel="stylesheet" href="<?=BASE_URL?>/assets/css/desktop/admin-dashboard.css"><?php endif;?>
<link rel="stylesheet" href="<?=BASE_URL?>/assets/css/desktop/server-monitor.css"></head>
<body class="server-monitor-page">
<?php $activePage=$scope==='facilitator'?'servermonitoring':'servers'; if($scope==='facilitator'){include ROOT_PATH.'/includes/layout/sidebar-facilitator.php';}else{include ROOT_PATH.'/includes/layout/sidebar-admin.php';} ?>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
<div class="main-content monitor-main">
<header class="monitor-appbar"><button class="monitor-menu" onclick="toggleSidebar()" aria-label="Open navigation">☰</button><div><strong>Server Monitoring</strong><span><?= $scope==='facilitator'?'Facilitator':'Administrator' ?></span></div><a href="<?=h($back)?>" class="monitor-return">Back to <?= $scope==='facilitator'?'Dashboard':'Servers' ?></a></header>
<main class="monitor-page-content"><div class="monitor-shell">
  <div class="monitor-top"><a class="back" href="<?=h($back)?>">← Back</a><div class="server-head"><div class="server-icon"><?=h($s['icon_emoji']??'🖥')?></div><div><h1><?=h($s['name'])?></h1><div class="sub"><?=h(ucfirst($s['type']??$s['visibility']??'private'))?> server · Owner: <?=h($s['owner_username']??'Unknown')?><?php if(!empty($s['facilitator_username'])&&$s['facilitator_username']!=='—'):?> · Facilitator: <?=h($s['facilitator_username'])?><?php endif;?></div></div></div><div class="status">● <?=h(ucfirst($s['status']??'active'))?></div></div>
  <div class="tabs"><button class="tab active" data-tab="overview">Overview</button><button class="tab" data-tab="channels">Channels</button><button class="tab" data-tab="members">Members</button><button class="tab" data-tab="activity">Activity</button><button class="tab" data-tab="reports">Reports</button></div>
  <section id="tab-overview" class="panel active">
    <div class="stats">
      <?php foreach([['Members',$stats['members'],'👥'],['Channels',$stats['channels'],'#'],['Active Members',$stats['active_members'],'●'],['Messages',$stats['messages'],'💬'],['Threads',$stats['threads'],'🔗'],['Reports',$stats['reports'],'🚩']] as $x):?><div class="stat"><div class="si"><?=$x[2]?></div><div><strong><?=number_format((int)$x[1])?></strong><span><?=$x[0]?></span></div></div><?php endforeach;?>
    </div>
    <div class="grid2"><div class="card big"><div class="ct">Server Activity <small>Last 7 days</small></div><canvas id="activityChart"></canvas></div><div class="card"><div class="ct">Channel Activity <small>Messages</small></div><div class="bars"><?php $mx=max(1,...array_map(fn($c)=>(int)$c['messages'],$channels?:[['messages'=>0]])); foreach(array_slice($channels,0,8) as $c):?><div class="barrow"><span># <?=h($c['name'])?></span><div class="track"><i style="width:<?=max(3,round(((int)$c['messages']/$mx)*100))?>%"></i></div><b><?=number_format((int)$c['messages'])?></b></div><?php endforeach;?></div></div></div>
    <div class="grid3"><div class="card"><div class="ct">Member Participation</div><canvas id="memberChart"></canvas></div><div class="card"><div class="ct">Message Activity</div><canvas id="messageChart"></canvas></div><div class="card"><div class="ct">Activity Heatmap</div><div class="heat"><?php $hm=max(1,...$heat); foreach($heat as $v):?><span style="opacity:<?=0.12+0.88*($v/$hm)?>" title="<?=$v?> messages"></span><?php endforeach;?></div><div class="heatlabels">Mon Tue Wed Thu Fri Sat Sun</div></div></div>
    <div class="grid3 bottom"><div class="card"><div class="ct">Channels</div><?php foreach(array_slice($channels,0,8) as $c):?><div class="row"><span># <?=h($c['name'])?></span><span><?=number_format((int)$c['messages'])?> msg · <?=number_format((int)$c['active_members'])?> active</span></div><?php endforeach;?></div><div class="card"><div class="ct">Recent Activity</div><?php foreach($recent as $r):?><div class="row stack"><span><b><?=h($r['username'])?></b> in #<?=h($r['channel_name'])?></span><small><?=h($r['content'])?></small></div><?php endforeach;?></div><div class="card"><div class="ct">Flagged Content</div><?php foreach($reports as $r):?><div class="row stack"><span><b><?=h($r['reported_user'])?></b> · #<?=h($r['channel_name'])?></span><small><?=h(ucfirst($r['reason']))?> · <?=h(ucfirst($r['status']))?></small></div><?php endforeach;?></div></div>
  </section>
  <section id="tab-channels" class="panel"><div class="card"><div class="ct">Server Channels</div><?php foreach($channels as $c):?><div class="row"><span># <?=h($c['name'])?> <small><?=h($c['type'])?></small></span><span><?=number_format((int)$c['messages'])?> messages · <?=number_format((int)$c['active_members'])?> active</span></div><?php endforeach;?></div></section>
  <section id="tab-members" class="panel"><div class="card owner-summary"><div class="ct">Server Owner</div><div class="owner-line"><div class="owner-avatar">👑</div><div><strong><?=h($s['owner_name']??$s['owner_username']??'Unknown')?></strong><small>@<?=h($s['owner_username']??'Unknown')?> · Owner</small></div></div></div><div class="card"><div class="ct">Members <small>Owner is identified from servers.owner_id</small></div><?php foreach($members as $m):?><div class="row"><span><?=h($m['full_name'])?> <small>@<?=h($m['username'])?> · <?=!empty($m['is_owner'])?'👑 Owner':h(ucfirst((string)$m['server_role']))?></small></span><span><?=number_format((int)$m['messages'])?> messages · <?=h($m['status'])?></span></div><?php endforeach;?></div></section>
  <section id="tab-activity" class="panel"><div class="card"><div class="ct">Recent Messages</div><?php foreach($recent as $r):?><div class="row stack"><span><b><?=h($r['username'])?></b> · #<?=h($r['channel_name'])?> · <?=h($r['created_at'])?></span><small><?=h($r['content'])?></small></div><?php endforeach;?></div></section>
  <section id="tab-reports" class="panel"><div class="card"><div class="ct">Server Reports</div><?php foreach($reports as $r):?><div class="row"><span><?=h($r['reported_user'])?> · #<?=h($r['channel_name'])?> · <?=h($r['reason'])?></span><span><?=h($r['status'])?> · <?=h($r['created_at'])?></span></div><?php endforeach;?></div></section>
</div>
<script>window.SERVER_MONITOR=<?=json_encode(['daily'=>$daily,'channels'=>$channels],JSON_UNESCAPED_SLASHES)?>;</script>
<script src="<?=BASE_URL?>/assets/js/server-monitor.js"></script>
<script>
function showPage(page){window.location.href=(<?= json_encode($scope==='facilitator'?BASE_URL.'/modules/facilitator/dashboard.php?page=':BASE_URL.'/modules/admin/dashboard.php?page=') ?>)+encodeURIComponent(page);}
function goToChat(){window.location.href=<?= json_encode(BASE_URL.'/modules/chat/chat.php') ?>;}
function toggleSidebar(){document.querySelector('.sidebar')?.classList.toggle('open');document.getElementById('sidebarOverlay')?.classList.toggle('open');}
function closeSidebar(){document.querySelector('.sidebar')?.classList.remove('open');document.getElementById('sidebarOverlay')?.classList.remove('open');}
</script></main></div></body></html>