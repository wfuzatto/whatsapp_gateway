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
}
function mbTrim(string $s): string { return substr($s, 0, 4096); }
