const assert=require('node:assert/strict'),fs=require('node:fs'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<div id="htsp-map-app"></div>',{runScripts:'outside-only',url:'https://example.com/wp-admin/'}),w=dom.window;
w.HTSPMap={ajax:'/ajax',nonce:'test'};w.confirm=()=>true;w.alert=message=>{throw Error(message);};w.IntersectionObserver=class{observe(){}unobserve(){}disconnect(){}};
const nodes=[
 {id:1,title:'Home',slug:'home',language:'en',group:'landing',categories:['growth'],menus:[{menu:'classic:2',menu_label:'English'}]},
 {id:2,title:'Inizio',slug:'inizio',language:'it',group:'landing',categories:[],menus:[]},
 {id:3,title:'Privacy',slug:'privacy',language:'en',group:'service',categories:[],menus:[]}
].map(n=>({...n,status:'publish',preview_url:'https://example.com/'+n.slug}));
let pageWrite,menuWrite;
const model=()=>({node:nodes[0],fields:{post_title:nodes[0].title,post_name:nodes[0].slug,page_group:'landing',language:'en'},taxonomies:{htsp_page_category:{label:'Categorie',value:'growth',editable:true}},groups:{'':'Non classificata',landing:'Landing page',service:'Servizio del sito'},languages:['en','it'],token:'page-v1',menus:{token:'menu-v1',can_edit:true,menus:[{key:'classic:2',kind:'classic',label:'English',items:[{key:'9',parent:'0',label:'Start',current:false,page:99},{key:'10',parent:'9',label:'Home',current:true,page:1}]}]}});
w.fetch=async(_,init)=>{const q=new URLSearchParams(init.body);let data;
 switch(q.get('action')){
 case 'htsp_map_list':data={nodes,edges:[],root:1,scope:{},scope_note:'Scope',groups:model().groups,categories:[{slug:'growth',name:'Growth'}],permissions:{publish:true},generated_at:'now'};break;
 case 'htsp_map_page':data=model();break;
 case 'htsp_map_write':pageWrite=JSON.parse(q.get('payload'));nodes[0].title=pageWrite.fields.post_title;data={page:model()};break;
 case 'htsp_map_menus':menuWrite=JSON.parse(q.get('payload'));data=model().menus;break;
 default:throw Error(q.get('action'));
 }return {ok:true,json:async()=>({success:true,data})};};
w.eval(fs.readFileSync(__dirname+'/../assets/map-layout.js','utf8'));w.eval(fs.readFileSync(__dirname+'/../assets/visual-map.js','utf8'));
const tick=()=>new Promise(r=>setImmediate(r)),doc=w.document;
const button=(name,scope=doc)=>[...scope.querySelectorAll('button')].find(b=>b.textContent===name);
const filter=(name,value)=>{const s=doc.getElementById('htsp-map-'+name);s.value=value;s.dispatchEvent(new w.Event('change'));};
const count=()=>doc.querySelectorAll('figure').length;
(async()=>{
 await tick();assert.equal(count(),3);
 filter('language','it');assert.equal(count(),1);assert(doc.querySelector('figure').textContent.includes('Inizio'));
 filter('group','service');assert.equal(count(),0);button('Azzera filtri').click();assert.equal(count(),3);
 filter('category','growth');assert.equal(count(),1);filter('menu','none');assert.equal(count(),0);button('Azzera filtri').click();
 filter('group','service');assert.equal(count(),1);button('Azzera filtri').click();
 button('Modifica qui',doc.querySelector('[data-page-id="1"]')).click();await tick();
 let node=doc.querySelector('.is-editing');assert(node);assert.equal(doc.querySelectorAll('#htsp-map-fields').length,1);
 const title=node.querySelector('[data-field="post_title"]');title.value='New Home';title.dispatchEvent(new w.Event('input'));
 assert(node.querySelector('.htsp-menu-row input').disabled);
 button('Salva dati pagina',node).click();await tick();await tick();assert.equal(pageWrite.token,'page-v1');assert.deepEqual(pageWrite.fields,{post_title:'New Home'});assert.deepEqual(pageWrite.tax,{});assert(!('post_content' in pageWrite.fields));
 node=doc.querySelector('.is-editing');const label=node.querySelector('.htsp-menu-row input');label.value='Start Here';label.dispatchEvent(new w.Event('input'));
 assert(doc.getElementById('htsp-map-fields').disabled);button('Salva voce',node).click();await tick();await tick();
 assert.equal(menuWrite.label,'Start Here');assert.equal(menuWrite.parent,'9');assert.equal(menuWrite.item,'10');assert.equal(menuWrite.token,'menu-v1');assert.equal(menuWrite.id,1);
 button('Chiudi',doc.querySelector('.is-editing')).click();assert.equal(doc.querySelector('.is-editing'),null);
 console.log('Management UI: combined filters, node edit, sparse page payload, independent menu save, token forwarding, dirty isolation and close passed.');w.close();
})().catch(e=>{console.error(e);process.exitCode=1;w.close();});
