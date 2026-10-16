(() => {
'use strict';
const cfg=window.OSGS_CONFIG;
const root=document.getElementById('osgs-root');
const state={section:'overview',data:null,categories:[],products:[],page:1,pages:1,total:0,category:'',search:'',selected:new Map(),modal:null,generated:null,loading:false};
const E=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
async function req(endpoint,method='GET',data){
 const route=cfg.api+endpoint;
 const url=method==='GET' ? route+(route.includes('?')?'&':'?')+'_osgs_ts='+Date.now() : route;
 let res;
 try{
   res=await fetch(url,{method,credentials:'same-origin',headers:{'X-WP-Nonce':cfg.nonce,'Content-Type':'application/json','Accept':'application/json'},body:data===undefined?undefined:JSON.stringify(data),cache:'no-store'});
 }catch(e){throw Error('Cannot reach WordPress REST API: '+(e?.message||'Network error'));}
 const raw=await res.text();
 let json;
 try{json=JSON.parse(raw);}catch(e){
   throw Error('WordPress REST API returned invalid JSON (HTTP '+res.status+'). Check the REST API, login session, security plugins and server/cache configuration.');
 }
 if(!res.ok){
   const message=typeof json?.message==='string'?json.message:typeof json?.error==='string'?json.error:'HTTP '+res.status;
   throw Error('REST '+res.status+': '+message);
 }
 if(!json||typeof json!=='object'||Array.isArray(json))throw Error('REST API returned an unexpected response for '+endpoint+'.');
 return json;
}
function isDashboard(d){return !!d && typeof d==='object' && !Array.isArray(d) && Array.isArray(d.slides);}
function validateDashboard(response){
 const data=isDashboard(response)?response:isDashboard(response?.data)?response.data:null;
 if(!data){
   const fields=response && typeof response==='object'?Object.keys(response).slice(0,8).join(', '):'(none)';
   throw Error('Invalid /dashboard REST response: missing slides array. Returned fields: '+(fields||'(none)')+'. Verify plugin PHP/JS versions and exclude /wp-json/open-signage/v1/* from caching.');
 }
 if(!data.settings||typeof data.settings!=='object'||Array.isArray(data.settings))throw Error('Invalid /dashboard REST response: missing settings object. Check the installed plugin version.');
 return {...data,
   playlist:Array.isArray(data.playlist)?data.playlist:[],
   telegram:data.telegram && typeof data.telegram==='object'?data.telegram:{configured:false,webhook:false,chats:'',users:''},
   settings:{duration:8,poll:3,currency:'kr',...data.settings},
   preview_url:typeof data.preview_url==='string'?data.preview_url:'#',
   screen_url:typeof data.screen_url==='string'?data.screen_url:location.origin+'/signage-screen/',
   paused:!!data.paused};
}

const ico=(name)=>({monitor:'▣',grid:'▦',bike:'♧',slides:'☷',message:'✎',settings:'⚙',telegram:'➤',check:'✓',sync:'↻',plus:'＋',external:'↗',trash:'×',right:'→',up:'↑',down:'↓'}[name]||'•');
function toast(text,error=false){let node=document.getElementById('osgs-toast');if(!node){node=document.createElement('div');node.id='osgs-toast';document.body.append(node);}node.textContent=text;node.className='osgs-toast '+(error?'is-error':'');clearTimeout(toast.timer);toast.timer=setTimeout(()=>node.classList.add('gone'),3900);}
async function task(fn){try{state.loading=true;await fn();}catch(e){toast(e.message,true);}finally{state.loading=false;}}
async function refresh(){
 let response=await req('dashboard');
 if(!isDashboard(response)&&!isDashboard(response?.data)){
   // Repeat once to bypass intermediaries that accidentally cached an old REST response.
   response=await req('dashboard?retry=1');
 }
 state.data=validateDashboard(response);
 render();
}
function activeCount(){return(state.data?.slides||[]).filter(x=>x.enabled&&!x.auto_paused).length;}
function sidebar(){let list=[['overview','Overview','grid'],['products','WooCommerce products','bike'],['slides','Playlist & slides','slides'],['message','Quick message','message'],['settings','Settings & access','settings'],['telegram','Telegram','telegram']];
 return `<aside class="osgs-sidebar"><div class="osgs-brand"><span class="osgs-brandicon">O</span><span>DISPLAY <b>STUDIO</b><small>for any storefront</small></span></div><div class="osgs-nav">${list.map(([key,label,icon])=>`<button class="${state.section===key?'active':''}" data-nav="${key}"><span>${ico(icon)}</span>${label}</button>`).join('')}</div><div class="osgs-sidefoot"><div class="osgs-statusdot"></div> WordPress native <small>v0.1.0-beta.1 · No Node.js</small></div></aside>`;
}
function top(){const title={overview:'Overview',products:'Products',slides:'Playlist',message:'Quick message',settings:'Settings',telegram:'Telegram integration'}[state.section];return `<div class="osgs-top"><div><div class="osgs-eyebrow">OPENSIGNAGE / CONTROL CENTER</div><h1>${title}</h1></div><div class="osgs-topactions"><button class="osgs-btn ghost" data-action="reload">${ico('sync')} Refresh</button><a class="osgs-btn blue" href="${E(state.data.preview_url)}" target="_blank" rel="noopener noreferrer">${ico('external')} Preview screen</a></div></div>`;}
function stat(label,value,sub){return `<div class="osgs-stat"><div>${E(label)}</div><strong>${E(value)}</strong><small>${E(sub)}</small></div>`;}
function overview(){let d=state.data;const total=d.slides.length;const product=d.slides.filter(x=>x.kind==='product').length;return `<div class="osgs-stats">${stat('Active slides',activeCount(),'of '+total+' total')}${stat('WooCommerce',product,'linked products')}${stat('Display status',d.paused?'Paused':'On air','Live polling every '+d.settings.poll+'s')}${stat('Telegram',d.telegram.configured?'Configured':'Not connected',d.telegram.webhook?'Webhook active':'Not registered')}</div>
 <div class="osgs-columns"><section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Live screen</h2><p>True rendering of your current slide design.</p></div><span class="osgs-tag">PREVIEW</span></div><div class="osgs-preview"><iframe loading="lazy" referrerpolicy="no-referrer" src="${E(d.preview_url)}" title="Display preview"></iframe></div><div class="osgs-actions"><button class="osgs-btn" data-nav="products">Add products ${ico('right')}</button><button class="osgs-btn" data-nav="message">Show quick message ${ico('right')}</button></div></section>
 <section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Quick actions</h2><p>Things you need during a busy day.</p></div></div><div class="osgs-shortcuts"><button data-nav="message"><span>✎</span><b>Write a message</b><small>Lunch, back soon, closing time</small></button><button data-nav="products"><span>♧</span><b>Choose in-stock bikes</b><small>Only your chosen items</small></button><button data-nav="slides"><span>☷</span><b>Control playlist</b><small>Switch, reorder, remove</small></button><button data-action="sync"><span>↻</span><b>Refresh prices</b><small>WooCommerce stock & prices</small></button></div></section></div>`;}
function categorySelect(){return `<select id="osgs-category"><option value="">All categories</option>${state.categories.map(c=>`<option value="${c.id}" ${String(c.id)===String(state.category)?'selected':''}>${E(c.label)}</option>`).join('')}</select>`;}
function products(){return `<section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Choose products to advertise</h2><p>Only products marked <b>In stock</b> in WooCommerce. Nothing is added until you confirm.</p></div><span class="osgs-tag success">IN STOCK ONLY</span></div>
 <div class="osgs-toolbar">${categorySelect()}<input id="osgs-search" placeholder="Search products or bike models…" value="${E(state.search)}"><button class="osgs-btn blue" data-action="find">Show in-stock products</button></div>
 <div class="osgs-toolbar minor"><span id="osgs-productcount">${state.total} products · ${state.selected.size} selected</span><button class="osgs-btn ghost" data-action="select-page">Select visible</button><button class="osgs-btn ghost" data-action="clear-selection">Clear selection</button></div>
 <div class="osgs-products">${state.products.length?state.products.map(p=>`<label class="osgs-product ${state.selected.has(p.id)?'picked':''}"><input type="checkbox" data-pick="${p.id}" ${state.selected.has(p.id)?'checked':''}><span class="osgs-thumb">${p.image?`<img src="${E(p.image)}" alt="" loading="lazy">`:'♧'}</span><span class="osgs-productinfo"><b>${E(p.name)}</b><small>${E(p.short)}</small><strong>${E(p.price)} ${E(state.data.settings.currency)}</strong></span><span class="osgs-stock">● In stock</span></label>`).join(''):'<div class="osgs-empty">Choose a category and click “Show in-stock products”.</div>'}</div>
 <div class="osgs-toolbar bottom"><button class="osgs-btn" data-action="prev" ${state.page<=1?'disabled':''}>← Previous</button><span>Page ${state.page} / ${Math.max(1,state.pages)}</span><button class="osgs-btn" data-action="next" ${state.page>=state.pages?'disabled':''}>Next →</button></div></section>
 <div class="osgs-sticky"><span><b>${state.selected.size}</b> products selected</span><button class="osgs-btn blue" data-action="add-products" ${!state.selected.size?'disabled':''}>${ico('plus')} Create selected slides</button></div>`;}
function slides(){const d=state.data;const chosen=new Set((d.playlist||[]).map(Number));return `<section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Playlist & slide manager</h2><p>Arrange, deactivate, remove or restrict the playing set.</p></div><button class="osgs-btn blue" data-action="new-text">${ico('plus')} Text slide</button></div>
 <div class="osgs-notice">${chosen.size?`Custom selection active: ${chosen.size} slide(s).`:'All enabled slides are eligible to play.'} <button class="osgs-link" data-action="play-all">Play all enabled slides</button></div>
 <div class="osgs-list">${d.slides.length?d.slides.map((s,i)=>`<div class="osgs-slide"><label class="osgs-check"><input type="checkbox" data-play="${s.id}" ${!chosen.size||chosen.has(s.id)?'checked':''} title="Include in selected playlist"></label><div class="osgs-slidevisual">${s.image?`<img src="${E(s.image)}" alt="">`:'✎'}</div><div class="osgs-slidetext"><b>${E(s.title)}</b><small>${s.kind==='product'?'WooCommerce · '+E(s.price)+' '+E(d.settings.currency):'Text slide'} · ${s.duration}s ${s.auto_paused?'· Out of stock':''}</small></div><span class="osgs-chip ${s.enabled&&!s.auto_paused?'ok':''}">${s.auto_paused?'Unavailable':s.enabled?'Active':'Off'}</span><div class="osgs-iconactions"><button data-move="${s.id}:up" title="Move up" ${i===0?'disabled':''}>↑</button><button data-move="${s.id}:down" title="Move down" ${i===d.slides.length-1?'disabled':''}>↓</button><button data-edit="${s.id}" title="Edit">✎</button><button data-switch="${s.id}" title="Enable/disable">${s.enabled?'◉':'○'}</button><button data-remove="${s.id}" title="Delete slide">×</button></div></div>`).join(''):'<div class="osgs-empty">No slides yet. Add products or create a text slide.</div>'}</div><div class="osgs-actions"><button class="osgs-btn blue" data-action="apply-playlist">Apply checked slide selection</button></div></section>`;}
function quickMessage(){const m=state.data.message;return `<div class="osgs-columns osgs-message-columns"><section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Instant message</h2><p>Choose a design and format letters individually. Whole-message blinking has its own switch.</p></div><span class="osgs-tag">PRIORITY</span></div>
 ${OSGSRich.markup('message-editor',m?.template||'clean',m?.html||'',m?.text||'')}
 <div class="osgs-toolbar"><label>Whole-message animation <select id="osgs-message-animation">${animationOptions(m?.animation||'none')}</select></label><label>Display for <select id="osgs-minutes"><option value="5">5 minutes</option><option value="10" selected>10 minutes</option><option value="30">30 minutes</option><option value="60">1 hour</option><option value="0">Until cleared</option></select></label></div>
 <div class="osgs-actions"><button class="osgs-btn blue" data-action="show-message">Show on screen</button><button class="osgs-btn red" data-action="clear-message">Clear message</button></div></section>
 <section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Screen controls</h2><p>Fast operational changes.</p></div></div><div class="osgs-infobox">${m?`<strong>Now showing · ${E(m.template||'clean')}</strong><p>${E(m.text).replace(/\n/g,'<br>')}</p><small>${m.until?new Date(m.until*1000).toLocaleString('en-US'):'Until cleared'}</small>`:'<strong>No priority message</strong><p>Normal playlist is running.</p>'}</div><button class="osgs-btn ${state.data.paused?'blue':''}" data-action="toggle-pause">${state.data.paused?'Resume slideshow':'Pause slideshow'}</button></section></div>`;}
function settings(){const s=state.data.settings;const d=state.data;return `<div class="osgs-columns"><section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Design system</h2><p>Consistent, high-contrast shop-window graphics.</p></div></div>
 <div class="osgs-formgrid"><label>Screen name<input id="set-screen_title" value="${E(s.screen_title)}"></label><label>Price label<input id="set-currency" value="${E(s.currency)}"></label><label>Default duration (seconds)<input id="set-duration" type="number" min="3" max="120" value="${s.duration}"></label><label>Refresh interval (seconds)<input id="set-poll" type="number" min="3" max="60" value="${s.poll}"></label><label>Words in product name<input id="set-short_words" type="number" min="1" max="5" value="${s.short_words}"></label><label>Price banner color<input id="set-price_bg" type="color" value="${E(s.price_bg)}"></label><label>Accent<input id="set-accent" type="color" value="${E(s.accent)}"></label><label>Background<input id="set-background" type="color" value="${E(s.background)}"></label></div><button class="osgs-btn blue" data-action="save-settings">Save design & settings</button></section>
 <section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Screen & mobile access</h2><p>Permanent public display URL and secure mobile controls.</p></div></div><div class="osgs-infobox"><b>Permanent screen address — no key</b><p><a href="${E(d.screen_url)}" target="_blank" rel="noopener noreferrer">${E(d.screen_url)}</a></p><button class="osgs-btn" data-action="copy-screen-url">Copy screen URL</button><p class="osgs-muted">Everyone who knows this URL can view the current slides and messages, but cannot control them.</p></div><div class="osgs-infobox"><b>Mobile quick control — protected</b><p>${d.has_quick?'A secure phone link exists.':'No quick control link yet.'}</p><button class="osgs-btn" data-action="gen-quick">${d.has_quick?'Rotate mobile link':'Generate mobile link'}</button></div>${state.generated?`<div class="osgs-result"><b>Copy this link now (shown once)</b><input id="osgs-generated" readonly value="${E(state.generated)}"><button class="osgs-btn blue" data-action="copy-link">Copy link</button></div>`:''}<p class="osgs-muted">Keep mobile control access keys private. Regenerating a mobile key invalidates the old mobile link.</p></section></div>
 <section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Migration from Node.js</h2><p>Import your existing slides.json. This never changes the running Node.js application.</p></div></div><input type="file" id="osgs-importfile" accept="application/json,.json"><button class="osgs-btn" data-action="import">Import legacy slides.json</button><p class="osgs-muted">Plain text, image URLs, slide durations and on/off status are imported. Complex HTML effects are not transferred automatically. Check the results before switching display links.</p></section>`;}
function animationOptions(selected='none'){
 const choices=[['none','◻️ No animation (static)'],['soft','💫 Soft blink — entire message'],['blink','✨ Normal blink — entire message']];
 return choices.map(([mode,label])=>`<option value="${mode}" ${mode===selected?'selected':''}>${label}</option>`).join('');
}
function telegram(){const t=state.data.telegram;const q=state.data.settings;return `<div class="osgs-columns"><section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Telegram Bot</h2><p>Command the display directly from Telegram. Server-only credentials.</p></div><span class="osgs-tag ${t.webhook?'success':''}">${t.webhook?'WEBHOOK CONNECTED':'SETUP'}</span></div>
 <div class="osgs-form"><label>Bot token (from @BotFather)<input id="tg-token" type="password" autocomplete="off" placeholder="${t.configured?'Configured — leave blank to keep':'123456:AA…'}"></label><label>Allowed Chat IDs<input id="tg-chats" value="${E(t.chats||'')}" placeholder="e.g. 123456789"></label><label>Allowed User IDs (recommended; required for groups)<input id="tg-users" value="${E(t.users||'')}" placeholder="e.g. 123456789"></label></div><p class="osgs-muted">In Telegram send <code>/id</code> to the bot to discover your IDs. Changes are denied unless allowed.</p><div class="osgs-actions"><button class="osgs-btn blue" data-action="tg-save">Save Telegram settings</button><button class="osgs-btn" data-action="tg-register">Register secure webhook</button></div></section>
 <section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>⚡ One-tap Telegram buttons</h2><p>These three actions appear at the top of the Telegram main menu. One press executes immediately — no confirmation.</p></div></div>
 <div class="osgs-infobox"><b>🧹 Cancel temporary message</b><p>Immediately clears the temporary announcement and returns to the normal slides. No text or timer needed.</p></div>
 <div class="osgs-quick-section"><h3>🍽 Lunch break</h3><p class="osgs-muted">Select text to format just those words.</p>
 ${OSGSRich.markup('tg-quick-lunch-editor',q.telegram_lunch_template||'notice',q.telegram_lunch_html||'',q.telegram_lunch_text||'',{showPreview:false})}
 <div class="osgs-formgrid"><label>Display duration (minutes · 0 = until cleared)<input id="tg-quick-lunch-minutes" type="number" min="0" max="1440" step="1" value="${E(q.telegram_lunch_minutes??60)}"></label>
</div>
 </div>
 <div class="osgs-quick-section"><h3>⏳ Back soon</h3><p class="osgs-muted">Create a separate design and highlight individual words.</p>
 ${OSGSRich.markup('tg-quick-soon-editor',q.telegram_soon_template||'notice',q.telegram_soon_html||'',q.telegram_soon_text||'',{showPreview:false})}
 <div class="osgs-formgrid"><label>Display duration (minutes · 0 = until cleared)<input id="tg-quick-soon-minutes" type="number" min="0" max="1440" step="1" value="${E(q.telegram_soon_minutes??10)}"></label>
</div>
 </div>
 <p class="osgs-muted">The Telegram buttons still publish instantly with one tap, without a confirmation step. Formatting and template settings are stored privately in WordPress.</p>
 <button class="osgs-btn blue" data-action="tg-quick-save">Save quick-button messages</button></section>
 <section class="osgs-panel"><div class="osgs-sectionhead"><div><h2>Interactive Telegram Control Center</h2><p>Send <code>/start</code> to open the inline dashboard. Choose WooCommerce products, build playlists, preview and publish timed messages, pause the player and sync prices using buttons.</p></div></div><div class="osgs-commandlist">${['/start','/status','/message 10m Back soon!','/message 0m Closed for lunch','/clear','/slides','/on 123','/off 123','/select 123,456','/all','/pause','/resume','/id'].map(x=>`<code>${E(x)}</code>`).join('')}</div><div class="osgs-infobox"><small>After upgrading</small><p>Click <b>Register secure webhook</b> again to enable Telegram button events (callback_query). Existing bot token and allowlists are preserved.</p></div><div class="osgs-infobox"><small>Webhook endpoint</small><p class="osgs-wrap">${E(t.webhook_url)}</p></div></section></div>`;}
function modal(){if(!state.modal)return '';const s=state.modal.slide||{};const editing=state.modal.type==='edit';const textEditor=(!editing||s.kind==='text');return `<div class="osgs-modalback" id="osgs-modalback"><div class="osgs-modal" role="dialog" aria-modal="true" aria-label="Slide editor"><div class="osgs-sectionhead"><h2>${editing?'Edit slide':'Create text slide'}</h2><button class="osgs-x" data-action="close-modal">×</button></div><label>${textEditor?'Slide name (admin only — not displayed on screen)':'Product title'}<input id="modal-title" value="${E(s.title||'')}"></label>${textEditor?OSGSRich.markup('slide-editor',s.template||'clean',s.html||'',s.text||''):''}<label>Duration (seconds)<input type="number" min="3" max="120" id="modal-duration" value="${s.duration||8}"></label><div class="osgs-actions"><button class="osgs-btn blue" data-action="save-modal">Save slide</button><button class="osgs-btn" data-action="close-modal">Cancel</button></div></div></div>`;}
function render(){if(!state.data)return;root.innerHTML=`<div class="osgs-shell">${sidebar()}<main class="osgs-main">${top()}<div class="osgs-content">${({overview,products,slides,message:quickMessage,settings,telegram})[state.section]()}</div><div class="osgs-copyright">OpenSignage Studio · WordPress + WooCommerce · Native & independent</div></main></div>${modal()}`;OSGSRich.init(root);}
async function loadCategories(){const d=await req('categories');const byId=new Map(d.categories.map(x=>[x.id,x]));state.categories=d.categories.map(x=>{let c=x,label=c.name,i=0;while(c.parent&&byId.has(c.parent)&&i++<6){c=byId.get(c.parent);label=c.name+' / '+label;}return {...x,label};}).sort((a,b)=>a.label.localeCompare(b.label,'en'));}
async function loadProducts(page=1){const q=new URLSearchParams({page:String(page),category:String(state.category),search:state.search});const d=await req('products?'+q);state.products=d.products;state.page=d.page;state.pages=d.pages;state.total=d.total;render();}
async function navigate(to){state.section=to;state.modal=null;if(to==='products' && !state.categories.length) await loadCategories();render();}
function val(id){return document.getElementById(id)?.value??'';}
async function execAction(action){switch(action){
 case 'reload':await refresh();toast('Updated');break;
 case 'sync':{const x=await req('sync','POST',{});await refresh();toast('Synchronized '+x.synced+' products');break;}
 case 'find':state.category=val('osgs-category');state.search=val('osgs-search').trim();await loadProducts(1);break;
 case 'prev':await loadProducts(Math.max(1,state.page-1));break;
 case 'next':await loadProducts(Math.min(state.pages,state.page+1));break;
 case 'select-page':state.products.forEach(x=>state.selected.set(x.id,x));render();break;
 case 'clear-selection':state.selected.clear();render();break;
 case 'add-products':{if(!state.selected.size)return;const d=await req('slides','POST',{kind:'products',ids:[...state.selected.keys()]});state.selected.clear();await refresh();toast(d.added+' created · '+d.skipped+' already present or unavailable');break;}
 case 'new-text':state.modal={type:'new'};render();break;
 case 'close-modal':state.modal=null;render();break;
 case 'save-modal':{const s=state.modal.slide;const rich=OSGSRich.value('slide-editor');if(state.modal.type==='edit'){await req('slide/'+s.id,'POST',{title:val('modal-title'),...(s.kind==='text'?rich:{}),duration:Number(val('modal-duration'))});}else{await req('slides','POST',{kind:'text',title:val('modal-title'),...rich,duration:Number(val('modal-duration'))});}state.modal=null;await refresh();toast('Slide saved');break;}
 case 'play-all':await req('playlist','POST',{ids:[]});await refresh();toast('All enabled slides selected');break;
 case 'apply-playlist':{const selected=[...document.querySelectorAll('[data-play]:checked')].map(el=>Number(el.dataset.play));if(selected.length===0){toast('Choose at least one slide, or use “Play all”.',true);return;}await req('playlist','POST',{ids:selected});await refresh();toast('Playlist updated');break;}
 case 'show-message':await req('message','POST',{...OSGSRich.value('message-editor'),minutes:Number(val('osgs-minutes')),animation:val('osgs-message-animation')});await refresh();toast('Message is now live');break;
 case 'clear-message':await req('message','POST',{text:''});await refresh();toast('Message cleared');break;
 case 'toggle-pause':await req('playlist','POST',{paused:!state.data.paused});await refresh();break;
 case 'save-settings':{const payload={};for(const x of ['screen_title','currency','duration','poll','short_words','price_bg','accent','background'])payload[x]=val('set-'+x);await req('settings','POST',payload);await refresh();toast('Settings saved');break;}
 case 'gen-quick':{if(!confirm('Generating a new mobile control link will revoke the previous link. Continue?'))return;const d=await req('access','POST',{kind:'quick'});state.generated=d.url;await refresh();toast('New mobile control link generated — copy it now');break;}
 case 'copy-screen-url':{const t=state.data.screen_url;if(navigator.clipboard)await navigator.clipboard.writeText(t);else{const el=document.createElement('textarea');el.value=t;el.style.position='fixed';el.style.opacity='0';document.body.append(el);el.select();document.execCommand('copy');el.remove();}toast('Screen URL copied');break;}
 case 'copy-link':{const t=val('osgs-generated');if(navigator.clipboard)await navigator.clipboard.writeText(t);else{document.getElementById('osgs-generated').select();document.execCommand('copy');}toast('Link copied');break;}
 case 'tg-quick-save':{
  const payload={};
  for(const key of ['lunch','soon']){
    const rich=OSGSRich.value('tg-quick-'+key+'-editor');
    if(!rich.text.trim()){toast('Message text cannot be empty',true);return;}
    if(rich.text.length>1200){toast('Messages must be at most 1200 characters',true);return;}
    const raw=val('tg-quick-'+key+'-minutes');
    if(!/^\d{1,4}$/.test(raw)||Number(raw)>1440){toast('Duration must be 0–1440 minutes',true);return;}
    payload['telegram_'+key+'_text']=rich.text;
    payload['telegram_'+key+'_html']=rich.html;
    payload['telegram_'+key+'_template']=rich.template;
    payload['telegram_'+key+'_animation']='none'; // Word-level Blink / Soft blink is controlled by the editor only.
    payload['telegram_'+key+'_minutes']=Number(raw);
  }
  await req('settings','POST',payload);await refresh();toast('Formatted Telegram quick messages saved');break;
 }
 case 'tg-save':await req('telegram/config','POST',{bot_token:val('tg-token'),chats:val('tg-chats'),users:val('tg-users')});await refresh();toast('Telegram settings saved');break;
 case 'tg-register':await req('telegram/webhook/register','POST',{});await refresh();toast('Webhook successfully registered');break;
 case 'import':{const f=document.getElementById('osgs-importfile')?.files[0];if(!f){toast('Choose a JSON file',true);return;}const data=JSON.parse(await f.text());if(!Array.isArray(data))throw Error('Expected slides.json array');if(!confirm('Import '+data.length+' slides as additional slides?'))return;const r=await req('import','POST',{slides:data});await refresh();toast('Imported '+r.imported+' legacy slides');break;}
 } }
OSGSRich.mount(root);
root.addEventListener('click',e=>{const nav=e.target.closest('[data-nav]');if(nav){task(()=>navigate(nav.dataset.nav));return;}
const action=e.target.closest('[data-action]');if(action){task(()=>execAction(action.dataset.action));return;}
const remove=e.target.closest('[data-remove]');if(remove){task(async()=>{if(!confirm('Delete this slide permanently?'))return;await req('slide/'+remove.dataset.remove,'DELETE');await refresh();toast('Slide deleted');});return;}
const toggle=e.target.closest('[data-switch]');if(toggle){task(async()=>{const x=state.data.slides.find(s=>s.id===Number(toggle.dataset.switch));await req('slide/'+x.id,'POST',{enabled:!x.enabled});await refresh();});return;}
const edit=e.target.closest('[data-edit]');if(edit){const x=state.data.slides.find(s=>s.id===Number(edit.dataset.edit));state.modal={type:'edit',slide:x};render();return;}
const move=e.target.closest('[data-move]');if(move){task(async()=>{const [id,direction]=move.dataset.move.split(':');const ids=state.data.slides.map(s=>s.id);const n=ids.indexOf(Number(id));const dest=n+(direction==='up'?-1:1);if(dest<0||dest>=ids.length)return;[ids[n],ids[dest]]=[ids[dest],ids[n]];await req('order','POST',{ids});await refresh();});return;}
});
root.addEventListener('change',e=>{const el=e.target;if(el.matches('[data-pick]')){const p=state.products.find(x=>x.id===Number(el.dataset.pick));if(el.checked)state.selected.set(p.id,p);else state.selected.delete(p.id);render();}});
root.addEventListener('keydown',e=>{if(e.key==='Enter'&&e.target.id==='osgs-search'){e.preventDefault();task(()=>execAction('find'));}});
(async()=>{try{await refresh();}catch(e){root.innerHTML='<div class="osgs-error">Could not load Display Studio: '+E(e.message)+'</div>';}})();
})();
