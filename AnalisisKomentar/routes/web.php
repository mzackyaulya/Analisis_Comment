<?php

use GuzzleHttp\Client;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use GuzzleHttp\Exception\RequestException;
use App\Http\Controllers\SentimentController;

// Route::get('/', function () {
//     return view('welcome');
// });

Route::get('/', [SentimentController::class, 'index'])->name('home');
Route::post('/analyze', [SentimentController::class, 'analyze'])->name('analyze');
Route::get('/export', [SentimentController::class, 'export'])->name('sentiment.export');
Route::post('/start',          [SentimentController::class, 'start'])->name('sentiment.start');
Route::get('/check/{runId}',   [SentimentController::class, 'check'])->name('sentiment.check');

// Route::get('/hf-test', function () {
//     $token = trim((string) config('services.huggingface.token'));
//     $model = (string) config('services.huggingface.model', 'w11wo/indonesian-roberta-base-sentiment-classifier');

//     $http = new Client(['timeout' => 60, 'http_errors' => false]);

//     $samples = [
//         "Aku senang sekali hari ini!",
//         "Biasa aja sih.",
//         "Ini jelek dan bikin kecewa."
//     ];

//     $resp = $http->post("https://api-inference.huggingface.co/models/{$model}", [
//         'headers' => [
//             'Authorization' => "Bearer {$token}",
//             'Accept'        => 'application/json',
//         ],
//         'json' => [
//             // ⬇⬇ WAJIB: kirim dalam objek { "inputs": [...] }
//             'inputs'  => $samples,
//             // biar tidak 503 saat model cold-start
//             'options' => ['wait_for_model' => true]
//         ],
//     ]);

//     return response()->json([
//         'status_code' => $resp->getStatusCode(),
//         'body'        => json_decode((string) $resp->getBody(), true),
//     ], $resp->getStatusCode());
// });
