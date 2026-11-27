<?php
declare(strict_types=1);
require_once __DIR__ . '/whatsapp_bootstrap.php';

class MetaFailure extends RuntimeException {
    public function __construct(
        public int $upstreamStatus, public array $metaBody = [],
        public bool $transportUncertain = false
    ) { parent::__construct($transportUncertain ? 'Meta transport failure; delivery uncertain' : 'Meta API request failed'); }
}
final class Graph {
    public static function call(array $account, string $method, string $path, ?array $payload = null,
                                array $query = [], ?array $upload = null): array {
        $version = config('GRAPH_VERSION', 'v26.0');
        if (!preg_match('/^v[0-9]{1,2}\.[0-9]{1,2}$/D', $version)) throw new RuntimeException('Invalid Graph version');
        if (!preg_match('~^[0-9a-zA-Z_./-]+$~D', $path) || str_contains($path, '..') || str_starts_with($path, '/'))
            throw new HttpError('Invalid Graph path');
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) throw new HttpError('Method unsupported', 405);
        $url = 'https://graph.facebook.com/' . $version . '/' . $path;
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Cannot initialize cURL');
        $headers = ['Authorization: Bearer ' . unseal($account['token_box']), 'Accept: application/json'];
        if ($upload === null && $payload !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'CleanWhatsAppGateway/1.0',
        ]);
        if ($upload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $upload);
        elseif ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, jsonString($payload));
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new MetaFailure(502, ['error' => ['message' => 'Network failure']], true);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw' => mbTrim($raw)];
        if ($status < 200 || $status >= 300) throw new MetaFailure($status ?: 502, $data);
        return $data;
    }

    /** Fetches an inbound Meta media object without exposing its signed URL or the Meta token. */
    public static function downloadMedia(array $account,string $mediaId): array {
        if(!preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$mediaId))throw new HttpError('Invalid media id',422);
        $meta=self::call($account,'GET',$mediaId);
        $url=(string)($meta['url']??'');$parts=parse_url($url);
        $host=strtolower((string)($parts['host']??''));
        $trustedHost=preg_match('/(?:^|\\.)(?:facebook\\.com|fbcdn\\.net|fbsbx\\.com)$/D',$host)===1;
        if(!$parts||($parts['scheme']??'')!=='https'||!$trustedHost||!empty($parts['user'])||!empty($parts['pass'])||
            (isset($parts['port'])&&(int)$parts['port']!==443))
            throw new HttpError('Meta returned an unsupported media location',502);
        $max=max(1024,(int)config('MAX_MEDIA_DOWNLOAD_BYTES',config('MAX_UPLOAD_BYTES','104857600')));
        $declared=filter_var($meta['file_size']??null,FILTER_VALIDATE_INT);
        if($declared!==false&&$declared!==null&&(int)$declared>$max)throw new HttpError('Media exceeds configured download size',413);
        $temp=tmpfile();
        if($temp===false)throw new RuntimeException('Cannot create temporary media file');
        try {
            $size=0;$tooLarge=false;$ch=curl_init($url);
            if($ch===false)throw new RuntimeException('Cannot initialize media download');
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Authorization: Bearer '.unseal($account['token_box']),'Accept: */*'],
                CURLOPT_USERAGENT=>'ZapApi/1.0',
                CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use($temp,&$size,&$tooLarge,$max): int {
                    $length=strlen($chunk);
                    if($size+$length>$max){$tooLarge=true;return 0;}
                    $written=fwrite($temp,$chunk);
                    if($written!==$length)return 0;
                    $size+=$written;return $written;
                }
            ]);
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            $mime=(string)(curl_getinfo($ch,CURLINFO_CONTENT_TYPE)?:($meta['mime_type']??''));
            $error=curl_error($ch);curl_close($ch);
            if($tooLarge)throw new HttpError('Media exceeds configured download size',413);
            if($ok===false||$status<200||$status>=300)
                throw new MetaFailure($status?:502,['error'=>['message'=>$error!==''?'Media download failed':'Meta media request failed']],false);
            rewind($temp);$head=fread($temp,min($size,16384));rewind($temp);
            $detected=(new finfo(FILEINFO_MIME_TYPE))->buffer(is_string($head)?$head:'');
            if(is_string($detected)&&preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~iD',$detected))$mime=$detected;
            if(!preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~iD',$mime))$mime='application/octet-stream';
            return ['stream'=>$temp,'mime_type'=>$mime,'file_size'=>$size];
        }catch(Throwable $e){fclose($temp);throw $e;}
    }
}
function mbTrim(string $s): string { return substr($s, 0, 4096); }
