<?php

declare(strict_types=1);

namespace VuFindConfigGui\Mcp;

use VuFindConfigGui\HttpException;

/**
 * Minimal MCP client (Streamable HTTP): initialize → notifications/initialized
 * → one request. Returns the answer plus a trace of all messages, so the GUI
 * can show exactly what an AI client would see.
 *
 * EXPERIMENTAL: targets the MCP server of VuFind pull request #4939, which
 * is not merged yet.
 */
final class McpClient
{
    public const PROTOCOL_VERSION = '2025-11-25';

    /** Methods the GUI may call; everything else is refused */
    public const ALLOWED_METHODS = ['initialize', 'tools/list', 'tools/call', 'resources/templates/list', 'resources/list', 'resources/read'];

    private array $trace = [];
    private ?string $session = null;

    /**
     * @param string $endpoint  URL the GUI uses to reach the server (may be a container name)
     * @param string $publicUrl URL clients use; its host:port is sent as Host header
     */
    public function __construct(private readonly string $endpoint, private readonly string $publicUrl)
    {
    }

    public function call(string $method, array $params = []): array
    {
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new HttpException('error.mcp_method');
        }
        [$status, $init] = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'vufind-config-gui', 'version' => '1'],
        ]]);
        if ($status !== 200 || !isset($init['result'])) {
            $hint = match ($status) {
                403 => 'mcp.hint_403',
                404 => 'mcp.hint_404',
                421, 400 => 'mcp.hint_host',
                default => '',
            };
            return ['ok' => false, 'error' => ['key' => 'mcp.init_failed', 'status' => $status, 'hint' => $hint], 'trace' => $this->trace];
        }
        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $result = null;
        if ($method !== 'initialize') {
            [, $result] = $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => $params ?: new \stdClass()]);
        }
        return ['ok' => true, 'server' => $init['result'], 'response' => $result, 'trace' => $this->trace];
    }

    /** @return array{0: int, 1: mixed} */
    private function send(array $msg): array
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('error.no_curl', [], 500);
        }
        // The MCP SDK's DNS rebinding protection only accepts localhost hosts,
        // and VuFind builds the links in its answers from the Host header.
        $pub = parse_url($this->publicUrl);
        $host = ($pub['host'] ?? 'localhost') . (isset($pub['port']) ? ':' . $pub['port'] : '');
        $headers = ['Host: ' . $host, 'Content-Type: application/json', 'Accept: application/json, text/event-stream',
            'MCP-Protocol-Version: ' . self::PROTOCOL_VERSION];
        if ($this->session) {
            $headers[] = 'Mcp-Session-Id: ' . $this->session;
        }
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new HttpException('error.mcp_unreachable', ['message' => curl_error($ch)], 502);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $hsize);
        $body = substr($raw, $hsize);
        if (preg_match('/^Mcp-Session-Id:\s*(\S+)/mi', $head, $m)) {
            $this->session = $m[1];
        }
        // The answer is either JSON or a server-sent event stream
        if (stripos($head, 'text/event-stream') !== false) {
            $data = [];
            foreach (preg_split('/\r?\n/', $body) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = trim(substr($line, 5));
                }
            }
            $body = end($data) ?: '';
        }
        $decoded = $body === '' ? null : json_decode($body, true);
        $this->trace[] = ['request' => $msg, 'status' => $status, 'response' => $decoded ?? ($body === '' ? null : $body)];
        return [$status, $decoded];
    }
}
