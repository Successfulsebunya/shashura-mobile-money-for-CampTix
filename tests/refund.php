<?php
require __DIR__.'/bootstrap.php';
foreach(['FAILED','PENDING','UNKNOWN',null,'TS'] as $state){
 fixture();$m=method();
 Shasmomo_API::$refundResponse=['amount_refunded'=>100,'refund_ref'=>'mock-refund','payout'=>$state===null?null:['status'=>$state,'message'=>'mock','ref'=>'mock-payout']];
 $result=$m->send_refund_request('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
 if($state==='TS'){
  check($result['status']===CampTix_Plugin::PAYMENT_STATUS_REFUNDED,'confirmed payout reports refund success');
  check(!get_post_meta(1,'_shashura_refund_hold',true),'confirmed payout releases reconciliation hold');
 }else{
  check($result['status']===CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,'unconfirmed payout never reports success: '.($state??'missing'));
  $hold=get_post_meta(1,'_shashura_refund_hold',true);
  check($hold['result']['refund_transaction_id']==='mock-refund','reversal reference retained for recovery');
  $m->send_refund_request('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');$m->payment_refund_partial('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',10,false);
  check(Shasmomo_API::$refundCalls===1,'full and partial retries do not repeat reversal');
  check(count($GLOBALS['mail'])===1,'one actionable administrator alert');
 }
 check(!$GLOBALS['wpdb']->locks,'refund lock released');
}
fixture();$m=method();Shasmomo_API::$refundResponse=new WP_Error;
check($m->send_refund_request('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')['status']===CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED,'transport uncertainty reports failure');
$m->send_refund_request('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');check(Shasmomo_API::$refundCalls===1,'ambiguous transport result blocks a second reversal');
fixture();$m=method();update_post_meta(1,'_shashura_refund_hold',['phase'=>'request_started']);
$m->send_refund_request('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');check(Shasmomo_API::$refundCalls===0,'crash marker blocks reversal');
fixture();$m=method();$lock=invoke($m,'lock','aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
$m->send_refund_request('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');check(Shasmomo_API::$refundCalls===0,'concurrent refund lock blocks reversal');$m->unlock($lock);
