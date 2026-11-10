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
