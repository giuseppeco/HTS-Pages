<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/class-htsp-map-links.php';
require_once __DIR__.'/class-htsp-map-blocks.php';
require_once __DIR__.'/class-htsp-shell-links.php';

final class HTSP_Visual_Map {
    const LIMIT=1000;
    private string $file;
    public function __construct(string $file){
        $this->file=$file;
        add_action('admin_menu',[$this,'menu']);add_action('admin_enqueue_scripts',[$this,'assets']);
        foreach(['list','page','write','trash','restore','sequence','paths'] as $action){add_action('wp_ajax_htsp_map_'.$action,[$this,'endpoint_'.$action]);}
        add_action('save_post',[$this,'invalidate']);add_action('deleted_post',[$this,'invalidate']);
        foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook){add_action($hook,[$this,'meta_changed'],10,4);}
        add_action('update_option_page_on_front',[$this,'invalidate']);add_action('update_option_permalink_structure',[$this,'invalidate']);
    }
    public function menu():void{add_submenu_page('hts-pages','Mappa pagine','Mappa pagine','edit_pages','hts-pages-map',[$this,'screen']);}
    public function assets(string $hook):void{
        if($hook!=='hts-pages_page_hts-pages-map'){return;}
        wp_enqueue_style('htsp-map',plugins_url('assets/visual-map.css',$this->file),[],HTS_Pages::VERSION);
        wp_enqueue_script('htsp-map-layout',plugins_url('assets/map-layout.js',$this->file),[],HTS_Pages::VERSION,true);
        wp_enqueue_script('htsp-map',plugins_url('assets/visual-map.js',$this->file),['htsp-map-layout'],HTS_Pages::VERSION,true);
        wp_localize_script('htsp-map','HTSPMap',['ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('htsp_map_nonce')]);
    }
    public function screen():void{
        if(!current_user_can('edit_pages')){return;}
        echo '<div class="wrap htsp-map"><h1>HTS · Mappa pagine</h1><p>I genitori e i figli rappresentano collegamenti reali tra pagine, non la gerarchia WordPress. Una pagina può avere più genitori e rimandi di ritorno.</p><div id="htsp-map-app"></div><noscript>Attivare JavaScript per visualizzare la mappa.</noscript></div>';
    }
    public function invalidate():void{update_option('htsp_map_generation',wp_generate_uuid4(),false);}
    public function meta_changed($meta_id,$post_id,$key,$value=null):void{if(in_array($key,['_htsp_map_description','_thumbnail_id','_wp_page_template'],true)){$this->invalidate();}}
    private function guard():void{
        if(!current_user_can('edit_pages')){wp_send_json_error(['message'=>'Non hai il permesso di gestire le pagine.'],403);}
        check_ajax_referer('htsp_map_nonce','nonce');
        nocache_headers();
    }
    private function readable_page(int $id){
        $p=$id>0?get_post($id):null;
        if(!$p||$p->post_type!=='page'||!current_user_can('read_post',$id)||!current_user_can('edit_post',$id)){return new WP_Error('htsp_access','Pagina non disponibile o non modificabile.',['status'=>403]);}
        return $p;
    }
    private function send($result):void{
        if(is_wp_error($result)){wp_send_json_error(['message'=>$result->get_error_message()],$result->get_error_data()['status']??400);}
        wp_send_json_success($result);
    }
    private static function short(string $text,int $length):string{
        $text=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($text))??'');
        if(function_exists('mb_substr')){return mb_substr($text,0,$length);}
        $chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);return $chars===false?substr($text,0,$length):implode('',array_slice($chars,0,$length));
    }
    private function node($p):array{
        $description=(string)get_post_meta($p->ID,'_htsp_map_description',true);
        if($description===''){$description=$p->post_excerpt?:$p->post_title;}
        $preview=in_array($p->post_status,['draft','pending','future'],true)?get_preview_post_link($p):get_permalink($p);
        return ['id'=>(int)$p->ID,'title'=>wp_strip_all_tags($p->post_title?:'#'.$p->ID),'description'=>self::short($description,220),
            'status'=>$p->post_status,'url'=>get_permalink($p),'preview_url'=>$p->post_status==='trash'?'':$preview,
            'image'=>get_the_post_thumbnail_url($p,'medium')?:'','can_delete'=>current_user_can('delete_post',$p->ID)];
    }
    public function endpoint_list():void{
        $this->guard();$include=!empty($_POST['trash']);
        if(!empty($_POST['refresh'])){$this->invalidate();}
        $shell=HTSP_Shell_Links::collect();
        $key='htsp_map_objects_'.md5(json_encode($shell).'|'.get_current_user_id().'|'.get_option('htsp_map_generation','0').'|'.(int)$include);
        $cached=get_transient($key);
        if(is_array($cached)){
            $cached['nodes']=array_values(array_filter($cached['nodes'],static fn($n)=>HTSP_Shell_Links::is_virtual($n['id'])||(current_user_can('edit_post',$n['id'])&&current_user_can('read_post',$n['id']))));
            foreach($cached['nodes'] as &$node){
                if(!isset($node['link_objects']))continue;
                foreach($node['link_objects'] as &$items)$items=array_values(array_filter($items,static fn($item)=>($item['id']??0)<=0||current_user_can('read_post',$item['id'])));unset($items);
                $node['link_counts']=array_map('count',$node['link_objects']);
            }unset($node);
            $ids=array_fill_keys(array_column($cached['nodes'],'id'),true);$cached['edges']=array_values(array_filter($cached['edges'],static fn($e)=>isset($ids[$e['source']],$ids[$e['target']])));
            $cached['self_links']=array_intersect_key($cached['self_links'],$ids);$this->send($cached);return;
        }
        $posts=get_posts(['post_type'=>'page','post_status'=>$include?HTS_Pages::statuses():['publish','private','draft','pending','future'],
            'posts_per_page'=>self::LIMIT+1,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
        $limited=count($posts)>self::LIMIT;$posts=array_slice($posts,0,self::LIMIT);$nodes=[];$links=[];$scope=[];$index=[];$byid=[];
        foreach($posts as $p){
            if(!current_user_can('edit_post',$p->ID)||!current_user_can('read_post',$p->ID)){continue;}
            $nodes[]=$this->node($p);$byid[$p->ID]=true;
            // Draft permalinks use ?page_id=ID; stripping that query would overwrite the home URL.
            $permalink=HTSP_Map_Links::absolute(get_permalink($p),home_url('/'));$canonical=HTSP_Map_Links::permalink_key($permalink);
            if($canonical!==''){$index[$canonical]=$p->ID;}
            if($p->post_status!=='trash'){$links[$p->ID]=HTSP_Map_Links::extract($p->post_content,get_permalink($p),$scope,[$p->ID]);}
        }
        $nodes=array_merge($nodes,HTSP_Shell_Links::nodes());$links=$links+$shell;
        $resolver=static function($href) use ($index,$byid){
            $parts=wp_parse_url($href);parse_str($parts['query']??'',$query);
            if(isset($query['page_id'])||isset($query['p'])){$id=absint($query['page_id']??$query['p']);return isset($byid[$id])?$id:0;}
            $canonical=HTSP_Map_Links::canonical($href);
            return (int)($index[$canonical]??url_to_postid(preg_replace('/#.*$/','',$href)));
        };
        $graph=HTSP_Map_Links::build($nodes,$links,$resolver,home_url('/'));
        // The visible numbers and drawers share the same destination lists.
        $counts=[];$objects=[];$node_index=[];
        foreach($nodes as $n){$node_index[$n['id']]=$n;$objects[$n['id']]=['in'=>[],'post'=>[],'page'=>[],'ext'=>[],'total'=>[]];}
        $home_host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        foreach($links as $source=>$hrefs){
            if(!isset($objects[$source]))continue;
            $seen=[];
            foreach($hrefs as $href){
                if(!is_string($href))continue;
                $host=strtolower((string)wp_parse_url($href,PHP_URL_HOST));
                $external=$host!==''&&$host!==$home_host;
                $target=$external?0:(int)$resolver($href);
                if($target&&!current_user_can('read_post',$target))continue;
                $key=$target?'id:'.$target:'url:'.$href;if(isset($seen[$key]))continue;$seen[$key]=true;
                $type=$external?'ext':($target&&get_post_type($target)==='post'?'post':($target&&get_post_type($target)==='page'?'page':'other'));
                $entry=['id'=>$target,'title'=>$target?wp_strip_all_tags(get_the_title($target)):($host?:$href),'url'=>$href,'type'=>$type];
                $objects[$source]['total'][]=$entry;
                if(isset($objects[$source][$type]))$objects[$source][$type][]=$entry;
            }
        }
        foreach($graph['edges'] as $edge){
            $source=$node_index[$edge['source']];
            $objects[$edge['target']]['in'][]=['id'=>$source['id'],'title'=>$source['title'],'url'=>$source['url'],'type'=>!empty($source['virtual'])?'site_shell':'page','virtual'=>!empty($source['virtual'])];
        }
        foreach($nodes as &$n){
            $n['link_objects']=$objects[$n['id']];
            $n['link_counts']=array_map('count',$n['link_objects']);
        }unset($n);
        usort($nodes,static fn($a,$b)=>($a['link_counts']['in']<=>$b['link_counts']['in'])?:($a['id']<=>$b['id']));
        $result=['nodes'=>$nodes,'edges'=>$graph['edges'],'self_links'=>$graph['self_links'],'root'=>(int)get_option('page_on_front'),'permissions'=>['publish'=>current_user_can('publish_pages')],
            'limited'=>$limited,'limit'=>self::LIMIT,'scanned'=>count($posts),'generated_at'=>gmdate('c'),'scope'=>$scope,
            'scope_note'=>'Link nei contenuti, nei pattern referenziati e nei nodi Header/Footer: HTS Navigation primary, menu classici assegnati e parti header/footer referenziate dai template del tema attivo. Esclusi Query Loop, shortcode e link generati da script. Gli autorimandi interni alla stessa pagina sono contati nelle proprietà, non disegnati come genitori.'];
        set_transient($key,$result,MINUTE_IN_SECONDS);$this->send($result);
    }
    public function endpoint_page():void{$this->guard();$id=(int)($_POST['id']??0);$p=$this->readable_page($id);$this->send(is_wp_error($p)?$p:$this->properties($p));}
    private function editable_meta($p):array{
        $safe=[];foreach(get_post_meta($p->ID) as $key=>$values){
            if(!HTS_Pages::allowed_meta($key)||!current_user_can('edit_post_meta',$p->ID,$key)){continue;}
            $value=get_post_meta($p->ID,$key,true);if(is_scalar($value)||$value===null){$safe[$key]=(string)$value;}
        }ksort($safe);return $safe;
    }
    private function properties($p):array{
        $fields=[];
        foreach(['post_title','post_name','post_status','post_author','post_parent','menu_order','post_excerpt','post_date','post_date_gmt','comment_status','ping_status','post_content'] as $field){$fields[$field]=(string)$p->$field;}
        $fields['map_description']=(string)get_post_meta($p->ID,'_htsp_map_description',true);
        $fields['post_template']=(string)get_page_template_slug($p->ID);$fields['thumbnail_id']=(string)(get_post_thumbnail_id($p->ID)?:'');
        $fields['language']=function_exists('pll_get_post_language')?(string)pll_get_post_language($p->ID,'slug'):'';
        $tax=[];foreach(get_object_taxonomies('page','objects') as $taxonomy){
            $slugs=wp_get_object_terms($p->ID,$taxonomy->name,['fields'=>'slugs']);if(is_wp_error($slugs)){$slugs=[];}sort($slugs);
            $tax[$taxonomy->name]=['label'=>$taxonomy->label,'value'=>implode(', ',$slugs),'editable'=>current_user_can($taxonomy->cap->assign_terms)];
        }ksort($tax);
        $meta=$this->editable_meta($p);
        $system=[];foreach(['ID','post_type','guid','post_modified','post_modified_gmt','post_mime_type','comment_count','to_ping','pinged'] as $field){$system[$field]=(string)$p->$field;}
        $system['permalink']=get_permalink($p);$system['password_set']=$p->post_password!==''?'Sì':'No';
        $templates=['default'=>'Predefinito'];foreach(wp_get_theme()->get_page_templates($p) as $slug=>$label){$templates[$slug]=$label;}
        $token=hash('sha256',wp_json_encode([$fields,$meta,$tax,$p->post_password,$system,get_post_meta($p->ID,'_htsp_map_paths_v1',true)]));
        return ['node'=>$this->node($p),'fields'=>$fields,'system'=>$system,'meta'=>$meta,'taxonomies'=>$tax,'token'=>$token,
            'templates'=>$templates,'languages'=>function_exists('pll_languages_list')?pll_languages_list(['fields'=>'slug']):[],
            'authors'=>array_map(static fn($u)=>['id'=>$u->ID,'name'=>$u->display_name],get_users(['who'=>'authors','fields'=>['ID','display_name'],'number'=>300])),
            'can_publish'=>current_user_can('publish_pages'),'can_author'=>current_user_can('edit_others_pages'),
            'edit_url'=>get_edit_post_link($p->ID,'raw'),'can_restore'=>$p->post_status==='trash'&&current_user_can('delete_post',$p->ID)];
    }
    private function request_body(){
        $body=json_decode(wp_unslash($_POST['payload']??''),true);
        return is_array($body)?$body:new WP_Error('htsp_payload','Dati non validi.',['status'=>400]);
    }
    private function error(string $message,int $status=400){return new WP_Error('htsp_map',$message,['status'=>$status]);}
    private function lock(int $id){
        $name='htsp_map_lock_'.$id;$value=['token'=>wp_generate_uuid4(),'until'=>time()+120];
        if(add_option($name,$value,'','no')){return $value['token'];}
        $old=get_option($name);
        if(is_array($old)&&($old['until']??PHP_INT_MAX)<time()){
            global $wpdb;$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",$name,maybe_serialize($old)));wp_cache_delete($name,'options');
            if(add_option($name,$value,'','no')){return $value['token'];}
        }
        return $this->error('Pagina già in aggiornamento: riprovare tra poco.',409);
    }
    private function unlock(int $id,string $token):void{$name='htsp_map_lock_'.$id;$lock=get_option($name);if(is_array($lock)&&($lock['token']??'')===$token){delete_option($name);}}

    /** All validation occurs before the first write; WordPress hooks remain authoritative. */
    public function validate(array $body,$post){
        $creating=!$post;$id=$post?(int)$post->ID:0;$fields=$body['fields']??[];
        if(!is_array($fields)||!is_array($body['tax']??[])||!is_array($body['meta']??[])){return $this->error('Proprietà non valide.');}
        $allowed=['post_title','post_name','post_status','post_author','post_parent','menu_order','post_excerpt','post_date','post_date_gmt','comment_status','ping_status','post_content','map_description','post_template','thumbnail_id','language','post_password'];
        $out=['post'=>[],'extras'=>[],'tax'=>[],'meta'=>[]];
        foreach($fields as $key=>$raw){
            if(!in_array($key,$allowed,true)||!is_string($raw)){return $this->error('Proprietà non modificabile: '.$key);}
            if(strlen($raw)>($key==='post_content'?2097152:20000)){return $this->error('Valore troppo lungo: '.$key);}
            if(in_array($key,['post_author','post_parent','thumbnail_id'],true)&&$raw!==''&&!ctype_digit($raw)){return $this->error('ID non valido: '.$key);}
            if($key==='menu_order'&&!preg_match('/^-?\d+$/',$raw)){return $this->error('Ordine non valido.');}
            switch($key){
                case 'post_status':
                    if(!in_array($raw,['draft','pending','publish','private','future'],true)){return $this->error('Stato non valido; usare il comando Cestina per rimuovere una pagina.');}
                    if(in_array($raw,['publish','private','future'],true)&&!current_user_can('publish_pages')){return $this->error('Non hai il permesso di pubblicare pagine.',403);}break;
                case 'post_author':
                    $raw=(string)absint($raw);if(!get_user_by('id',(int)$raw)){return $this->error('Autore inesistente.');}
                    if((int)$raw!==($post?(int)$post->post_author:get_current_user_id())&&!current_user_can('edit_others_pages')){return $this->error('Non puoi assegnare un altro autore.',403);}break;
                case 'post_parent':
                    $parent=(int)$raw;
                    if($parent){$p=get_post($parent);if(!$p||$p->post_type!=='page'||$p->post_status==='trash'||!current_user_can('read_post',$parent)){return $this->error('Genitore WordPress non disponibile.');}
                        if($parent===$id||in_array($id,array_map('intval',get_post_ancestors($parent)),true)){return $this->error('Il genitore WordPress creerebbe un ciclo.');}}
                    $raw=(string)$parent;break;
                case 'post_date':case 'post_date_gmt':
                    $tz=$key==='post_date'?wp_timezone():new DateTimeZone('UTC');$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,$tz);
                    if(!$date||$date->format('Y-m-d H:i:s')!==$raw){return $this->error('Data non valida: usare YYYY-MM-DD HH:MM:SS.');}break;
                case 'comment_status':case 'ping_status':if(!in_array($raw,['open','closed'],true)){return $this->error('Valore non valido: '.$key);}break;
                case 'post_template':
                    $templates=wp_get_theme()->get_page_templates($post);
                    if($raw!==''&&$raw!=='default'&&!isset($templates[$raw])){return $this->error('Template non disponibile per questa pagina.');}
                    $out['extras'][$key]=$raw?:'default';continue 2;
                case 'thumbnail_id':
                    if($raw!==''&&(int)$raw && (!wp_attachment_is_image((int)$raw)||!current_user_can('edit_post',(int)$raw))){return $this->error('Immagine in evidenza non disponibile.');}
                    $out['extras'][$key]=(int)$raw;continue 2;
                case 'language':
                    if(!function_exists('pll_set_post_language')||!in_array($raw,pll_languages_list(['fields'=>'slug']),true)){return $this->error('Lingua Polylang non disponibile.');}
                    $out['extras'][$key]=$raw;continue 2;
                case 'map_description':$out['extras'][$key]=sanitize_textarea_field($raw);continue 2;
                case 'post_name':$raw=sanitize_title($raw);break;
                case 'post_title':case 'post_password':$raw=sanitize_text_field($raw);break;
                case 'post_excerpt':$raw=wp_kses_post($raw);break;
                case 'post_content':if(!current_user_can('unfiltered_html')){$raw=wp_kses_post($raw);}break;
            }
            $out['post'][$key]=$raw;
        }
        if($creating && empty(trim($out['post']['post_title']??''))){return $this->error('Assegnare un titolo alla nuova pagina.');}
        // Local/GMT dates are coupled, never independently allowed to contradict each other.
        if(isset($out['post']['post_date'])&&!isset($out['post']['post_date_gmt'])){$out['post']['post_date_gmt']=get_gmt_from_date($out['post']['post_date']);}
        elseif(isset($out['post']['post_date_gmt'])&&!isset($out['post']['post_date'])){$out['post']['post_date']=get_date_from_gmt($out['post']['post_date_gmt']);}
        elseif(isset($out['post']['post_date'],$out['post']['post_date_gmt'])&&get_gmt_from_date($out['post']['post_date'])!==$out['post']['post_date_gmt']){return $this->error('Le date locale e GMT non corrispondono.');}
        $status=$out['post']['post_status']??($post?$post->post_status:'draft');
        if($status==='future'&&strtotime(($out['post']['post_date_gmt']??($post?$post->post_date_gmt:'' )).' UTC')<=time()){return $this->error('Per programmare una pagina indicare una data futura.');}
        foreach(($body['tax']??[]) as $name=>$raw){
            if(!is_string($raw)||!taxonomy_exists($name)||!is_object_in_taxonomy('page',$name)||!current_user_can(get_taxonomy($name)->cap->assign_terms)){return $this->error('Tassonomia non modificabile.',403);}
            $ids=[];foreach(array_filter(array_map('trim',explode(',',$raw))) as $slug){$term=get_term_by('slug',sanitize_title($slug),$name);if(!$term){return $this->error('Termine inesistente: '.$slug);}$ids[]=(int)$term->term_id;}$out['tax'][$name]=$ids;
        }
        foreach(($body['meta']??[]) as $key=>$raw){
            if(!is_string($raw)||strlen($raw)>20000||!HTS_Pages::allowed_meta($key)||($id&&!current_user_can('edit_post_meta',$id,$key))){return $this->error('Metadato non modificabile: '.$key,403);}
            $existing=$id?get_post_meta($id,$key,true):'';if(!is_scalar($existing)&&$existing!==null){return $this->error('Il metadato strutturato richiede il proprio editor.');}
            $out['meta'][$key]=sanitize_textarea_field($raw);
        }
        return $out;
    }
    public function endpoint_write():void{
        $this->guard();$body=$this->request_body();if(is_wp_error($body)){$this->send($body);return;}
        $id=(int)($body['id']??0);
        if(!$id){$type=get_post_type_object('page');if(!current_user_can($type->cap->create_posts)){$this->send($this->error('Non puoi creare pagine.',403));return;}}
        $token=$this->lock($id?:get_current_user_id()*-1);if(is_wp_error($token)){$this->send($token);return;}
        $result=null;
        try{$result=$this->write($body,$id);}finally{$this->unlock($id?:get_current_user_id()*-1,$token);}
        $this->send($result);
    }
    private function write(array $body,int $id){
        $post=$id?$this->readable_page($id):null;if(is_wp_error($post)){return $post;}
        if($post && $post->post_status==='trash'){return $this->error('Ripristinare la pagina prima di modificarla.');}
        if($post && !hash_equals($this->properties($post)['token'],(string)($body['token']??''))){return $this->error('La pagina è cambiata in un’altra sessione. Ricaricare prima di salvare.',409);}
        $change=$this->validate($body,$post);if(is_wp_error($change)){return $change;}
        $update=$change['post'];
        if($id){$update['ID']=$id;$saved=wp_update_post(wp_slash($update),true);}
        else{$update+=['post_type'=>'page','post_status'=>'draft','post_author'=>get_current_user_id()];$saved=wp_insert_post(wp_slash($update),true);}
        if(is_wp_error($saved)){return $saved;}if(!$saved){return $this->error('Salvataggio non riuscito.',500);}$id=(int)$saved;
        try{
            foreach($change['extras'] as $key=>$value){
                if($key==='post_template'){update_post_meta($id,'_wp_page_template',wp_slash($value));}
                elseif($key==='map_description'){update_post_meta($id,'_htsp_map_description',wp_slash($value));}
                elseif($key==='thumbnail_id'){if($value){set_post_thumbnail($id,$value);}else{delete_post_thumbnail($id);}}
                elseif($key==='language'){pll_set_post_language($id,$value);}
            }
            foreach($change['tax'] as $name=>$ids){$r=wp_set_object_terms($id,$ids,$name,false);if(is_wp_error($r)){throw new RuntimeException($r->get_error_message());}}
            foreach($change['meta'] as $key=>$value){update_post_meta($id,$key,wp_slash($value));}
        }catch(Throwable $e){$this->invalidate();return $this->error('Contenuto salvato, ma una proprietà aggiuntiva non è stata aggiornata: '.$e->getMessage().'. Ricaricare per vedere lo stato effettivo.',500);}
        $this->invalidate();HTS_Pages::clear_link_graph();clean_post_cache($id);
        return ['message'=>'Pagina salvata. I collegamenti sono ricalcolati dal contenuto reale.','page'=>$this->properties(get_post($id))];
    }
    public function endpoint_trash():void{$this->change_trash(false);}
    public function endpoint_restore():void{$this->change_trash(true);}
    private function change_trash(bool $restore):void{
        $this->guard();$body=$this->request_body();if(is_wp_error($body)){$this->send($body);return;}
        $id=(int)($body['id']??0);$post=$this->readable_page($id);
        if(is_wp_error($post)||!current_user_can('delete_post',$id)){$this->send($this->error('Non puoi rimuovere/ripristinare questa pagina.',403));return;}
        if(!$restore&&(!defined('EMPTY_TRASH_DAYS')||EMPTY_TRASH_DAYS<=0)){$this->send($this->error('Cestino disabilitato: la rimozione non è eseguita per evitare una cancellazione definitiva.'));return;}
        $lock=$this->lock($id);if(is_wp_error($lock)){$this->send($lock);return;}
        try{
            if(!hash_equals($this->properties(get_post($id))['token'],(string)($body['token']??''))){$result=$this->error('Pagina modificata: ricaricare prima di procedere.',409);}
            elseif(($post->post_status==='trash')!==$restore){$result=$this->error('Lo stato della pagina è già cambiato. Ricaricare.');}
            else{$r=$restore?wp_untrash_post($id):wp_trash_post($id);$result=$r?['message'=>$restore?'Pagina ripristinata. Verificare lo stato di pubblicazione.':'Pagina spostata nel cestino. I link dalle altre pagine non vengono rimossi automaticamente.']: $this->error('Operazione non riuscita.',500);$this->invalidate();HTS_Pages::clear_link_graph();}
        }finally{$this->unlock($id,$lock);}
        $this->send($result);
    }
    private function sequence($post):array{
        $home=wp_parse_url(home_url('/'));
        $resolve=static function($href) use ($home){
            $p=wp_parse_url($href);if(strtolower($p['host']??'')!==strtolower($home['host']??'')){return null;}
            parse_str($p['query']??'',$query);$id=isset($query['page_id'])||isset($query['p'])?absint($query['page_id']??$query['p']):url_to_postid(preg_replace('/#.*$/','',$href));
            $target=get_post($id);if(!$target||$target->post_type!=='page'||$target->post_status==='trash'||!current_user_can('edit_post',$id)||!current_user_can('read_post',$id)){return null;}
            return ['id'=>(int)$id,'title'=>wp_strip_all_tags($target->post_title)];
        };
        $design=get_post_meta($post->ID,'_htspd_design_v1',true);$outline=HTSP_Map_Blocks::outline($post,is_array($design)?$design:[],$resolve);
        $stored=get_post_meta($post->ID,'_htsp_map_paths_v1',true);
        return ['page_id'=>(int)$post->ID,'title'=>$post->post_title,'outline'=>$outline,
            'paths'=>is_array($stored)?($stored['items']??[]):HTSP_Map_Blocks::propose($outline),'stored'=>is_array($stored),
            'stale'=>is_array($stored)&&($stored['source_hash']??'')!==$outline['source_hash'],
            'token'=>$this->properties($post)['token']];
    }
    public function endpoint_sequence():void{$this->guard();$post=$this->readable_page((int)($_POST['id']??0));$this->send(is_wp_error($post)?$post:$this->sequence($post));}
    public function endpoint_paths():void{
        $this->guard();$body=$this->request_body();if(is_wp_error($body)){$this->send($body);return;}
        $id=(int)($body['id']??0);$post=$this->readable_page($id);if(is_wp_error($post)){$this->send($post);return;}
        $lock=$this->lock($id);if(is_wp_error($lock)){$this->send($lock);return;}
        try{
            $data=$this->sequence(get_post($id));
            if(!hash_equals($data['token'],(string)($body['token']??''))){$result=$this->error('La pagina o i percorsi sono cambiati. Ricaricare prima di salvare.',409);}
            else{
                $items=HTSP_Map_Blocks::validate_paths($body['items']??null,$data['outline']);
                if(is_wp_error($items)){$result=$items;}
                else{
                    $value=['schema'=>1,'items'=>$items,'source_hash'=>$data['outline']['source_hash'],'updated_at'=>gmdate('c'),'updated_by'=>get_current_user_id()];
                    $previous=get_post_meta($id,'_htsp_map_paths_v1',true);$saved=update_post_meta($id,'_htsp_map_paths_v1',wp_slash($value));
                    $result=$saved===false&&$previous!==$value?$this->error('Percorsi non salvati.',500):$this->sequence(get_post($id));
                }
            }
        }finally{$this->unlock($id,$lock);}
        $this->send($result);
    }
}
