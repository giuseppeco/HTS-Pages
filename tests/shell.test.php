<?php
const ABSPATH='/';const PHP_URL_HOST_TEST=1;
function wp_parse_url($s,$component=-1){return parse_url($s,$component);}
function home_url($s='/'){return 'https://example.com'.$s;}
function admin_url($s){return 'https://example.com/wp-admin/'.$s;}
function get_permalink($id){return 'https://example.com/page-'.$id.'/';}
function current_user_can(...$args){return true;}
function get_the_title($id){return 'Page '.$id;}
function wp_strip_all_tags($s){return strip_tags($s);}
function url_to_postid($url){return preg_match('~/page-(\d+)~',$url,$m)?(int)$m[1]:0;}
function get_nav_menu_locations(){return ['footer'=>10,'unused'=>0];}
function wp_get_nav_menu_items($id){return [(object)['url'=>'/page-3/']];}
function get_stylesheet(){return 'test-theme';}
function parse_blocks($content){
 if($content==='template')return [['blockName'=>'core/template-part','attrs'=>['slug'=>'header','area'=>'header']],['blockName'=>'core/template-part','attrs'=>['slug'=>'footer','area'=>'footer']]];
 if($content==='header')return [['blockName'=>'core/template-part','attrs'=>['slug'=>'branding']]];
 if($content==='branding')return [['blockName'=>'core/navigation-link','attrs'=>['url'=>'/page-4/']]];
 if($content==='footer')return [['blockName'=>'core/navigation-link','attrs'=>['url'=>'/page-5/']]];
 return [];
}
function get_block_templates($query,$type){return [(object)['content'=>'template']];}
function get_block_template($key,$type){$slug=explode('//',$key)[1];return (object)['content'=>$slug,'area'=>$slug==='branding'?'':$slug];}
class HTS_Nav_Model{
 static function exists($id){return $id==='primary';}
 static function get($id){return ['home'=>['enabled'=>1,'en'=>['url'=>'/page-1/','label'=>'Home']],'groups'=>['on'=>['enabled'=>1,'items'=>[['enabled'=>1,'en'=>['url'=>'/page-2/','label'=>'Series'],'it'=>['url'=>'/page-2/']],['enabled'=>0,'en'=>['url'=>'/page-9/']]]],'off'=>['enabled'=>0,'items'=>[['en'=>['url'=>'/page-8/']]]]]];}
}
require __DIR__.'/../includes/class-htsp-shell-links.php';
function check($value,$name){if(!$value)throw new Exception($name);echo 'PASS '.$name."\n";}
$links=HTSP_Shell_Links::collect();
check($links[-1]===['https://example.com/page-1','https://example.com/page-2','https://example.com/page-4'],'enabled primary links; disabled items/groups excluded; bilingual duplicates deduplicated');
check($links[-2]===['https://example.com/page-3','https://example.com/page-5'],'assigned footer menu attributed to footer');
$nodes=array_merge(HTSP_Shell_Links::nodes(),[['id'=>1],['id'=>2],['id'=>3],['id'=>4],['id'=>5]]);
$graph=HTSP_Map_Links::build($nodes,$links,'url_to_postid',home_url('/'));
check(count($graph['edges'])===5&&$graph['edges'][0]['source']===-1,'negative virtual IDs survive graph and produce real incoming edges');
$table=HTSP_Shell_Links::merge_table(['incoming'=>[],'outgoing'=>[]]);
check(isset($table['incoming'][2][-1])&&isset($table['incoming'][3][-2]),'table includes header and footer incoming references');
check(count($table['outgoing'][-1])===3,'table and map have consistent outgoing destination counts');
check(HTSP_Shell_Links::is_virtual(-1)&&HTSP_Shell_Links::is_virtual(-2)&&!HTSP_Shell_Links::is_virtual(1),'virtual IDs cannot alias real pages');
check(isset($table['incoming'][4][-1])&&isset($table['incoming'][5][-2]),'nested header part inherits header area; footer template links included');

function get_comments($args){
 check($args['status']==='approve'&&$args['type__in']===['pingback','trackback'],'only approved pingback/trackback records requested');
 return array_map(static fn($row)=>(object)$row,[
 ['comment_post_ID'=>2,'comment_author_url'=>'https://other.example/story/#one','comment_author'=>'External story','comment_type'=>'pingback'],
 ['comment_post_ID'=>2,'comment_author_url'=>'https://other.example/story/#two','comment_author'=>'External story','comment_type'=>'pingback'],
 ['comment_post_ID'=>2,'comment_author_url'=>'https://www.example.com/page-1/','comment_author'=>'Local','comment_type'=>'trackback'],
 ['comment_post_ID'=>2,'comment_author_url'=>'javascript:alert(1)','comment_author'=>'Unsafe','comment_type'=>'pingback'],
 ['comment_post_ID'=>99,'comment_author_url'=>'https://outside.example/','comment_author'=>'Other page','comment_type'=>'pingback']]);
}
$known=HTSP_Shell_Links::backlinks([1,2]);
check(count($known[2])===1&&$known[2][0]['title']==='External story'&&!$known[2][0]['verified_live'],'external backlinks deduplicated; local/unsafe/unrequested sources excluded; no live verification claimed');
$primary=HTSP_Shell_Links::primary_links();
check(count($primary)===2&&$primary[1]['title']==='Series','MENU uses enabled primary only, excludes footer and disabled entries');
