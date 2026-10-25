<?php
// Isolated regression harness: no production WordPress writes.
define('ABSPATH',__DIR__);
class WP_Error{public $code;public function __construct($code,$message='',$data=[]){$this->code=$code;}}
function is_wp_error($value){return $value instanceof WP_Error;}
$GLOBALS['permitted']=true;$GLOBALS['writes']=[];$GLOBALS['blocks']=[];
function current_user_can(...$args){return $GLOBALS['permitted'];}
function wp_get_nav_menus(){return [(object)['term_id'=>2,'name'=>'Primary']];}
function wp_get_nav_menu_items(...$args){return array_map(static function($r){return (object)array_merge(['url'=>'','object'=>'page','object_id'=>1,'type'=>'post_type','target'=>'','classes'=>[],'xfn'=>'','description'=>'','attr_title'=>'','post_status'=>'publish','menu_order'=>1],$r);},[['ID'=>10,'title'=>'Home','menu_item_parent'=>0],['ID'=>11,'title'=>'Child','menu_item_parent'=>10,'object_id'=>2]]);}
function get_posts($args){return $GLOBALS['blocks']?[(object)['ID'=>7,'post_title'=>'Block nav','post_content'=>'test']]:[];}
function parse_blocks($content){return $GLOBALS['blocks'];}
function wp_strip_all_tags($s){return strip_tags($s);}
function wp_json_encode($s){return json_encode($s);}
function url_to_postid($url){return 0;}
function sanitize_text_field($s){return trim(strip_tags($s));}
function wp_delete_post(...$args){$GLOBALS['writes'][]=['delete',$args];return true;}
function wp_update_nav_menu_item(...$args){$GLOBALS['writes'][]=['menu',$args];return 20;}
function get_permalink($id){return 'https://example.com/?page_id='.$id;}
function serialize_blocks($blocks){return json_encode($blocks);}
function wp_slash($s){return $s;}
function wp_update_post($data,$error){$GLOBALS['writes'][]=['block',$data];return $data['ID'];}
require __DIR__.'/../includes/class-htsp-map-menus.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function reset_snapshot(){$property=new ReflectionProperty(HTSP_Map_Menus::class,'cache');$property->setAccessible(true);$property->setValue(null,null);}
function change($args=[]){$s=HTSP_Map_Menus::public_snapshot(1);return HTSP_Map_Menus::change(1,array_merge(['token'=>$s['token'],'menu'=>'classic:2','item'=>'10','parent'=>'0','label'=>'Start'],$args));}
$s=HTSP_Map_Menus::public_snapshot(1);check($s['menus'][0]['items'][0]['current'],'Page membership');check(!isset($s['menus'][0]['items'][0]['raw']),'Private snapshot excluded');
check(is_wp_error(change(['token'=>'old'])),'Stale menu rejected');check(!count($GLOBALS['writes']),'No stale write');
check(is_wp_error(change(['parent'=>'11'])),'Cycle rejected');check(is_wp_error(change(['remove'=>true])),'Children protected');
check(is_wp_error(change(['item'=>'11'])),'Wrong target rejected');
$GLOBALS['permitted']=false;check(is_wp_error(change()),'Menu capability required');$GLOBALS['permitted']=true;
check(!is_wp_error(change()),'Label updated');$write=end($GLOBALS['writes']);check($write[1][2]['menu-item-object-id']===1,'Page identity retained');check($write[1][2]['menu-item-title']==='Start','Label saved');
$submenu=['blockName'=>'core/navigation-submenu','attrs'=>['label'=>'Discover'],'innerBlocks'=>[],'innerHTML'=>'','innerContent'=>[]];
$link=['blockName'=>'core/navigation-link','attrs'=>['label'=>'Home','type'=>'page','id'=>1,'url'=>'https://example.com/'],'innerBlocks'=>[],'innerHTML'=>'','innerContent'=>[]];
$GLOBALS['blocks']=[$submenu,$link];reset_snapshot();$s=HTSP_Map_Menus::public_snapshot(1);$menu=$s['menus'][1];check($menu['items'][0]['key']==='b0','Root sentinel distinct from first block');
$r=HTSP_Map_Menus::change(1,['token'=>$s['token'],'menu'=>'block:7','item'=>'b1','parent'=>'b0','label'=>'Start Here']);check(!is_wp_error($r),'Block move saved');
$write=end($GLOBALS['writes']);$saved=json_decode($write[1]['post_content'],true);check(count($saved)===1,'Moved link removed from root');check($saved[0]['innerBlocks'][0]['attrs']['label']==='Start Here','Nested label retained');check($saved[0]['innerContent']===[null],'Nested serialization slots retained');
echo "Menus: capabilities, conflicts, cycles, children, target validation, classic identity and block reparenting passed.\n";
