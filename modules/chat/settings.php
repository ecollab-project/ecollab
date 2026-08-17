<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$csrfToken = AuthMiddleware::csrfToken();

$role = strtolower((string)($user['role'] ?? 'student'));
$dashboard = match ($role) {
    'admin', 'super_admin', 'moderator' => BASE_URL . '/modules/admin/dashboard.php',
    'facilitator' => BASE_URL . '/modules/facilitator/dashboard.php',
    default => BASE_URL . '/modules/student/dashboard.php',
};

$displayName = (string)($user['full_name'] ?? $user['username'] ?? 'User');
$username = (string)($user['username'] ?? '');
$email = (string)($user['email'] ?? '');
$bio = (string)($user['bio'] ?? '');
$gradient = (string)($user['avatar_color_gradient'] ?? '#a855f7,#ec4899');
$colors = explode(',', $gradient);
$c1 = htmlspecialchars($colors[0] ?? '#a855f7', ENT_QUOTES, 'UTF-8');
$c2 = htmlspecialchars($colors[1] ?? $colors[0] ?? '#ec4899', ENT_QUOTES, 'UTF-8');
$initial = strtoupper(substr($displayName !== '' ? $displayName : $username, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Ecollab — User Settings</title>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/desktop/variables.css">
  <style>
    :root{--s-bg:#0b0e15;--s-panel:#111724;--s-panel2:#171e2d;--s-hover:#20283a;--s-border:rgba(255,255,255,.07);--s-text:#f1f5f9;--s-muted:#8b98ad;--s-purple:#a855f7;--s-pink:#ec4899;--s-danger:#ed4245}
    *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--s-bg);color:var(--s-text)}
    body{display:flex;min-height:100vh;overflow:hidden}.settings-shell{display:flex;width:100vw;height:100vh}
    .settings-nav{width:255px;flex:0 0 255px;background:#0d111a;border-right:1px solid var(--s-border);padding:24px 14px;overflow:auto}.settings-brand{display:flex;align-items:center;gap:10px;padding:0 10px 24px;font-size:17px;font-weight:800}.brand-dot{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,var(--s-purple),var(--s-pink));display:grid;place-items:center;color:#fff;font-weight:900}
    .nav-group{margin:18px 0 7px;padding:0 10px;color:#63718a;font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}.nav-item{display:flex;align-items:center;gap:10px;padding:10px;border-radius:8px;color:#a9b4c7;font-size:13px;font-weight:600;cursor:pointer;margin:2px 0;transition:.15s}.nav-item:hover{background:rgba(255,255,255,.045);color:#fff}.nav-item.active{background:rgba(168,85,247,.18);color:#d8b4fe}.nav-icon{width:20px;text-align:center;font-size:15px}.nav-divider{height:1px;background:var(--s-border);margin:16px 10px}.nav-bottom{margin-top:18px}.logout{color:#f87171}.logout:hover{background:rgba(239,68,68,.1);color:#fca5a5}
    .settings-main{flex:1;min-width:0;overflow:auto}.settings-inner{width:min(980px,calc(100% - 64px));margin:0 auto;padding:38px 0 70px}.settings-title{font-size:26px;font-weight:800;margin-bottom:25px}.settings-section{display:none}.settings-section.active{display:block}.section-title{font-size:20px;font-weight:800;margin:0 0 7px}.section-desc{font-size:13px;color:var(--s-muted);margin:0 0 22px;line-height:1.55}
    .card{background:var(--s-panel);border:1px solid var(--s-border);border-radius:12px;margin-bottom:18px;overflow:hidden}.card-head{padding:18px 20px;border-bottom:1px solid var(--s-border);font-weight:750;font-size:14px}.card-body{padding:20px}.account-hero{display:flex;align-items:center;gap:16px;padding:20px;background:linear-gradient(135deg,rgba(168,85,247,.12),rgba(236,72,153,.07));border:1px solid var(--s-border);border-radius:12px;margin-bottom:20px}.avatar{width:70px;height:70px;border-radius:50%;background:linear-gradient(135deg,<?= $c1 ?>,<?= $c2 ?>);display:grid;place-items:center;font-size:28px;font-weight:800;box-shadow:0 0 0 5px rgba(255,255,255,.05)}.hero-name{font-size:19px;font-weight:800}.hero-user{font-size:12px;color:var(--s-muted);margin-top:3px}.role-badge{display:inline-flex;margin-top:8px;padding:4px 8px;border-radius:999px;background:rgba(168,85,247,.15);border:1px solid rgba(168,85,247,.25);color:#d8b4fe;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.field{display:flex;flex-direction:column;gap:7px}.field.full{grid-column:1/-1}.field label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#8e9bb0}.field input,.field textarea,.field select{width:100%;border:1px solid #293347;background:#171f2f;color:#e8edf5;border-radius:8px;padding:11px 12px;outline:none;font:inherit;font-size:13px}.field textarea{min-height:100px;resize:vertical}.field input:focus,.field textarea:focus,.field select:focus{border-color:rgba(168,85,247,.65);box-shadow:0 0 0 3px rgba(168,85,247,.1)}.field input:disabled{opacity:.55;cursor:not-allowed}.hint{font-size:11px;color:#69778e}.actions{display:flex;justify-content:flex-end;gap:9px;margin-top:18px}.btn{border:0;border-radius:8px;padding:10px 16px;font-size:12px;font-weight:800;cursor:pointer}.btn.secondary{background:#202a3d;color:#aeb9cb;border:1px solid #2d3950}.btn.primary{background:linear-gradient(135deg,var(--s-purple),var(--s-pink));color:#fff;box-shadow:0 6px 20px rgba(168,85,247,.2)}.btn.danger{background:rgba(237,66,69,.12);color:#f87171;border:1px solid rgba(237,66,69,.25)}.btn:disabled{opacity:.55;cursor:default}
    .setting-row{display:flex;align-items:center;gap:16px;padding:17px 20px;border-bottom:1px solid var(--s-border)}.setting-row:last-child{border-bottom:0}.setting-copy{flex:1}.setting-name{font-size:13px;font-weight:750}.setting-help{font-size:11px;color:var(--s-muted);line-height:1.45;margin-top:4px}.switch{width:42px;height:24px;border-radius:99px;background:#293347;border:1px solid #39465d;position:relative;cursor:pointer;flex:0 0 auto}.switch::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#8b98ad;transition:.16s}.switch.on{background:linear-gradient(135deg,var(--s-purple),var(--s-pink));border-color:transparent}.switch.on::after{left:21px;background:#fff}.radio-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.radio-card{padding:14px;border:1px solid #293347;border-radius:10px;background:#151c2a;cursor:pointer}.radio-card.active{border-color:rgba(168,85,247,.65);background:rgba(168,85,247,.1)}.radio-card b{font-size:12px}.radio-card span{display:block;color:var(--s-muted);font-size:10px;margin-top:4px}.range-wrap{display:flex;align-items:center;gap:12px}.range-wrap input{flex:1;accent-color:var(--s-purple)}.range-value{width:38px;text-align:right;font-size:11px;color:#aeb9cb}.status{min-height:18px;margin-top:10px;font-size:12px;color:#8b98ad}.status.success{color:#4ade80}.status.error{color:#f87171}.danger-zone{border-color:rgba(237,66,69,.25)}
    @media(max-width:760px){body{overflow:auto}.settings-shell{height:auto;min-height:100vh;display:block}.settings-nav{width:100%;border-right:0;border-bottom:1px solid var(--s-border);padding:12px}.settings-brand{padding-bottom:10px}.nav-group,.nav-divider,.nav-bottom{display:none}.nav-item{display:none}.nav-item.active{display:flex}.settings-main{overflow:visible}.settings-inner{width:calc(100% - 28px);padding:24px 0 50px}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.radio-grid{grid-template-columns:1fr}.settings-title{font-size:22px}}
  </style>
</head>
<body>
<div class="settings-shell">
  <aside class="settings-nav">
    <div class="settings-brand"><div class="brand-dot">E</div><span>Ecollab</span></div>
    <div class="nav-group">User Settings</div>
    <div class="nav-item active" data-section="account"><span class="nav-icon">👤</span>My Account</div>
    <div class="nav-item" data-section="profile"><span class="nav-icon">✨</span>Profiles</div>
    <div class="nav-item" data-section="privacy"><span class="nav-icon">🔒</span>Privacy & Safety</div>
    <div class="nav-item" data-section="connections"><span class="nav-icon">🤝</span>Connections</div>
    <div class="nav-group">App Settings</div>
    <div class="nav-item" data-section="appearance"><span class="nav-icon">🎨</span>Appearance</div>
    <div class="nav-item" data-section="notifications"><span class="nav-icon">🔔</span>Notifications</div>
    <div class="nav-item" data-section="voice"><span class="nav-icon">🎙️</span>Voice & Audio</div>
    <div class="nav-item" data-section="accessibility"><span class="nav-icon">♿</span>Accessibility</div>
    <div class="nav-divider"></div>
    <div class="nav-item" onclick="location.href='<?= htmlspecialchars($dashboard, ENT_QUOTES, 'UTF-8') ?>'"><span class="nav-icon">🏠</span>Dashboard</div>
    <div class="nav-item" onclick="location.href='<?= BASE_URL ?>/modules/chat/chat.php'"><span class="nav-icon">💬</span>Back to Chat</div>
    <div class="nav-item logout" onclick="location.href='<?= BASE_URL ?>/modules/auth/logout.php'"><span class="nav-icon">↪</span>Log Out</div>
  </aside>

  <main class="settings-main">
    <div class="settings-inner">
      <div class="settings-title" id="pageTitle">My Account</div>

      <section class="settings-section active" id="section-account">
        <div class="account-hero"><div class="avatar" id="accountAvatar"><?= htmlspecialchars($initial) ?></div><div><div class="hero-name" id="accountHeroName"><?= htmlspecialchars($displayName) ?></div><div class="hero-user">@<?= htmlspecialchars($username) ?></div><div class="role-badge"><?= htmlspecialchars($role) ?></div></div></div>
        <div class="card"><div class="card-head">Account Information</div><div class="card-body">
          <form id="accountForm"><div class="form-grid">
            <div class="field"><label>Display Name</label><input id="displayName" name="full_name" maxlength="80" value="<?= htmlspecialchars($displayName) ?>" required><span class="hint">This is the name other Ecollab members see.</span></div>
            <div class="field"><label>Username</label><input value="<?= htmlspecialchars($username) ?>" disabled><span class="hint">Your unique account identifier.</span></div>
            <div class="field full"><label>Email</label><input value="<?= htmlspecialchars($email) ?>" disabled><span class="hint">Your verified account email cannot be changed from this screen.</span></div>
            <div class="field full"><label>Bio</label><textarea id="bio" name="bio" maxlength="500" placeholder="Tell other learners a little about yourself…"><?= htmlspecialchars($bio) ?></textarea></div>
          </div><div class="actions"><button class="btn secondary" type="button" id="resetAccount">Reset</button><button class="btn primary" type="submit" id="saveAccount">Save Changes</button></div><div class="status" id="accountStatus"></div></form>
        </div></div>
        <div class="card"><div class="card-head">Security</div>
          <div class="setting-row"><div class="setting-copy"><div class="setting-name">Password</div><div class="setting-help">Keep your account protected with a strong password.</div></div><button class="btn secondary" type="button" onclick="alert('Use the password reset flow from the login page to change your password.')">Change Password</button></div>
          <div class="setting-row"><div class="setting-copy"><div class="setting-name">Two-Factor Authentication</div><div class="setting-help">Add another layer of protection to your Ecollab account.</div></div><div class="switch" data-key="twoFactor"></div></div>
        </div>
      </section>

      <section class="settings-section" id="section-profile"><h2 class="section-title">Profiles</h2><p class="section-desc">Customize what other students and facilitators see when they open your profile.</p>
        <div class="card"><div class="card-head">Profile Appearance</div><div class="card-body"><div class="form-grid"><div class="field"><label>Avatar Gradient</label><select id="avatarGradient"><option value="#a855f7,#ec4899">Purple / Pink</option><option value="#3b82f6,#a855f7">Blue / Purple</option><option value="#06b6d4,#3b82f6">Cyan / Blue</option><option value="#10b981,#06b6d4">Green / Cyan</option><option value="#f97316,#ec4899">Orange / Pink</option></select></div><div class="field"><label>Profile Visibility</label><select id="profileVisibility"><option value="everyone">Everyone in Ecollab</option><option value="servers">People who share a server with me</option><option value="connections">Connections only</option></select></div></div><div class="actions"><button class="btn primary" id="saveProfileAppearance">Save Profile Settings</button></div></div></div>
      </section>

      <section class="settings-section" id="section-privacy"><h2 class="section-title">Privacy & Safety</h2><p class="section-desc">Control who can contact you and what activity information Ecollab exposes.</p>
        <div class="card"><div class="setting-row"><div class="setting-copy"><div class="setting-name">Allow Connection Requests</div><div class="setting-help">Let other members send you connection requests.</div></div><div class="switch on" data-key="connectionRequests"></div></div>
          <div class="setting-row"><div class="setting-copy"><div class="setting-name">Allow Direct Messages</div><div class="setting-help">Allow members to start a direct conversation with you.</div></div><div class="switch on" data-key="directMessages"></div></div>
          <div class="setting-row"><div class="setting-copy"><div class="setting-name">Show Activity Status</div><div class="setting-help">Show when you are online, idle, or active in study sessions.</div></div><div class="switch on" data-key="activityStatus"></div></div>
          <div class="setting-row"><div class="setting-copy"><div class="setting-name">Read Receipts</div><div class="setting-help">Let conversation partners know when a message has been read.</div></div><div class="switch on" data-key="readReceipts"></div></div>
          <div class="setting-row"><div class="setting-copy"><div class="setting-name">Secret Conversation Screenshot Alerts</div><div class="setting-help">Notify the other participant when the browser reports a screenshot event in a secret conversation.</div></div><div class="switch on" data-key="screenshotAlerts"></div></div>
        </div>
      </section>

      <section class="settings-section" id="section-connections"><h2 class="section-title">Connections</h2><p class="section-desc">Manage how Ecollab connects your account to other services and people.</p>
        <div class="card"><div class="setting-row"><div class="setting-copy"><div class="setting-name">Connected Study Partners</div><div class="setting-help">Your connections are used by peer matching and direct messaging.</div></div><button class="btn secondary" onclick="location.href='<?= BASE_URL ?>/modules/chat/chat.php'">Open Chat</button></div><div class="setting-row"><div class="setting-copy"><div class="setting-name">AI Peer Matching</div><div class="setting-help">Use your study profile, interests, goals, and shared servers to improve suggestions.</div></div><div class="switch on" data-key="aiMatching"></div></div></div>
      </section>

      <section class="settings-section" id="section-appearance"><h2 class="section-title">Appearance</h2><p class="section-desc">Change how Ecollab looks on this device. These preferences are saved locally.</p>
        <div class="card"><div class="card-head">Theme</div><div class="card-body"><div class="radio-grid"><div class="radio-card active" data-theme="dark"><b>Dark</b><span>Default Ecollab dark interface</span></div><div class="radio-card" data-theme="light"><b>Light</b><span>Bright interface for daytime use</span></div><div class="radio-card" data-theme="system"><b>System</b><span>Follow your operating system</span></div></div></div></div>
        <div class="card"><div class="setting-row"><div class="setting-copy"><div class="setting-name">Compact Mode</div><div class="setting-help">Reduce spacing in lists and chat.</div></div><div class="switch" data-key="compactMode"></div></div><div class="setting-row"><div class="setting-copy"><div class="setting-name">Reduce Motion</div><div class="setting-help">Reduce animations and transitions.</div></div><div class="switch" data-key="reduceMotion"></div></div></div>
      </section>

      <section class="settings-section" id="section-notifications"><h2 class="section-title">Notifications</h2><p class="section-desc">Choose what Ecollab should notify you about.</p>
        <div class="card"><div class="setting-row"><div class="setting-copy"><div class="setting-name">Desktop Notifications</div><div class="setting-help">Show notifications outside the browser tab.</div></div><div class="switch on" data-key="desktopNotifications"></div></div><div class="setting-row"><div class="setting-copy"><div class="setting-name">Direct Message Notifications</div><div class="setting-help">Notify me when I receive a new direct message.</div></div><div class="switch on" data-key="dmNotifications"></div></div><div class="setting-row"><div class="setting-copy"><div class="setting-name">Mentions</div><div class="setting-help">Notify me when another member mentions me.</div></div><div class="switch on" data-key="mentionNotifications"></div></div><div class="setting-row"><div class="setting-copy"><div class="setting-name">Match Suggestions</div><div class="setting-help">Notify me when AI peer matching finds a strong match.</div></div><div class="switch on" data-key="matchNotifications"></div></div></div>
      </section>

      <section class="settings-section" id="section-voice"><h2 class="section-title">Voice & Audio</h2><p class="section-desc">Configure your microphone and speaker behavior for voice channels and calls.</p>
        <div class="card"><div class="card-body"><div class="form-grid"><div class="field"><label>Input Device</label><select id="inputDevice"><option>Default Microphone</option></select></div><div class="field"><label>Output Device</label><select id="outputDevice"><option>Default Speakers</option></select></div></div><div style="height:18px"></div><div class="field"><label>Input Volume</label><div class="range-wrap"><input type="range" min="0" max="100" value="80" data-range="inputVolume"><span class="range-value" data-range-value="inputVolume">80%</span></div></div><div style="height:16px"></div><div class="field"><label>Output Volume</label><div class="range-wrap"><input type="range" min="0" max="100" value="100" data-range="outputVolume"><span class="range-value" data-range-value="outputVolume">100%</span></div></div></div></div>
        <div class="card"><div class="setting-row"><div class="setting-copy"><div class="setting-name">Noise Suppression</div><div class="setting-help">Reduce keyboard, fan, and room noise during voice chat.</div></div><div class="switch on" data-key="noiseSuppression"></div></div><div class="setting-row"><div class="setting-copy"><div class="setting-name">Echo Cancellation</div><div class="setting-help">Prevent your speakers from feeding back into your microphone.</div></div><div class="switch on" data-key="echoCancellation"></div></div></div>
      </section>

      <section class="settings-section" id="section-accessibility"><h2 class="section-title">Accessibility</h2><p class="section-desc">Make the interface easier to read and use.</p>
        <div class="card"><div class="setting-row"><div class="setting-copy"><div class="setting-name">Reduce Motion</div><div class="setting-help">Minimize interface animation.</div></div><div class="switch" data-key="reduceMotion"></div></div><div class="setting-row"><div class="setting-copy"><div class="setting-name">High Contrast</div><div class="setting-help">Increase borders and text contrast.</div></div><div class="switch" data-key="highContrast"></div></div><div class="setting-row"><div class="setting-copy"><div class="setting-name">Larger Chat Text</div><div class="setting-help">Increase readable text size in conversations.</div></div><div class="switch" data-key="largerText"></div></div></div>
      </section>
    </div>
  </main>
</div>
<script>
window.ECOLLAB=window.ECOLLAB||{};
window.ECOLLAB.baseUrl=<?= json_encode(BASE_URL) ?>;
window.ECOLLAB.csrfToken=<?= json_encode($csrfToken) ?>;
window.ECOLLAB.userId=<?= (int)$user['id'] ?>;
window.ECOLLAB.dashboard=<?= json_encode($dashboard) ?>;
</script>
<script>
(function(){
  const key='ecollab-settings-v1';
  const defaults={connectionRequests:true,directMessages:true,activityStatus:true,readReceipts:true,screenshotAlerts:true,aiMatching:true,desktopNotifications:true,dmNotifications:true,mentionNotifications:true,matchNotifications:true,compactMode:false,reduceMotion:false,noiseSuppression:true,echoCancellation:true,highContrast:false,largerText:false,twoFactor:false};
  let state={...defaults};try{state={...state,...JSON.parse(localStorage.getItem(key)||'{}')} }catch(e){}
  const save=()=>localStorage.setItem(key,JSON.stringify(state));
  const toast=(m,ok=true)=>{const e=document.getElementById('accountStatus');if(e){e.textContent=m;e.className='status '+(ok?'success':'error');setTimeout(()=>{if(e.textContent===m)e.textContent=''},3000)}};
  const titleMap={account:'My Account',profile:'Profiles',privacy:'Privacy & Safety',connections:'Connections',appearance:'Appearance',notifications:'Notifications',voice:'Voice & Audio',accessibility:'Accessibility'};
  document.querySelectorAll('.nav-item[data-section]').forEach(item=>item.addEventListener('click',()=>{const s=item.dataset.section;document.querySelectorAll('.nav-item[data-section]').forEach(x=>x.classList.toggle('active',x===item));document.querySelectorAll('.settings-section').forEach(x=>x.classList.toggle('active',x.id==='section-'+s));document.getElementById('pageTitle').textContent=titleMap[s]||s;history.replaceState(null,'','#'+s)}));
  const hash=location.hash.replace('#','');if(titleMap[hash])document.querySelector('.nav-item[data-section="'+hash+'"]')?.click();
  document.querySelectorAll('.switch[data-key]').forEach(sw=>{const k=sw.dataset.key;sw.classList.toggle('on',!!state[k]);sw.addEventListener('click',()=>{state[k]=!state[k];sw.classList.toggle('on',state[k]);save()})});
  document.querySelectorAll('[data-range]').forEach(r=>{const k=r.dataset.range,v=document.querySelector('[data-range-value="'+k+'"]');const saved=localStorage.getItem('ecollab-'+k);if(saved!==null)r.value=saved;if(v)v.textContent=r.value+'%';r.addEventListener('input',()=>{if(v)v.textContent=r.value+'%';localStorage.setItem('ecollab-'+k,r.value)})});
  document.querySelectorAll('.radio-card[data-theme]').forEach(c=>c.addEventListener('click',()=>{document.querySelectorAll('.radio-card[data-theme]').forEach(x=>x.classList.remove('active'));c.classList.add('active');localStorage.setItem('ecollab-theme',c.dataset.theme);applyTheme(c.dataset.theme)}));
  function applyTheme(t){if(t==='light'){document.documentElement.style.setProperty('--s-bg','#eef1f6');document.documentElement.style.setProperty('--s-panel','#fff');document.documentElement.style.setProperty('--s-panel2','#f3f5f9');document.documentElement.style.setProperty('--s-text','#172033');document.documentElement.style.setProperty('--s-muted','#5d6a7f');document.documentElement.style.setProperty('--s-border','rgba(15,23,42,.1)')}else{document.documentElement.style.removeProperty('--s-bg');document.documentElement.style.removeProperty('--s-panel');document.documentElement.style.removeProperty('--s-panel2');document.documentElement.style.removeProperty('--s-text');document.documentElement.style.removeProperty('--s-muted');document.documentElement.style.removeProperty('--s-border')}}
  const theme=localStorage.getItem('ecollab-theme')||'dark';document.querySelector('.radio-card[data-theme="'+theme+'"]')?.classList.add('active');applyTheme(theme);
  document.getElementById('resetAccount').onclick=()=>{document.getElementById('displayName').value=<?= json_encode($displayName) ?>;document.getElementById('bio').value=<?= json_encode($bio) ?>;toast('Changes reset.',true)};
  document.getElementById('accountForm').addEventListener('submit',async e=>{e.preventDefault();const btn=document.getElementById('saveAccount');btn.disabled=true;try{const r=await fetch(window.ECOLLAB.baseUrl+'/API/profile/update-profile.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.ECOLLAB.csrfToken},body:JSON.stringify({full_name:document.getElementById('displayName').value.trim(),bio:document.getElementById('bio').value.trim(),avatar_color_gradient:document.getElementById('avatarGradient')?.value||<?= json_encode($gradient) ?>})});const d=await r.json().catch(()=>({}));if(!r.ok||d.success===false)throw new Error(d.error||'Could not save changes');document.getElementById('accountHeroName').textContent=d.profile.full_name;document.getElementById('accountAvatar').textContent=(d.profile.full_name||'U').charAt(0).toUpperCase();toast('Changes saved successfully.',true)}catch(err){toast(err.message||'Could not save changes.',false)}finally{btn.disabled=false}});
  document.getElementById('saveProfileAppearance').onclick=()=>{const g=document.getElementById('avatarGradient').value;localStorage.setItem('ecollab-profile-gradient',g);document.getElementById('accountAvatar').style.background='linear-gradient(135deg,'+g.split(',')[0]+','+(g.split(',')[1]||g.split(',')[0])+')';toast('Profile appearance saved.',true)};
  navigator.mediaDevices?.enumerateDevices?.().then(devices=>{const ins=document.getElementById('inputDevice'),outs=document.getElementById('outputDevice');devices.filter(d=>d.kind==='audioinput').forEach(d=>{const o=document.createElement('option');o.value=d.deviceId;o.textContent=d.label||'Microphone';ins.appendChild(o)});devices.filter(d=>d.kind==='audiooutput').forEach(d=>{const o=document.createElement('option');o.value=d.deviceId;o.textContent=d.label||'Speaker';outs.appendChild(o)})}).catch(()=>{});
})();
</script>
</body>
</html>
