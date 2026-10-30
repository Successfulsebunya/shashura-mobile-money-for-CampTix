<?php
require __DIR__.'/bootstrap.php';
foreach([90,110] as $amount){
 $ctx=fixture($amount);$m=method();
 $cancelled=false;
 $state=invoke($m,'settle',$ctx,false,true,function()use(&$cancelled){$cancelled=true;});
 check($state==='reconciliation','under/overpayment has distinct reconciliation state: '.$amount);
 check(!$cancelled && !$GLOBALS['camptix']->reports,'mismatch never completes or cancels as unpaid');
 check(count($GLOBALS['mail'])===1 && strpos($GLOBALS['mail'][0][2],(string)$amount)!==false,'actionable mismatch alert');
 $fresh=invoke($m,'load',$ctx['token']);
 check($fresh['attempts'][0]['paid_amount']===$amount,'collected amount persisted');
 check(invoke($m,'settle',$fresh,false,true)==='reconciliation','replay remains in reconciliation');
 check(count($GLOBALS['mail'])===1 && Shasmomo_API::$enquiries===1,'replay does not re-alert or requery final attempt');
 check(!invoke($m,'all_failed',['attempts'=>$fresh['attempts']]),'mismatch is not all-failed');
 foreach(['guard_draft','step_confirm'] as $handler){
  $fresh['pending']=['msisdn'=>'256770000000','at'=>time()];
  try{invoke($m,$handler,$fresh);throw new Exception('Expected reconciliation warning');}catch(Rendered $e){check(true,$handler.' blocks new charge with reconciliation screen');}
 }
}
$ctx=fixture(100);$m=method();check(invoke($m,'settle',$ctx,false,true)==='paid','exact amount still completes');
check(count($GLOBALS['camptix']->reports)===1 && !$GLOBALS['mail'],'ordinary settlement remains compatible with CampTix result');
