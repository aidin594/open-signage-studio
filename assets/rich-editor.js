/* OpenSignage Studio - safe rich-message authoring shared by admin and quick control. */
(()=>{'use strict';
const presets=[
 {id:'clean',name:'Clean',sub:'Minimal',sample:'Back soon!',},
 {id:'notice',name:'Information',sub:'Light blue',sample:'Back in a moment!'},
 {id:'alert',name:'Alert',sub:'Red accent',sample:'Important announcement!'},
 {id:'night',name:'Dark',sub:'High contrast',sample:'Closed for lunch'},
 {id:'warm',name:'Warm',sub:'Soft tone',sample:'Welcome!'},
 {id:'promo',name:'Promotion',sub:'Bright',sample:'Special offers!',}
];
const ids=new Set(presets.map(p=>p.id));
const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const ranges=new WeakMap();
const histories=new WeakMap();
const classes=new Set(['osgs-blink','osgs-blink-soft','osgs-mark','osgs-text-large','osgs-text-small']);
const allowed=new Set(['DIV','P','BR','STRONG','B','EM','I','U','SPAN']);
const safeColors=new Set(['#ffffff','#0f172a','#dc2626','#f59e0b','#2563eb','#059669']);
function clean(raw){
 const tpl=document.createElement('template');tpl.innerHTML=String(raw||'').slice(0,18000);
 const walk=(parent)=>{
  [...parent.childNodes].forEach(n=>{
   if(n.nodeType===8){n.remove();return;}
   if(n.nodeType!==1)return;
   const tag=n.tagName;
   if(['SCRIPT','STYLE','IFRAME','SVG','MATH','OBJECT','VIDEO','IMG','FORM','INPUT','LINK'].includes(tag)){n.remove();return;}
   walk(n);
   if(!allowed.has(tag)) {const frag=document.createDocumentFragment();while(n.firstChild)frag.append(n.firstChild);n.replaceWith(frag);return;}
   const cls=(n.getAttribute('class')||'').split(/\s+/).filter(c=>classes.has(c)).join(' ');
   const inline=(n.getAttribute('style')||'').split(';').map(x=>x.trim()).filter(Boolean);
   const safe=[];
   for(const rule of inline){let sep=rule.indexOf(':');if(sep<0)continue;let k=rule.slice(0,sep).trim().toLowerCase(),v=rule.slice(sep+1).trim().toLowerCase();
    if((k==='color'||k==='background-color')&&safeColors.has(v))safe.push(k+':'+v);
    if(k==='text-align'&&['center','left','right'].includes(v))safe.push(k+':'+v);
    if(k==='font-size'&&['1.3em','.8em','0.8em','1.6em'].includes(v))safe.push(k+':'+v);
   }
   [...n.attributes].forEach(a=>n.removeAttribute(a.name));
   if(cls)n.setAttribute('class',cls);if(safe.length)n.setAttribute('style',safe.join(';'));
  });
 };
 walk(tpl.content);return tpl.innerHTML;
}
function markup(id,template='clean',html='',plain='',options={}){
 const chosen=ids.has(template)?template:'clean';
 const tplCards=presets.map(p=>`<button type="button" class="osgs-preset ${chosen===p.id?'chosen':''}" data-rich-template="${p.id}" aria-pressed="${chosen===p.id}"><span class="osgs-preset-tile osgs-theme-${p.id}">Aa</span><span><strong>${p.name}</strong><small>${p.sub}</small></span></button>`).join('');
 const buttons=[['undo','↶ Undo'],['redo','↷ Redo'],['bold','B'],['italic','I'],['underline','U'],['blink','Blink'],['blink-soft','Soft blink'],['mark','Highlight'],['large','A+'],['small','A−'],['left','⇤'],['center','⇔'],['right','⇥'],['unblink','No blink in text'],['clear','Clear format']].map(([cmd,label])=>`<button type="button" data-rich-command="${cmd}" title="${cmd==='clear'?'Remove formatting from selection (or entire message if nothing is selected)':label}" aria-label="${label}">${label}</button>`).join('');
 return `<div class="osgs-rich" data-rich-box="${id}" data-selected-template="${chosen}">
  <div class="osgs-rich-heading">Choose a template</div><div class="osgs-presets" role="group" aria-label="Message templates">${tplCards}</div>
  <div class="osgs-rich-heading">Format selected text</div>
  <div class="osgs-rich-toolbar" role="toolbar" aria-label="Text formatting">${buttons}<span class="osgs-rich-sep"></span>
  ${['#0f172a','#ffffff','#dc2626','#f59e0b','#2563eb','#059669'].map(c=>`<button type="button" class="osgs-rich-swatch" data-rich-color="${c}" aria-label="Text color ${c}" title="Text color ${c}" style="--swatch:${c}"></button>`).join('')}</div>
  <div class="osgs-editor" contenteditable="true" role="textbox" aria-label="Message text" aria-multiline="true" data-rich-editor="${id}" data-initial-html="${escape(html)}" data-initial-text="${escape(plain)}" data-placeholder="Write your message here…" spellcheck="true"></div>
  <div class="osgs-rich-help">Select a letter or word and choose, for example, <b>Blink</b>. Only the selection is animated. <b>No blink in text</b> removes blink effects from this text only. <b>Clear format</b> clears text formatting; whole-message effects are configured separately.</div>
  ${options.showPreview===false?'':`<div class="osgs-rich-heading">Preview · matches the editor</div><div class="osgs-rich-preview osgs-theme-${chosen}" data-rich-preview="${id}"><div class="osgs-rich-preview-inner"></div></div>`}
 </div>`;
}
function populatePlainText(editor,plain){
 const lines=String(plain||'').replace(/\r\n?/g,'\n').split('\n');
 // Each line gets its own block; styling one line must not change the others.
 // Empty lines need a BR so Chromium keeps the paragraph editable.
 editor.replaceChildren();
 for(const line of lines){const block=document.createElement('div');if(line)block.textContent=line;else block.appendChild(document.createElement('br'));editor.appendChild(block);}
}
function removeEmptyInline(editor){
 [...editor.querySelectorAll('span,b,strong,i,em,u')].reverse().forEach(el=>{
  if(!el.isConnected)return;
  if(el.textContent==='' && !el.querySelector('br')){el.remove();return;}
  if(el.tagName==='SPAN' && !el.attributes.length)el.replaceWith(...el.childNodes);
 });
}
function text(editor){return (editor?.innerText||'').replace(/\u200b/g,'').trim();}
function selectionOffsets(editor){
 const sel=window.getSelection();
 if(!sel?.rangeCount || !editor.contains(sel.getRangeAt(0).commonAncestorContainer))return null;
 const range=sel.getRangeAt(0);const pre=document.createRange();pre.selectNodeContents(editor);
 pre.setEnd(range.startContainer,range.startOffset);const start=pre.toString().length;
 pre.setEnd(range.endContainer,range.endOffset);return {start,end:pre.toString().length};
}
function selectOffsets(editor,rangeOffsets){
 const sel=window.getSelection();if(!sel)return;
 const offsets=rangeOffsets||{start:editor.textContent.length,end:editor.textContent.length};
 const nodes=[];const walker=document.createTreeWalker(editor,NodeFilter.SHOW_TEXT);let n;
 while(n=walker.nextNode())nodes.push(n);
 const range=document.createRange();if(!nodes.length){range.selectNodeContents(editor);range.collapse(false);}
 else{
  function point(index){let position=0;for(const node of nodes){const next=position+node.length;if(index<=next)return {node,offset:Math.max(0,index-position)};position=next;}return {node:nodes[nodes.length-1],offset:nodes[nodes.length-1].length};}
  const a=point(offsets.start),b=point(offsets.end);range.setStart(a.node,a.offset);range.setEnd(b.node,b.offset);
 }
 sel.removeAllRanges();sel.addRange(range);ranges.set(editor,range.cloneRange());
}
function snapshot(editor){
 const record=histories.get(editor);if(!record)return;
 const html=editor.innerHTML;
 if(record.states[record.index]?.html===html)return;
 record.states.splice(record.index+1);
 record.states.push({html,selection:selectionOffsets(editor)});
 if(record.states.length>100)record.states.shift();
 record.index=record.states.length-1;
}
function historyMove(editor,step){
 const record=histories.get(editor);if(!record)return;
 snapshot(editor);
 const index=record.index+step;if(index<0||index>=record.states.length)return;
 record.index=index;
 const current=record.states[index];editor.innerHTML=current.html;
 editor.focus({preventScroll:true});selectOffsets(editor,current.selection);paint(editor.closest('[data-rich-box]'));
}
function value(id){const box=document.querySelector('[data-rich-box="'+id+'"]');if(!box)return {text:'',html:'',template:'clean'};
 const editor=box.querySelector('[data-rich-editor]');
 return {text:text(editor),html:clean(editor.innerHTML),template:box.dataset.selectedTemplate||'clean'};
}
function paint(box){const editor=box.querySelector('[data-rich-editor]');const preview=box.querySelector('[data-rich-preview]');if(!preview)return;
 preview.className='osgs-rich-preview osgs-theme-'+box.dataset.selectedTemplate;
 const dst=preview.querySelector('.osgs-rich-preview-inner');dst.innerHTML=clean(editor.innerHTML);
 if(!text(editor))dst.textContent='Preview';
}
function init(root=document){root.querySelectorAll('[data-rich-box]').forEach(box=>{
 const editor=box.querySelector('[data-rich-editor]');if(editor.dataset.ready)return;
 editor.dataset.ready='1';const html=editor.dataset.initialHtml||'';
 if(html){
  editor.innerHTML=clean(html);
  if(!editor.querySelector('div,p,span,strong,b,em,i,u') && editor.textContent.includes('\n'))populatePlainText(editor,editor.textContent);
 }else populatePlainText(editor,editor.dataset.initialText||'');
 delete editor.dataset.initialHtml;delete editor.dataset.initialText;
 histories.set(editor,{states:[{html:editor.innerHTML,selection:null}],index:0});
 editor.addEventListener('input',()=>{snapshot(editor);paint(box);});
 editor.addEventListener('keydown',e=>{
  if((e.ctrlKey||e.metaKey) && !e.altKey && e.key.toLowerCase()==='z'){
   e.preventDefault();historyMove(editor,e.shiftKey?1:-1);
  }else if((e.ctrlKey||e.metaKey) && !e.altKey && e.key.toLowerCase()==='y'){
   e.preventDefault();historyMove(editor,1);
  }
 });
 editor.addEventListener('paste',e=>{e.preventDefault();let plain=e.clipboardData?.getData('text/plain')||'';document.execCommand('insertText',false,plain.slice(0,2500));paint(box);});
 paint(box);
 });}
function remember(){const sel=window.getSelection();if(!sel||!sel.rangeCount)return;
 const n=sel.anchorNode;const el=n?.nodeType===1?n:n?.parentElement;
 const editor=el?.closest?.('[data-rich-editor]');if(editor&&editor.contains(sel.focusNode))ranges.set(editor,sel.getRangeAt(0).cloneRange());
}
document.addEventListener('selectionchange',remember);
function restore(editor){editor.focus({preventScroll:true});const sel=window.getSelection();let range=ranges.get(editor);
 if(range&&editor.contains(range.commonAncestorContainer)){sel.removeAllRanges();sel.addRange(range);}else if(!sel.rangeCount||!editor.contains(sel.anchorNode)){
  range=document.createRange();range.selectNodeContents(editor);range.collapse(false);sel.removeAllRanges();sel.addRange(range);
 }return sel;}
function wrap(editor,cls,style=''){
 const sel=restore(editor);if(!sel.rangeCount)return;const selection=sel.getRangeAt(0);
 if(selection.collapsed)return;
 // Apply to selected text nodes individually. A span wrapping multiple DIV/P
 // blocks is invalid HTML and can unexpectedly format the whole message.
 const walker=document.createTreeWalker(editor,NodeFilter.SHOW_TEXT);
 const segments=[];let n;
 while((n=walker.nextNode())){
  if(!n.nodeValue||!selection.intersectsNode(n))continue;
  const start=n===selection.startContainer?selection.startOffset:0;
  const end=n===selection.endContainer?selection.endOffset:n.nodeValue.length;
  if(end>start)segments.push({node:n,start,end});
 }
 if(!segments.length)return;
 const inserted=[];
 for(const {node,start,end} of segments.reverse()){
  const selected=node.splitText(start);
  selected.splitText(end-start);
  const span=document.createElement('span');
  if(cls)span.className=cls;
  if(style)span.setAttribute('style',style);
  selected.replaceWith(span);span.appendChild(selected);inserted.unshift(span);
 }
 // The selection remains on the selected pieces, even after a toolbar click.
 if(inserted.length){
  const range=document.createRange();
  range.setStartBefore(inserted[0]);range.setEndAfter(inserted[inserted.length-1]);
  sel.removeAllRanges();sel.addRange(range);ranges.set(editor,range.cloneRange());
 }
}
// Remove all rich formatting, including custom spans created by this editor.
// execCommand('removeFormat') cannot remove classes like osgs-blink-soft,
// osgs-mark, osgs-text-large or inline color styles.
function clearFormatting(editor){
 const sel=restore(editor);
 if(!sel?.rangeCount)return;
 const selection=sel.getRangeAt(0).cloneRange();
 if(selection.collapsed){
  // With no selection, Clear format intentionally resets the entire message.
  // Keep DIV/P/BR as paragraph and line-break structure.
  [...editor.querySelectorAll('*')].reverse().forEach(el=>{
   if(['DIV','P','BR'].includes(el.tagName)){
    [...el.attributes].forEach(a=>el.removeAttribute(a.name));
   }else{
    el.replaceWith(...el.childNodes);
   }
  });
  const range=document.createRange();range.selectNodeContents(editor);range.collapse(false);
  sel.removeAllRanges();sel.addRange(range);ranges.set(editor,range.cloneRange());
  removeEmptyInline(editor);
  return;
 }
 // Remember every text fragment intersected by the selected range before
 // modifying the DOM. Split only the boundaries; never touch adjacent words.
 const walker=document.createTreeWalker(editor,NodeFilter.SHOW_TEXT);
 const pieces=[];let node;
 while((node=walker.nextNode())){
  if(!node.nodeValue||!selection.intersectsNode(node))continue;
  const start=node===selection.startContainer?selection.startOffset:0;
  const end=node===selection.endContainer?selection.endOffset:node.nodeValue.length;
  if(end>start)pieces.push({node,start,end});
 }
 if(!pieces.length)return;
 const selected=[];
 for(const {node,start,end} of pieces.reverse()){
  let part=node;
  if(start>0)part=part.splitText(start);
  if(end-start<part.length)part.splitText(end-start);
  selected.unshift(part);
 }
 // Lift each selected text fragment out of every inline formatting ancestor.
 // Before/after fragments keep cloned wrappers and retain their formatting.
 for(const part of selected){
  while(part.parentElement&&part.parentElement!==editor){
   const parent=part.parentElement;
   if(!['SPAN','B','STRONG','EM','I','U'].includes(parent.tagName))break;
   const earlier=parent.cloneNode(false),later=parent.cloneNode(false);
   while(parent.firstChild!==part)earlier.appendChild(parent.firstChild);
   while(part.nextSibling)later.appendChild(part.nextSibling);
   const fragment=document.createDocumentFragment();
   if(earlier.childNodes.length)fragment.appendChild(earlier);
   fragment.appendChild(part);
   if(later.childNodes.length)fragment.appendChild(later);
   parent.replaceWith(fragment);
  }
 }
 // Text alignment applies to paragraphs, not isolated letters. Remove any
 // block-level editor formatting only for paragraphs fully inside selection.
 const blocks=new Set(selected.map(n=>n.parentElement?.closest('p,div')).filter(b=>b&&b!==editor));
 for(const block of blocks){
  const nodes=[];const w=document.createTreeWalker(block,NodeFilter.SHOW_TEXT);let t;while((t=w.nextNode()))nodes.push(t);
  if(nodes.length&&nodes.every(n=>!n.nodeValue?.trim()||selected.includes(n))){
   block.removeAttribute('style');block.removeAttribute('class');block.removeAttribute('align');
  }
 }
 // Preserve a selection so users can apply another format immediately.
 if(selected[0].isConnected&&selected[selected.length-1].isConnected){
  const range=document.createRange();
  range.setStart(selected[0],0);
  range.setEnd(selected[selected.length-1],selected[selected.length-1].length);
  sel.removeAllRanges();sel.addRange(range);ranges.set(editor,range.cloneRange());
 }
 removeEmptyInline(editor);
}

function clearTextBlink(editor){
 // Removes word-level blink from the entire editor, preserving bold, color,
 // highlight and every paragraph. Global animation is a separate setting.
 editor.querySelectorAll('.osgs-blink,.osgs-blink-soft').forEach(node=>{
  node.classList.remove('osgs-blink','osgs-blink-soft');
  if(node.hasAttribute('class') && !node.classList.length)node.removeAttribute('class');
 });
}
function alignText(editor,alignment){
 const selection=restore(editor);
 if(!selection?.rangeCount)return;
 const range=selection.getRangeAt(0);
 // Align complete line blocks; never wrap text nodes inside SPAN or apply
 // browser-generated align attributes to every line in a message.
 const blocks=[...editor.children].filter(n=>n.nodeType===1 && ['DIV','P'].includes(n.tagName));
 const selected=blocks.filter(block=>range.intersectsNode(block));
 if(!selected.length && blocks.length){
  const target=(range.startContainer.nodeType===1?range.startContainer:range.startContainer.parentElement)?.closest('div,p');
  if(target&&editor.contains(target))selected.push(target);
 }
 // Mixed legacy inline elements need one block to align predictably.
 if(!blocks.length){const block=document.createElement('div');while(editor.firstChild)block.appendChild(editor.firstChild);editor.appendChild(block);selected.push(block);}
 selected.forEach(block=>{block.style.textAlign=alignment;});
}
function format(editor,command,color){
 if(command==='undo'||command==='redo'){historyMove(editor,command==='undo'?-1:1);return;}
 restore(editor);
 if(['bold','italic','underline'].includes(command))document.execCommand(command,false,null);
 else if(command==='blink')wrap(editor,'osgs-blink');
 else if(command==='blink-soft')wrap(editor,'osgs-blink-soft');
 else if(command==='mark')wrap(editor,'osgs-mark');
 else if(command==='large')wrap(editor,'osgs-text-large');
 else if(command==='small')wrap(editor,'osgs-text-small');
 else if(command==='color'&&safeColors.has(color))wrap(editor,'','color:'+color);
 else if(['left','center','right'].includes(command))alignText(editor,command);
 else if(command==='clear')clearFormatting(editor);
 else if(command==='unblink')clearTextBlink(editor);
 const box=editor.closest('[data-rich-box]');snapshot(editor);paint(box);
}
function mount(root){
 root.addEventListener('pointerdown',e=>{if(e.target.closest('[data-rich-command],[data-rich-color]'))e.preventDefault();});
 root.addEventListener('click',e=>{
  const tpl=e.target.closest('[data-rich-template]');if(tpl){const box=tpl.closest('[data-rich-box]');box.dataset.selectedTemplate=tpl.dataset.richTemplate;
   box.querySelectorAll('[data-rich-template]').forEach(btn=>{let on=btn===tpl;btn.classList.toggle('chosen',on);btn.setAttribute('aria-pressed',on?'true':'false');});
   // A theme is only a visual choice. Never silently insert sample copy.
   paint(box);return;
  }
  const btn=e.target.closest('[data-rich-command],[data-rich-color]');if(btn){const editor=btn.closest('[data-rich-box]')?.querySelector('[data-rich-editor]');if(editor)format(editor,btn.dataset.richCommand||(btn.dataset.richColor?'color':''),btn.dataset.richColor);}
 });
}
window.OSGSRich={markup,init,mount,value,clean,presets};
})();
