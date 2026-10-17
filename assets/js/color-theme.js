/* Account-wide color palettes, independent of Dark / Light / System mode. */
(() => {
 'use strict';
 const root=document.documentElement,source=document.getElementById('ecColorPalettes');
 if(!source||window.EcollabColors)return;
 const palettes=JSON.parse(source.textContent),byId=new Map(palettes.map(p=>[p.id,p]));
 let selected='forest-mint',saving=false;
 const media=window.matchMedia?.('(prefers-color-scheme: light)');
 function apply(settings={}){
  selected=byId.has(settings.color_palette)?settings.color_palette:'forest-mint';
  root.dataset.colorPalette=selected;
  document.querySelectorAll('[data-color-choice]').forEach(b=>{const active=b.dataset.colorChoice===selected;b.setAttribute('aria-pressed',String(active));b.classList.toggle('selected',active);});
 }
 function mode(settings){const t=settings.theme||'dark';root.dataset.theme=t==='light'||t==='system'&&media?.matches?'light':'dark';}
 function base(){return window.ECOLLAB?.baseUrl||window.ECOLLAB_BASE||window.ECOLLAB_CALLS_CONFIG?.baseUrl||window.BASE_URL||'';}
 async function choose(id){
  if(saving||!byId.has(id))return;
  const previous=selected,status=document.getElementById('ecPaletteStatus');saving=true;apply({color_palette:id});
  const buttons=[...document.querySelectorAll('[data-color-choice]')];buttons.forEach(b=>b.disabled=true);if(status)status.textContent='Saving '+byId.get(id).name+'…';
  try{
   const token=document.querySelector('meta[name="csrf-token"]')?.content||window.ECOLLAB?.csrfToken||'';
   const response=await fetch(base()+'/API/profile/settings.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({color_palette:id})});
   const data=await response.json();if(!response.ok||data.success===false||data.settings?.color_palette!==id)throw new Error(data.error||'Could not save this palette.');
   window._userSettings={...window._userSettings,...data.settings};apply(data.settings);window.dispatchEvent(new CustomEvent('ecollab:settings-applied',{detail:window._userSettings}));
   if(status)status.textContent=byId.get(id).name+' saved to your account.';
  }catch(error){apply({color_palette:previous});if(status)status.textContent=error.message||'Could not save this palette. Try again.';}
  finally{saving=false;buttons.forEach(b=>b.disabled=false);}
 }
 function picker(){const host=document.getElementById('ecPaletteGrid');if(!host)return;
  for(const palette of palettes){
   const b=document.createElement('button');b.type='button';b.className='ec-palette-choice';b.dataset.colorChoice=palette.id;b.setAttribute('aria-pressed','false');
   const swatches=document.createElement('span');swatches.className='ec-palette-swatches';swatches.setAttribute('aria-hidden','true');
   for(const color of palette.swatches){const swatch=document.createElement('span');swatch.style.backgroundColor=color;swatches.append(swatch);}
   const name=document.createElement('strong');name.textContent=palette.name;const note=document.createElement('small');note.textContent=palette.id==='forest-mint'?'Ecollab default':palette.swatches.map(x=>x.toUpperCase()).join(' · ');
   b.append(swatches,name,note);b.addEventListener('click',()=>choose(palette.id));host.append(b);
  }
 }
 window.EcollabColors={apply,choose,palettes};picker();apply(window._userSettings);
 window.addEventListener('ecollab:settings-applied',event=>apply(event.detail));
 media?.addEventListener?.('change',()=>{if(window._userSettings?.theme==='system')mode(window._userSettings);});
 // Existing applicator also handles accessibility/audio options; avoid a duplicate request.
 if(!document.querySelector('script[src*="accessibility-apply.js"]')&&document.querySelector('meta[name="csrf-token"]')){
  fetch(base()+'/API/profile/settings.php',{credentials:'same-origin'}).then(r=>r.ok?r.json():null).then(data=>{if(!data?.settings)return;window._userSettings={...window._userSettings,...data.settings};mode(data.settings);apply(data.settings);window.dispatchEvent(new CustomEvent('ecollab:settings-applied',{detail:window._userSettings}));}).catch(()=>{});
 }
})();
