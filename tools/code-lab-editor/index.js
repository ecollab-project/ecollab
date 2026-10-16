import {basicSetup} from 'codemirror';
import {EditorState,Compartment} from '@codemirror/state';
import {EditorView,keymap} from '@codemirror/view';
import {indentWithTab} from '@codemirror/commands';
import {cpp} from '@codemirror/lang-cpp';
import {java} from '@codemirror/lang-java';
import {python} from '@codemirror/lang-python';
const root=document.getElementById('codeLab'),field=document.getElementById('clCode');
if(root&&field){
 const language=new Compartment(),editable=new Compartment(),readonly=new Compartment();
 const host=document.createElement('div');host.id='clCodeEditor';host.className='cl-code-editor';field.after(host);
 const valueDescriptor=Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype,'value');
 let isReadOnly=field.readOnly,isDisabled=field.disabled,sync=false;
 const theme=EditorView.theme({'&':{height:'420px',backgroundColor:'#0b1220',color:'#e7eaf3'},'.cm-scroller':{overflow:'auto',fontFamily:'ui-monospace,monospace',fontSize:'14px'},'.cm-content':{padding:'12px 0',caretColor:'#c084fc'},'.cm-gutters':{backgroundColor:'#101a2a',color:'#8391ab',borderRight:'1px solid #344057'},'&.cm-focused':{outline:'2px solid #a855f7'},'.cm-activeLine':{backgroundColor:'#a855f711'},'.cm-activeLineGutter':{backgroundColor:'#a855f722'},'.cm-selectionBackground, &.cm-focused .cm-selectionBackground':{backgroundColor:'#a855f755'}},{dark:true});
 const extensions=[basicSetup,keymap.of([indentWithTab]),theme,language.of(python()),readonly.of(EditorState.readOnly.of(isReadOnly)),editable.of(EditorView.editable.of(!isReadOnly&&!isDisabled)),EditorView.contentAttributes.of({'aria-label':'Source code','spellcheck':'false','autocapitalize':'off','autocomplete':'off'}),EditorView.updateListener.of(update=>{if(update.docChanged&&!sync){valueDescriptor.set.call(field,view.state.doc.toString());field.dispatchEvent(new Event('input',{bubbles:true}));}})];
 const view=new EditorView({parent:host,state:EditorState.create({doc:field.value,extensions})});
 function setValue(value){const text=String(value??'');if(view.state.doc.toString()===text)return;sync=true;try{view.setState(EditorState.create({doc:text,extensions}));lock();setLanguage();valueDescriptor.set.call(field,text);}finally{sync=false;}}
 function setSelection(start,end=start){view.dispatch({selection:{anchor:Math.min(Math.max(0,start),view.state.doc.length),head:Math.min(Math.max(0,end),view.state.doc.length)}});}
 Object.defineProperty(field,'value',{configurable:true,get:()=>view.state.doc.toString(),set:setValue});
 for(const [name,getter]of [['selectionStart',()=>view.state.selection.main.from],['selectionEnd',()=>view.state.selection.main.to]])Object.defineProperty(field,name,{configurable:true,get:getter,set:value=>setSelection(name==='selectionStart'?value:field.selectionStart,name==='selectionEnd'?value:field.selectionEnd)});
 field.setSelectionRange=setSelection;field.setRangeText=(text,start=field.selectionStart,end=field.selectionEnd)=>{if(isReadOnly||isDisabled)return;view.dispatch({changes:{from:start,to:end,insert:text},selection:{anchor:start+text.length}});};
 function lock(){view.dispatch({effects:[readonly.reconfigure(EditorState.readOnly.of(isReadOnly||isDisabled)),editable.reconfigure(EditorView.editable.of(!isReadOnly&&!isDisabled))]});}
 for(const name of ['readOnly','disabled'])Object.defineProperty(field,name,{configurable:true,get:()=>name==='readOnly'?isReadOnly:isDisabled,set:value=>{if(name==='readOnly')isReadOnly=Boolean(value);else isDisabled=Boolean(value);lock();}});
 field.focus=()=>view.focus();field.classList.add('cl-code-mirror');field.setAttribute('aria-hidden','true');field.tabIndex=-1;
 const select=document.getElementById('clLanguage');function setLanguage(){const name=select.value;view.dispatch({effects:language.reconfigure(name==='java'?java():name==='python'?python():cpp())});}select.addEventListener('change',setLanguage);
 window.EcollabCodeEditor={fieldFor:node=>node instanceof Element&&host.contains(node)?field:null,setLanguage,view};

 host.addEventListener('focusin',()=>field.dispatchEvent(new Event('focus')));
 window.addEventListener('pagehide',event=>{if(!event.persisted)view.destroy();});setLanguage();
}
