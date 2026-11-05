'use strict';
(() => {
 window.EcollabCodeLabModules={init({root,api,onPublished,status}){
  const $=id=>document.getElementById(id),dialog=$('clTaskDialog'),form=$('clTaskForm'),upload=$('clModuleForm'),sid=Number(root.dataset.serverId);
  let mode='module',job=null,appliedId=0,poll=0,busy=false,epoch=0;
  const fail=message=>{$('clFormError').textContent=message;};
  async function request(action,data={},file=null){
   const params={action,server_id:sid,...data};let options,url=root.dataset.base+'/API/code-lab/module-tasks.php';
   if(action==='status'){url+='?'+new URLSearchParams(params);options={cache:'no-store'};}
   else if(file){const body=new FormData();for(const [k,v] of Object.entries(params))body.append(k,String(v));body.append('module',file);options={method:'POST',headers:{'X-CSRF-Token':root.dataset.csrf},body};}
   else options={method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf},body:JSON.stringify(params)};
   const response=await fetch(url,options);let result;try{result=await response.json();}catch{throw Error('Module request failed. Reopen this form to check your saved draft.');}
   if(!response.ok||!result.success)throw Error(result.error||'Module request failed.');return result.data;
  }
  function resetReview(){form.reset();$('clTaskAdvanced').open=false;$('clCaseEditor').hidden=true;$('clCaseRows').replaceChildren();}
  function controls(){
   $('clModuleMode').setAttribute('aria-pressed',String(mode==='module'));$('clManualMode').setAttribute('aria-pressed',String(mode==='manual'));
   $('clModuleMode').classList.toggle('active',mode==='module');$('clManualMode').classList.toggle('active',mode==='manual');$('clModulePane').hidden=mode!=='module';
   form.hidden=mode==='module'&&job?.state!=='ready';
  }
  function showJob(next){
   job=next||null;controls();upload.hidden=!!job;
   $('clModuleRetry').hidden=!(job?.state==='failed'&&job.can_retry);$('clModuleDiscard').hidden=!job;
   $('clModuleEvidence').hidden=job?.state!=='ready';
   const messages={queued:'Jarred is queued. You can close this form and come back.',running:'Jarred is drafting your task. You can keep using eCollab.',ready:'Your draft is ready. Review and edit it before publishing.',failed:job?.error_code===422?'Jarred could not make a complete, source-backed draft. Retry or use a shorter programming lesson.':'Jarred could not finish this draft. Retry generation or upload the module again.',expired:'This draft expired. Upload your module again.',cancelled:'Draft discarded.',published:'Task published.'};
   $('clModuleState').textContent=job?(job.file_name+' · '+job.difficulty+'\n'+(messages[job.state]||'')):'';
   if(job?.state==='ready'&&job.id!==appliedId&&mode==='module'){
    resetReview();const draft=job.draft;form.elements.title.value=draft.title;form.elements.instructions.value=draft.instructions;form.elements.language.value=draft.language;form.elements.starter_code.value='';form.elements.grading_mode.value='manual';
    $('clModuleQuote').textContent=draft.source_excerpt;$('clModuleCoverage').textContent='Drafted from the first '+job.used_chars.toLocaleString()+' of '+job.source_chars.toLocaleString()+' extracted characters. PDF extraction covers at most 40 pages.';appliedId=job.id;
   }
  }
  async function watch(id){const token=++poll;for(let n=0;n<320;n++){
   if(token!==poll||!dialog.open||mode!=='module')return;
   try{const data=await request('status',{job_id:id});if(token!==poll||!dialog.open||mode!=='module')return;showJob(data.job);if(!['queued','running'].includes(data.job?.state))return;}
   catch(e){if(token===poll)fail(e.message);return;}await new Promise(r=>setTimeout(r,3000));
  }if(token===poll)fail('Still waiting. Reopen this form to check your saved draft.');}
  async function resume(){const token=++epoch;try{const data=await request('status');if(token!==epoch||!dialog.open||mode!=='module')return;showJob(data.job);if(['queued','running'].includes(data.job?.state))watch(data.job.id);}catch(e){if(token===epoch&&mode==='module')fail(e.message);}}
  $('clCreate').onclick=()=>{if(busy){status('Your task request is still finishing. Reopen shortly.');return;}fail('');mode='module';controls();dialog.showModal();resume();};
  $('clCancel').onclick=()=>dialog.close();dialog.addEventListener('close',()=>{++poll;++epoch;});
  $('clManualMode').onclick=()=>{if(busy)return;++epoch;++poll;mode='manual';fail('');resetReview();appliedId=0;controls();};
  $('clModuleMode').onclick=()=>{if(busy)return;++epoch;mode='module';fail('');controls();resume();};
  upload.addEventListener('submit',async e=>{e.preventDefault();if(busy)return;const file=$('clModuleFile').files[0];if(!file){fail('Choose your module first.');return;}if(file.size>5*1024*1024){fail('Module must be 5 MB or smaller.');return;}
   busy=true;$('clGenerateTask').disabled=true;fail('');const token=++epoch;
   try{const data=await request('generate',{language:upload.elements.language.value,difficulty:upload.elements.difficulty.value},file);if(token!==epoch||!dialog.open)return;showJob(data.job);watch(data.job.id);}catch(err){if(token===epoch)fail(err.message);}finally{busy=false;$('clGenerateTask').disabled=false;}
  });
  $('clModuleRetry').onclick=async()=>{if(busy||!job)return;busy=true;$('clModuleRetry').disabled=true;fail('');const id=job.id,token=++epoch;
   try{const data=await request('retry',{job_id:id});if(token!==epoch||!dialog.open)return;showJob(data.job);watch(id);}catch(e){if(token===epoch)fail(e.message);}finally{busy=false;$('clModuleRetry').disabled=false;}
  };
  $('clModuleDiscard').onclick=async()=>{if(busy||!job||!confirm('Discard this private draft and use another module? Published tasks are unchanged.'))return;busy=true;$('clModuleDiscard').disabled=true;fail('');const token=++epoch,id=job.id;++poll;
   try{await request('cancel',{job_id:id});if(token!==epoch||!dialog.open)return;job=null;appliedId=0;resetReview();upload.reset();showJob(null);}catch(e){if(token===epoch)fail(e.message);}finally{busy=false;$('clModuleDiscard').disabled=false;}
  };
  form.addEventListener('submit',async e=>{e.preventDefault();if(busy)return;if(mode==='module'&&job?.state!=='ready'){fail('Wait for a draft, or choose Write manually.');return;}
   const data=Object.fromEntries(new FormData(form)),button=form.querySelector('[type=submit]');busy=true;button.disabled=true;fail('');
   try{for(const key of ['opens_at','due_at'])data[key]=data[key]?new Date(data[key]).toISOString():null;data.duration_minutes=data.duration_minutes?Number(data.duration_minutes):null;
    data.test_cases=data.grading_mode==='automatic'?[...$('clCaseRows').children].map(r=>({input:r.querySelector('[data-case=input]').value,expected_output:r.querySelector('[data-case=expected]').value,hidden:r.querySelector('[data-case=hidden]').checked,weight:Number(r.querySelector('[data-case=weight]').value)})):[];
    if(mode==='module')await request('publish',{...data,job_id:job.id});else await api('create',data,true);
    ++poll;++epoch;job=null;appliedId=0;dialog.close();resetReview();upload.reset();showJob(null);status('Task published to this server.');await onPublished();
   }catch(err){fail(err.message);}finally{busy=false;button.disabled=false;}
  });
 }};
})();
