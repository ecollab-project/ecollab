(function(){'use strict';
const base=()=>window.ECOLLAB?.baseUrl||'';
const csrf=()=>window.ECOLLAB?.csrfToken||document.querySelector('meta[name="csrf-token"]')?.content||'';
const api=base()+'/API/threads/index.php';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function req(url,opt={}){
  const r=await fetch(url,{credentials:'same-origin',...opt,headers:{'X-CSRF-Token':csrf(),...(opt.headers||{})}});
  const d=await r.json().catch(()=>({}));
  if(!r.ok||d.success===false)throw new Error(d.error||d.message||'Request failed');
  return d;
}
async function upload(files){
  const out=[];
  for(const f of Array.from(files||[])){
    if(f.size>10*1024*1024)throw new Error(f.name+': max image size is 10 MB');
    if(!['image/jpeg','image/png','image/gif','image/webp'].includes(f.type))throw new Error(f.name+': only JPG, PNG, GIF or WebP images are allowed');
    const fd=new FormData(); fd.append('image',f);
    out.push(await req(base()+'/API/threads/upload-image.php',{method:'POST',body:fd}));
  }
  return out;
}
function imageHtml(a){
  const src=a.file_url||a.url||a.path||'';
  if(!src)return '';
  return '<img class="tv2-attachment" src="'+esc(src)+'" alt="'+esc(a.file_name||'Thread image')+'" loading="lazy" onerror="this.dataset.failed=1;this.alt=\'Image unavailable\';">';
}
function addFileInput(modalId,inputId,labelId){
  const m=document.getElementById(modalId); if(!m||document.getElementById(inputId))return;
  const body=m.querySelector('.tv2-modal-body'); if(!body)return;
  const row=document.createElement('div'); row.className='tv2-file-row';
  row.innerHTML='<label class="tv2-file-label">🖼️ Add images<input id="'+inputId+'" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden></label><span id="'+labelId+'" class="tv2-file-name">No images selected</span>';
  body.insertBefore(row,body.querySelector('.tv2-scope-grid')||null);
  document.getElementById(inputId).onchange=e=>document.getElementById(labelId).textContent=Array.from(e.target.files||[]).map(x=>x.name).join(', ')||'No images selected';
}
let originalOpenCreate=null,originalCloseCreate=null;
function installCreateHooks(){
  if(typeof window.openThreadCreateModal!=='function'||window.__threadsEnhCreateInstalled)return false;
  originalOpenCreate=window.openThreadCreateModal; originalCloseCreate=window.closeThreadCreateModal;
  window.__threadsEnhCreateInstalled=true;
  window.openThreadCreateModal=function(){originalOpenCreate?.();addFileInput('threadsV2CreateModal','tv2EnhCreateImages','tv2EnhCreateFiles');};
  return true;
}
function normalizedAttachments(items){return Array.from(items||[]).map(x=>({path:x.path||'',url:x.url||'',file_name:x.file_name||'',mime_type:x.mime_type||'',file_size:x.file_size||0}));}
window.createThreadV2=async function(){
  const title=document.getElementById('tv2CreateTitle')?.value.trim();
  const body=document.getElementById('tv2CreateBody')?.value.trim();
  const files=document.getElementById('tv2EnhCreateImages')?.files;
  const scope=window._threadCreateScope||'public',sid=Number(window.ECOLLAB?.currentServerId||0),cid=Number(window.ECOLLAB?.currentChannelId||0);
  if(!title||(!body&&!files?.length)){window.showToast?.('Add a title and either text or an image.','info');return;}
  try{
    const attachments=normalizedAttachments(await upload(files));
    await req(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'create',title,body,scope,server_id:sid,channel_id:cid,attachments})});
    originalCloseCreate?.(); window.showToast?.('Discussion posted.','success');
    window.loadThreadsV2?.(scope==='public'?'all':scope);
  }catch(e){window.showToast?.(e.message,'error');}
};

