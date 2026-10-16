<?php
// Supply WordPress's official block parser directory as the first CLI argument.
define('ABSPATH',__DIR__);
require $argv[1].'/class-wp-block-parser-block.php';
require $argv[1].'/class-wp-block-parser-frame.php';
require $argv[1].'/class-wp-block-parser.php';
function parse_blocks($s){return (new WP_Block_Parser())->parse($s);}
function serialize_block($b){$s=$b['innerHTML']??'';foreach($b['innerBlocks']??[] as $child){$s.=serialize_block($child);}return $s;}
function wp_strip_all_tags($s){return strip_tags($s);}
function wp_parse_url($s){return parse_url($s);}
function get_permalink($p){return 'https://example.com/home/';}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function sanitize_text_field($s){return trim(strip_tags($s));}
class WP_Error{public function __construct(public $code,public $message,public $data){}}
require __DIR__.'/../includes/class-htsp-map-links.php';
require __DIR__.'/../includes/class-htsp-map-blocks.php';
function ok($b,$m){if(!$b){throw new Exception($m);}}
$post=(object)['ID'=>1,'post_content'=>'<!-- wp:group {"anchor":"intro"} --><div><!-- wp:heading --><h2>Benvenuti</h2><!-- /wp:heading --><!-- wp:paragraph --><p><a href="/child/">Scopri</a></p><!-- /wp:paragraph --></div><!-- /wp:group --><!-- wp:paragraph {"anchor":"end"} --><p>Conclusione</p><!-- /wp:paragraph -->'];
$design=['elements'=>[['kind'=>'block','anchor'=>'intro','title'=>'Introduzione','purpose'=>'Presenta il sito']]];
$o=HTSP_Map_Blocks::outline($post,$design,fn($url)=>str_contains($url,'/child')?['id'=>2,'title'=>'Destinazione']:null);
ok(count($o['blocks'])===4,'all nested blocks');
ok($o['blocks'][0]['id']==='anchor:intro','stable anchor');
ok($o['blocks'][0]['title']==='Introduzione','TO BE title');
ok($o['blocks'][0]['description']==='Presenta il sito','TO BE purpose');
ok($o['blocks'][2]['parent_id']==='anchor:intro','parent remapped');
ok($o['blocks'][2]['links'][0]['title']==='Destinazione','clickable child link');
$paths=HTSP_Map_Blocks::propose($o);ok(count($paths)===2,'reading and child proposals');
ok(is_array(HTSP_Map_Blocks::validate_paths($paths,$o)),'valid paths');
$paths[0]['block_ids']=['missing'];ok(HTSP_Map_Blocks::validate_paths($paths,$o) instanceof WP_Error,'stale block rejected');
ok(HTSP_Map_Links::absolute('../child/?a=1#x','https://example.com/home/')==='https://example.com/child?a=1#x','relative URL');
ok(HTSP_Map_Links::absolute('javascript:alert(1)','https://example.com')==='','unsafe URL');
$g=HTSP_Map_Links::build([['id'=>1],['id'=>2],['id'=>3]],[1=>['https://example.com/?page_id=2','https://example.com/?page_id=3','https://example.com/?page_id=99']],function($u){parse_str(parse_url($u,PHP_URL_QUERY),$q);return $q['page_id'];},'https://example.com');
ok(count($g['edges'])===2,'query identity retained and hidden pages excluded');
echo "Blocks and links: nested order, TO BE labels, journeys, stale references, safe URLs and permissions passed.\n";
