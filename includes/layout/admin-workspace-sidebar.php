<?php
$workspaceNav=[
 'Workspace'=>[['overview','Overview','grid'],['users','People','users'],['servers','Spaces','layers'],['courses','Courses & interests','book']],
 'Review'=>[['reports','Reports','flag'],['facilitatorrequests','Facilitator requests','check'],['feedback','Feedback','message']],
 'Insights'=>[['analytics','Activity insights','chart'],['aimatching','AI & peer matching','spark'],['capacity','System capacity','gauge'],['syshealth','System status','pulse'],['activitylogs','Audit trail','clock']],
 'Tools'=>[['announcements','Announcements','megaphone'],['settings','Preferences','settings']]
];
?>
<aside class="workspace-sidebar sidebar" id="workspaceSidebar" aria-label="Admin navigation">
 <a class="workspace-brand" href="<?= BASE_URL ?>/modules/admin/dashboard.php"><img src="<?= BASE_URL ?>/assets/ecollab-wordmark.webp" alt="eCollab"><span>ADMIN WORKSPACE</span></a>
 <nav><?php foreach($workspaceNav as $group=>$items): ?><div class="workspace-nav-group"><span class="workspace-nav-label"><?=htmlspecialchars($group)?></span><?php foreach($items as [$id,$label,$icon]):?><button type="button" class="nav-item" data-page="<?=$id?>"><span data-icon="<?=$icon?>" aria-hidden="true"></span><span><?=$label?></span><?php if(in_array($id,['reports','facilitatorrequests'],true)):?><span class="workspace-nav-count" id="navCount-<?=$id?>" hidden></span><?php endif;?></button><?php endforeach;?></div><?php endforeach;?></nav>
 <div class="workspace-sidebar-foot"><a href="<?= BASE_URL ?>/modules/chat/chat.php"><span data-icon="message" aria-hidden="true"></span>Go to messages</a><div class="workspace-account"><span class="workspace-avatar"><?=htmlspecialchars(strtoupper(substr($user['full_name']?:$user['username'],0,2)))?></span><div><strong><?=htmlspecialchars($user['full_name']?:$user['username'])?></strong><small><?=htmlspecialchars(ucfirst(str_replace('_',' ',$user['role'])))?></small></div><button type="button" data-action="logout" title="Sign out" aria-label="Sign out"><span data-icon="logout" aria-hidden="true"></span></button></div></div>
</aside>
