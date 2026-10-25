<?php
if (!defined('ABSPATH')) { exit; }

/** Menu references remain real WordPress menu items, never a parallel registry. */
final class HTSP_Map_Menus {
    private static ?array $cache=null;
    public static function snapshot(int $page):array {
        if(self::$cache!==null)return self::present($page,self::$cache);
        $menus=[];
        foreach(wp_get_nav_menus() as $menu){
            $items=[];
            foreach(wp_get_nav_menu_items($menu->term_id,['post_status'=>['publish','draft']])?:[] as $item){
                $items[]=['key'=>(string)$item->ID,'parent'=>(string)(int)$item->menu_item_parent,
                    'label'=>$item->title,'page'=>self::target($item->object,$item->object_id,$item->url),
                    'raw'=>['title'=>$item->title,'url'=>$item->url,'object'=>$item->object,'object_id'=>$item->object_id,'type'=>$item->type,
                        'target'=>$item->target,'classes'=>$item->classes,'xfn'=>$item->xfn,'description'=>$item->description,'attr_title'=>$item->attr_title,'status'=>$item->post_status,'position'=>$item->menu_order]];
            }
            $menus[]=['key'=>'classic:'.$menu->term_id,'label'=>$menu->name,'kind'=>'classic','id'=>(int)$menu->term_id,'items'=>$items];
        }
        foreach(get_posts(['post_type'=>'wp_navigation','post_status'=>['publish','draft','private'],'numberposts'=>-1,'suppress_filters'=>true]) as $nav){
            if(!current_user_can('edit_post',$nav->ID))continue;
            $items=[];self::flatten(parse_blocks($nav->post_content),'0',$items);
            $menus[]=['key'=>'block:'.$nav->ID,'label'=>$nav->post_title?:'Navigazione #'.$nav->ID,'kind'=>'block','id'=>(int)$nav->ID,'items'=>$items];
        }
        self::$cache=$menus;return self::present($page,$menus);
    }
    private static function present(int $page,array $menus):array {
        $token=hash('sha256',wp_json_encode($menus));
        return ['menus'=>array_map(static function($m)use($page){
            $m['items']=array_map(static function($i)use($page){$i['submenu']=($i['raw']['blockName']??'')==='core/navigation-submenu';unset($i['raw']);$i['current']=$i['page']===$page;return $i;},$m['items']);return $m;
        },$menus),'token'=>$token,'can_edit'=>current_user_can('edit_theme_options'),'raw'=>$menus];
    }
    private static function target($object,$id,$url):int {
        if($object==='page' && (int)$id)return (int)$id;
        return $url?(int)url_to_postid($url):0;
    }
    private static function flatten(array $blocks,string $parent,array &$items):void {
        foreach($blocks as $index=>$block){
            $key=($parent==='0'?'b':$parent.'.').$index;$a=$block['attrs']??[];
            $items[]=['key'=>$key,'parent'=>$parent,'label'=>wp_strip_all_tags($a['label']??$block['blockName']??'Blocco'),
                'page'=>self::target($a['type']??'', $a['id']??0,$a['url']??''),'raw'=>$block];
            self::flatten($block['innerBlocks']??[],$key,$items);
        }
    }
    public static function public_snapshot(int $page):array {$data=self::snapshot($page);unset($data['raw']);return $data;}

