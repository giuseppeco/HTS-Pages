<?php
if (!defined('ABSPATH')) { exit; }

/** Link direction describes actual source content; it never uses post_parent. */
final class HTSP_Map_Links {
    public static function absolute(string $href,string $base): string {
        $href=trim(html_entity_decode($href,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if($href===''){return '';}
        $p=wp_parse_url($base);if(!$p || empty($p['host'])){return '';}
        $origin=($p['scheme']??'https').'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
        if(str_starts_with($href,'//')){$href=($p['scheme']??'https').':'.$href;}
        elseif(str_starts_with($href,'#')){$href=$origin.($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'').$href;}
        elseif(str_starts_with($href,'?')){$href=$origin.($p['path']??'/').$href;}
        elseif(!preg_match('~^[a-z][a-z0-9+.-]*:~i',$href)){$href=$origin.(str_starts_with($href,'/')?$href:preg_replace('~/[^/]*$~','/',$p['path']??'/').$href);}
        $p=wp_parse_url($href);
        if(!$p || !in_array(strtolower($p['scheme']??''),['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])){return '';}
        $segments=[];foreach(explode('/',$p['path']??'/') as $s){if($s==='..'){array_pop($segments);}elseif($s!==''&&$s!=='.'){$segments[]=$s;}}
        return strtolower($p['scheme']).'://'.strtolower($p['host']).(isset($p['port'])?':'.$p['port']:'').'/'.implode('/',$segments).(isset($p['query'])?'?'.$p['query']:'').(isset($p['fragment'])?'#'.$p['fragment']:'');
    }
    public static function canonical(string $url): string {return rtrim(preg_replace('~[?#].*$~','',$url)??$url,'/');}

    public static function extract(string $content,string $base,array &$scope=[],array $visited=[],int $depth=0): array {
        $hrefs=[];
        if(class_exists('DOMDocument')){
            $doc=new DOMDocument();$prev=libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8">'.$content,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
            libxml_clear_errors();libxml_use_internal_errors($prev);
            foreach($doc->getElementsByTagName('a') as $a){if($a->hasAttribute('href')){$hrefs[]=$a->getAttribute('href');}}
        }else{$scope['dom_unavailable']=true;}
        $walk=static function(array $blocks) use (&$walk,&$hrefs,&$scope,$visited,$depth,$base): void {
            foreach($blocks as $b){
                $name=$b['blockName']??'';$attrs=$b['attrs']??[];
                if(in_array($name,['core/navigation-link','core/navigation-submenu'],true)&&is_string($attrs['url']??null)){$hrefs[]=$attrs['url'];}
                if(in_array($name,['core/block','core/navigation'],true)&&!empty($attrs['ref'])){
                    $ref=(int)$attrs['ref'];
                    if($depth>=6 || in_array($ref,$visited,true)){$scope['reference_limit']=true;}
                    else{
                        $post=get_post($ref);
                        if($post&&current_user_can('read_post',$ref)){
                            $nested=self::extract($post->post_content,$base,$scope,array_merge($visited,[$ref]),$depth+1);
                            $hrefs=array_merge($hrefs,$nested);
                        }else{$scope['unreadable_reference']=true;}
                    }
                }
                if(in_array($name,['core/query','core/latest-posts','core/page-list','core/shortcode'],true)){$scope['dynamic_links_excluded']=true;}
                if(!empty($b['innerBlocks'])){$walk($b['innerBlocks']);}
            }
        };
        if(function_exists('parse_blocks')){$walk(parse_blocks($content));}
        if(preg_match('/\[(?:[a-z][a-z0-9_-]*)(?:\s|\])/i',$content)){$scope['dynamic_links_excluded']=true;}
        return array_values(array_unique(array_filter(array_map(static fn($href)=>self::absolute($href,$base),$hrefs))));
    }

    /** Pure edge builder accepts the caller's permission-filtered page set and resolver. */
    public static function build(array $nodes,array $links,callable $resolver,string $home): array {
        $allowed=array_fill_keys(array_map(static fn($n)=>(int)$n['id'],$nodes),true);$edges=[];$self=[];$cache=[];
        $host=strtolower(wp_parse_url($home)['host']??'');
        foreach($links as $source=>$hrefs){
            if(!isset($allowed[$source])){continue;}
            foreach($hrefs as $href){
                if(strtolower(wp_parse_url($href)['host']??'')!==$host){continue;}
                $key=preg_replace('~#.*$~','',$href);
                if(!array_key_exists($key,$cache)){$cache[$key]=(int)$resolver($href);}
                $target=$cache[$key];if(!$target||!isset($allowed[$target])){continue;}
                if((int)$source===$target){$self[$source]=($self[$source]??0)+1;continue;}
                $edgekey=$source.':'.$target;
                if(!isset($edges[$edgekey])){$edges[$edgekey]=['source'=>(int)$source,'target'=>$target,'hrefs'=>[]];}
                if(!in_array($href,$edges[$edgekey]['hrefs'],true)){$edges[$edgekey]['hrefs'][]=$href;}
            }
        }
        return ['edges'=>array_values($edges),'self_links'=>$self];
    }
}