let parentReply=0,detailPoll=null,detailBusy=false,activeDetailId=0,lastReplySignature='';
function replyAvatar(r){const u=r.author_avatar_url||'',g=r.author_gradient||'#a855f7,#ec4899',n=(r.author_name||r.author_username||'?');const bg=u?'background-image:url(\''+esc(u)+'\');background-size:cover;background-position:center;':'background:linear-gradient(135deg,'+g+');';return '<div class="tv2-avatar" style="'+bg+'">'+(u?'':esc(n[0].toUpperCase()))+'</div>';}
function replyTree(replies,atts,parent=0){
  const children=(replies||[]).filter(r=>Number(r.parent_reply_id||0)===Number(parent));
  return children.map(r=>{
    const imgs=(atts||[]).filter(a=>Number(a.reply_id)===Number(r.id));
    return '<div class="tv2-reply" data-reply-id="'+Number(r.id)+'">'+replyAvatar(r)+'<div class="tv2-reply-body"><div style="font-size:11px;color:var(--text-muted);margin-bottom:4px"><b style="color:var(--text-primary)">'+esc(r.author_name||r.author_username||'Unknown')+'</b> · '+esc(r.created_at||'')+'</div>'+(r.body?'<div class="tv2-reply-text">'+esc(r.body)+'</div>':'')+imgs.map(imageHtml).join('')+'<div class="tv2-reply-actions"><button type="button" class="tv2-reply-btn" data-reply-to="'+Number(r.id)+'">↩ Reply</button></div></div></div>'+(children.length?'<div class="tv2-reply-children">'+replyTree(replies,atts,r.id)+'</div>':'');
  }).join('');
}
function composerHtml(threadId){
  return '<div class="tv2-reply-composer"><div id="tv2EnhReplying" class="tv2-replying" style="display:none"></div><textarea id="tv2ReplyInput" class="tv2-field" placeholder="Share your opinion…"></textarea><div class="tv2-file-row"><label class="tv2-file-label">🖼️ Add images<input id="tv2EnhReplyImages" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden></label><span id="tv2EnhReplyFiles" class="tv2-file-name">No images selected</span></div><button type="button" class="tv2-btn primary" id="tv2PostReplyBtn">Post Reply</button></div>';
}
function renderReplies(replies,atts){
  const wrap=document.getElementById('tv2RepliesEnhanced'); if(!wrap)return;
  const html=replyTree(replies,atts)||'<div class="tv2-empty" style="padding:30px 0">No replies yet.</div>';
  const sig=JSON.stringify((replies||[]).map(r=>[r.id,r.parent_reply_id,r.body,r.created_at,r.score,r.my_vote]).concat((atts||[]).map(a=>[a.id,a.reply_id,a.file_url,a.file_name])));
  if(sig!==lastReplySignature){wrap.innerHTML=html;lastReplySignature=sig;}
}
async function fetchDetailData(id){
  return req(api+'?action=get&id='+encodeURIComponent(id));
}
async function initialDetail(id){
  const body=document.getElementById('tv2DetailBody'),modal=document.getElementById('threadsV2DetailModal');
  if(!body||!modal)return;
  modal.classList.add('open'); activeDetailId=id; lastReplySignature=''; parentReply=0;
  body.innerHTML='<div class="tv2-empty">Loading discussion…</div>';
  try{
    const d=await fetchDetailData(id),t=d.thread;
    document.getElementById('tv2DetailTitle').textContent=t.title;
    body.innerHTML='<div class="tv2-detail"><div class="tv2-meta"><b>'+esc(t.author_name||t.author_username||'Unknown')+'</b> · '+esc(t.created_at||'')+'</div><h2 style="font-size:19px;color:var(--text-primary);margin:0 0 8px">'+esc(t.title)+'</h2>'+(t.body?'<p style="font-size:13px;line-height:1.65;color:var(--text-secondary);white-space:pre-wrap">'+esc(t.body)+'</p>':'')+(d.attachments||[]).filter(a=>!a.reply_id).map(imageHtml).join('')+'<div id="tv2Replies" style="margin-top:14px"><div id="tv2RepliesEnhanced"></div></div>'+composerHtml(t.id)+'</div>';
    renderReplies(d.replies||[],d.attachments||[]);
    const fi=document.getElementById('tv2EnhReplyImages');
    fi&&(fi.onchange=()=>document.getElementById('tv2EnhReplyFiles').textContent=Array.from(fi.files||[]).map(x=>x.name).join(', ')||'No images selected');
  }catch(e){body.innerHTML='<div class="tv2-empty"><strong>Could not load discussion</strong>'+esc(e.message)+'</div>';}
}
window.threadReplyTo=function(id){
  parentReply=Number(id)||0;
  const e=document.getElementById('tv2EnhReplying');
  if(e){e.style.display='block';e.textContent='Replying to comment #'+parentReply+' · click here to cancel';e.onclick=()=>{parentReply=0;e.style.display='none';};}
  document.getElementById('tv2ReplyInput')?.focus();
};
window.threadPostReply=async function(id){
  const input=document.getElementById('tv2ReplyInput'),body=input?.value.trim(),files=document.getElementById('tv2EnhReplyImages')?.files;
  if(!body&&!files?.length){window.showToast?.('Add text, an image, or both.','info');return;}
  try{
    const attachments=normalizedAttachments(await upload(files));
    await req(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reply',thread_id:id,parent_reply_id:parentReply,body,attachments})});
    parentReply=0; input.value=''; const fi=document.getElementById('tv2EnhReplyImages'); if(fi)fi.value='';
    const f=document.getElementById('tv2EnhReplyFiles');if(f)f.textContent='No images selected';
    const e=document.getElementById('tv2EnhReplying');if(e)e.style.display='none';
    const d=await fetchDetailData(id);renderReplies(d.replies||[],d.attachments||[]);
  }catch(e){window.showToast?.(e.message,'error');}
};
function installDetailHooks(){
  if(typeof window.openThreadDetail!=='function'||window.__threadsEnhDetailInstalled)return false;
  const originalDetail=window.openThreadDetail; window.__threadsEnhDetailInstalled=true;
  window.openThreadDetail=async function(id){
    localStorage.setItem('ecollab.threads.activeThread',String(id)); clearInterval(detailPoll); await initialDetail(id);
    detailPoll=setInterval(async()=>{
      if(!document.getElementById('threadsV2DetailModal')?.classList.contains('open')||detailBusy||!activeDetailId)return;
      detailBusy=true; try{const d=await fetchDetailData(activeDetailId);renderReplies(d.replies||[],d.attachments||[]);}catch(e){} finally{detailBusy=false;}
    },1000);
  };
  const oldClose=window.closeThreadDetail;
  window.closeThreadDetail=function(){clearInterval(detailPoll);detailPoll=null;activeDetailId=0;parentReply=0;localStorage.removeItem('ecollab.threads.activeThread');oldClose?.();};
  return true;
}
function installReplyDelegation(){
  if(window.__threadsEnhReplyDelegation)return;
  window.__threadsEnhReplyDelegation=true;
  document.addEventListener('click',e=>{
    const btn=e.target.closest?.('[data-reply-to]');
    if(btn){e.preventDefault();e.stopPropagation();window.threadReplyTo(Number(btn.dataset.replyTo));}
    if(e.target.closest?.('#tv2PostReplyBtn')){e.preventDefault();window.threadPostReply(activeDetailId);}
  });
}
let feedPoll=null,originalLoadFeed=null,feedScope='all';
function installFeedPolling(){
  if(typeof window.loadThreadsV2!=='function'||window.__threadsEnhFeedInstalled)return false;
  originalLoadFeed=window.loadThreadsV2; window.__threadsEnhFeedInstalled=true;
  window.loadThreadsV2=function(scope='all'){feedScope=scope;return originalLoadFeed(scope);};
  clearInterval(feedPoll);
  feedPoll=setInterval(async()=>{
    const view=document.getElementById('threadsV2View');
    if(!view||view.offsetParent===null||document.getElementById('threadsV2DetailModal')?.classList.contains('open'))return;
    try{await originalLoadFeed(feedScope);}catch(e){}
  },1000);
  return true;
}
function boot(){
  if(!document.getElementById('threadsEnhStyles')){
    const s=document.createElement('style');s.id='threadsEnhStyles';s.textContent='.tv2-reply-children{margin-left:28px;border-left:2px solid var(--border);padding-left:12px}.tv2-attachment{display:block;max-width:min(560px,100%);max-height:420px;margin-top:8px;border-radius:9px;border:1px solid var(--border);object-fit:contain}.tv2-file-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px}.tv2-file-label{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);background:var(--bg-tertiary);color:var(--text-secondary);border-radius:8px;padding:7px 10px;cursor:pointer;font:600 11px Inter,sans-serif}.tv2-file-name{font-size:10px;color:var(--text-muted);max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tv2-reply-btn{border:0;background:transparent;color:#a78bfa;cursor:pointer;font:600 11px Inter,sans-serif;padding:3px 0}.tv2-reply-composer{margin-top:14px;padding-top:12px;border-top:1px solid var(--border)}.tv2-replying{font-size:11px;color:#a78bfa;background:rgba(168,85,247,.08);border:1px solid rgba(168,85,247,.18);border-radius:7px;padding:7px 9px;margin-bottom:8px;cursor:pointer}';
    document.head.appendChild(s);
  }
  installCreateHooks();installReplyDelegation();
  if(!installDetailHooks()||!installFeedPolling())setTimeout(boot,100);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();