<?php
if (!defined('ABSPATH')) exit;
final class HTSP_Links_Map {
 static function init(){
  add_action('admin_menu',[__CLASS__,'menu'],30);
  add_action('wp_ajax_htsp_links_map',[__CLASS__,'data']);
 }
 static function menu(){add_submenu_page('hts-pages','Mappa collegamenti','Mappa collegamenti','edit_pages','htsp-links-map',[__CLASS__,'screen']);}
 static function data(){
  if(!current_user_can('edit_pages'))wp_send_json_error(['message'=>'Accesso negato'],403);
  check_ajax_referer('htsp_links_map','nonce');
  $pages=get_posts(['post_type'=>'page','post_status'=>['publish','private','draft','pending','future'],'numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
  $graph=HTS_Pages::link_graph();$nodes=[];$menu_refs=[];
  foreach(wp_get_nav_menus() as $menu){
   foreach(wp_get_nav_menu_items($menu->term_id)?:[] as $item){
    $id=0;
    if($item->type==='post_type'&&$item->object==='page')$id=(int)$item->object_id;
    elseif($item->url)$id=(int)url_to_postid($item->url);
    if($id && get_post_type($id)==='page')$menu_refs[$id][]=['title'=>$menu->name.' → '.$item->title,'url'=>$item->url];
   }
  }
  foreach($pages as $p){
   if(!current_user_can('edit_post',$p->ID))continue;
   $in=[];
   foreach($graph['incoming'][$p->ID]??[] as $src){if(current_user_can('edit_post',$src['id']))$in[]=$src;}
   $nodes[]=['id'=>$p->ID,'title'=>get_the_title($p->ID),'url'=>get_permalink($p->ID),'edit'=>get_edit_post_link($p->ID,'raw'),'menus'=>$menu_refs[$p->ID]??[],'editorial'=>$in];
  }
  wp_send_json_success(['pages'=>$nodes,'scanned'=>$graph['scanned'],'limited'=>$graph['limited']]);
 }
 static function screen(){
  if(!current_user_can('edit_pages'))return;
  $nonce=wp_create_nonce('htsp_links_map');$url=admin_url('admin-ajax.php');
  ?>
  <div class="wrap"><h1>HTS Pages · Mappa collegamenti</h1>
  <p>Verde: collegamenti dai menu. Rosso: collegamenti editoriali entranti. La mappa originale resta invariata.</p>
  <div style="display:flex;gap:20px;flex-wrap:wrap;margin:15px 0">
  <label><input type="checkbox" id="htsp-lm-menu" checked> Menu (verde)</label>
  <label><input type="checkbox" id="htsp-lm-editorial" checked> Editoriali (rosso)</label>
  <label><input type="checkbox" id="htsp-lm-zero"> Solo pagine senza ingressi</label>
  <label><input type="checkbox" id="htsp-lm-some"> Solo pagine con ingressi</label>
  </div><p id="htsp-lm-status" role="status">Caricamento collegamenti…</p>
  <div id="htsp-lm-results" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px"></div></div>
  <script>
  (()=>{const $=id=>document.getElementById(id),status=$('htsp-lm-status'),host=$('htsp-lm-results');let pages=[];
   const menu=$('htsp-lm-menu'),editorial=$('htsp-lm-editorial'),zero=$('htsp-lm-zero'),some=$('htsp-lm-some');
   zero.onchange=()=>{if(zero.checked)some.checked=false;draw()};
   some.onchange=()=>{if(some.checked)zero.checked=false;draw()};
   menu.onchange=editorial.onchange=draw;
   function draw(){host.replaceChildren();let visible=0;
    for(const p of pages){const total=p.menus.length+p.editorial.length;
     if(zero.checked&&total!==0||some.checked&&total===0)continue;visible++;
     const card=document.createElement('section');card.style.cssText='background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:14px;overflow-wrap:anywhere';
     const heading=document.createElement('h3');heading.style.margin='0 0 8px';const link=document.createElement('a');link.href=p.edit||p.url;link.textContent=p.title+' (#'+p.id+')';heading.append(link);card.append(heading);
     const summary=document.createElement('p');summary.textContent='Ingressi: '+total+' · Menu: '+p.menus.length+' · Editoriali: '+p.editorial.length;card.append(summary);
     function section(items,color,label){if(!items.length)return;const h=document.createElement('strong');h.textContent=label;h.style.color=color;card.append(h);const ul=document.createElement('ul');ul.style.margin='6px 0 12px 18px';for(const item of items){const li=document.createElement('li');const a=document.createElement('a');a.textContent=item.title;a.href=item.url||'#';a.target='_blank';a.rel='noopener noreferrer';a.style.color=color;li.append(a);ul.append(li)}card.append(ul)}
     if(menu.checked)section(p.menus,'#17803d','Dai menu');
     if(editorial.checked)section(p.editorial,'#bd2626','Da contenuti');
     host.append(card);
    }status.textContent=visible+' pagine visualizzate su '+pages.length;
   }
   const body=new URLSearchParams({action:'htsp_links_map',nonce:<?php echo wp_json_encode($nonce); ?>});
   fetch(<?php echo wp_json_encode($url); ?>,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
   .then(async r=>{const t=await r.text();let j;try{j=JSON.parse(t)}catch(e){throw Error('Risposta non JSON (HTTP '+r.status+'): '+t.slice(0,160))}if(!r.ok||!j.success)throw Error(j.data?.message||'HTTP '+r.status);return j.data})
   .then(data=>{pages=data.pages;draw();if(data.limited)status.textContent+=' · Scansione editoriale limitata a '+data.scanned+' contenuti'})
   .catch(e=>{status.textContent='Errore caricamento: '+e.message});
  })();
  </script><?php
 }
}
HTSP_Links_Map::init();
