<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';
require_once __DIR__.'/whatsapp_ApiSecurity.php';
require_once __DIR__.'/whatsapp_IntegrationService.php';

/** Stateless MCP Streamable HTTP endpoint; supports 2026 and handshake-era clients. */
final class WhatsAppMcp {
    private const MODERN='2026-07-28';
    private const LEGACY='2025-11-25';
    private const SERVER_INFO=['name'=>'ZapApi','version'=>'1.0.0'];

    public static function route(string $path,string $method): bool {
        if($path!=='/mcp')return false;
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        try {
            self::validateOrigin();
            self::addCorsHeaders();
            if($method==='OPTIONS'){
                header('Access-Control-Allow-Methods: POST, OPTIONS');
                header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Method, Mcp-Name');
                header('Access-Control-Max-Age: 600');http_response_code(204);exit;
            }
            if($method!=='POST'){
                header('Allow: POST');
                self::httpError(405,'MCP endpoint accepts POST requests only.');
            }
            $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''),2)[0]));
            $accept=strtolower((string)($_SERVER['HTTP_ACCEPT']??''));
            if($contentType!=='application/json'||
                !str_contains($accept,'application/json')||!str_contains($accept,'text/event-stream'))
                self::httpError(406,'MCP requires application/json and Accept: application/json, text/event-stream.');
            $max=max(1024,(int)config('MAX_BODY_BYTES','2097152'));
            if((int)($_SERVER['CONTENT_LENGTH']??0)>$max)self::httpError(413,'MCP request too large.');
            $raw=file_get_contents('php://input',false,null,0,$max+1);
            if(!is_string($raw)||strlen($raw)>$max)self::httpError(413,'MCP request too large.');
            try{$request=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}
            catch(JsonException $e){self::send(400,['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Parse error']],null);}
            if(!is_array($request)||array_is_list($request)||($request['jsonrpc']??null)!=='2.0'||!is_string($request['method']??null))
                self::send(400,['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid Request']],null);

            $context=bearerContext();
            requireApiScope($context,'mcp:connect',false);
            $params=is_array($request['params']??null)?$request['params']:[];
            $methodName=(string)$request['method'];
            $version=self::requestVersion($request,$params,$methodName);
            self::validateRoutingHeaders($request,$params,$version);
            if($methodName==='notifications/initialized'||$methodName==='notifications/cancelled'){
                if(array_key_exists('id',$request))self::send(400,['jsonrpc'=>'2.0','id'=>$request['id'],'error'=>['code'=>-32600,'message'=>'Notification must not include an id']],$version);
                if($version===self::MODERN)self::send(400,['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Client notifications are not supported in this protocol version']],$version);
                http_response_code(202);header_remove('Content-Type');return true;
            }
            if((!is_string($request['id']??null)&&!is_int($request['id']??null))||($request['id']??null)===null)
                self::send(400,['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid Request']],$version);
            $known=['server/discover','initialize','ping','tools/list','tools/call','resources/list','prompts/list'];
            if(!in_array($methodName,$known,true))
                self::send(200,['jsonrpc'=>'2.0','id'=>$request['id'],'error'=>['code'=>-32601,'message'=>'Method not found']],$version);
            if($methodName==='tools/call'&&(!is_string($params['name']??null)||isset($params['arguments'])&&!is_array($params['arguments'])))
                self::send(200,['jsonrpc'=>'2.0','id'=>$request['id'],'error'=>['code'=>-32602,'message'=>'Invalid params']],$version);
            $result=self::dispatch($methodName,$params,$context,$version);
            self::send(200,['jsonrpc'=>'2.0','id'=>$request['id'],'result'=>self::decorate($result,$version)],$version);
        } catch(HttpError $e) {
            if($e->httpStatus===401)header('WWW-Authenticate: Bearer realm="ZapApi MCP"');
            elseif($e->httpStatus===403)header('WWW-Authenticate: Bearer error="insufficient_scope", scope="mcp:connect"');
            self::httpError($e->httpStatus,$e->getMessage());
        } catch(Throwable $e) {
            error_log('MCP gateway failure: '.get_class($e));
            self::httpError(500,'Internal MCP server error.');
        }
        return true;
    }

    private static function validateOrigin(): void {
        $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
        if($origin==='')return; // Non-browser machine clients normally omit Origin.
        $parts=parse_url($origin);
        if(!$parts||!in_array(strtolower((string)($parts['scheme']??'')),['https'],true)||empty($parts['host'])||
            !empty($parts['user'])||!empty($parts['pass'])||isset($parts['path'])&&$parts['path']!==''||
            isset($parts['query'])||isset($parts['fragment']))
            self::httpError(403,'MCP Origin is not allowed.');
        $port=isset($parts['port'])?(int)$parts['port']:null;
        $normal='https://'.strtolower((string)$parts['host']).($port&&$port!==443?':'.$port:'');
        $allowed=[];
        $configured=explode(',',config('MCP_ALLOWED_ORIGINS'));
        foreach($configured as $value){$value=strtolower(rtrim(trim($value),'/'));if($value!=='')$allowed[]=$value;}
        if(!in_array(strtolower($normal),$allowed,true))self::httpError(403,'MCP Origin is not allowed.');
    }
    private static function addCorsHeaders(): void {
        $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
        if($origin!==''){header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');}
    }

    private static function requestVersion(array $request,array $params,string $method): ?string {
        if($method==='initialize'){
            $offered=$params['protocolVersion']??self::LEGACY;
            return is_string($offered)&&in_array($offered,[self::LEGACY,'2025-06-18','2025-03-26','2024-11-05'],true)?$offered:self::LEGACY;
        }
        if($method==='server/discover')return self::MODERN;
        $meta=$params['_meta']??[];
        $fromBody=is_array($meta)?($meta['io.modelcontextprotocol/protocolVersion']??null):null;
        $fromHeader=$_SERVER['HTTP_MCP_PROTOCOL_VERSION']??null;
        if(is_string($fromHeader)&&$fromHeader!==''&&is_string($fromBody)&&$fromBody!==''&&$fromHeader!==$fromBody)
            self::send(400,['jsonrpc'=>'2.0','id'=>$request['id']??null,'error'=>['code'=>-32600,'message'=>'Protocol version header and metadata differ']],null);
        $version=is_string($fromHeader)&&$fromHeader!==''?$fromHeader:(is_string($fromBody)?$fromBody:null);
        if(in_array($version,[self::MODERN,self::LEGACY,'2025-06-18','2025-03-26','2024-11-05'],true))return $version;
        $supported=[self::MODERN,self::LEGACY];
        self::send(400,['jsonrpc'=>'2.0','id'=>$request['id']??null,'error'=>[
            'code'=>-32022,'message'=>'Unsupported or missing MCP protocol version',
            'data'=>['supported'=>$supported,'requested'=>$version]
        ]],null);
    }

    private static function validateRoutingHeaders(array $request,array $params,?string $version): void {
        $headerMethod=(string)($_SERVER['HTTP_MCP_METHOD']??'');
        if($headerMethod!==''&&$headerMethod!==(string)$request['method'])self::httpError(400,'Mcp-Method does not match the JSON-RPC request.');
        $headerName=(string)($_SERVER['HTTP_MCP_NAME']??'');
        if($headerName!==''&&$request['method']==='tools/call'&&$headerName!==(string)($params['name']??''))
            self::httpError(400,'Mcp-Name does not match the requested tool.');
        if($version===self::MODERN&&$headerMethod==='')self::httpError(400,'Modern MCP requests must include the Mcp-Method header.');
        if($version===self::MODERN&&($_SERVER['HTTP_MCP_PROTOCOL_VERSION']??'')!==self::MODERN)
            self::httpError(400,'Modern MCP requests must include MCP-Protocol-Version: '.self::MODERN.'.');
        if($version===self::MODERN&&($request['method']??'')==='tools/call'&&$headerName==='')
            self::httpError(400,'Modern MCP tool calls must include the Mcp-Name header.');
    }

    private static function dispatch(string $method,array $params,array $context,string $version): mixed {
        if($method==='server/discover')return [
            'resultType'=>'complete','supportedVersions'=>[self::MODERN,self::LEGACY],
            'capabilities'=>['tools'=>['listChanged'=>false]],
            '_meta'=>['io.modelcontextprotocol/serverInfo'=>self::SERVER_INFO],
            'instructions'=>'A ZapApi integra o WhatsApp Cloud API da empresa autenticada. Envie somente para contatos autorizados; use templates aprovados fora da janela de atendimento. Envio é assíncrono e retorna um job_id. Use events_list com after_id para receber mensagens e mudanças de status.',
            'ttlMs'=>3600000,'cacheScope'=>'private'
        ];
        if($method==='initialize'){
            $offered=(string)($params['protocolVersion']??self::LEGACY);
            $supported=[self::LEGACY,'2025-06-18','2025-03-26','2024-11-05'];
            if(!in_array($offered,$supported,true))throw new HttpError('Unsupported MCP protocol version.',400);
            return ['protocolVersion'=>$offered,'capabilities'=>['tools'=>['listChanged'=>false]],
                'serverInfo'=>self::SERVER_INFO,
                'instructions'=>'ZapApi V2: mensagens oficiais WhatsApp Cloud API, templates aprovados e eventos da empresa autenticada.'];
        }
        if($method==='ping')return (object)[];
        if($method==='tools/list'){
            $tools=[];foreach(self::tools() as $tool)
                if(in_array($tool['scope'],$context['scopes']??[],true)){$item=$tool['definition'];unset($item['_scope']);$tools[]=$item;}
            $result=['tools'=>$tools];if($version===self::MODERN){$result['ttlMs']=30000;$result['cacheScope']='private';}
            return $result;
        }
        if($method==='tools/call'){
            $name=$params['name']??'';$args=$params['arguments']??[];
            if(!is_string($name)||!is_array($args)||array_is_list($args)&&$args!==[])throw new HttpError('Invalid tool arguments.',422);
            return self::callTool($name,$args,$context);
        }
        if($method==='resources/list')return ['resources'=>[]];
        if($method==='prompts/list')return ['prompts'=>[]];
        throw new HttpError('Unknown MCP method: '.$method,404);
    }

    private static function tools(): array {
        $str=['type'=>'string'];$phone=['type'=>'string','pattern'=>'^[0-9]{7,20}$'];
        $number=['type'=>'string','pattern'=>'^[0-9]{1,40}$'];
        $tools=[];
        $add=static function(string $scope,array $definition)use(&$tools):void{$definition['_scope']=$scope;$tools[]=['scope'=>$scope,'definition'=>$definition];};
        $add('accounts:read',['name'=>'accounts_list','description'=>'Lista os números WhatsApp acessíveis pelo token.','inputSchema'=>['type'=>'object','properties'=>new stdClass(),'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true]]);
        $add('conversations:read',['name'=>'conversations_list','description'=>'Lista conversas recentes, última mensagem, estado e quantidade não lida.','inputSchema'=>['type'=>'object','properties'=>['phone_number_id'=>$number,'q'=>$str,'before_id'=>['type'=>'string'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100]],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true]]);
        $add('messages:read',['name'=>'messages_list','description'=>'Lê o histórico de mensagens de uma conversa. Retorna payload normalizado e cursor local.','inputSchema'=>['type'=>'object','properties'=>['phone_number_id'=>$number,'wa_id'=>$phone,'before_id'=>['type'=>'string'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100]],'required'=>['wa_id'],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true]]);
        $add('messages:send',['name'=>'messages_send','description'=>'Envia texto, template, mídia ou outro tipo aceito pela Cloud API usando a fila da V2. Use um idempotency_key novo e estável por intenção. Só templates aprovados e consentidos são aceitos; o retorno queued não significa entregue.','inputSchema'=>['type'=>'object','properties'=>['phone_number_id'=>$number,'idempotency_key'=>['type'=>'string','minLength'=>8,'maxLength'=>180,'pattern'=>'^[A-Za-z0-9._:-]+$'],'message'=>['type'=>'object','properties'=>['to'=>$phone,'type'=>['type'=>'string','pattern'=>'^[a-z_]{2,40}$'],'text'=>['type'=>'object'],'template'=>['type'=>'object'],'image'=>['type'=>'object'],'video'=>['type'=>'object'],'audio'=>['type'=>'object'],'document'=>['type'=>'object'],'sticker'=>['type'=>'object'],'interactive'=>['type'=>'object'],'location'=>['type'=>'object'],'contacts'=>['type'=>'array']],'required'=>['to','type'],'additionalProperties'=>true]],'required'=>['idempotency_key','message'],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>true]]);
        $add('templates:read',['name'=>'templates_list','description'=>'Consulta templates e seus status na Meta. Use after para paginar.','inputSchema'=>['type'=>'object','properties'=>['phone_number_id'=>$number,'name'=>$str,'after'=>$str,'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100]],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true]]);
        $add('events:read',['name'=>'events_list','description'=>'Busca eventos de webhook após after_id. Guarde next_after_id para continuar sem reler eventos.','inputSchema'=>['type'=>'object','properties'=>['phone_number_id'=>$number,'after_id'=>['type'=>'string','pattern'=>'^(0|[1-9][0-9]{0,18})$'],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100]],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true]]);
        $add('jobs:read',['name'=>'jobs_get','description'=>'Consulta o status de um envio da fila.','inputSchema'=>['type'=>'object','properties'=>['phone_number_id'=>$number,'job_id'=>['type'=>'integer','minimum'=>1]],'required'=>['job_id'],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true]]);
        $add('conversations:mark_read',['name'=>'conversation_mark_read','description'=>'Marca até a mensagem recebida indicada como lida na Meta e avança o cursor monotônico que sincroniza a notificação dos aparelhos Android. Não use apenas por ter consultado o histórico.','inputSchema'=>['type'=>'object','properties'=>['phone_number_id'=>$number,'wa_id'=>$phone,'message_id'=>['type'=>'string','pattern'=>'^[1-9][0-9]{0,18}$']],'required'=>['wa_id','message_id'],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>true]]);
        return $tools;
    }

    private static function callTool(string $name,array $args,array $context): array {
        $byName=[];foreach(self::tools() as $tool)$byName[$tool['definition']['name']]=$tool;
        if(!isset($byName[$name]))return self::toolError('Ferramenta inexistente ou não habilitada para este token.');
        $allowedArgs=[
            'accounts_list'=>[],
            'conversations_list'=>['phone_number_id','q','before_id','limit'],
            'messages_list'=>['phone_number_id','wa_id','before_id','limit'],
            'messages_send'=>['phone_number_id','idempotency_key','message'],
            'templates_list'=>['phone_number_id','name','after','limit'],
            'events_list'=>['phone_number_id','after_id','limit'],
            'jobs_get'=>['phone_number_id','job_id'],
            'conversation_mark_read'=>['phone_number_id','wa_id','message_id']
        ];
        if(array_diff(array_keys($args),$allowedArgs[$name]??[]))return self::toolError('Argumento não reconhecido para esta ferramenta.');
        $scope=$byName[$name]['scope'];
        try {
            requireApiScope($context,$scope,false);
            if($name==='accounts_list')$value=['data'=>WhatsAppIntegrationService::accounts($context)];
            else {
                $requested=$args['phone_number_id']??null;
                if($requested!==null&&!is_string($requested))throw new HttpError('phone_number_id inválido.',422);
                if($requested==='')throw new HttpError('phone_number_id inválido.',422);
                $account=selectedAccount($context,$requested);
                $tenantId=(int)$context['tenant_id'];$accountId=(int)$account['id'];
                if($name==='conversations_list'){
                    if(isset($args['q'])&&!is_string($args['q']))throw new HttpError('q inválido.',422);
                    $before=self::cursor($args['before_id']??null,PHP_INT_MAX);
                    $value=['account_id'=>$accountId,'data'=>WhatsAppIntegrationService::conversations($context,$account,
                        is_string($args['q']??null)?substr(trim($args['q']),0,120):'', $before,self::toolLimit($args['limit']??null))];
                }elseif($name==='messages_list'){
                    if(!is_string($args['wa_id']??null)||!validPhone($args['wa_id']))throw new HttpError('wa_id inválido.',422);
                    $before=self::cursor($args['before_id']??null,PHP_INT_MAX);
                    $value=['account_id'=>$accountId,'wa_id'=>$args['wa_id'],'data'=>WhatsAppIntegrationService::messages($context,$account,
                        $args['wa_id'],$before,self::toolLimit($args['limit']??null))];
                }elseif($name==='messages_send'){
                    if(!is_array($args['message']??null)||array_is_list($args['message']))throw new HttpError('message deve ser um objeto.',422);
                    if(!is_string($args['idempotency_key']??null))throw new HttpError('idempotency_key inválida.',422);
                    $message=$args['message'];
                    if(isset($message['phone_number_id'])||isset($message['idempotency_key']))throw new HttpError('Campos de controle devem ficar fora de message.',422);
                    $value=WhatsAppIntegrationService::enqueueMessage($context,$account,$message,$args['idempotency_key']);
                }elseif($name==='templates_list'){
                    if(isset($args['name'])&&!is_string($args['name'])||isset($args['after'])&&!is_string($args['after']))throw new HttpError('Parâmetros de template inválidos.',422);
                    $value=WhatsAppIntegrationService::templates($account,self::toolLimit($args['limit']??null),$args['name']??null,$args['after']??null);
                }elseif($name==='events_list'){
                    $value=['account_id'=>$accountId]+WhatsAppIntegrationService::events($context,$account,self::cursor($args['after_id']??'0',0),self::toolLimit($args['limit']??null));
                }elseif($name==='jobs_get'){
                    if(!is_int($args['job_id']??null)||$args['job_id']<1)throw new HttpError('job_id inválido.',422);
                    $value=WhatsAppIntegrationService::job($context,$account,$args['job_id']);
                }elseif($name==='conversation_mark_read'){
                    if(!is_string($args['wa_id']??null)||!validPhone($args['wa_id']))throw new HttpError('wa_id inválido.',422);
                    $id=self::cursor($args['message_id']??null,0);
                    if($id<1)throw new HttpError('message_id inválido.',422);
                    $read=WhatsAppIntegrationService::markConversationRead($context,$account,$args['wa_id'],$id);
                    $value=['account_id'=>$accountId,'wa_id'=>$args['wa_id'],'read_through_id'=>$read['read_through_id'],'advanced'=>$read['advanced']];
                }else return self::toolError('Ferramenta não implementada.');
            }
            return ['content'=>[['type'=>'text','text'=>jsonString($value)]]];
        }catch(MetaFailure $e){return self::toolError('Falha na Cloud API da Meta (HTTP '.$e->upstreamStatus.($e->transportUncertain?', resultado incerto':'').').');}
        catch(HttpError $e){return self::toolError($e->getMessage());}
        catch(Throwable $e){error_log('MCP tool failure: '.get_class($e));return self::toolError('Falha interna ao executar a ferramenta.');}
    }

    private static function cursor(mixed $value,int $default): int {
        if($value===null)return $default;
        if(!is_string($value)&&!is_int($value))throw new HttpError('Cursor inválido.',422);
        $raw=(string)$value;if(!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$raw))throw new HttpError('Cursor inválido.',422);
        $parsed=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($parsed===false)throw new HttpError('Cursor fora do limite suportado.',422);
        return (int)$parsed;
    }
    private static function toolLimit(mixed $value): int {
        if($value===null)return 50;
        if(!is_int($value)||$value<1||$value>100)throw new HttpError('limit deve ser um inteiro entre 1 e 100.',422);
        return $value;
    }
    private static function toolError(string $message): array {return ['isError'=>true,'content'=>[['type'=>'text','text'=>$message]]];}
    private static function decorate(mixed $result,?string $version): mixed {
        if($version===self::MODERN&&!is_array($result))$result=(array)$result;
        if($version===self::MODERN){$result['resultType']='complete';$result['_meta']=$result['_meta']??[];$result['_meta']['io.modelcontextprotocol/serverInfo']=self::SERVER_INFO;}
        return $result;
    }
    private static function send(int $status,array $body,?string $version): never {
        http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        if($version!==null)header('MCP-Protocol-Version: '.$version);
        echo jsonString($body);exit;
    }
    private static function httpError(int $status,string $message): never {
        http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
        echo jsonString(['error'=>$message]);exit;
    }
}
