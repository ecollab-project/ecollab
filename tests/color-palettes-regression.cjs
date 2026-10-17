'use strict';
const fs=require('fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const palettes=JSON.parse(fs.readFileSync('assets/data/color-palettes.json','utf8'));
function luminance(c){const v=c.match(/\w\w/g).map(x=>parseInt(x,16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);return v.reduce((s,x,i)=>s+x*[.2126,.7152,.0722][i],0);}
function contrast(a,b){const x=luminance(a.slice(1)),y=luminance(b.slice(1));return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
(async()=>{
 assert.equal(palettes.length,20);assert.equal(new Set(palettes.map(p=>p.id)).size,20);assert.deepEqual(palettes.find(p=>p.id==='forest-mint').swatches,['#064e3b','#16a34a','#86efac','#d1fae5','#f0fdf4']);
 const css=fs.readFileSync('assets/css/color-palettes.css','utf8');
 for(const p of palettes){assert.equal(p.swatches.length,5);for(const mode of ['dark','light']){const c=p[mode];for(const [key,value] of Object.entries(c)){assert.match(value,/^#[a-f0-9]{6}$/);assert(css.includes('--ec-'+key+':'+value+';'));}for(const bg of ['page','surface','raised']){assert(contrast(c.text,c[bg])>=4.5,p.name+' '+mode+' text');assert(contrast(c.muted,c[bg])>=4.5,p.name+' '+mode+' muted');}assert(contrast(c.accent,c.surface)>=4.5,p.name+' '+mode+' accent');assert(contrast('#ffffff',c.primary)>=4.5,p.name+' button');assert(contrast('#ffffff',c['primary-end'])>=4.5,p.name+' gradient end');}}
 const dom=new JSDOM('<meta name="csrf-token" content="test-csrf"><script src="/assets/js/accessibility-apply.js"></script><script id="ecColorPalettes" type="application/json">'+JSON.stringify(palettes)+'</script><div id="ecPaletteGrid"></div><p id="ecPaletteStatus"></p>',{runScripts:'outside-only',url:'https://ecollab.test'}),w=dom.window,d=w.document;let failure=false,post;
 let change;w.matchMedia=()=>({matches:true,addEventListener:(event,cb)=>change=cb});
 w.fetch=async(url,opts)=>{post=opts;const id=JSON.parse(opts.body).color_palette;return {ok:!failure,json:async()=>failure?{error:'Save rejected'}:{success:true,settings:{color_palette:id,theme:'system'}}};};
 w.eval(fs.readFileSync('assets/js/color-theme.js','utf8'));assert.equal(d.documentElement.dataset.colorPalette,'forest-mint');assert.equal(d.querySelectorAll('[data-color-choice]').length,20);
 for(const p of palettes){await w.EcollabColors.choose(p.id);assert.equal(d.documentElement.dataset.colorPalette,p.id);assert.equal(d.querySelector('[aria-pressed="true"]').textContent.includes(p.name),true);assert.equal(post.headers['X-CSRF-Token'],'test-csrf');}
 failure=true;const previous=d.documentElement.dataset.colorPalette;await w.EcollabColors.choose('forest-mint');assert.equal(d.documentElement.dataset.colorPalette,previous);assert.equal(d.getElementById('ecPaletteStatus').textContent,'Save rejected');failure=false;
 await w.EcollabColors.choose('forest-mint');change();assert.equal(d.documentElement.dataset.theme,'light');w.dispatchEvent(new w.CustomEvent('ecollab:settings-applied',{detail:{color_palette:'purple-pink'}}));assert.equal(d.documentElement.dataset.colorPalette,'purple-pink');w.EcollabColors.apply({color_palette:'invalid'});assert.equal(d.documentElement.dataset.colorPalette,'forest-mint');w.close();
 console.log('PASS: 20 reference palettes, readable dark/light colors, selection, account saving, CSRF, rollback, device mode and invalid palette fallback');
})().catch(e=>{console.error(e);process.exitCode=1;});
