<?php
// Standalone, in-memory WordPress/CampTix doubles; never loads real credentials or networking.
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS',3600); define('MINUTE_IN_SECONDS',60); define('DAY_IN_SECONDS',86400);
$GLOBALS['meta']=[]; $GLOBALS['cache']=[]; $GLOBALS['mail']=[]; $GLOBALS['status']='draft';
class WP_Error { public function get_error_message(){return 'Mock error';} }
function is_wp_error($x){return $x instanceof WP_Error;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_post_meta($id,$key,$v){$GLOBALS['meta'][$id][$key]=$v;return true;}
function delete_post_meta($id,$key){unset($GLOBALS['meta'][$id][$key]);return true;}
function get_post_status($id){return $GLOBALS['status'];}
function get_posts($args){return [1];}
function get_transient($k){return $GLOBALS['cache'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['cache'][$k]=$v;}
function wp_slash($v){return $v;}
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return $v;}
function wp_generate_password($n,$s=false,$e=false){return str_repeat('a',$n);}
function get_option($k){return $k==='admin_email'?'admin@example.test':'';}
function get_bloginfo($k){return 'Mock event';}
function wp_specialchars_decode($s,$f){return $s;}
function admin_url($s){return 'https://example.test/wp-admin/'.$s;}
function wp_mail($to,$subject,$body){$GLOBALS['mail'][]=[$to,$subject,$body];return true;}
function __($s,$domain=null){return $s;}
function wp_list_pluck($a,$k){return array_column($a,$k);}
class Rendered extends Exception {}
function status_header($status){throw new Rendered('Rendered reconciliation warning');}
class MockDB {
 public $options='wp_options'; public $locks=[];
 function prepare($q,...$args){return [$q,$args];}
 function query($prepared){list($q,$a)=$prepared; if(strpos($q,'INSERT')===0){if(isset($this->locks[$a[0]]))return 0;$this->locks[$a[0]]=$a[1];return 1;} if(strpos($q,'DELETE')===0 && ($this->locks[$a[0]]??null)===$a[1])unset($this->locks[$a[0]]);return 1;}
 function get_var($prepared){return $this->locks[$prepared[1][0]]??'';}
}
$GLOBALS['wpdb']=new MockDB;
class CampTix_Plugin {
 const PAYMENT_STATUS_COMPLETED=1, PAYMENT_STATUS_REFUNDED=2, PAYMENT_STATUS_REFUND_FAILED=3;
 public $logs=[]; public $reports=[];
 function log(...$args){$this->logs[]=$args;}
 function get_post_meta_from_payment_token($token,$key){return 'mock-tid';}
 function payment_result($token,$result,$data,$interactive=true){$this->reports[]=[$token,$result,$data];return $result;}
}
$GLOBALS['camptix']=new CampTix_Plugin;
class CampTix_Payment_Method {
 function get_order($token){return ['attendee_id'=>1,'total'=>100];}
 function get_order_by_attendee_id($id){return ['total'=>100];}
}
class Shasmomo_API {
 public static $response; public static $refundResponse; public static $refundCalls=0; public static $enquiries=0;
 function __construct(...$args){}
 function enquiry($ref){self::$enquiries++;return self::$response;}
 function refund(...$args){self::$refundCalls++;return self::$refundResponse;}
 static function error_code($e){return 'mock';}
 static function http_status($e){return 0;}
 function request_to_pay(...$args){throw new Exception('Unexpected new charge');}
}
class Shasmomo_Phone {static function mask($s){return 'masked';}}
require (getenv('PLUGIN_UNDER_TEST')?:dirname(__DIR__)).'/includes/class-shashura-payment-method.php';
function check($ok,$message){if(!$ok)throw new Exception($message);echo "PASS $message\n";}
function invoke($object,$name,...$args){$method=new ReflectionMethod($object,$name);if(PHP_VERSION_ID<80100)$method->setAccessible(true);return $method->invoke($object,...$args);}
function method(){ $m=new Shasmomo_Payment_Method; $p=new ReflectionProperty($m,'options');if(PHP_VERSION_ID<80100)$p->setAccessible(true);$p->setValue($m,['client_id'=>'mock','client_secret'=>'mock','environment'=>'sandbox','webhook_secret'=>'mock','base_url'=>'']); return $m; }
function fixture($amount=100){
 $GLOBALS['meta']=[1=>['tix_payment_method'=>'shashura','_shashura_attempts'=>[['status'=>'sent','ref'=>'CTXMOCK','tid'=>'mock-tid','amount'=>100,'created'=>time(),'msisdn'=>'256770000000','name'=>'Test Name','network'=>'MTN','env'=>'sandbox']]]];
 $GLOBALS['status']='draft';$GLOBALS['mail']=[];$GLOBALS['cache']=[];$GLOBALS['wpdb']->locks=[];$GLOBALS['camptix']->reports=[];
 Shasmomo_API::$enquiries=0; Shasmomo_API::$refundCalls=0;
 Shasmomo_API::$response=['state'=>'paid','raw_state'=>'TS','amount'=>$amount,'external_ref'=>'telco-ref','tid'=>'mock-tid','network_ref'=>'mock-network'];
 return ['token'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','ids'=>[1],'first'=>1,'status'=>'draft','total'=>100,'attempts'=>$GLOBALS['meta'][1]['_shashura_attempts'],'pending'=>[],'lookups'=>0];
}
