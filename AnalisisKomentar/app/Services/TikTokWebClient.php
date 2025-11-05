<?php

namespace App\Services;

use GuzzleHttp\Client;

class TikTokWebClient
{
    protected Client $http;
    protected string $cookie;

    public function __construct()
    {
        $this->http = new Client([
            'timeout'         => 90,
            'connect_timeout' => 15,
            'http_errors'     => false,
        ]);

        $this->cookie = (string) config('services.apify.session', '');
        if ($this->cookie === '') {
            throw new \RuntimeException('Cookie TikTok kosong. Isi APIFY_TT_SESSION di .env.');
        }
    }

    /** Ambil komentar dari web API TikTok (butuh login cookie). */
    public function fetchComments(string $videoUrl, int $max = 2000): array
    {
        $videoId = $this->extractVideoId($videoUrl);
        if (!$videoId) {
            throw new \InvalidArgumentException('URL video TikTok tidak valid.');
        }

        $headers = [
            'Accept'           => 'application/json, text/plain, */*',
            'Accept-Language'  => 'id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cookie'           => $this->cookie,
            'Referer'          => $videoUrl,
            'User-Agent'       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Origin'           => 'https://www.tiktok.com',
            'Sec-Fetch-Site'   => 'same-origin',
            'Sec-Fetch-Mode'   => 'cors',
            'Sec-Fetch-Dest'   => 'empty',
        ];

        $out    = [];
        $cursor = 0;
        $pageSize = 100; // up to 100

        while (count($out) < $max) {
            $query = [
                'aid'      => 1988,      // Web app
                'cursor'   => $cursor,
                'count'    => $pageSize,
                'aweme_id' => $videoId,
                'item_id'  => $videoId,
            ];

            $res  = $this->http->get('https://www.tiktok.com/api/comment/list/', [
                'headers' => $headers,
                'query'   => $query,
            ]);

            if ($res->getStatusCode() >= 300) {
                throw new \RuntimeException('Gagal memanggil TikTok API: '.$res->getStatusCode().' '.$res->getReasonPhrase());
            }

            $json = json_decode((string)$res->getBody(), true);
            if (!is_array($json)) {
                throw new \RuntimeException('Respon TikTok tidak valid.');
            }

            $comments = $json['comments'] ?? $json['comment_list'] ?? [];
            foreach ($comments as $c) {
                $text =
                    $c['text'] ??
                    ($c['comment']['text'] ?? null) ??
                    ($c['content'] ?? null);

                if (is_string($text) && trim($text) !== '') {
                    $out[] = ['text' => trim($text)];
                    if (count($out) >= $max) break 2;
                }
            }

            $hasMore = (int)($json['has_more'] ?? 0) === 1;
            $next    = (int)($json['cursor'] ?? $json['cursor_next'] ?? 0);

            // fallback cursor kalau API tidak menaikkan pointer
            if ($hasMore && $next <= $cursor) {
                $next = $cursor + $pageSize;
            }
            $cursor = $next;

            if (!$hasMore) break;
        }

        return $out;
    }

    private function extractVideoId(string $url): ?string
    {
        if (preg_match('~tiktok\.com/@[^/]+/video/(\d+)~i', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
