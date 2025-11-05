<?php

namespace App\Http\Controllers;

use App\Services\ApifyClient;
use App\Services\SentimentService;
use App\Services\TikTokWebClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\CommentsExport;
use GuzzleHttp\Client;

class SentimentController extends Controller
{
    /** Halaman form */
    public function index()
    {
        return view('sentiment.index');
    }

    /** Ambil komentar TikTok → klasifikasi → kirim JSON untuk chart */
    public function analyze(Request $request, ApifyClient $apify, SentimentService $sentiment, TikTokWebClient $ttWeb)
    {
        @set_time_limit(300);
        ini_set('max_execution_time', '300');

        $request->validate(['tiktok_url' => ['required','url']]);

        $url = $this->canonicalizeTikTokUrl(trim($request->tiktok_url));
        if (!preg_match('~tiktok\.com/@[^/]+/video/\d+~', $url)) {
            return response()->json(['error' => 'URL video TikTok tidak valid.'], 422);
        }

        try {
            $target   = 1200; // target ambil komentar
            $comments = [];

            // 1) Apify dulu
            try {
                $comments = $apify->fetchComments($url, $target);
            } catch (\Throwable $e) {
                Log::warning('Apify fetch failed: '.$e->getMessage());
            }

            // 2) Fallback & merge jika kurang
            if (count($comments) < 300) {
                $more     = $ttWeb->fetchComments($url, $target);
                $comments = $this->mergeAndDedup($comments, $more, $target);
                if (count($comments) < 300) {
                    $more2    = $ttWeb->fetchComments($url, $target);
                    $comments = $this->mergeAndDedup($comments, $more2, $target);
                }
            }

            if (empty($comments)) {
                return response()->json([
                    'error' => 'Gagal mengambil komentar. Pastikan link video publik & cookie masih valid.',
                ], 422);
            }

            // Preprocess + heuristik → lalu model
            $scored = $this->classifyNotTooShort($sentiment, $comments, 'text');

            $counts = [
                'positive' => collect($scored)->where('sentiment','positive')->count(),
                'neutral'  => collect($scored)->where('sentiment','neutral')->count(),
                'negative' => collect($scored)->where('sentiment','negative')->count(),
            ];

            Cache::put('last_analysis', $scored, now()->addMinutes(30));

            return response()->json([
                'chart'    => $counts,
                'positive' => collect($scored)->where('sentiment','positive')->pluck('text')->values(),
                'neutral'  => collect($scored)->where('sentiment','neutral')->pluck('text')->values(),
                'negative' => collect($scored)->where('sentiment','negative')->pluck('text')->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Analyze error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $payload = ['error' => 'Terjadi kesalahan saat analisis.'];
            if (app()->isLocal() || config('app.debug')) {
                $payload['exception'] = get_class($e);
                $payload['message']   = $e->getMessage();
            }
            return response()->json($payload, 500);
        }
    }

    /** Export Excel */
    public function export()
    {
        $rows = Cache::get('last_analysis', []);
        if (empty($rows)) {
            return back()->with('error', 'Belum ada hasil analisis untuk diexport.');
        }
        return Excel::download(new CommentsExport($rows), 'tiktok_comments_analysis.xlsx');
    }

    /** Konversi short link TikTok → URL kanonik */
    private function canonicalizeTikTokUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') return $url;

        if (preg_match('~https?://(www\.)?tiktok\.com/@[^/]+/video/\d+~i', $url)) {
            return $url;
        }

        try {
            $client = new Client([
                'timeout' => 20,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ],
            ]);

            $current = $url;
            for ($i = 0; $i < 10; $i++) {
                $res  = $client->request('GET', $current, ['allow_redirects' => false]);
                $code = $res->getStatusCode();
                if (!in_array($code, [301,302,303,307,308], true)) break;

                $loc = $res->getHeaderLine('Location');
                if (!$loc) break;

                if (!preg_match('~^https?://~i', $loc)) {
                    $p    = parse_url($current);
                    $base = $p['scheme'].'://'.$p['host'] . (isset($p['port']) ? ':'.$p['port'] : '');
                    if (str_starts_with($loc, '/')) {
                        $current = $base.$loc;
                    } else {
                        $dir     = rtrim(dirname(parse_url($current, PHP_URL_PATH) ?? '/'), '/');
                        $current = $base.$dir.'/'.$loc;
                    }
                } else {
                    $current = $loc;
                }
            }
            return $current;
        } catch (\Throwable $e) {
            return $url;
        }
    }