    /** Only one explicit membership is changed per request. Validate before writing. */
    public static function change(int $page,array $body){
        if(!current_user_can('edit_theme_options'))return new WP_Error('menu_permission','Non puoi modificare i menu.',['status'=>403]);
        $snapshot=self::snapshot($page);
        if(!hash_equals($snapshot['token'],(string)($body['token']??'')))return new WP_Error('menu_conflict','Il menu è cambiato: ricarica prima di salvare.',['status'=>409]);
        $menu=null;foreach($snapshot['raw'] as $candidate){if($candidate['key']===($body['menu']??'')){$menu=$candidate;break;}}
        if(!$menu)return new WP_Error('menu_missing','Menu non disponibile.');
        $key=(string)($body['item']??'');$parent=(string)($body['parent']??'0');$label=sanitize_text_field($body['label']??'');$remove=!empty($body['remove']);
        $items=array_column($menu['items'],null,'key');
        if($menu['kind']==='block'){foreach($items as $entry){$raw=$entry['raw'];if(($raw['blockName']!=='core/navigation-submenu'&&!empty($raw['innerBlocks']))||isset($raw['attrs']['ref']))return new WP_Error('menu_structure','Questo menu contiene blocchi o riferimenti che richiedono l’editor WordPress.');}}$item=$key!==''?($items[$key]??null):null;
        if($key!==''&&(!$item||$item['page']!==$page))return new WP_Error('menu_target','La voce non punta a questa pagina.');
        if($remove&&!$item)return new WP_Error('menu_target','Voce da rimuovere non disponibile.');
        if(!$remove&&($label===''||strlen($label)>500))return new WP_Error('menu_label','Inserire un’etichetta valida, massimo 500 byte.');
        if($parent!=='0'&&!isset($items[$parent]))return new WP_Error('menu_parent','Voce superiore non disponibile.');
        $visited=[];$cursor=$parent;
        while($cursor!=='0'){
            if($cursor===$key||isset($visited[$cursor]))return new WP_Error('menu_cycle','La dipendenza creerebbe un ciclo.');
            $visited[$cursor]=true;$cursor=$items[$cursor]['parent']??'0';
        }
        if($menu['kind']==='classic'){
            $raw=$item['raw']??[];
            if($remove){
                foreach($items as $child){if($child['parent']===$key)return new WP_Error('menu_children','Spostare prima le voci figlie.');}
                if(!wp_delete_post((int)$key,true))return new WP_Error('menu_save','Voce non rimossa.');
            }else{
                $args=['menu-item-title'=>$label,'menu-item-parent-id'=>(int)$parent,'menu-item-status'=>$raw['status']??'publish'];
                if($item){foreach(['url','object','object_id','type','target','classes','xfn','description','attr_title','position'] as $field){$args['menu-item-'.str_replace('_','-',$field)]=$raw[$field];}}
                else{$args+=['menu-item-object'=>'page','menu-item-object-id'=>$page,'menu-item-type'=>'post_type'];}
                if(isset($args['menu-item-classes'])&&is_array($args['menu-item-classes']))$args['menu-item-classes']=implode(' ',$args['menu-item-classes']);
                $result=wp_update_nav_menu_item($menu['id'],(int)$key,wp_slash($args));if(is_wp_error($result))return $result;if(!$result)return new WP_Error('menu_save','Voce non salvata.');
            }
        }else{
            if($parent!=='0'&&($items[$parent]['raw']['blockName']??'')!=='core/navigation-submenu')return new WP_Error('menu_parent','Scegliere una voce sottomenu come superiore.');
            if($remove){foreach($items as $child){if($child['parent']===$key)return new WP_Error('menu_children','Spostare prima le voci figlie.');}unset($items[$key]);}
            else{
                $raw=$item['raw']??['blockName'=>'core/navigation-link','attrs'=>['type'=>'page','kind'=>'post-type','id'=>$page,'url'=>get_permalink($page)],'innerBlocks'=>[],'innerHTML'=>'','innerContent'=>[]];
                $raw['attrs']['label']=$label;
                $key=$key!==''?$key:'new';$items[$key]=['key'=>$key,'parent'=>$parent,'page'=>$page,'label'=>$label,'raw'=>$raw];
            }
            $build=function($parent)use(&$build,$items){$blocks=[];foreach($items as $i){if($i['parent']!==$parent)continue;$raw=$i['raw'];$children=$build($i['key']);
                // Only navigation containers can be structurally edited. Preserve other markup.
                if($raw['blockName']==='core/navigation-submenu'){$raw['innerBlocks']=$children;$raw['innerContent']=array_fill(0,count($children),null);$raw['innerHTML']='';}
                $blocks[]=$raw;}return $blocks;};
            $result=wp_update_post(wp_slash(['ID'=>$menu['id'],'post_content'=>serialize_blocks($build('0'))]),true);if(is_wp_error($result))return $result;
        }
        self::$cache=null;return self::public_snapshot($page);
    }
}
