<?php

namespace App\Services;

use GuzzleHttp\Client;

class ApifyClient
{
    protected Client $http;
    protected string $token;
    protected string $actor;    // e.g. clockworks/tiktok-comments-scraper
    protected string $actorId;  // e.g. clockworks~tiktok-comments-scraper

    public function __construct()
    {
        $this->http = new Client([
            'timeout'         => 180,
            'connect_timeout' => 20,
            'http_errors'     => false,
        ]);

        $this->token   = trim((string) config('services.apify.token', ''));
        $actorCfg      = trim((string) config('services.apify.actor', 'clockworks/tiktok-comments-scraper'));
        $this->actor   = strtolower($actorCfg);
        $this->actorId = str_contains($this->actor, '~') ? $this->actor : str_replace('/', '~', $this->actor);
    }

    private function actorKind(): string
    {
        $id = strtolower($this->actorId);
        if ($id === 'clockworks~tiktok-comments-scraper') return 'clockworks_comments';
        if ($id === 'clockworks~tiktok-scraper')          return 'clockworks_generic';
        if ($id === 'apify~tiktok-scraper')               return 'apify_generic';
        return 'unknown';
    }

    /** Build input payload sesuai actor. */
    private function buildInput(string $cleanUrl, int $max): array
    {
        $want = max(20000, (int) $max);

        $rawCookie = (string) config('services.apify.session', '');
        $cookies   = $this->cookieHeaderToArray($rawCookie);

        $commonLimit = [
            'maxComments'        => $want,
            'commentsLimit'      => $want,
            'maxCommentsPerPost' => $want,
            'limit'              => $want,
            'maxItems'           => $want,
        ];
        $commonCrawl = [
            'maxRequestRetries' => 6,
            'maxConcurrency'    => 8,
            // SG biasanya lebih longgar untuk TikTok
            'proxy'             => ['useApifyProxy' => true, 'apifyProxyCountry' => 'SG'],
        ];

        switch ($this->actorKind()) {
            case 'apify_generic':
                $input = [
                    'startUrls'             => [ ['url' => $cleanUrl] ],
                    'device'                => 'desktop',
                    'browser'               => 'chrome',
                    'scrapeComments'        => true,
                    'includeCommentReplies' => true,
                ] + $commonLimit + $commonCrawl;

                if (!empty($cookies)) {
                    $input['userCookies'] = $cookies;
                }
                break;

            case 'clockworks_comments':
                $input = [
                    'postURLs'              => [$cleanUrl],
                    'resultsType'           => 'comments',          // penting
                    'includeCommentReplies' => true,
                ] + $commonLimit + $commonCrawl;

                if (!empty($cookies)) {
                    $input['userCookies']     = $cookies;
                    $input['useLoggedInMode'] = true;
                }
                break;

            default: // clockworks_generic / unknown
                $input = [
                    'postURLs'              => [$cleanUrl],
                    'resultsType'           => 'comments',
                    'includeCommentReplies' => true,
                ] + $commonLimit + $commonCrawl;

                if (!empty($cookies)) {
                    $input['userCookies']     = $cookies;
                    $input['useLoggedInMode'] = true;
                }
                break;
        }

        \Log::info('apify-input', $input);
        return $input;
    }

