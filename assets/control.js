(()=>{'use strict';
const app=document.getElementById('osgs-app'),config=window.OSGS_PUBLIC,params=new URLSearchParams(location.search);
let key=params.get('key')||'';
if(key){try{sessionStorage.setItem('osgs-quick-key',key)}catch(e){}params.delete('key');history.replaceState(null,'',location.pathname+(params.toString()?'?'+params:''));}
if(!key)try{key=sessionStorage.getItem('osgs-quick-key')||''}catch(e){}
let state={slides:[],playlist:[],message:null,paused:false};
const escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function req(path,method='GET',payload){const headers={'Content-Type':'application/json'};
 if(config.preview)headers['X-WP-Nonce']=config.nonce;else headers['X-OSGS-Control-Key']=key;
 const r=await fetch(config.api+'quick/'+path,{method,headers,credentials:'same-origin',cache:'no-store',body:payload===undefined?undefined:JSON.stringify(payload)});
 const d=await r.json().catch(()=>({}));if(!r.ok)throw Error(d.message||'Access denied');return d;}
async function reload(){state=await req('state');render();}
function render(){const selected=new Set((state.playlist||[]).map(Number));
 app.innerHTML=`<header><div class="brand">O</div><span>SIGNAGE <b>CONTROL</b><small>Digital signage · Live</small></span><div class="live">● ONLINE</div></header>
 <main><div class="heading"><small>QUICK ACCESS</small><h1>What should we show?</h1><p>Control your shop window in seconds.</p></div>
 <section class="panel"><h2>Instant announcement</h2><p>Choose a theme; mark a word or letter to make it blink.</p>
 ${OSGSRich.markup('mobile-message',state.message?.template||'clean',state.message?.html||'',state.message?.text||'')}
 <div class="line"><label>Animation <select id="mobile-animation"><option value="none">No animation</option><option value="soft" ${state.message?.animation==='soft'?'selected':''}>Soft blink (whole message)</option><option value="blink" ${state.message?.animation==='blink'?'selected':''}>Normal blink (whole message)</option></select></label></div>
 <div class="line"><label>Duration <select id="minutes"><option value="5">5 min</option><option value="10" selected>10 min</option><option value="30">30 min</option><option value="60">60 min</option><option value="0">Until cleared</option></select></label></div>
 <div class="line"><button class="primary" data-action="send">Display message</button><button data-action="clear">Clear message</button></div></section>
 <section class="panel"><h2>Playlist</h2><p>Choose which slides can play. No products are added here.</p>
 <div class="slides">${state.slides.map(s=>`<label><input type="checkbox" data-slide="${s.id}" ${selected.size===0||selected.has(s.id)?'checked':''}><span>${escape(s.title)} <small>${s.enabled&&!s.auto_paused?'Active':'Inactive'}</small></span></label>`).join('')}</div>
 <div class="line"><button class="primary" data-action="playlist">Show selected</button><button data-action="all">All slides</button></div></section>
 <section class="panel"><h2>Screen status</h2><p>${state.paused?'Slideshow paused':'Slideshow active'}</p><button data-action="pause">${state.paused?'Resume':'Pause'} slideshow</button></section>
 <footer>OpenSignage Studio · Secure phone access</footer></main>`;
 OSGSRich.init(app);
}
let busy=false;OSGSRich.mount(app);
app.addEventListener('click',async e=>{const action=e.target.closest('[data-action]')?.dataset.action;if(!action||busy)return;busy=true;
 try{switch(action){
 case 'send':await req('message','POST',{...OSGSRich.value('mobile-message'),minutes:Number(document.getElementById('minutes').value),animation:document.getElementById('mobile-animation').value});break;
 case 'clear':await req('message','POST',{text:''});break;
 case 'pause':await req('playlist','POST',{paused:!state.paused});break;
 case 'all':await req('playlist','POST',{ids:[]});break;
 case 'playlist':{const chosen=[...document.querySelectorAll('[data-slide]:checked')].map(x=>Number(x.dataset.slide));if(!chosen.length)throw Error('Choose at least one slide');await req('playlist','POST',{ids:chosen});break;}
 }await reload();notice('Updated');}catch(err){notice(err.message,true);}finally{busy=false;}
});
function notice(text,error){const n=document.createElement('div');n.className='notice '+(error?'error':'');n.textContent=text;document.body.append(n);setTimeout(()=>n.remove(),3500);}
reload().catch(e=>{app.innerHTML=`<main><h1>Secure access required</h1><p>${escape(e.message)}</p><p>Open the current mobile-control link from Display Studio.</p></main>`;});
})();
