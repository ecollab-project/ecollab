/* Progressive layout support for dynamic tables and variable editor headers. */
'use strict';
(() => {
  function enhanceTables(){
    for(const table of document.querySelectorAll('table')){
      if(table.closest('.device-table-scroll,.table-wrap,.responsive-table,.lw-table-wrap,.workspace-table-wrap,.workspace-table-scroll,.act-table-wrap'))continue;
      const parent=table.parentElement;if(!parent)continue;
      const overflow=getComputedStyle(parent).overflowX;
      if(overflow==='auto'||overflow==='scroll')continue;
      const wrap=document.createElement('div');wrap.className='device-table-scroll';wrap.tabIndex=0;wrap.setAttribute('role','region');
      wrap.setAttribute('aria-label',table.caption?.textContent.trim()||table.getAttribute('aria-label')||'Scrollable table');
      parent.insertBefore(wrap,table);wrap.appendChild(table);
    }
  }
  let pending=false;
  function schedule(){if(pending)return;pending=true;requestAnimationFrame(()=>{pending=false;enhanceTables();});}
  enhanceTables();new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true});
  if(['editor','whiteboard'].includes(document.body.dataset.mobileSurface)){
    const whiteboard=document.body.dataset.mobileSurface==='whiteboard';
    const header=whiteboard?document.querySelector('.wb-hdr'):document.body.querySelector(':scope > header');
    const update=()=>{if(header)document.documentElement.style.setProperty(whiteboard?'--device-whiteboard-header':'--device-editor-header',Math.ceil(header.getBoundingClientRect().height)+'px');};
    if(header&&window.ResizeObserver)new ResizeObserver(update).observe(header);
    window.addEventListener('resize',update,{passive:true});update();
  }
})();
