<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/whatsapp_MetaPanel.php';
function checkMeta(bool $ok,string $label): void {
    if(!$ok){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}
    echo "OK: ".$label."\n";
}
function invalidMeta(array $value,string $label): void {
    try{WhatsAppMetaPanel::templatePayload($value);}
    catch(HttpError $e){checkMeta($e->httpStatus===422,$label);return;}
    checkMeta(false,$label);
}
$basic=[
    'template_mode'=>'simple',
    'template_name'=>'confirmacao_reserva',
    'template_category'=>'UTILITY',
    'template_language'=>'pt_BR',
    'template_header'=>'Reserva {{1}}',
    'header_examples'=>'ABC123',
    'template_body'=>'Olá {{1}}, sua reserva para {{2}} está confirmada.',
    'body_examples'=>"João\n25/12/2026",
    'template_footer'=>'Equipe de reservas'
];
$result=WhatsAppMetaPanel::templatePayload($basic);
checkMeta($result['name']==='confirmacao_reserva','template name');
checkMeta($result['components'][0]['example']['header_text']===['ABC123'],'header example');
checkMeta($result['components'][1]['example']['body_text'][0]===['João','25/12/2026'],'body examples');
invalidMeta(array_merge($basic,['body_examples'=>'João']),'reject missing body example');
invalidMeta(array_merge($basic,['template_body'=>'Olá {{2}}']),'reject non-sequential placeholder');
invalidMeta(array_merge($basic,['template_name'=>'TEMPLATE INVALIDO']),'reject invalid name');
invalidMeta(array_merge($basic,['template_category'=>'INVALID']),'reject invalid category');
$advanced=[
    'template_mode'=>'advanced',
    'advanced_json'=>json_encode([
        'name'=>'otp_login',
        'language'=>'pt_BR',
        'category'=>'AUTHENTICATION',
        'components'=>[['type'=>'BODY','add_security_recommendation'=>true],
             ['type'=>'BUTTONS','buttons'=>[['type'=>'OTP','otp_type'=>'COPY_CODE','text'=>'Copiar código']]]]
    ],JSON_THROW_ON_ERROR)
];
checkMeta(WhatsAppMetaPanel::templatePayload($advanced)['category']==='AUTHENTICATION',
 'advanced OTP accepted for submission to Meta validation');
invalidMeta(['template_mode'=>'advanced','advanced_json'=>'{"name":"test","category":"UTILITY","language":"pt_BR","components":[], "access_token":"secret"}'],
 'reject unsafe top-level JSON fields');
echo "Meta template payload tests passed\n";


// Unit tests for rich Meta Graph errors: never show request token.
$metaError=new MetaFailure(400, ['error'=>[
    'message'=>'Invalid parameter',
    'code'=>100,
    'error_subcode'=>2494160,
    'error_user_title'=>'WABA not allowed to manage templates',
    'error_user_msg'=>'This WABA is not allowed to create or update templates.',
    'fbtrace_id'=>'A12345trace'
]]);
$msg=WhatsAppMetaPanel::formatMetaError($metaError);
checkMeta(str_contains($msg,'WABA is not allowed'), 'Meta WABA restriction visible');
checkMeta(str_contains($msg,'2494160'), 'Meta subcode visible');
checkMeta(str_contains($msg,'A12345trace'), 'Meta trace visible');
$exampleError=new MetaFailure(400, ['error'=>[
    'message'=>'Invalid parameter',
    'error_user_msg'=>'BODY missing expected example',
    'error_data'=>['details'=>'Missing body_text',
        'blame_field_specs'=>[['components','BODY','example']]]
]]);
$msg=WhatsAppMetaPanel::formatMetaError($exampleError);
checkMeta(str_contains($msg,'Missing body_text'), 'Meta field detail visible');
checkMeta(str_contains($msg,'components.BODY.example'), 'Meta blame path visible');
$secret='EAA'.str_repeat('A',45);
$secretError=new MetaFailure(400,['error'=>['message'=>'Bearer '.$secret,
    'error_data'=>['details'=>'access_token='.$secret]]]);
checkMeta(!str_contains(WhatsAppMetaPanel::formatMetaError($secretError),$secret),
    'Meta error must redact token');
$generic=new MetaFailure(400,['error'=>['message'=>'Invalid parameter','code'=>100]]);
checkMeta(str_contains(WhatsAppMetaPanel::formatMetaError($generic),'A Meta não informou'),
    'Generic code 100 gets guidance');
echo "Meta diagnostics tests passed\n";
