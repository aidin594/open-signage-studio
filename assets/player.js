(() => {
'use strict';
const config=window.OSGS_PUBLIC, app=document.getElementById('osgs-app');
// No screen credentials are needed. Remove any old key from the URL/storage
// when upgrading from versions that used bearer-token screen links.
const params=new URLSearchParams(location.search);
if(params.has('key')){params.delete('key');history.replaceState(null,'',location.pathname+(params.toString()?'?'+params:''));}
try{sessionStorage.removeItem('osgs-screen-key');}catch(e){}
const data={rev:0,slides:[],message:null,paused:false,settings:{duration:8,poll:3,background:'#fff',price_bg:'#e5483a',currency:'kr'},idx:0};
let rotation=null,polling=null,errorShown=false;
const el=(tag,cls,txt)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(txt!==undefined)n.textContent=txt;return n;};
// Fit real content inside the CONTENT box, excluding the frame's padding.
// Using outer.clientHeight directly clips multi-paragraph rich messages.
function fit(node,outer,min=44,max=280){
 if(!node||!outer)return;
 const o=getComputedStyle(outer);
 const px=v=>Number.parseFloat(v)||0;
 const availW=Math.max(1,outer.clientWidth-px(o.paddingLeft)-px(o.paddingRight));
 const availH=Math.max(1,outer.clientHeight-px(o.paddingTop)-px(o.paddingBottom)-6);
 let lo=Math.max(12,min),hi=max,best=lo;
 while(lo<=hi){
  const mid=Math.floor((lo+hi)/2);
  node.style.fontSize=mid+'px';
  const fitsW=node.scrollWidth<=Math.min(availW,node.clientWidth||availW)+1;
  const fitsH=node.scrollHeight<=Math.min(availH,node.clientHeight||availH)+1;
  if(fitsW&&fitsH){best=mid;lo=mid+1;}else hi=mid-1;
 }
 node.style.fontSize=best+'px';
}
// Fit the PRICE to available height & width using intrinsic text size.
// The previous .osgs-price had width/height:100%, which made its own
// scrollHeight equal the banner height at every font size and forced 24px.
function fitPrice(node,banner){
 if(!node||!banner)return;
 const bw=banner.clientWidth, bh=banner.clientHeight;
 if(bw<1||bh<1)return;
 // Keep the glyphs' native proportions: no scaleX and no forced full-width price.
 node.style.transform='none';
 node.style.fontStretch='normal';
 const allowedWidth=bw*.84;
 const allowedHeight=bh*.82;
 // Cap headline size independently of the available blank space.
 // A short price is still easy to read, but does not grow into a wall of text.
 const cap=Math.max(36,Math.floor(Math.min(230,window.innerHeight*.235,bh*.90)));
 let low=18,high=cap,best=18;
 while(low<=high){
   const size=Math.floor((low+high)/2);
   node.style.fontSize=size+'px';
   const rect=node.getBoundingClientRect();
   if(rect.width<=allowedWidth && rect.height<=allowedHeight){best=size;low=size+1;}
   else high=size-1;
 }
 node.style.fontSize=best+'px';
}
// Product titles must wrap ONLY at spaces. Build explicit one- or two-line
// candidates, fit each by its full-word widths, and never truncate a word.
function fitProductTitle(node,pane){
 if(!node||!pane||!pane.clientWidth||!pane.clientHeight)return;
 const full=(node.dataset.fullTitle??node.textContent??'').replace(/\s+/gu,' ').trim();
 node.dataset.fullTitle=full;
 const words=full.split(' ').filter(Boolean);
 if(!words.length){node.replaceChildren();return;}
 const availableW=Math.max(1,pane.clientWidth-12);
 const availableH=Math.max(1,pane.clientHeight*.88);
 const cap=Math.min(150,Math.max(30,window.innerWidth*.067),Math.max(30,pane.clientWidth*.26));
 const options=[ [words.join(' ')] ];
 for(let i=1;i<words.length;i++){
   options.push([words.slice(0,i).join(' '),words.slice(i).join(' ')]);
 }
 // No clipping/ellipsis, even if an exceptionally long single word needs
 // a smaller font than the usual design size.
 const renderLines=(lines)=>{
   node.replaceChildren();
   for(const line of lines){
     const span=document.createElement('span');
     span.className='osgs-titleline';
     span.textContent=line;
     node.append(span);
   }
 };
 const fits=(font)=>{
   node.style.fontSize=font+'px';
   const rect=node.getBoundingClientRect();
   if(rect.height>availableH+1)return false;
   return [...node.children].every(line=>line.getBoundingClientRect().width<=availableW+1);
 };
 let chosen=options[0], chosenSize=0, bestScore=-1;
 for(const lines of options){
   renderLines(lines);
   let lo=6,hi=Math.max(6,Math.floor(cap)),best=0;
   while(lo<=hi){
     const mid=Math.floor((lo+hi)/2);
     if(fits(mid)){best=mid;lo=mid+1;}
     else hi=mid-1;
   }
   // Prefer one line when almost the same size; otherwise use the larger
   // and clearer two-line arrangement of complete words.
   const score=best*(lines.length===1?1.08:1);
   if(best>0 && score>bestScore){chosen=lines;chosenSize=best;bestScore=score;}
 }
 renderLines(chosen);
 node.style.fontSize=Math.max(6,chosenSize)+'px';
 node.style.maxHeight='none';
 node.style.overflow='visible';
}

const themeIds=new Set(['clean','notice','alert','night','warm','promo']);
function theme(value){return themeIds.has(value)?value:'clean';}
function applyRich(node,html,plain){
 if(html && window.OSGSRich?.clean) {node.classList.add('osgs-has-rich');node.innerHTML=OSGSRich.clean(html);}
 else node.textContent=plain||'';
}
function putImage(box,url){if(!url)return;const img=el('img');img.src=url;img.alt='';img.referrerPolicy='no-referrer';img.addEventListener('error',()=>{img.style.display='none';});box.append(img);}
function render(){clearTimeout(rotation);const s=data.settings;document.body.style.background=s.background||'#fff';app.replaceChildren();
 const marker=el('div','osgs-marker');marker.textContent='';app.append(marker);
 if(data.message&&data.message.text){const block=el('section','osgs-message osgs-theme-'+theme(data.message.template));const text=el('div','osgs-message-text');applyRich(text,data.message.html,data.message.text);const anim=String(data.message.animation||'none');if(anim==='soft'||anim==='blink')text.classList.add(anim==='soft'?'osgs-blink-soft':'osgs-blink');block.append(text);app.append(block);requestAnimationFrame(()=>fit(text,block,32,170));return;}
 if(!data.slides.length){const none=el('div','osgs-standby','');app.append(none);return;}
 data.idx=Math.min(data.idx,data.slides.length-1);const slide=data.slides[data.idx];const frame=el('section','osgs-frame');
 if(slide.kind==='product'){
   const top=el('div','osgs-producttop'), photo=el('div','osgs-photo'), info=el('div','osgs-info');
   putImage(photo,slide.image);info.append(el('h1','osgs-producttitle',slide.title));top.append(photo,info);frame.append(top);
   const banner=el('div','osgs-pricebanner'),price=el('div','osgs-price');
   price.append(el('span','osgs-price-number',String(slide.price??'')));
   price.append(el('span','osgs-price-currency',s.currency||'kr'));
   banner.style.background=s.price_bg||'#e5483a';banner.append(price);frame.append(banner);
   app.append(frame);requestAnimationFrame(()=>{fitPrice(price,banner);fitProductTitle(info.querySelector('h1'),info);});
 }else{
   frame.classList.add('osgs-textslide','osgs-theme-'+theme(slide.template));if(slide.image){const photo=el('div','osgs-manualimage');putImage(photo,slide.image);frame.append(photo);}
   // Manual slide titles are internal labels in WordPress, not screen content.
   const info=el('div','osgs-manualtext');if(slide.text){const body=el('div','osgs-body');applyRich(body,slide.html,slide.text);info.append(body);}frame.append(info);app.append(frame);
   requestAnimationFrame(()=>fit(info,frame,22,120));
 }
 if(!data.paused&&data.slides.length>1)rotation=setTimeout(()=>{data.idx=(data.idx+1)%data.slides.length;render();},Math.max(3,slide.duration||s.duration||8)*1000);
}
async function poll(){const headers={};if(config.preview)headers['X-OSGS-Preview']='1';
 try{const r=await fetch(config.api+'screen/state?since='+data.rev+'&_ts='+Date.now(),{headers,cache:'no-store',credentials:'same-origin'});
   if(!r.ok)throw Error(r.status===403?'Screen access denied':'Connection error '+r.status);
   const body=await r.json();if(body.changed){const old=data.slides[data.idx]?.id;data.slides=body.slides||[];data.message=body.message;data.paused=!!body.paused;data.settings=body.settings||data.settings;data.rev=body.revision;const index=data.slides.findIndex(x=>x.id===old);data.idx=index>=0?index:0;render();}else data.rev=body.revision||data.rev;
   errorShown=false;document.querySelector('.osgs-errorbar')?.remove();
 }catch(e){if(!errorShown){const b=el('div','osgs-errorbar','Connection interrupted · '+e.message);app.append(b);errorShown=true;}}
 finally{polling=setTimeout(poll,Math.max(3,Math.min(60,data.settings.poll||3))*1000);}}
if(document.fonts && document.fonts.ready){document.fonts.ready.then(()=>{const pane=document.querySelector('.osgs-info');if(pane)fitProductTitle(pane.querySelector('.osgs-producttitle'),pane);const banner=document.querySelector('.osgs-pricebanner');if(banner)fitPrice(banner.firstElementChild,banner);}).catch(()=>{});}
window.addEventListener('resize',()=>{const banner=document.querySelector('.osgs-pricebanner');if(banner)fitPrice(banner.firstElementChild,banner);const pane=document.querySelector('.osgs-info');if(pane)fitProductTitle(pane.querySelector('.osgs-producttitle'),pane);});
window.addEventListener('keydown',e=>{if(e.key==='f'&&document.fullscreenEnabled){document.documentElement.requestFullscreen().catch(()=>{});}});
poll();
})();
