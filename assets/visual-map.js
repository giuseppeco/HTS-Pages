(function(){
 'use strict';
 const host=document.getElementById('htsp-map-app'),L=window.HTSPMapLayout;
 const S={data:null,selected:0,root:0,zoom:1,search:'',status:'',focus:false,trash:false,model:null,dirty:false,busy:false,positions:null,panelMode:'properties',sequence:null};
 const labels={post_title:'Titolo',post_name:'Slug',post_status:'Stato',post_author:'Autore',post_parent:'Genitore WordPress (gerarchia)',menu_order:'Ordine',post_excerpt:'Riassunto',map_description:'Descrizione unica sotto la miniatura',post_date:'Data pubblicazione locale',post_date_gmt:'Data pubblicazione UTC',comment_status:'Commenti',ping_status:'Ping',post_template:'Template',thumbnail_id:'ID immagine in evidenza',language:'Lingua',post_password:'Nuova password (vuoto = rimuovi)',post_content:'Contenuto completo: sorgente blocchi WordPress'};
 function el(tag,attrs={},...children){const n=document.createElement(tag);Object.entries(attrs).forEach(([k,v])=>{if(k==='class')n.className=v;else if(k.startsWith('on'))n.addEventListener(k.slice(2),v);else if(k in n&&!k.startsWith('aria-'))n[k]=v;else n.setAttribute(k,v);});children.flat().filter(c=>c!==null&&c!==undefined).forEach(c=>n.append(c.nodeType?c:document.createTextNode(String(c))));return n;}
 const btn=(label,action,cls='')=>el('button',{type:'button',class:'button '+cls,onclick:action},label);
 const $=id=>document.getElementById(id);
 async function api(action,fields={}){const response=await fetch(HTSPMap.ajax,{method:'POST',cache:'no-store',credentials:'same-origin',body:new URLSearchParams({action:'htsp_map_'+action,nonce:HTSPMap.nonce,...fields})});let json;try{json=await response.json();}catch(e){throw Error('Risposta non leggibile. Ricaricare per verificare lo stato prima di riprovare una modifica.');}if(!response.ok||!json.success)throw Error(json.data?.message||'Operazione non riuscita.');return json.data;}
 function say(message,error=false){const n=$('htsp-map-message');if(n){n.textContent=message;n.classList.toggle('is-error',error);}}
 function setBusy(value){S.busy=value;host.setAttribute('aria-busy',String(value));host.querySelectorAll('[data-lock]').forEach(n=>{n.disabled=value||n.dataset.permanentDisabled==='1';});const form=$('htsp-map-fields');if(form)form.disabled=value||(S.panelMode==='properties'&&S.model?.node.status==='trash');}
 function confirmDiscard(){return !S.dirty||window.confirm('Scartare le modifiche non ancora salvate?');}
 function shell(){
  const search=el('input',{id:'htsp-map-search',type:'search',placeholder:'Cerca titolo o descrizione…','aria-label':'Cerca pagine'});
  search.addEventListener('input',()=>{S.search=search.value;draw();});
  const status=el('select',{id:'htsp-map-status','aria-label':'Filtra stato'},el('option',{value:''},'Tutti gli stati'),...['publish','private','draft','pending','future','trash'].map(s=>el('option',{value:s},s)));
  status.addEventListener('change',()=>{S.status=status.value;draw();});
  const root=el('select',{id:'htsp-map-root','aria-label':'Radice visuale'});root.addEventListener('change',()=>{S.root=Number(root.value);draw();fit();});
  const focus=el('input',{type:'checkbox',id:'htsp-map-focus',onchange:event=>{S.focus=event.target.checked;draw();fit();}});
  const trash=el('input',{type:'checkbox',id:'htsp-map-show-trash',onchange:async event=>{if(S.busy)return;S.trash=event.target.checked;await load();}});
  const viewport=el('div',{id:'htsp-map-viewport',tabIndex:0,'aria-label':'Mappa delle pagine: scorrere per esplorare, usare Tab per selezionare una pagina'},el('div',{id:'htsp-map-space'},el('div',{id:'htsp-map-canvas'})));
  let drag=null;viewport.addEventListener('pointerdown',e=>{if(e.target.closest('.htsp-map-node')||e.button!==0)return;drag={x:e.clientX,y:e.clientY,left:viewport.scrollLeft,top:viewport.scrollTop};viewport.setPointerCapture(e.pointerId);viewport.classList.add('is-panning');});
  viewport.addEventListener('pointermove',e=>{if(drag){viewport.scrollLeft=drag.left+drag.x-e.clientX;viewport.scrollTop=drag.top+drag.y-e.clientY;}});
  const stop=()=>{drag=null;viewport.classList.remove('is-panning');};viewport.addEventListener('pointerup',stop);viewport.addEventListener('pointercancel',stop);
  host.replaceChildren(el('div',{class:'htsp-map-toolbar'},btn('＋ Nuova pagina',()=>openNew(),'button-primary'),btn('↻ Aggiorna collegamenti',()=>load(true)),search,status,el('label',{},'Radice ',root),el('label',{},focus,' Solo selezionata e collegamenti diretti'),el('label',{},trash,' Includi cestino')),
   el('div',{class:'htsp-map-toolbar'},btn('−',()=>zoom(S.zoom/1.2)),btn('＋',()=>zoom(S.zoom*1.2)),btn('Adatta',fit),el('span',{id:'htsp-map-zoom'},'100%'),el('span',{class:'htsp-map-legend'},'→ Direzione del link · Viola: entra nella selezionata · Corallo: esce dalla selezionata')),
   el('p',{id:'htsp-map-message',role:'status'}),el('p',{id:'htsp-map-scope',class:'htsp-map-scope'}),viewport,
   el('aside',{id:'htsp-map-drawer',class:'htsp-map-drawer',role:'dialog','aria-modal':'false','aria-labelledby':'htsp-map-drawer-title',hidden:true}));
  host.addEventListener('keydown',e=>{if(e.key==='Escape'&&!S.busy&&!$('htsp-map-drawer').hidden)closeDrawer();});
  host.querySelectorAll('.htsp-map-toolbar button').forEach(n=>n.setAttribute('data-lock','1'));
 }
 async function load(refresh=false){
  if(S.busy)return;setBusy(true);say('Lettura pagine e collegamenti…');
  try{
   S.data=await api('list',{trash:S.trash?'1':'',refresh:refresh?'1':''});
   if(!S.root)S.root=S.data.root||S.data.nodes[0]?.id||0;
   const root=$('htsp-map-root');root.replaceChildren(...S.data.nodes.map(n=>el('option',{value:n.id,selected:Number(n.id)===S.root},n.title+' (#'+n.id+')')));
   $('htsp-map-scope').textContent=S.data.scope_note+(S.data.limited?' ATTENZIONE: limite di '+S.data.limit+' pagine raggiunto; mappa incompleta.':'')+(S.data.scope.dom_unavailable?' PHP DOM assente: lettura dei link HTML incompleta.':'')+(S.data.scope.reference_limit?' Alcuni riferimenti ricorsivi/profondi sono stati esclusi.':'');
   draw();fit();
  }catch(error){say(error.message,true);}finally{setBusy(false);}
 }
 function visible(){
  if(!S.data)return {nodes:[],edges:[]};
  let nodes=S.data.nodes.filter(n=>(!S.status||n.status===S.status)&&(!S.search||[n.title,n.description,String(n.id)].join(' ').toLocaleLowerCase().includes(S.search.toLocaleLowerCase())));
  if(S.focus&&S.selected){const ids=new Set([S.selected]);S.data.edges.forEach(e=>{if(e.source===S.selected)ids.add(e.target);if(e.target===S.selected)ids.add(e.source);});nodes=nodes.filter(n=>ids.has(n.id));}
  const ids=new Set(nodes.map(n=>n.id));return {nodes,edges:S.data.edges.filter(e=>ids.has(e.source)&&ids.has(e.target))};
 }
 function svg(tag,attrs){const n=document.createElementNS('http://www.w3.org/2000/svg',tag);Object.entries(attrs).forEach(([k,v])=>n.setAttribute(k,String(v)));return n;}
 let observer=null;
 function draw(){
  if(!S.data)return;const view=visible(),canvas=$('htsp-map-canvas');S.positions=L.compute(view.nodes,view.edges,S.root);
  if(observer)observer.disconnect();observer=new IntersectionObserver(entries=>{entries.forEach(entry=>{if(entry.isIntersecting){const frame=entry.target;frame.src=frame.dataset.src;observer.unobserve(frame);}});},{root:$('htsp-map-viewport'),rootMargin:'180px'});
  const lines=svg('svg',{class:'htsp-map-lines',width:S.positions.width+80,height:S.positions.height,'aria-hidden':'true'}),defs=svg('defs',{});
  for(const [name,color] of [['normal','#a7a0b4'],['in','#4b286d'],['out','#c13f3e']]){const marker=svg('marker',{id:'htsp-arrow-'+name,viewBox:'0 0 10 10',refX:9,refY:5,markerWidth:7,markerHeight:7,orient:'auto'});marker.append(svg('path',{d:'M 0 0 L 10 5 L 0 10 z',fill:color}));defs.append(marker);}lines.append(defs);
  for(const edge of view.edges){const kind=edge.source===S.selected?'out':edge.target===S.selected?'in':'normal';const p=svg('path',{d:L.edgePath(S.positions.positions[edge.source],S.positions.positions[edge.target]),class:'htsp-map-edge edge-'+kind,'marker-end':'url(#htsp-arrow-'+kind+')'});lines.append(p);}
  canvas.replaceChildren(lines);
  for(const node of view.nodes){
   const pos=S.positions.positions[node.id];const figure=el('figure',{class:'htsp-map-node'+(S.selected===node.id?' is-selected':''),'data-page-id':node.id});figure.style.left=pos.x+'px';figure.style.top=pos.y+'px';
   const viewport=el('div',{class:'htsp-map-thumb'});
   if(node.image)viewport.append(el('img',{src:node.image,alt:'',loading:'lazy'}));
   if(node.preview_url){const frame=el('iframe',{'data-src':node.preview_url,title:'',tabIndex:-1,'aria-hidden':'true',sandbox:'allow-same-origin',referrerPolicy:'same-origin'});viewport.append(frame);observer.observe(frame);}else viewport.append(el('span',{class:'htsp-map-no-preview'},node.virtual?node.title:'Pagina nel cestino'));
   const caption=el('figcaption',{},node.description||node.title);const button=el('button',{type:'button',class:'htsp-map-node-open','aria-label':'Proprietà di '+node.title+' (#'+node.id+')',title:node.title+' · '+node.status,onclick:()=>openPage(node.id)});
   const actions=node.virtual?el('div',{class:'htsp-map-node-actions'},el('span',{},node.title+' · elemento condiviso')):el('div',{class:'htsp-map-node-actions'},btn('Sequenza blocchi',()=>openStructure(node.id,'blocks')),btn('Percorsi ipotizzati',()=>openStructure(node.id,'paths')));
   const c=node.link_counts||{in:0,post:0,page:0,ext:0,total:0};
   const metrics=el('div',{class:'htsp-map-metrics'+(c.in===0?' has-zero-in':'')},
     ...[['IN','in'],['POST','post'],['PAGE','page'],['EXT','ext'],['TOT','total']].map(([label,key])=>el('button',{type:'button',title:'Mostra '+label+' di '+node.title,'aria-label':label+': '+(c[key]||0)+' · '+node.title,onclick:event=>{event.stopPropagation();openLinkList(node.id,key);}},el('b',{},String(c[key]||0)),el('small',{},label))));
   figure.append(metrics,viewport,caption,button,actions);canvas.append(figure);
  }
  if(!view.nodes.length)canvas.append(el('p',{class:'htsp-map-empty'},'Nessuna pagina corrisponde ai filtri.'));
  canvas.style.width=S.positions.width+80+'px';canvas.style.height=S.positions.height+'px';applyScale();
  const hidden=S.data.edges.length-view.edges.length;say(view.nodes.length+' / '+S.data.nodes.length+' pagine · '+view.edges.length+' collegamenti visibili'+(hidden?' · '+hidden+' fuori filtro':'')+' · '+S.positions.backEdges+' rimandi trasversali/di ritorno. Dati: '+S.data.generated_at);
 }
 function applyScale(){if(!S.positions)return;$('htsp-map-canvas').style.transform='scale('+S.zoom+')';$('htsp-map-space').style.width=(S.positions.width+80)*S.zoom+'px';$('htsp-map-space').style.height=S.positions.height*S.zoom+'px';$('htsp-map-zoom').textContent=Math.round(S.zoom*100)+'%';}
 function zoom(value){S.zoom=Math.max(.15,Math.min(2,value));applyScale();}
 function fit(){if(!S.positions)return;const width=$('htsp-map-viewport').clientWidth||1000;zoom(Math.min(1,(width-30)/(S.positions.width+80)));$('htsp-map-viewport').scrollTop=0;}
 function openLinkList(id,key){
  if(S.busy||!confirmDiscard())return;
  const node=S.data.nodes.find(n=>n.id===id);if(!node)return;
  const labels={in:'Collegamenti in ingresso',post:'Articoli puntati',page:'Pagine puntate',ext:'Collegamenti esterni',total:'Tutti i collegamenti in uscita'};
  let items=node.link_objects?.[key];
  if(!Array.isArray(items)&&key==='in')items=S.data.edges.filter(e=>e.target===id).map(e=>S.data.nodes.find(n=>n.id===e.source)).filter(Boolean);
  items=Array.isArray(items)?items:[];S.selected=id;S.dirty=false;draw();
  const drawer=$('htsp-map-drawer');drawer.hidden=false;
  const list=el('ul',{class:'htsp-map-link-objects'});
  items.forEach(item=>{
   const row=el('li',{},el('strong',{},item.title||item.url||'Oggetto senza titolo'));
   if(item.id)row.append(el('span',{},' · '+(item.virtual?'elemento condiviso':'#'+item.id)));
   if(/^https?:\/\//i.test(item.url||''))row.append(el('a',{href:item.url,target:'_blank',rel:'noopener noreferrer'},item.url));
   if(S.data.nodes.some(n=>n.id===item.id))row.append(btn('Mostra nella mappa',()=>openPage(item.id)));
   list.append(row);
  });
  drawer.replaceChildren(el('h2',{id:'htsp-map-drawer-title'},labels[key]+' · '+node.title),el('p',{},items.length+' oggetti'),items.length?list:el('p',{},'Nessun collegamento rilevato in questa categoria.'),btn('Chiudi',closeDrawer));
 }
 async function openPage(id){
  const virtual=S.data?.nodes.find(n=>n.id===Number(id)&&n.virtual);
  if(virtual){
   if(S.busy||!confirmDiscard())return;S.selected=Number(id);S.dirty=false;draw();
   const drawer=$('htsp-map-drawer');drawer.hidden=false;
   drawer.replaceChildren(el('h2',{id:'htsp-map-drawer-title'},virtual.title),el('p',{},virtual.description),el('p',{},'Elemento condiviso in sola lettura: modifica il menu o il template nella sua applicazione.'),related('Collegamenti in uscita',S.data.edges.filter(e=>e.source===Number(id)).map(e=>e.target)),el('a',{href:virtual.editor_url},'Apri Site Editor'),btn('Chiudi',closeDrawer));return;
  }
  if(S.busy||!confirmDiscard())return;setBusy(true);
  try{const model=await api('page',{id});S.selected=Number(id);S.model=model;S.panelMode='properties';S.dirty=false;draw();renderDrawer();}
  catch(error){say(error.message,true);}finally{setBusy(false);}
 }
 function openNew(){
  if(S.busy||!confirmDiscard())return;S.panelMode='properties';S.selected=0;S.model={node:{id:0,title:'Nuova pagina',status:'draft'},fields:{post_title:'',post_name:'',post_status:'draft',post_excerpt:'',map_description:'',post_content:'',post_parent:'0',menu_order:'0',comment_status:'closed',ping_status:'closed'},system:{},meta:{},taxonomies:{},templates:{default:'Predefinito'},languages:[],authors:[],can_publish:S.data.permissions?.publish??false,can_author:false};S.dirty=false;draw();renderDrawer();
 }
 function closeDrawer(){if(S.busy||!confirmDiscard())return;S.dirty=false;$('htsp-map-drawer').hidden=true;const button=host.querySelector('[data-page-id="'+S.selected+'"] button');if(button)button.focus();}
 function markDirty(){S.dirty=true;const state=$('htsp-map-edit-state');if(state)state.textContent='Modifiche non salvate.';}
 function formField(key,value,options={}){
  let control;
  if(options.choices){control=el('select',{'data-field':key},...Object.entries(options.choices).map(([v,text])=>el('option',{value:v,selected:String(v)===String(value)},text)));}
  else if(options.multiline){control=el('textarea',{'data-field':key,rows:key==='post_content'?16:3,value});}
  else{control=el('input',{'data-field':key,type:options.type||'text',value});}
  control.addEventListener('input',markDirty);control.addEventListener('change',markDirty);
  return el('label',{class:'htsp-map-field'},el('span',{},labels[key]||options.label||key),control,options.help?el('small',{},options.help):null);
 }
 function detail(title,...children){return el('details',{open:true},el('summary',{},title),...children);}
 function related(title,ids){const box=el('section',{class:'htsp-map-related'},el('h3',{},title+' ('+ids.length+')'));if(!ids.length)box.append(el('p',{},'Nessuna pagina rilevata nella scansione corrente.'));ids.forEach(id=>{const n=S.data.nodes.find(n=>n.id===id);if(n)box.append(btn(n.title+' (#'+n.id+')',()=>openPage(id)));});return box;}
 function renderDrawer(){
  const m=S.model,creating=!m.node.id,drawer=$('htsp-map-drawer');drawer.hidden=false;
  const statuses={draft:'Bozza',pending:'In revisione',publish:'Pubblicata',private:'Privata',future:'Programmata'};
  if(m.node.status==='trash'){statuses.trash='Nel cestino';}
  if(!m.can_publish){delete statuses.publish;delete statuses.private;delete statuses.future;if(m.fields.post_status)statuses[m.fields.post_status]=m.fields.post_status;}
  const basic=detail('Proprietà della pagina',formField('post_title',m.fields.post_title),formField('map_description',m.fields.map_description,{multiline:true,help:'Un solo testo sotto la miniatura. Se vuoto viene usato il riassunto o il titolo.'}),formField('post_name',m.fields.post_name),formField('post_status',m.fields.post_status,{choices:statuses}),formField('post_excerpt',m.fields.post_excerpt,{multiline:true}),formField('post_parent',m.fields.post_parent,{type:'number',help:'Gerarchia WordPress: non aggiunge né rimuove le frecce dei collegamenti.'}),formField('menu_order',m.fields.menu_order,{type:'number'}));
  if(!creating){
   basic.append(formField('post_author',m.fields.post_author,{choices:{[m.fields.post_author]:'Autore #'+m.fields.post_author,...Object.fromEntries(m.authors.map(a=>[a.id,a.name+' (#'+a.id+')']))}}));basic.querySelector('[data-field="post_author"]').disabled=!m.can_author;
   basic.append(formField('post_date',m.fields.post_date),formField('post_date_gmt',m.fields.post_date_gmt));
  }
  const extra=detail('Presentazione e impostazioni',formField('post_template',m.fields.post_template||'default',{choices:{[m.fields.post_template||'default']:m.fields.post_template||'Predefinito',...m.templates}}),formField('thumbnail_id',m.fields.thumbnail_id||'',{type:'number'}),formField('comment_status',m.fields.comment_status,{choices:{closed:'Chiusi',open:'Aperti'}}),formField('ping_status',m.fields.ping_status,{choices:{closed:'Chiusi',open:'Aperti'}}));
  if(m.languages.length)extra.append(formField('language',m.fields.language,{choices:Object.fromEntries(m.languages.map(x=>[x,x]))}));
  const changePassword=el('input',{type:'checkbox',id:'htsp-map-change-password'}),password=formField('post_password','',{type:'password'});password.hidden=true;changePassword.addEventListener('change',()=>{password.hidden=!changePassword.checked;markDirty();});extra.append(el('label',{},changePassword,' Imposta, sostituisci o rimuovi password'),password);
  const content=el('details',{},el('summary',{},'Contenuto completo della pagina'),el('p',{},'La sorgente contiene i blocchi Gutenberg. Puoi modificarla qui oppure usare l’editor WordPress; il contenuto viene inviato solo se cambiato.'),formField('post_content',m.fields.post_content,{multiline:true}));
  const tax=detail('Tassonomie e metadati');
  Object.entries(m.taxonomies).forEach(([name,t])=>{const field=formField('tax:'+name,t.value,{label:t.label,help:'Slug esistenti separati da virgole.'});field.querySelector('input').disabled=!t.editable;tax.append(field);});
  Object.entries(m.meta).forEach(([key,value])=>tax.append(formField('meta:'+key,value,{label:key,multiline:true})));
  tax.append(btn('＋ Aggiungi metadato',()=>{const key=window.prompt('Nome del metadato scalare pubblico:');if(!key)return;if(!/^[A-Za-z0-9][A-Za-z0-9_-]*$/.test(key)){window.alert('Nome non valido. I metadati interni richiedono il proprio editor.');return;}if(tax.querySelector('[data-field="meta:'+key+'"]'))return;tax.append(formField('meta:'+key,'',{label:key,multiline:true}));markDirty();}));
  tax.append(el('p',{class:'htsp-map-help'},'Valori di sistema, segreti o strutturati non vengono esposti. SEO Yoast/Rank Math è disponibile quando presente.'));
  const system=detail('Proprietà di sistema — sola lettura',el('dl',{},...Object.entries(m.system).flatMap(([key,value])=>[el('dt',{},key),el('dd',{},value)])));
  const incoming=S.data.edges.filter(e=>e.target===m.node.id).map(e=>e.source),outgoing=S.data.edges.filter(e=>e.source===m.node.id).map(e=>e.target);
  const relationships=detail('Collegamenti reali',related('Genitori · pagine che puntano qui',incoming),related('Figli · pagine puntate da qui',outgoing),el('p',{},'Autorimandi nella stessa pagina: '+(S.data.self_links[m.node.id]||0)+'. La descrizione o il genitore WordPress non modificano questi collegamenti: occorre modificare i link nel contenuto sorgente.'));
  const actions=el('div',{class:'htsp-map-drawer-actions'},btn(creating?'Crea pagina':'Salva pagina',savePage,'button-primary'),btn('Ricarica proprietà',()=>openPage(m.node.id)),m.edit_url?el('a',{class:'button',href:m.edit_url,target:'_blank',rel:'noopener'},'Apri editor WordPress'):null,m.node.url?el('a',{class:'button',href:m.node.preview_url||m.node.url,target:'_blank',rel:'noopener'},'Visualizza pagina'):null);
  if(creating)actions.querySelectorAll('button')[1].hidden=true;
  if(!creating&&m.node.can_delete)actions.append(btn(m.node.status==='trash'?'Ripristina pagina':'Cancella pagina · cestino',()=>trashPage(m.node.status==='trash'),'htsp-map-danger'));
  actions.querySelectorAll('button').forEach(n=>n.setAttribute('data-lock','1'));
  drawer.replaceChildren(el('div',{class:'htsp-map-drawer-head'},el('h2',{id:'htsp-map-drawer-title'},creating?'Nuova pagina':m.node.title+' (#'+m.node.id+')'),btn('Chiudi ✕',closeDrawer)),el('p',{id:'htsp-map-edit-state',role:'status'},'Proprietà caricate. Le modifiche pubblicate sono visibili subito.'),actions,el('fieldset',{id:'htsp-map-fields',disabled:m.node.status==='trash'},basic,extra,content,tax,system),relationships);
  drawer.querySelector('.htsp-map-drawer-head button').setAttribute('data-lock','1');drawer.querySelector('.htsp-map-drawer-head button').focus();
  if(m.node.status==='trash'){const save=drawer.querySelector('.htsp-map-drawer-actions button');save.disabled=true;save.dataset.permanentDisabled='1';}
 }
 async function openStructure(id,mode){
  if(S.busy||!confirmDiscard())return;setBusy(true);
  try{S.sequence=await api('sequence',{id});S.selected=Number(id);S.panelMode=mode;S.dirty=false;draw();renderStructure();}
  catch(error){say(error.message,true);}finally{setBusy(false);}
 }
 function structureHeader(title){return el('div',{class:'htsp-map-drawer-head'},el('h2',{id:'htsp-map-drawer-title'},title+' · '+S.sequence.title),btn('Chiudi ✕',closeDrawer));}
 function renderStructure(){
  const data=S.sequence,drawer=$('htsp-map-drawer');drawer.hidden=false;
  const tabs=el('div',{class:'htsp-map-drawer-actions'},btn('Sequenza blocchi',()=>{S.panelMode='blocks';renderStructure();}),btn('Percorsi ipotizzati',()=>{S.panelMode='paths';renderStructure();}),btn('Proprietà pagina',()=>openPage(data.page_id)));
  const fieldset=el('fieldset',{id:'htsp-map-fields'});
  drawer.replaceChildren(structureHeader(S.panelMode==='blocks'?'Sequenza dei blocchi':'Percorsi ipotizzati'),tabs,el('p',{id:'htsp-map-edit-state',role:'status'},S.dirty?'Modifiche non salvate.':S.panelMode==='paths'?(data.stored?'Percorsi salvati.':'Proposte da salvare: ipotesi generate dalla struttura, non percorsi osservati.'):'Blocchi nell’ordine della pagina.'),el('p',{class:'htsp-map-help'},data.outline.note),fieldset);
  if(S.panelMode==='blocks'){
   const all=el('input',{type:'checkbox',checked:true}),list=el('div',{class:'htsp-map-block-list'});
   const drawBlocks=()=>{
    const chosen=all.checked?data.outline.blocks:data.outline.blocks.filter(b=>data.outline.section_ids.includes(b.id));list.replaceChildren();
    chosen.forEach((block,index)=>{
     const item=el('section',{class:'htsp-map-block-item'});item.style.marginLeft=Math.min(block.depth,4)*9+'px';
     item.append(el('h3',{},(index+1)+'. '+block.title),el('p',{},block.description+(block.function?' Funzione: '+block.function:'')));
     if(block.links.length){item.append(el('strong',{},'Link in uscita → pagine figlie'),el('ul',{},...block.links.map(link=>el('li',{},el('a',{href:link.url,target:'_blank',rel:'noopener noreferrer'},link.title)))));}
     else item.append(el('p',{class:'htsp-map-help'},'Nessun link a pagine figlie rilevato in questo blocco.'));
     list.append(item);
    });
   };
   all.addEventListener('change',drawBlocks);fieldset.append(el('label',{},all,' Mostra tutti i blocchi tecnici e strutturali'),list);drawBlocks();
  }else{
   if(data.stale)fieldset.append(el('p',{class:'htsp-map-warning'},'Il contenuto è cambiato dopo il salvataggio dei percorsi: rivedere i riferimenti prima di confermarli.'));
   const pathsList=el('div',{id:'htsp-map-path-list'}),byId=new Map(data.outline.blocks.map(b=>[b.id,b]));
   const redraw=()=>{
    pathsList.replaceChildren();
    data.paths.forEach((path,pathIndex)=>{
     const name=el('input',{value:path.name,'aria-label':'Nome percorso '+(pathIndex+1)});name.addEventListener('input',()=>{path.name=name.value;markDirty();});
     const list=el('ol',{});
     path.block_ids.forEach((id,index)=>{
      const options=data.outline.blocks;const select=el('select',{'aria-label':'Blocco '+(index+1)+' del percorso '+(pathIndex+1)},...options.map(b=>el('option',{value:b.id,selected:b.id===id},b.title)));
      if(!byId.has(id))select.prepend(el('option',{value:id,selected:true},'Blocco non più presente: '+id));
      select.addEventListener('change',()=>{path.block_ids[index]=select.value;markDirty();});
      const up=btn('↑',()=>{if(index){[path.block_ids[index-1],path.block_ids[index]]=[path.block_ids[index],path.block_ids[index-1]];markDirty();redraw();}}),down=btn('↓',()=>{if(index<path.block_ids.length-1){[path.block_ids[index+1],path.block_ids[index]]=[path.block_ids[index],path.block_ids[index+1]];markDirty();redraw();}});
      up.setAttribute('aria-label','Sposta blocco su');down.setAttribute('aria-label','Sposta blocco giù');up.disabled=index===0;down.disabled=index===path.block_ids.length-1;
      list.append(el('li',{},select,up,down,btn('Rimuovi',()=>{path.block_ids.splice(index,1);markDirty();redraw();})));
     });
     pathsList.append(el('section',{class:'htsp-map-path-item'},el('label',{class:'htsp-map-field'},el('span',{},'Nome percorso · ipotesi'),name),list,btn('＋ Aggiungi blocco',()=>{const first=data.outline.section_ids[0]||data.outline.blocks[0]?.id;if(first){path.block_ids.push(first);markDirty();redraw();}}),btn('Rimuovi percorso',()=>{data.paths.splice(pathIndex,1);markDirty();redraw();})));
    });
   };
   fieldset.append(el('p',{},'Ogni percorso è una lista ordinata di nomi di blocchi. Puoi rinominarlo, cambiare i blocchi e riordinarli. Le ipotesi non sono dati di navigazione misurati.'),btn('＋ Nuovo percorso',()=>{const id=data.outline.section_ids[0]||data.outline.blocks[0]?.id;data.paths.push({id:'path-'+crypto.randomUUID(),name:'Nuovo percorso',block_ids:id?[id]:[],hypothesis:true});markDirty();redraw();}),pathsList,btn('Salva percorsi',savePaths,'button-primary'));
   redraw();
  }
  drawer.querySelectorAll('.htsp-map-drawer-head button,.htsp-map-drawer-actions button').forEach(n=>n.setAttribute('data-lock','1'));drawer.querySelector('.htsp-map-drawer-head button').focus();
 }
 async function savePaths(){
  if(S.busy)return;setBusy(true);
  try{S.sequence=await api('paths',{payload:JSON.stringify({id:S.sequence.page_id,token:S.sequence.token,items:S.sequence.paths})});S.dirty=false;renderStructure();$('htsp-map-edit-state').textContent='Percorsi ipotizzati salvati. Nessun contenuto della pagina è stato modificato.';}
  catch(error){$('htsp-map-edit-state').textContent=error.message;say(error.message,true);}finally{setBusy(false);}
 }
 async function savePage(){
  if(S.busy)return;const m=S.model,fields={},tax={},meta={};
  $('htsp-map-fields').querySelectorAll('[data-field]').forEach(input=>{if(input.disabled)return;const key=input.dataset.field,val=input.value;
   if(key.startsWith('tax:')){const name=key.slice(4);if(val!==m.taxonomies[name]?.value)tax[name]=val;}
   else if(key.startsWith('meta:')){const name=key.slice(5);if(val!==(m.meta[name]??null))meta[name]=val;}
   else if(key==='post_password'){if($('htsp-map-change-password').checked)fields[key]=val;}
   else if(val!==(m.fields[key]??''))fields[key]=val;
  });
  if(!m.node.id){fields.post_title??='';fields.post_status??='draft';fields.post_content??='';}
  if(m.node.id&&!Object.keys(fields).length&&!Object.keys(tax).length&&!Object.keys(meta).length){$('htsp-map-edit-state').textContent='Nessuna modifica da salvare.';return;}
  setBusy(true);
  try{
   const result=await api('write',{payload:JSON.stringify({id:m.node.id,token:m.token,fields,tax,meta})});S.model=result.page;S.selected=result.page.node.id;S.dirty=false;
   setBusy(false);await load(true);renderDrawer();$('htsp-map-edit-state').textContent=result.message;
  }catch(error){$('htsp-map-edit-state').textContent=error.message+' Ricaricare per verificare lo stato effettivo.';say(error.message,true);}finally{setBusy(false);}
 }
 async function trashPage(restore){
  if(S.busy)return;const m=S.model;
  if(!restore&&S.dirty&&!confirmDiscard())return;
  const inCount=S.data.edges.filter(e=>e.target===m.node.id).length;
  if(!window.confirm(restore?'Ripristinare «'+m.node.title+'» dal cestino?':'Spostare «'+m.node.title+'» nel cestino? '+inCount+' pagine puntano qui; i loro link rimarranno da correggere.'))return;
  setBusy(true);
  try{const result=await api(restore?'restore':'trash',{payload:JSON.stringify({id:m.node.id,token:m.token})});S.dirty=false;$('htsp-map-drawer').hidden=true;setBusy(false);await load(true);say(result.message);}
  catch(error){$('htsp-map-edit-state').textContent=error.message;say(error.message,true);}finally{setBusy(false);}
 }
 window.addEventListener('beforeunload',e=>{if(S.dirty||S.busy){e.preventDefault();e.returnValue='';}});
 shell();load();
})();
