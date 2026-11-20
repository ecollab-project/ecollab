'use strict';
(() => {
 window.EcollabLiveConsole={init({root,api,canRun,getProgram}){
  const $=id=>document.getElementById(id),input=$('clStdin'),output=$('clOutput');
  let id=null,scope='',generation=0,starting=false,state='ready',sending=false,sequence=0,pending=null,example=null;
  const terminal=()=>['finished','failed','ready'].includes(state);
  function refresh(){
   const running=state==='running'&&canRun();$('clRun').disabled=starting||id!==null||!canRun();
   $('clStop').hidden=!starting&&id===null;$('clConsoleEntry').hidden=!running;
   input.disabled=!running;input.readOnly=sending;
   $('clConsoleSend').disabled=!running||sending;$('clConsoleEof').disabled=!running||sending;
   $('clConsoleExample').hidden=example===null||!running;
   $('clConsole').setAttribute('aria-busy',String(starting||id!==null));
  }
  function acknowledged(seq){if(pending&&seq>=pending.sequence){if(input.value===pending.raw)input.value='';sequence=Math.max(sequence,seq);pending=null;sending=false;}}
  function display(data){
   const previous=state;state=data.state;output.textContent=data.output||'';acknowledged(Number(data.input_seq)||0);
   $('clConsoleStatus').textContent=terminal()?(data.reason||'Finished'):({queued:'Queued',starting:'Starting',compiling:'Compiling',running:'Running · Enter sends input'}[state]||state);
   if(terminal()){id=null;starting=false;pending=null;sending=false;}
   refresh();output.scrollTop=output.scrollHeight;
   if(state==='running'&&previous!=='running'&&[document.body,$('clRun')].includes(document.activeElement))input.focus();
  }
  async function poll(token){
   while(token===generation&&id!==null){
    if(!canRun()){stop('Attempt ended');return;}
    try{const data=await api('live_status',{session_id:id});if(token!==generation)return;display(data);if(terminal())return;}
    catch(e){if(token!==generation)return;const old=id;id=null;state='failed';starting=false;sending=false;pending=null;$('clConsoleStatus').textContent='Disconnected';output.textContent+='\n'+e.message;refresh();if(old)api('live_stop',{session_id:old},true).catch(()=>{});return;}
    await new Promise(r=>setTimeout(r,350));
   }
  }
  async function run(){
   if(starting||id!==null||!canRun())return;const token=++generation;starting=true;state='starting';sequence=0;pending=null;sending=false;input.value='';output.textContent='';$('clConsoleStatus').textContent='Starting';refresh();
   try{const data=await api('live_start',getProgram(),true);
    if(token!==generation){api('live_stop',{session_id:data.session_id},true).catch(()=>{});return;}
    id=Number(data.session_id);if(!Number.isSafeInteger(id)||id<1)throw Error('Invalid console session.');starting=false;poll(token);
   }catch(e){if(token!==generation)return;starting=false;id=null;state='failed';output.textContent=e.message;$('clConsoleStatus').textContent='Unable to run';refresh();}
  }
  async function send(eof=false,sample=false){
   if(state!=='running'||!id||sending||!canRun())return;
   if(!pending){const raw=sample?example:input.value;const data=raw?(raw.endsWith('\n')?raw:raw+'\n'):(eof?'':'\n');pending={sequence:sequence+1,data,eof,raw};}
   const token=generation,session=id,request=pending;sending=true;refresh();
   try{const result=await api('live_input',{session_id:session,sequence:request.sequence,data:request.data,eof:request.eof},true);if(token!==generation)return;acknowledged(Number(result.input_seq)||0);}
   catch(e){if(token!==generation)return;$('clConsoleStatus').textContent='Checking input delivery · Send retries the same answer';}
   finally{if(token===generation){sending=false;refresh();}}
  }
  function stop(label='Stopped'){
   const old=id;++generation;id=null;starting=false;state='ready';sending=false;pending=null;input.value='';$('clConsoleStatus').textContent=label;refresh();
   if(old)api('live_stop',{session_id:old},true).catch(()=>{});
  }
  $('clRun').onclick=run;$('clStop').onclick=()=>stop();$('clConsoleSend').onclick=()=>send();$('clConsoleEof').onclick=()=>send(true);$('clConsoleExample').onclick=()=>send(true,true);
  input.addEventListener('keydown',event=>{if(event.isComposing)return;if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();send();}else if(event.key.toLowerCase()==='d'&&event.ctrlKey){event.preventDefault();send(true);}});
  window.addEventListener('pagehide',()=>stop());
  refresh();
  return {run,stop,refresh,setExample(value){example=value;$('clConsoleExample').hidden=true;},setScope(key){const same=scope===key&&id!==null;if(scope!==key){stop('Ready');scope=key;example=null;}refresh();return same;}};
 }};
})();