    public function fetchComments(string $videoUrl, int $max = 5000): array
    {
        if ($this->token === '') {
            throw new \RuntimeException('APIFY_TOKEN belum di-set.');
        }

        $cleanUrl = strtok($videoUrl, '?');
        $input    = $this->buildInput($cleanUrl, $max);

        $start = $this->http->post("https://api.apify.com/v2/acts/{$this->actorId}/runs", [
            'query'   => ['token' => $this->token, 'waitForFinish' => 120],
            'json'    => $input,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if ($start->getStatusCode() >= 300) {
            throw new \RuntimeException("Gagal start Apify run: " . (string) $start->getBody());
        }

        $info   = json_decode((string) $start->getBody(), true) ?: [];
        $runId  = $info['data']['id'] ?? null;
        $status = $info['data']['status'] ?? 'READY';

        // Poll status
        $tries = 0;
        while (!in_array($status, ['SUCCEEDED','FAILED','ABORTED','TIMED-OUT'], true) && $tries < 80) {
            usleep(900_000);
            $r = $this->http->get("https://api.apify.com/v2/actor-runs/{$runId}", [
                'query' => ['token' => $this->token],
            ]);
            $info   = json_decode((string) $r->getBody(), true) ?: [];
            $status = $info['data']['status'] ?? $status;
            $tries++;
        }

        if ($status !== 'SUCCEEDED') {
            $statusMsg = $info['data']['statusMessage'] ?? '';
            $errMsg    = $info['data']['errorMessage']  ?? '';
            throw new \RuntimeException("Run Apify tidak sukses. Status: {$status}. {$statusMsg} {$errMsg}");
        }

        $datasetId = $info['data']['defaultDatasetId'] ?? null;
        if (!$datasetId) {
            throw new \RuntimeException('defaultDatasetId tidak tersedia.');
        }

        $items = $this->fetchDataset($datasetId);

        // Ambil teks komentar
        $out = [];
        foreach ($items as $it) {
            $text = $it['text'] ?? ($it['comment']['text'] ?? ($it['content'] ?? null));
            if (is_string($text) && trim($text) !== '') {
                $out[] = ['text' => trim($text)];
            }
            if (count($out) >= $max) break;
        }
        return $out;
    }

    public function startRun(string $videoUrl): string
    {
        $cleanUrl = strtok($videoUrl, '?');
        $input    = $this->buildInput($cleanUrl, 20000);

        $res = $this->http->post("https://api.apify.com/v2/acts/{$this->actorId}/runs", [
            'query'   => ['token' => $this->token],
            'json'    => $input,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $code = $res->getStatusCode();
        $body = (string) $res->getBody();
        if ($code >= 300) {
            throw new \RuntimeException("Start run failed ($code) for actorId={$this->actorId}: ".$body);
        }

        $data = json_decode($body, true)['data'] ?? [];
        return $data['id'] ?? throw new \RuntimeException('Gagal start run: '.$body);
    }

    public function getRunStatus(string $runId): array
    {
        $r = $this->http->get("https://api.apify.com/v2/actor-runs/{$runId}", [
            'query' => ['token' => $this->token],
        ]);
        $data = json_decode((string) $r->getBody(), true)['data'] ?? [];
        return [
            'status'        => $data['status'] ?? 'UNKNOWN',
            'datasetId'     => $data['defaultDatasetId'] ?? null,
            'statusMessage' => $data['statusMessage'] ?? null,
            'errorMessage'  => $data['errorMessage']  ?? null,
        ];
    }

    public function fetchDataset(string $datasetId): array
    {
        $batch  = 1000;
        $offset = 0;
        $items  = [];

        while (true) {
            $r = $this->http->get("https://api.apify.com/v2/datasets/{$datasetId}/items", [
                'query' => [
                    'token'  => $this->token,
                    'clean'  => 'true',
                    'format' => 'json',
                    'limit'  => $batch,
                    'offset' => $offset,
                ],
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($r->getStatusCode() >= 300) {
                throw new \RuntimeException("Gagal ambil dataset: " . (string) $r->getBody());
            }

            $page  = json_decode((string) $r->getBody(), true) ?? [];
            $count = count($page);
            if ($count === 0) break;

            $items  = array_merge($items, $page);
            $offset += $count;

            if ($count < $batch) break;
        }

        return $items;
    }

    /** Convert header Cookie mentah menjadi array cookie untuk Apify `userCookies`. */
    private function cookieHeaderToArray(string $raw): array
    {
        $raw = trim($raw, "\"' \n\r\t");
        if ($raw === '') return [];

        $pairs = [];
        foreach (explode(';', $raw) as $kv) {
            $kv = trim($kv);
            if ($kv === '' || !str_contains($kv, '=')) continue;
            [$name, $value] = array_map('trim', explode('=', $kv, 2));
            if ($name === '' || $value === '') continue;
            $pairs[] = [
                'name'     => $name,
                'value'    => $value,
                'domain'   => '.tiktok.com',
                'path'     => '/',
                'httpOnly' => false,
                'secure'   => true,
            ];
        }
        return $pairs;
    }
}
