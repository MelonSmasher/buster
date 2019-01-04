<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use GuzzleHttp\Client;
use App\Models\Scheme;
use App\Models\Purge;

class BustPath implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;
    protected $http_scheme;
    protected $path;
    protected $method;
    protected $scheme;
    protected $action;
    protected $resolvePrefix;

    /**
     * BustPath constructor.
     * @param array $data
     * @param string $http_scheme
     * @param string $path
     * @param string $method
     * @param Scheme $scheme
     * @param array $resolvePrefix
     * @param $action
     */
    public function __construct(array $data, string $http_scheme, string $path, string $method, Scheme $scheme, array $resolvePrefix, $action)
    {
        $this->data = $data;
        $this->http_scheme = $http_scheme;
        $this->path = $path;
        $this->method = $method;
        $this->scheme = $scheme;
        $this->resolvePrefix = $resolvePrefix;
        $this->action = $action;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle()
    {
        $client = new Client();

        $this->action->buster_key = $this->http_scheme . $this->method . $this->scheme->server->hostname . $this->path;
        $resolveParam = $this->resolvePrefix;
        $resolveParam[] = $this->action->masquerade;
        $response = $client->request(
            $this->method,
            $this->action->url . $this->path,
            [
                'connect_timeout' => 58.00,
                'debug' => false,
                'http_errors' => false,
                'headers' =>
                    [
                        'Host' => $this->scheme->server->hostname,
                        'x-buster-mode' => 'live',
                        'x-buster-key' => $this->action->buster_key
                    ],
                'curl' => [
                    CURLOPT_DNS_CACHE_TIMEOUT => 0,
                    CURLOPT_RESOLVE => $resolveParam
                ]
            ]
        );

        $this->action->response = (object)[];
        $this->action->response->code = $response->getStatusCode();
        $this->action->response->headers = $response->getHeaders();
        $this->action->response->body = $response->getBody();
        $this->action->response->reason = $response->getReasonPhrase();

        Purge::create([
            'scheme_id' => $this->scheme->id,
            'server_id' => $this->scheme->server->id,
            'url' => $this->action->masquerade,
            'path' => $this->path,
            'response_code' => $response->getStatusCode(),
            'reason' => $this->action->response->reason,
            'action' => json_encode($this->action)
        ]);

        unset($client);
    }

}
