<?php
if(!defined('ABSPATH')){exit;}

/** Ordered source outline and explicitly hypothetical journeys; no claims about observed traffic. */
final class HTSP_Map_Blocks {
    private static function text(string $html,int $limit=240):string{
        $html=preg_replace('~</(?:p|div|h[1-6]|li)>|<br\s*/?>~i',' $0 ',$html)??$html;
        $text=trim(preg_replace('/\s+/u',' ',html_entity_decode(wp_strip_all_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))??'');
        if(function_exists('mb_substr')){return mb_substr($text,0,$limit);}$chars=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);return $chars===false?substr($text,0,$limit):implode('',array_slice($chars,0,$limit));
    }
    public static function outline($post,array $design,callable $resolve):array{
        $flat=[];$anchors=[];
        $walk=static function(array $blocks,string $prefix='',?string $parent=null,int $depth=0) use (&$walk,&$flat,&$anchors,$post,$design,$resolve):void{
            if($depth>25){return;}
            foreach($blocks as $index=>$block){
                $path=$prefix===''?(string)$index:$prefix.'.'.$index;$type=$block['blockName']??'';
                if(!$type){continue;}
                $anchor=(string)($block['attrs']['anchor']??'');$id='path:'.$path;
                if($anchor!==''){$anchors[$anchor]=($anchors[$anchor]??0)+1;}
                $matching=null;foreach(($design['elements']??[]) as $element){
                    if(($element['kind']??'')!=='block'){continue;}
                    if((!empty($element['anchor'])&&$element['anchor']===$anchor)||empty($element['anchor'])&&isset($element['path'])&&$element['path']===$path){$matching=$element;break;}
                }
                $html=serialize_block($block);$title=(string)($matching['title']??$block['attrs']['metadata']['name']??'');
                if($title===''&&preg_match('~<h[1-6]\b[^>]*>(.*?)</h[1-6]>~is',$html,$m)){$title=self::text($m[1],130);}
                $names=['core/group'=>'Sezione','core/columns'=>'Colonne','core/column'=>'Colonna','core/paragraph'=>'Testo','core/image'=>'Immagine','core/buttons'=>'Gruppo pulsanti','core/button'=>'Pulsante','core/html'=>'HTML','convertkit/form'=>'Modulo Kit'];
                if($title===''){$title=($names[$type]??$type).' · '.$path;}
                $description=trim((string)($matching['purpose']??''));
                if($description===''){$description=self::text($block['innerHTML']??'');}
                if($description===''){$description=count($block['innerBlocks']??[])?'Contiene '.count($block['innerBlocks']).' blocchi figli.':'Blocco '.$type.'; contenuto dinamico o tecnico da verificare nel frontend.';}
                $links=[];$scope=[];
                foreach(HTSP_Map_Links::extract($html,get_permalink($post),$scope,[$post->ID]) as $url){
                    $target=$resolve($url);if(!$target||(int)$target['id']===(int)$post->ID){continue;}
                    $key=$target['id'].'|'.$url;$links[$key]=['page_id'=>(int)$target['id'],'title'=>$target['title'],'url'=>$url];
                }
                $technical=$type==='core/html'&&self::text($html)==='';
                $flat[]=['id'=>$id,'path'=>$path,'parent_id'=>$parent,'anchor'=>$anchor,'type'=>$type,'title'=>self::text($title,180),
                    'description'=>self::text($description,600),'function'=>self::text((string)($matching['function']??''),600),
                    'links'=>array_values($links),'depth'=>$depth,'designed'=>(bool)$matching,'technical'=>$technical,
                    'named'=>!empty($block['attrs']['metadata']['name'])||$anchor!==''];
                if(!empty($block['innerBlocks'])){$walk($block['innerBlocks'],$path,$id,$depth+1);}
            }
        };
        $walk(parse_blocks($post->post_content));
        // Prefer stable anchors; retain paths for duplicated anchors so no block is silently merged.
        $ids=[];foreach($flat as &$block){$old=$block['id'];if($block['anchor']!==''&&$anchors[$block['anchor']]===1){$block['id']='anchor:'.$block['anchor'];}$ids[$old]=$block['id'];}unset($block);
        foreach($flat as &$block){if($block['parent_id']){$block['parent_id']=$ids[$block['parent_id']]??null;}}unset($block);
        $hasDesign=(bool)array_filter($flat,static fn($b)=>$b['designed']);
        $sectionIds=[];foreach($flat as $block){if($hasDesign?$block['designed']:($block['named']||(!$block['technical']&&$block['depth']===0))){$sectionIds[]=$block['id'];}}
        if(!$sectionIds){$sectionIds=array_column(array_filter($flat,static fn($b)=>!$b['technical']),'id');}
        return ['blocks'=>$flat,'section_ids'=>$sectionIds,'has_design'=>$hasDesign,'source_hash'=>hash('sha256',$post->post_content),
            'note'=>'Ordine ricavato dai blocchi Gutenberg salvati. Le schede TO BE, quando presenti e associate, forniscono titoli e descrizioni; non espande la struttura interna dei pattern referenziati. Link dinamici non materializzati esclusi.'];
    }
    public static function propose(array $outline):array{
        $byId=array_column($outline['blocks'],null,'id');$selected=array_fill_keys($outline['section_ids'],true);$major=[];
        foreach($outline['section_ids'] as $id){$block=$byId[$id];$parent=$block['parent_id'];$nested=false;while($parent&&isset($byId[$parent])){if(isset($selected[$parent])){$nested=true;break;}$parent=$byId[$parent]['parent_id'];}if(!$nested){$major[]=$id;}}
        if(!$major){return [];}
        $paths=[['id'=>'reading','name'=>'Lettura progressiva della pagina','block_ids'=>array_slice($major,0,40),'hypothesis'=>true]];$seen=[];
        foreach($outline['section_ids'] as $id){
            $block=$byId[$id];
            foreach($block['links'] as $link){
                if(isset($seen[$link['page_id']])||preg_match('~privacy|cookie|terms|login~i',$link['url'])){continue;}
                $seen[$link['page_id']]=true;$ancestors=[$id];$parent=$block['parent_id'];
                while($parent&&isset($byId[$parent])){$ancestors[]=$parent;$parent=$byId[$parent]['parent_id'];}
                $owner=null;foreach($major as $root){if(in_array($root,$ancestors,true)){$owner=$root;break;}}
                $sequence=[];foreach($major as $root){$sequence[]=$root;if($root===$owner){break;}}
                if(!in_array($id,$sequence,true)){$sequence[]=$id;}
                $paths[]=['id'=>'destination-'.$link['page_id'],'name'=>'Verso «'.$link['title'].'»','block_ids'=>array_slice($sequence,0,40),'hypothesis'=>true];
                if(count($paths)>=7){break 2;}
            }
        }
        return $paths;
    }
    public static function validate_paths($items,array $outline){
        if(!is_array($items)||count($items)>30){return new WP_Error('htsp_paths','Massimo 30 percorsi.',['status'=>400]);}
        $valid=array_fill_keys(array_column($outline['blocks'],'id'),true);$ids=[];$out=[];
        foreach($items as $path){
            if(!is_array($path)||!is_string($path['id']??null)||!is_string($path['name']??null)||!is_array($path['block_ids']??null)){return new WP_Error('htsp_paths','Percorso non valido.',['status'=>400]);}
            $id=sanitize_key($path['id']);$name=sanitize_text_field($path['name']);
            if(!$id||isset($ids[$id])||$name===''||strlen($name)>500||!count($path['block_ids'])||count($path['block_ids'])>40){return new WP_Error('htsp_paths','Nome, ID o lunghezza del percorso non validi.',['status'=>400]);}
            foreach($path['block_ids'] as $block){if(!is_string($block)||!isset($valid[$block])){return new WP_Error('htsp_paths','Un blocco del percorso non è più presente. Ricaricare e correggere.',['status'=>409]);}}
            $ids[$id]=true;$out[]=['id'=>$id,'name'=>$name,'block_ids'=>array_values($path['block_ids']),'hypothesis'=>true];
        }
        return $out;
    }
}
