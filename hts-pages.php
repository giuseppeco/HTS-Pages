<?php
/**
 * Plugin Name: HTS Pages
 * Description: Spreadsheet-style management of WordPress page properties without opening Gutenberg.
 * Version: 0.2.0
 * Author: HiTechStories
 * Text Domain: hts-pages
 */
if (!defined('ABSPATH')) exit;
final class HTS_Pages {
 const VERSION='0.2.0';
 const FIELDS=['post_title'=>'Title','post_name'=>'Slug','post_status'=>'Status','post_author'=>'Author ID','post_date'=>'Publication date','post_date_gmt'=>'Publication date GMT','post_parent'=>'Parent ID','menu_order'=>'Order','post_excerpt'=>'Excerpt','comment_status'=>'Comments','ping_status'=>'Pings','post_password'=>'Password','post_template'=>'Template','thumbnail_id'=>'Featured image ID','language'=>'Polylang language'];
 const SEO=['_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_focuskw','rank_math_title','rank_math_description','rank_math_focus_keyword'];
 static function init(){
   add_action('admin_menu',[__CLASS__,'menu']);
   add_action('wp_ajax_htsp_list',[__CLASS__,'listing']);
   add_action('wp_ajax_htsp_save',[__CLASS__,'save']);
 }
 static function menu(){ add_menu_page('HTS Pages','HTS Pages','edit_pages','hts-pages',[__CLASS__,'screen'],'dashicons-editor-table',25); }
 static function guard(){ if(!current_user_can('edit_pages')) wp_send_json_error(['message'=>'Permission denied'],403); check_ajax_referer('htsp_nonce','nonce'); }
 static function statuses(){return ['publish','draft','pending','private','future','trash'];}
 static function columns(){ $cols=['preview'=>'Preview','ID'=>'ID','post_title'=>'Title','post_name'=>'Slug','post_status'=>'Status','permalink'=>'URL','post_author'=>'Author ID','post_date'=>'Published (local)','post_date_gmt'=>'Published (GMT)','post_modified'=>'Modified','post_modified_gmt'=>'Modified GMT','post_parent'=>'Parent ID','menu_order'=>'Order','post_excerpt'=>'Excerpt','comment_status'=>'Comments','ping_status'=>'Pings','post_password'=>'Password','post_template'=>'Template','thumbnail_id'=>'Image ID','language'=>'Language','guid'=>'GUID','post_type'=>'Type','post_mime_type'=>'MIME','comment_count'=>'Comments #','to_ping'=>'To ping','pinged'=>'Pinged']; foreach(get_object_taxonomies('page','objects') as $tax){$cols['tax:'.$tax->name]='Taxonomy: '.$tax->label;} return $cols; }
 static function listing(){
   self::guard();
   $page=max(1,absint($_POST['page']??1));$size=min(100,max(10,absint($_POST['size']??30)));
   $args=['post_type'=>'page','post_status'=>self::statuses(),'posts_per_page'=>$size,'paged'=>$page,'orderby'=>'ID','order'=>'DESC','s'=>sanitize_text_field(wp_unslash($_POST['search']??''))];
   if(!empty($_POST['status']) && in_array($_POST['status'],self::statuses(),true))$args['post_status']=sanitize_key($_POST['status']);
   $q=new WP_Query($args);$rows=[];$allMeta=[];
   foreach($q->posts as $p){
     if(!current_user_can('edit_post',$p->ID))continue;
     $row=['ID'=>$p->ID,'permalink'=>get_permalink($p),'post_template'=>get_page_template_slug($p->ID)?:'','thumbnail_id'=>get_post_thumbnail_id($p->ID)?:'','language'=>function_exists('pll_get_post_language')?(pll_get_post_language($p->ID,'slug')?:''):''];
     foreach(['post_title','post_name','post_status','post_author','post_date','post_date_gmt','post_modified','post_modified_gmt','post_parent','menu_order','post_excerpt','comment_status','ping_status','post_password','guid','post_type','post_mime_type','comment_count','to_ping','pinged'] as $field){$row[$field]=$field==='post_password'&&$p->$field!==''?'••••••':$p->$field;}
     foreach(get_object_taxonomies('page','objects') as $tax){$terms=wp_get_object_terms($p->ID,$tax->name,['fields'=>'slugs']);$row['tax:'.$tax->name]=is_wp_error($terms)?'':implode(', ',$terms);}
     $meta=get_post_meta($p->ID);
     foreach($meta as $key=>$values){
       if(!self::allowed_meta($key))continue;
       $val=get_post_meta($p->ID,$key,true);
       if(!is_scalar($val)&&$val!==null)continue;
       $k='meta:'.$key;$row[$k]=(string)$val;$allMeta[$k]='Meta: '.$key;
     }
     $rows[]=$row;
   }
   $columns=array_merge(self::columns(),$allMeta);
   wp_send_json_success(['rows'=>$rows,'columns'=>$columns,'total'=>(int)$q->found_posts,'pages'=>(int)$q->max_num_pages,'page'=>$page,'size'=>$size,'languages'=>function_exists('pll_languages_list')?pll_languages_list(['fields'=>'slug']):[]]);
 }
 static function allowed_meta($key){
   if(!is_string($key)||strlen($key)>191||!preg_match('/^[a-zA-Z0-9_-]+$/',$key))return false;
   if(in_array($key,self::SEO,true))return true;
   return $key[0]!=='_' && !in_array(strtolower($key),['password','token','secret','api_key','private_key'],true) && !preg_match('/(password|secret|token|credential|api.?key)/i',$key);
 }
 static function save(){
   self::guard();$id=absint($_POST['id']??0);
   if(!$id||get_post_type($id)!=='page'||!current_user_can('edit_post',$id))wp_send_json_error(['message'=>'Cannot edit this page'],403);
   $field=sanitize_text_field(wp_unslash($_POST['field']??''));$raw=isset($_POST['value'])?wp_unslash($_POST['value']):'';
   if(!is_string($raw))wp_send_json_error(['message'=>'Invalid value'],400);
   if(strlen($raw)>20000)wp_send_json_error(['message'=>'Value too long'],400);
   $expected=sanitize_text_field(wp_unslash($_POST['expected']??''));
   $p=get_post($id);
   if(!$p)wp_send_json_error(['message'=>'Page unavailable'],404);
   $columns=self::columns();
   if(!array_key_exists($field,$columns)&&!str_starts_with($field,'meta:'))wp_send_json_error(['message'=>'Field not editable'],400);
   $current='';
   if(str_starts_with($field,'meta:')){$key=substr($field,5);if(!self::allowed_meta($key))wp_send_json_error(['message'=>'Meta key restricted'],403);$current=(string)get_post_meta($id,$key,true);}
   elseif(str_starts_with($field,'tax:')){$taxonomy=substr($field,4);if(!taxonomy_exists($taxonomy)||!is_object_in_taxonomy('page',$taxonomy)||!current_user_can(get_taxonomy($taxonomy)->cap->assign_terms))wp_send_json_error(['message'=>'Taxonomy permission denied'],403);$terms=wp_get_object_terms($id,$taxonomy,['fields'=>'slugs']);$current=is_wp_error($terms)?'':implode(', ',$terms);}
   elseif($field==='post_template')$current=(string)get_page_template_slug($id);
   elseif($field==='thumbnail_id')$current=(string)(get_post_thumbnail_id($id)?:'');
   elseif($field==='language')$current=function_exists('pll_get_post_language')?(string)pll_get_post_language($id,'slug'):'';
   elseif(property_exists($p,$field))$current=(string)$p->$field;
   if($field!=='post_password' && $current!==$expected)wp_send_json_error(['message'=>'Another change was detected. Reload before editing.'],409);
   if(str_starts_with($field,'meta:')){
     if(!current_user_can('edit_post_meta',$id,$key))wp_send_json_error(['message'=>'Cannot edit metadata'],403);
     if($raw==='')delete_post_meta($id,$key);else update_post_meta($id,$key,sanitize_textarea_field($raw));
   }elseif(str_starts_with($field,'tax:')){
     $slugs=array_filter(array_map('sanitize_title',array_map('trim',explode(',',$raw))));
     $termids=[];foreach($slugs as $slug){$t=get_term_by('slug',$slug,$taxonomy);if(!$t)wp_send_json_error(['message'=>'Unknown term: '.$slug],400);$termids[]=(int)$t->term_id;}
     $res=wp_set_object_terms($id,$termids,$taxonomy,false);if(is_wp_error($res))wp_send_json_error(['message'=>$res->get_error_message()],400);
   }elseif($field==='thumbnail_id'){
     $img=absint($raw);if($img && (!wp_attachment_is_image($img)||!current_user_can('edit_post',$img)))wp_send_json_error(['message'=>'Invalid image ID'],400);
     if($img)set_post_thumbnail($id,$img);else delete_post_thumbnail($id);
   }elseif($field==='post_template'){
     $templates=wp_get_theme()->get_page_templates(get_post($id));if($raw!==''&&!isset($templates[$raw]))wp_send_json_error(['message'=>'Unknown template'],400);
     update_post_meta($id,'_wp_page_template',$raw?:'default');
   }elseif($field==='language'){
     if(!function_exists('pll_set_post_language')||!function_exists('pll_languages_list')||!in_array($raw,pll_languages_list(['fields'=>'slug']),true))wp_send_json_error(['message'=>'Invalid Polylang language'],400);
     pll_set_post_language($id,$raw);
   }else{
     if(!isset(self::FIELDS[$field]))wp_send_json_error(['message'=>'Read-only field'],403);
     if($field==='post_author'&&!current_user_can('edit_others_pages'))wp_send_json_error(['message'=>'Cannot reassign author'],403);
     if($field==='post_status'&&!in_array($raw,self::statuses(),true))wp_send_json_error(['message'=>'Invalid status'],400);
     if(in_array($field,['comment_status','ping_status'],true)&&!in_array($raw,['open','closed'],true))wp_send_json_error(['message'=>'Invalid switch'],400);
     if(in_array($field,['post_date','post_date_gmt'],true)&&$raw!==''&&!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/',$raw))wp_send_json_error(['message'=>'Use YYYY-MM-DD HH:MM:SS'],400);
     if(in_array($field,['post_parent','post_author','menu_order'],true))$raw=(string)intval($raw);
     if($field==='post_parent'&&$raw!=='0'){if((int)$raw===$id||get_post_type((int)$raw)!=='page')wp_send_json_error(['message'=>'Invalid parent'],400);$anc=get_post_ancestors((int)$raw);if(in_array($id,$anc,true))wp_send_json_error(['message'=>'Hierarchy loop'],400);}
     if($field==='post_author'&&!get_user_by('id',(int)$raw))wp_send_json_error(['message'=>'Unknown author'],400);
     if($field==='post_name')$raw=sanitize_title($raw);
     if($field==='post_excerpt')$raw=wp_kses_post($raw);elseif($field==='post_title')$raw=sanitize_text_field($raw);elseif($field==='post_password')$raw=sanitize_text_field($raw);
     $update=['ID'=>$id,$field=>$raw];
     // Never send post_content: wp_update_post retains existing Gutenberg blocks.
     $res=wp_update_post(wp_slash($update),true);
     if(is_wp_error($res))wp_send_json_error(['message'=>$res->get_error_message()],400);
   }
   clean_post_cache($id);
   wp_send_json_success(['message'=>'Saved','field'=>$field,'reload'=>true]);
 }
 static function screen(){
   if(!current_user_can('edit_pages'))return;
   $nonce=wp_create_nonce('htsp_nonce');$endpoint=admin_url('admin-ajax.php');
   ?>
   <div class="wrap htsp"><h1>HTS Pages <small>v<?php echo esc_html(self::VERSION); ?></small></h1>
   <p>Inline editing of page properties only; Gutenberg content is never modified. Click a cell to edit. ID, URL, GUID, timestamps and system fields are read-only. Sensitive or structured metadata is intentionally excluded.</p>
   <div class="htsp-toolbar"><input id="htsp-search" placeholder="Search page title…"><select id="htsp-status"><option value="">All statuses</option><?php foreach(self::statuses() as $s)echo '<option value="'.esc_attr($s).'">'.esc_html(ucfirst($s)).'</option>'; ?></select><select id="htsp-size"><option>30</option><option>50</option><option>100</option></select><button class="button" id="htsp-reload">Refresh</button><button class="button" id="htsp-columns">Columns</button><span id="htsp-msg" role="status"></span></div>
   <div id="htsp-colpanel" hidden></div><div class="htsp-scroller"><table class="widefat striped" id="htsp-table"><thead></thead><tbody></tbody></table></div>
   <dialog id="htsp-preview-dialog" class="htsp-dialog" aria-labelledby="htsp-preview-title">
     <div class="htsp-dialog-bar"><strong id="htsp-preview-title"></strong><span style="flex:1"></span><a id="htsp-preview-link" class="button" target="_blank" rel="noopener noreferrer">Open in new tab ↗</a><button type="button" class="button" id="htsp-preview-close">Close ✕</button></div>
     <iframe id="htsp-preview-frame" title="Live page preview"></iframe>
   </dialog>
   <div class="htsp-toolbar"><button class="button" id="htsp-prev">← Previous</button><span id="htsp-page"></span><button class="button" id="htsp-next">Next →</button></div>
   </div>
   <style>
   .htsp .htsp-toolbar{display:flex;align-items:center;gap:9px;margin:14px 0;flex-wrap:wrap}
   .htsp-scroller{max-width:100%;overflow:auto;background:#fff;border:1px solid #dcdcde;max-height:68vh}
   #htsp-table{border:0;border-collapse:separate;border-spacing:0;white-space:nowrap;width:max-content;min-width:100%}
   #htsp-table th{position:sticky;top:0;background:#f4f5f7;z-index:2;border-bottom:2px solid #ced4dd;min-width:115px}
   #htsp-table td{max-width:320px;overflow:hidden;text-overflow:ellipsis;cursor:pointer;padding:9px}
   #htsp-table td:first-child,#htsp-table th:first-child{position:sticky;left:0;z-index:1;background:#fff;min-width:55px}
   #htsp-table th:first-child{z-index:3;background:#f4f5f7}
   #htsp-table td.htsp-readonly{cursor:default;color:#606a75}
   #htsp-table td.htsp-edit:hover{background:#e7f4fa}
   #htsp-table td input,#htsp-table td textarea,#htsp-table td select{width:100%;min-width:180px}
   #htsp-table td.htsp-active{overflow:visible;min-width:270px}
   #htsp-colpanel{background:#fff;padding:12px;border:1px solid #c3c4c7;max-height:190px;overflow:auto}
   #htsp-colpanel label{display:inline-block;margin:5px 15px 5px 0}
   .htsp small{color:#6b7280;font-size:12px}
   #htsp-table td.htsp-preview-cell{padding:5px;min-width:148px;width:148px;max-width:148px}
   .htsp-thumb{position:relative;display:block;padding:0;margin:0;width:136px;height:86px;overflow:hidden;cursor:zoom-in;border:1px solid #a9b5c2;border-radius:4px;background:#f8fafc}
   .htsp-thumb-inner{position:absolute;display:block;inset:0;overflow:hidden;pointer-events:none}
   .htsp-thumb iframe{position:absolute;left:0;top:0;width:1024px;height:650px;border:0;transform:scale(.1328);transform-origin:top left;pointer-events:none}
   .htsp-thumb:focus-visible{outline:3px solid #2271b1;outline-offset:2px}
   .htsp-dialog{padding:0;border:1px solid #9da9b4;border-radius:7px;width:min(95vw,1400px);height:min(92vh,1000px);max-width:95vw;max-height:92vh;box-shadow:0 20px 75px #0005}
   .htsp-dialog::backdrop{background:#121c2baf}
   .htsp-dialog[open]{display:flex;flex-direction:column}
   .htsp-dialog-bar{display:flex;align-items:center;gap:10px;padding:10px 15px;border-bottom:1px solid #ddd;background:#fff}
   #htsp-preview-frame{flex:1;width:100%;min-height:0;border:0;background:white}
   </style>
   <script>
   (() => {
    'use strict';
    const ajax=<?php echo wp_json_encode($endpoint); ?>,nonce=<?php echo wp_json_encode($nonce); ?>;
    const $=id=>document.getElementById(id), state={page:1,pages:1,cols:{},rows:[],hidden:new Set(JSON.parse(localStorage.getItem('htsp_hidden')||'[]'))};
    const readonly=new Set(['preview','ID','permalink','post_modified','post_modified_gmt','guid','post_type','post_mime_type','comment_count','to_ping','pinged']);
    const escape=s=>String(s??'');
    const msg=(s,bad=false)=>{$('htsp-msg').textContent=s;$('htsp-msg').style.color=bad?'#b32d2e':'#176c43';};
    async function request(action,fields){
      const body=new URLSearchParams({action,nonce,...fields});
      const res=await fetch(ajax,{method:'POST',body,credentials:'same-origin'});
      const json=await res.json();if(!json.success)throw Error(json.data?.message||'Request failed');
      return json.data;
    }
    function draw(){
      const fields=Object.keys(state.cols).filter(k=>!state.hidden.has(k));
      const head=$('htsp-table').tHead||$('htsp-table').createTHead();head.replaceChildren();
      const tr=head.insertRow();fields.forEach(f=>{const th=document.createElement('th');th.textContent=state.cols[f];tr.append(th);});
      const tbody=$('htsp-table').tBodies[0];tbody.replaceChildren();
      state.rows.forEach(row=>{
        const tr=tbody.insertRow();
        fields.forEach(field=>{
          const td=tr.insertCell();const value=escape(row[field]);td.textContent=value||'—';td.title=value;
          if(field==='preview'){
            td.textContent='';td.className='htsp-preview-cell';
            const u=escape(row.permalink);
            if(u.startsWith('https://')||u.startsWith('http://')){
              const thumb=document.createElement('button');thumb.type='button';thumb.className='htsp-thumb';thumb.title='Preview page';thumb.setAttribute('aria-label','Preview '+escape(row.post_title));
              const pane=document.createElement('span');pane.className='htsp-thumb-inner';
              const frame=document.createElement('iframe');frame.src=u;frame.loading='lazy';frame.tabIndex=-1;frame.setAttribute('aria-hidden','true');frame.title='';pane.append(frame);thumb.append(pane);
              thumb.onclick=()=>openPreview(u,escape(row.post_title));td.append(thumb);
            }else td.textContent='No preview';
            return;
          }
          if(readonly.has(field)){td.className='htsp-readonly';if(field==='permalink'&&value){td.textContent='';const a=document.createElement('a');a.href=value;a.target='_blank';a.rel='noopener noreferrer';a.textContent='View ↗';td.append(a);}}
          else{td.className='htsp-edit';td.title='Click to edit • '+value;td.addEventListener('click',()=>edit(td,row,field));}
        });
      });
      $('htsp-page').textContent='Page '+state.page+' / '+Math.max(1,state.pages)+' · '+state.rows.length+' visible rows';
      $('htsp-prev').disabled=state.page<=1;$('htsp-next').disabled=state.page>=state.pages;
      const panel=$('htsp-colpanel');panel.replaceChildren();
      Object.keys(state.cols).forEach(field=>{const label=document.createElement('label');const cb=document.createElement('input');cb.type='checkbox';cb.checked=!state.hidden.has(field);cb.addEventListener('change',()=>{if(cb.checked)state.hidden.delete(field);else state.hidden.add(field);localStorage.setItem('htsp_hidden',JSON.stringify([...state.hidden]));draw();});label.append(cb,' '+state.cols[field]);panel.append(label);});
    }
    function openPreview(url,title){
      const dialog=$('htsp-preview-dialog'),frame=$('htsp-preview-frame');
      $('htsp-preview-title').textContent=title;
      $('htsp-preview-link').href=url;
      frame.src=url;
      if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
    }
    $('htsp-preview-close').onclick=()=>{$('htsp-preview-dialog').close();};
    $('htsp-preview-dialog').addEventListener('close',()=>{$('htsp-preview-frame').src='about:blank';});
    function edit(td,row,field){
      if(td.classList.contains('htsp-active'))return;
      const initial=escape(row[field]??'');if(field==='post_password'&&initial==='••••••'){msg('Password is write-only: enter a replacement.',false);}
      td.classList.add('htsp-active');td.replaceChildren();
      let input;
      const choices=field==='post_status'?['publish','draft','pending','private','future','trash']:['comment_status','ping_status'].includes(field)?['open','closed']:field==='language'?window.htspLanguages||[]:null;
      if(choices&&choices.length){input=document.createElement('select');choices.forEach(v=>{const o=new Option(v,v);input.add(o);});input.value=initial;}
      else if(field==='post_excerpt'||field.startsWith('meta:')){input=document.createElement('textarea');input.rows=3;input.value=initial;}
      else{input=document.createElement('input');input.type=field==='post_password'?'password':'text';input.value=field==='post_password'?'':initial;}
      const save=document.createElement('button'),cancel=document.createElement('button');save.className='button button-primary';save.textContent='Save';cancel.className='button';cancel.textContent='Cancel';
      const close=()=>{td.classList.remove('htsp-active');td.replaceChildren();td.textContent=initial||'—';};
      cancel.onclick=e=>{e.stopPropagation();close();};input.onclick=e=>e.stopPropagation();
      save.onclick=async e=>{e.stopPropagation();save.disabled=true;
        try{await request('htsp_save',{id:row.ID,field,value:input.value,expected:initial==='••••••'?'':initial});msg('Saved page #'+row.ID+' · '+field);await load();}
        catch(err){msg(err.message,true);save.disabled=false;}
      };
      td.append(input,save,cancel);input.focus();
    }
    async function load(){
      msg('Loading…');
      try{const data=await request('htsp_list',{page:state.page,size:$('htsp-size').value,status:$('htsp-status').value,search:$('htsp-search').value});
        state.cols=data.columns;state.rows=data.rows;state.page=data.page;state.pages=data.pages;window.htspLanguages=data.languages;draw();msg(data.total+' pages found');
      }catch(e){msg(e.message,true);}
    }
    let delay;$('htsp-search').addEventListener('input',()=>{clearTimeout(delay);delay=setTimeout(()=>{state.page=1;load();},350);});
    $('htsp-status').onchange=$('htsp-size').onchange=()=>{state.page=1;load();};
    $('htsp-reload').onclick=()=>load();$('htsp-prev').onclick=()=>{state.page--;load();};$('htsp-next').onclick=()=>{state.page++;load();};
    $('htsp-columns').onclick=()=>{$('htsp-colpanel').hidden=!$('htsp-colpanel').hidden;};
    load();
   })();
   </script>
   <?php
 }
}
HTS_Pages::init();
