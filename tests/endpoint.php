<?php
// No network: every HTTP operation is a local mock, using invented sandbox secrets.
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
class WP_Error { public $code; public $message; public $data; public function __construct($code,$message,$data=null){$this->code=$code;$this->message=$message;$this->data=$data;} public function get_error_message(){return $this->message;} }
function is_wp_error($x){return $x instanceof WP_Error;}
$GLOBALS['endpoint_filter']=null; $GLOBALS['requests']=[]; $GLOBALS['cache']=[];
function apply_filters($tag,$value){return $tag==='shasmomo_base_url' && $GLOBALS['endpoint_filter']!==null ? $GLOBALS['endpoint_filter'] : $value;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function untrailingslashit($s){return rtrim($s,'/');}
function wp_json_encode($s){return json_encode($s);}
function get_transient($k){return $GLOBALS['cache'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['cache'][$k]=$v;}
function wp_safe_remote_request($url,$args){$GLOBALS['requests'][]=[$url,$args];return ['code'=>200,'body'=>'{"data":{"access_token":"mock-token"}}'];}
function wp_remote_request(){throw new Exception('Unsafe HTTP transport used');}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
require __DIR__.'/../includes/class-shashura-api.php';
function check($ok,$message){if(!$ok){throw new Exception($message);} echo "PASS $message\n";}
$api=new Shasmomo_API('mock-client','mock-secret','sandbox','','https://attacker.example');
check($api->test_credentials()===true,'mock auth succeeds');
check($GLOBALS['requests'][0][0]===Shasmomo_API::BASE_URL.'/auth','saved endpoint cannot receive credentials');
check($GLOBALS['requests'][0][1]['redirection']===0,'redirects disabled');
check($GLOBALS['requests'][0][1]['sslverify']===true,'TLS verified');
foreach(['http://localhost','http://127.0.0.1','https://u:p@example.com','https://example.com?x=1','https://example.com#frag','https://example.com:8080','file:///etc/passwd'] as $bad){
 $GLOBALS['endpoint_filter']=$bad;$before=count($GLOBALS['requests']);
 $api=new Shasmomo_API('mock-client','mock-secret','sandbox');
 check(is_wp_error($api->test_credentials()) && count($GLOBALS['requests'])===$before,'invalid trusted URL fails closed: '.$bad);
}
$GLOBALS['endpoint_filter']='https://sandbox.example/api/v1';
$a=new Shasmomo_API('mock-client','mock-secret','sandbox');
$b=new Shasmomo_API('mock-client','mock-secret','sandbox');
$key=new ReflectionMethod(Shasmomo_API::class,'token_key');if(PHP_VERSION_ID<80100)$key->setAccessible(true);$first=$key->invoke($a);
$GLOBALS['endpoint_filter']='https://another.example/api/v1';$b=new Shasmomo_API('mock-client','mock-secret','sandbox');
check($first!==$key->invoke($b),'token cache isolated per endpoint');
// Real DNS/private-address validation is supplied by WordPress safe HTTP, not this stub.
