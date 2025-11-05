<?php

namespace App\Services;

use GuzzleHttp\Client;

class SentimentService
{
    protected Client $http;
    protected string $token;
    protected string $model;

    public function __construct()
    {
        $this->http  = new Client(['timeout' => 60, 'http_errors' => false]);
        $this->token = (string) config('services.huggingface.token', '');
        $this->model = (string) config('services.huggingface.model', 'w11wo/indonesian-roberta-base-sentiment-classifier');
    }

    /**
     * Klasifikasi banyak teks sekaligus.
     * @param array $rows   Array of rows (tiap row harus punya key $textKey)
     * @param string $textKey  Nama kolom teks pada tiap row, default 'text'
     * @return array rows yang sama + field 'sentiment' (positive|neutral|negative)
     */
    // App\Services\SentimentService.php
    public function classifyMany(array $rows, string $textKey = 'text'): array
    {
        if (trim($this->token) === '') {
            return array_map(function ($r) { $r['sentiment'] = 'neutral'; return $r; }, $rows);
        }

        $out = [];
        foreach (array_chunk($rows, 16) as $chunk) {
            $payload = array_map(function ($r) use ($textKey) {
                $txt = (string)($r[$textKey] ?? '');
                return mb_substr($txt, 0, 300);
            }, $chunk);

            // --- request + retry kalau 503 / loading
            $resp = null;
            for ($i = 0; $i < 4; $i++) {
                $resp = $this->http->post("https://api-inference.huggingface.co/models/{$this->model}", [
                    'headers' => [
                        'Authorization' => "Bearer {$this->token}",
                        'Accept'        => 'application/json',
                    ],
                    'json' => [
                        'inputs'  => $payload,
                        'options' => ['wait_for_model' => true, 'use_cache' => true],
                    ],
                ]);

                $code = $resp->getStatusCode();
                if ($code === 503) { usleep(900000); continue; }

                // HF kadang balas 200 tapi JSON { "error": "Model ... loading" }
                $body = json_decode((string)$resp->getBody(), true);
                if (isset($body['error'])) { usleep(900000); continue; }

                $preds = $body;
                break;
            }

            // Jika masih gagal → tandai netral
            if (!isset($preds) || !is_array($preds)) {
                foreach ($chunk as $row) { $row['sentiment'] = 'neutral'; $out[] = $row; }
                continue;
            }

            // Deteksi bentuk output:
            // Bentuk normal: $preds = [ [ ['label'=>'positive','score'=>..], ... ], ... ]
            $isPerItemArray = is_array($preds) && isset($preds[0]) && is_array($preds[0]);

            foreach ($chunk as $i => $row) {
                $labels = $isPerItemArray ? ($preds[$i] ?? []) : [];

                // Amankan tipe
                if (!is_array($labels)) $labels = [];

                // Urutkan skor desc
                usort($labels, fn($a,$b)=>($b['score']??0)<=>($a['score']??0));

                // Ambil top-1
                $top = $labels[0] ?? null;
                $rawLabel = strtolower((string)($top['label'] ?? ''));

                // ---- Normalisasi label
                // Banyak model pakai: LABEL_0=negative, LABEL_1=neutral, LABEL_2=positive
                $map = [
                    'label_0' => 'negative',
                    'label_1' => 'neutral',
                    'label_2' => 'positive',
                    // variasi lain yang sering muncul
                    'pos' => 'positive', 'neg' => 'negative', 'neu' => 'neutral',
                ];

                $label = $map[$rawLabel] ?? $rawLabel;

                // Kalau label tidak dikenal, jatuhkan ke neutral
                if (!in_array($label, ['positive','neutral','negative'], true)) {
                    // Heuristik: kalau ada 3 label berbeda, tebak dari urutan kalau memungkinkan
                    if (isset($labels[0]['label'], $labels[1]['label'], $labels[2]['label'])) {
                        $cands = array_map(fn($x)=>strtolower((string)($x['label'] ?? '')), $labels);
                        // urutan paling umum HF: negative, neutral, positive
                        // cari apakah ada LABEL_0/1/2
                        if (in_array('label_2', $cands, true))      $label = 'positive';
                        elseif (in_array('label_1', $cands, true))  $label = 'neutral';
                        elseif (in_array('label_0', $cands, true))  $label = 'negative';
                    }
                }

                // ---- Threshold ringan (opsional)
                $score = (float)($top['score'] ?? 0);
                if ($score < 0.55) { // skor ragu → netral
                    $label = 'neutral';
                }

                $row['sentiment'] = in_array($label, ['positive','neutral','negative'], true) ? $label : 'neutral';
                $out[] = $row;
            }
        }

        return $out;
    }

}
