'use strict';
(() => {
  const dock=document.getElementById('workspaceSwitcher'),logo=document.getElementById('workspaceLogo'),list=document.getElementById('workspaceServerList');
  if(!dock||!logo||!list)return;
  const desktop=()=>matchMedia('(min-width:861px)').matches;
  const account=document.getElementById('workspaceAccount'),home=document.getElementById('workspaceAccountHome'),accountDock=document.getElementById('workspaceAccountDock');
  const settings=document.getElementById('workspaceSettings'),settingsDock=document.getElementById('workspaceSettingsDock'),controls=account?.querySelector('.footer-controls');
  function place(){
    if(desktop()){if(account&&accountDock)accountDock.appendChild(account);if(settings&&settingsDock)settingsDock.appendChild(settings);}
    else{if(account&&home)home.after(account);if(settings&&controls)controls.appendChild(settings);}
  }
  function overflow(){
    dock.querySelectorAll('[data-scroll]').forEach(button=>{
      button.hidden=!desktop()||list.scrollWidth<=list.clientWidth+2;
      button.disabled=button.dataset.scroll==='-1'?list.scrollLeft<=1:list.scrollLeft+list.clientWidth>=list.scrollWidth-1;
    });
  }
  dock.querySelectorAll('[data-scroll]').forEach(button=>button.addEventListener('click',()=>list.scrollBy({left:Number(button.dataset.scroll)*Math.max(80,list.clientWidth*.75),behavior:matchMedia('(prefers-reduced-motion:reduce)').matches?'auto':'smooth'})));
  list.addEventListener('scroll',overflow,{passive:true});
  list.addEventListener('keydown',e=>{
    const items=[...list.querySelectorAll('.workspace-icon')],index=items.indexOf(e.target);
    if(index<0||!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
    e.preventDefault();
    const target=e.key==='Home'?items[0]:e.key==='End'?items.at(-1):items[(index+(e.key==='ArrowRight'?1:-1)+items.length)%items.length];
    target.focus({preventScroll:true});
    if(desktop())list.scrollLeft=Math.max(0,target.offsetLeft-list.offsetLeft-list.clientWidth/2+target.offsetWidth/2);
  });
  const menu=document.createElement('div');menu.id='workspaceLogoMenu';menu.className='workspace-logo-menu';menu.setAttribute('role','menu');menu.setAttribute('aria-label','eCollab');menu.hidden=true;
  menu.innerHTML='<button type="button" role="menuitem" data-logo-action="dashboard">Dashboard</button><button type="button" role="menuitem" data-logo-action="logout">Logout</button>';
  document.body.appendChild(menu);logo.setAttribute('aria-controls',menu.id);
  const items=[...menu.querySelectorAll('button')];
  function close(restore=false){menu.hidden=true;logo.setAttribute('aria-expanded','false');if(restore)logo.focus();}
  function open(){
    menu.hidden=false;logo.setAttribute('aria-expanded','true');
    const box=logo.getBoundingClientRect();menu.style.left=Math.max(8,Math.min(innerWidth-menu.offsetWidth-8,box.left))+'px';
    menu.style.top=(desktop()?Math.max(8,box.top-menu.offsetHeight-8):box.bottom+8)+'px';items[0].focus();
  }
  logo.addEventListener('click',()=>menu.hidden?open():close(true));
  logo.addEventListener('keydown',e=>{if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();open();if(e.key==='ArrowUp')items.at(-1).focus();}});
  menu.addEventListener('click',e=>{const action=e.target.closest('[data-logo-action]')?.dataset.logoAction;if(!action)return;close();if(action==='dashboard')window.goToDashboard?.();else window.handleLogout?.();});
  menu.addEventListener('keydown',e=>{if(e.key==='Tab'){close();return;}if(!['ArrowDown','ArrowUp','Home','End'].includes(e.key))return;e.preventDefault();const index=items.indexOf(document.activeElement);(e.key==='Home'?items[0]:e.key==='End'?items.at(-1):items[(index+(e.key==='ArrowDown'?1:-1)+items.length)%items.length]).focus();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!menu.hidden){e.preventDefault();close(true);}});
  document.addEventListener('pointerdown',e=>{if(!menu.hidden&&!menu.contains(e.target)&&!logo.contains(e.target))close();});
  window.addEventListener('resize',()=>{close();place();overflow();});
  if(window.ResizeObserver)new ResizeObserver(overflow).observe(list);
  place();overflow();
})();