    /** Mulai run (opsional untuk mode polling) */
    public function start(Request $request, ApifyClient $apify)
    {
        $request->validate(['tiktok_url' => 'required|url']);

        try {
            $raw = trim($request->tiktok_url);
            $url = $this->canonicalizeTikTokUrl($raw);
            $url = strtok($url, '?');

            if (!preg_match('~tiktok\.com/@[^/]+/video/\d+~', $url)) {
                return response()->json([
                    'error' => 'URL TikTok tidak valid. Buka videonya di browser lalu salin URL penuh: https://www.tiktok.com/@user/video/123...'
                ], 422);
            }

            $runId = $apify->startRun($url);
            Log::debug('start(): Apify run dimulai', ['url' => $url, 'runId' => $runId]);

            return response()->json(['runId' => $runId]);
        } catch (\Throwable $e) {
            Log::error('Start error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'error'     => 'Gagal memulai scraping di Apify.',
                // <<< PERBAIKAN DI SINI: tambahkan tanda kurung pembuka >>>
                'exception' => (app()->isLocal() || config('app.debug')) ? get_class($e) : null,
                'message'   => (app()->isLocal() || config('app.debug')) ? $e->getMessage() : null,
            ], 500);
        }
    }

    /** Cek run → ambil dataset → klasifikasi (untuk mode polling) */
    public function check($runId, ApifyClient $apify, SentimentService $sentiment)
    {
        $status = $apify->getRunStatus($runId);
        $state  = $status['status'] ?? 'UNKNOWN';

        if ($state !== 'SUCCEEDED') {
            if (in_array($state, ['FAILED','ABORTED','TIMED-OUT'], true)) {
                return response()->json([
                    'status' => $state,
                    'error'  => 'Scraping di Apify tidak berhasil. Coba ulangi atau ganti proxyCountry/actor.',
                ], 422);
            }
            return response()->json($status);
        }

        $datasetId = $status['datasetId'] ?? null;
        if (!$datasetId) {
            return response()->json([
                'status' => $state,
                'error'  => 'defaultDatasetId tidak tersedia dari run Apify.',
            ], 422);
        }

        $comments = $apify->fetchDataset($datasetId);
        $comments = array_map(function($it){
            $t = $it['text'] ?? ($it['comment']['text'] ?? ($it['content'] ?? ''));
            return ['text' => is_string($t) ? trim($t) : ''];
        }, $comments);

        $scored = $this->classifyNotTooShort($sentiment, $comments, 'text');

        $counts = [
            'positive' => collect($scored)->where('sentiment', 'positive')->count(),
            'neutral'  => collect($scored)->where('sentiment', 'neutral')->count(),
            'negative' => collect($scored)->where('sentiment', 'negative')->count(),
        ];

        Cache::put('last_analysis', $scored, now()->addMinutes(30));

        return response()->json([
            'chart'    => $counts,
            'positive' => collect($scored)->where('sentiment','positive')->pluck('text')->values(),
            'neutral'  => collect($scored)->where('sentiment','neutral')->pluck('text')->values(),
            'negative' => collect($scored)->where('sentiment','negative')->pluck('text')->values(),
        ]);
    }

    /* ===================== Helpers ===================== */

    private function mergeAndDedup(array $a, array $b, int $limit): array
    {
        $merge = array_merge($a, $b);
        $seen  = [];
        $out   = [];
        foreach ($merge as $it) {
            $t = trim((string)($it['text'] ?? ''));
            if ($t === '' || isset($seen[$t])) continue;
            $seen[$t] = true;
            $out[]    = ['text' => $t];
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private function normalizeText(string $t): string
    {
        $t = preg_replace('~https?://\S+~', '', $t);      // buang link
        $t = preg_replace('~[@#]\S+~', '', $t);           // buang mention/hashtag
        $t = preg_replace('~\s+~', ' ', $t);
        $t = trim($t);
        // “lucu bangeeetttt” → “lucu banget”
        $t = preg_replace('/(.)\1{2,}/u', '$1$1', $t);
        return mb_strtolower($t);
    }

    private function emojiLexiconLabel(string $t): ?string
    {
        $pos = ['😂','🤣','😊','😍','😆','😄','mantap','keren','nice','bagus','the best','suka','terbaik','wkwk','wk wk','lol','hehe','mantul','gaskeun','solid'];
        $neg = ['😡','🤬','😠','😤','anjir','anjing','goblok','jelek','parah','buruk','lebay','benci','menipu','scam','rip','payah','cape','capek','kecewa'];

        foreach ($pos as $p) { if (mb_strpos($t, $p) !== false) return 'positive'; }
        foreach ($neg as $n) { if (mb_strpos($t, $n) !== false) return 'negative'; }
        return null;
    }

    /** Preprocess + heuristik, lalu model untuk sisanya */
    private function classifyNotTooShort(SentimentService $svc, array $rows, string $field): array
    {
        $clean = [];
        foreach ($rows as $r) {
            $txt = isset($r[$field]) ? (string)$r[$field] : '';
            $txt = $this->normalizeText($txt);

            // drop komentar super pendek tanpa sinyal
            if (mb_strlen(preg_replace('~[^a-zA-Z]~u', '', $txt)) < 3 && !preg_match('/(😂|🤣|😡|🤬)/u', $txt)) {
                continue;
            }
            $clean[] = [$field => $txt];
        }
        if (empty($clean)) return [];

        $out = [];
        $needModel = [];
        foreach ($clean as $r) {
            $hint = $this->emojiLexiconLabel($r[$field]);
            if ($hint) {
                $out[] = ['text' => $r[$field], 'sentiment' => $hint, 'score' => 0.99];
            } else {
                $needModel[] = $r;
            }
        }

        if (!empty($needModel)) {
            $scored = $svc->classifyMany($needModel, $field);
            $out = array_merge($out, $scored);
        }
        return $out;
    }
}
