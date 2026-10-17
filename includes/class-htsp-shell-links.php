<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/class-htsp-map-links.php';

/** Header and footer are virtual graph sources, never real WordPress pages. */
final class HTSP_Shell_Links {
    const HEADER=-1;
    const FOOTER=-2;
    public static function is_virtual($id):bool{return in_array((int)$id,[self::HEADER,self::FOOTER],true);}
    public static function nodes():array{
        $nodes=[];
        foreach([self::HEADER=>'Header',self::FOOTER=>'Footer'] as $id=>$title){
            $nodes[]=['id'=>$id,'title'=>$title,'description'=>$title.' del sito · collegamenti condivisi','status'=>'publish','url'=>home_url('/'),'preview_url'=>'','image'=>'','can_delete'=>false,'virtual'=>true,'editor_url'=>admin_url('site-editor.php')];
        }
        return $nodes;
    }
    /** Only enabled sections of a mounted HTS menu contribute references. */
    public static function model_links(array $model):array{
        $out=[];
        $walk=static function($value)use(&$walk,&$out):void{
            if(!is_array($value)|| (array_key_exists('enabled',$value)&&!$value['enabled']))return;
            if(isset($value['url'])||isset($value['content_id'])){
                $url=trim((string)($value['url']??''));
                if($url===''&&!empty($value['content_id']))$url=(string)get_permalink((int)$value['content_id']);
                $url=HTSP_Map_Links::absolute($url,home_url('/'));
                if($url!=='')$out[]=['url'=>$url,'label'=>(string)($value['label']??'')];
            }
            foreach($value as $child)if(is_array($child))$walk($child);
        };
        $walk($model);return $out;
    }
    /** Known records, not a crawl or a claim of live backlink verification. */
    public static function backlinks(array $ids):array{
        $out=[];$allowed=array_fill_keys(array_filter($ids,static fn($id)=>$id>0),true);
        if(!$allowed||!function_exists('get_comments'))return $out;
        $home=preg_replace('/^www\./','',strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)));
        $comments=get_comments(['post__in'=>array_keys($allowed),'type__in'=>['pingback','trackback'],'status'=>'approve','number'=>3000,'orderby'=>'comment_ID','order'=>'DESC']);
        foreach($comments as $comment){
            $id=(int)$comment->comment_post_ID;if(!isset($allowed[$id])||!current_user_can('read_post',$id))continue;
            $url=trim((string)$comment->comment_author_url);$parts=wp_parse_url($url);
            if(!$parts||!in_array(strtolower($parts['scheme']??''),['http','https'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))continue;
            $host=preg_replace('/^www\./','',strtolower($parts['host']));if($host===$home)continue;
            $url=HTSP_Map_Links::absolute($url,home_url('/'));$key=preg_replace('/#.*$/','',$url);
            $out[$id][$key]=['id'=>0,'title'=>wp_strip_all_tags($comment->comment_author?:$parts['host']),'url'=>$url,'type'=>'backlink','source'=>'WordPress '.$comment->comment_type,'verified_live'=>false];
        }
        foreach($out as &$entries)$entries=array_values($entries);unset($entries);return $out;
    }
    /** The main header menu only, excluding unrelated header/footer links. */
    public static function primary_links():array{
        if(!class_exists('HTS_Nav_Model')||!HTS_Nav_Model::exists('primary'))return [];
        $out=[];foreach(self::model_links(HTS_Nav_Model::get('primary')) as $entry){
            $key=$entry['url'];
            if(!isset($out[$key]))$out[$key]=['id'=>0,'title'=>$entry['label']?:$entry['url'],'url'=>$entry['url'],'type'=>'menu','source'=>'HTS Navigation primary'];
        }
        return array_values($out);
    }
    public static function collect():array{
        $links=[self::HEADER=>[],self::FOOTER=>[]];$scope=[];
        // Primary is the menu rendered by HTS Navigation; fixture/unmounted models are excluded.
        if(class_exists('HTS_Nav_Model')&&HTS_Nav_Model::exists('primary')){
            foreach(self::model_links(HTS_Nav_Model::get('primary')) as $entry)$links[self::HEADER][]=$entry['url'];
        }
        // Classic menus contribute only when assigned to a theme location.
        if(function_exists('get_nav_menu_locations'))foreach(get_nav_menu_locations() as $location=>$menu){
            if(!$menu)continue;
            $source=preg_match('/footer|bottom/i',(string)$location)?self::FOOTER:self::HEADER;
            foreach((array)wp_get_nav_menu_items($menu) as $item){
                if(!is_object($item)||empty($item->url))continue;
                $url=HTSP_Map_Links::absolute($item->url,home_url('/'));if($url!=='')$links[$source][]=$url;
            }
        }
        // Scan header/footer template parts referenced by active-theme templates only.
        if(function_exists('get_block_templates')){
            $refs=[];
            $walk=static function(array $blocks,string $inherited='')use(&$walk,&$refs):void{
                foreach($blocks as $block){
                    if(($block['blockName']??'')==='core/template-part'){
                        $a=$block['attrs']??[];$slug=(string)($a['slug']??'');$area=(string)(($a['area']??'')?:$inherited);
                        if($slug!=='')$refs[($a['theme']??get_stylesheet()).'//'.$slug]=in_array($area,['header','footer'],true)?$area:'';
                    }
                    if(!empty($block['innerBlocks']))$walk($block['innerBlocks'],$inherited);
                }
            };
            foreach(get_block_templates([],'wp_template') as $template)$walk(parse_blocks($template->content));
            $seen=[];
            // Resolve nested parts with a strict bound, without rendering arbitrary shortcodes.
            for($depth=0;$depth<6;$depth++){
                $pending=array_diff_key($refs,$seen);if(!$pending)break;
                foreach($pending as $key=>$area){
                    $seen[$key]=true;$part=get_block_template($key,'wp_template_part');if(!$part)continue;
                    $area=$area?:($part->area??'');if(!in_array($area,['header','footer'],true))continue;
                    $source=$area==='footer'?self::FOOTER:self::HEADER;
                    $links[$source]=array_merge($links[$source],HTSP_Map_Links::extract($part->content,home_url('/'),$scope));
                    $walk(parse_blocks($part->content),$area);
                }
            }
        }
        foreach($links as &$urls)$urls=array_values(array_unique($urls));unset($urls);
        return $links;
    }
    public static function merge_table(array $graph):array{
        foreach(self::collect() as $source=>$urls){
            $node=self::nodes()[$source===self::HEADER?0:1];
            foreach($urls as $url){
                if(strtolower((string)wp_parse_url($url,PHP_URL_HOST))!==strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)))continue;
                $target=(int)url_to_postid($url);
                if(!$target||!current_user_can('read_post',$target))continue;
                $graph['incoming'][$target][$source]=['id'=>$source,'title'=>$node['title'],'url'=>home_url('/'),'virtual'=>true];
                $graph['outgoing'][$source][$target]=['id'=>$target,'title'=>wp_strip_all_tags(get_the_title($target)),'url'=>get_permalink($target)];
            }
        }
        return $graph;
    }
}
