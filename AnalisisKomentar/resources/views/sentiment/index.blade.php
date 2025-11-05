<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Analisis Komentar TikTok</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8f9fa; }
    /* tampilan mirip contoh: grid lembut & label abu */
    .chart-wrap { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; }
  </style>
</head>
<body class="p-4">
  <div class="container">
    <h2 class="mb-4">📊 Analisis Komentar TikTok</h2>

    <form id="analyzeForm" class="mb-3">
      <div class="input-group">
        <input type="url" name="tiktok_url" class="form-control" placeholder="Tempel link video TikTok di sini..." required>
        <button type="submit" class="btn btn-primary">Analisis</button>
      </div>
    </form>

    <div class="chart-wrap">
      <canvas id="chart" height="200"></canvas>
    </div>

    <div class="mt-4">
      <button id="exportBtn" class="btn btn-success">⬇️ Export ke Excel</button>
    </div>

    <pre id="resultBox" class="mt-3 bg-white p-3 border rounded"></pre>
  </div>

  <script>
    // --------- DOM refs & routes ----------
    const form      = document.getElementById('analyzeForm');
    const resultBox = document.getElementById('resultBox');
    const chartCtx  = document.getElementById('chart').getContext('2d');
    const token     = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const urlInput  = document.querySelector('input[name="tiktok_url"]');

    // pakai helper route -> anti-404
    const START_URL  = "{{ route('sentiment.start') }}";
    const CHECK_URL  = (id) => "{{ route('sentiment.check', ['runId' => '__ID__']) }}".replace('__ID__', id);
    const EXPORT_URL = "{{ route('sentiment.export') }}";

    let chart;

    // --------- Events ----------
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      await startAnalysis();
    });

    document.getElementById('exportBtn').onclick = () => {
      window.location.href = EXPORT_URL;
    };

    // --------- Flow ----------
    async function startAnalysis() {
      resultBox.textContent = "Analisis sedang diproses...";
      const formData = new FormData();
      formData.append('tiktok_url', urlInput.value);

      try {
        const startRes = await fetch(START_URL, {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
          body: formData
        });

        if (!startRes.ok) {
          const t = await startRes.text().catch(()=> '');
          resultBox.textContent = `Gagal memulai analisis: ${startRes.status} ${t || ''}`;
          return;
        }

        const { runId } = await startRes.json();
        resultBox.textContent = "Run dimulai. Menunggu hasil dari Apify...";
        pollStatus(runId);
      } catch (err) {
        resultBox.textContent = "Terjadi error saat memulai analisis: " + err.message;
      }
    }

    async function pollStatus(runId) {
      try {
        const res  = await fetch(CHECK_URL(runId));
        const data = await res.json();

        if (data.status && data.status !== 'SUCCEEDED') {
          resultBox.textContent = `Status: ${data.status}...`;
          setTimeout(() => pollStatus(runId), 5000);
        } else if (data.chart) {
          showBarChart(data); // <— ganti ke bar chart
          resultBox.textContent = "✅ Analisis selesai!\n" + JSON.stringify(data.chart, null, 2);
        } else {
          resultBox.textContent = '❌ Gagal mengambil hasil.';
        }
      } catch (err) {
        resultBox.textContent = "Error polling: " + err.message;
        setTimeout(() => pollStatus(runId), 5000);
      }
    }

    // --------- Chart (Bar seperti contoh gambar) ----------
    function showBarChart(json) {
      // label kita: Positive / Neutral / Negative (3 batang)
      const labels = Object.keys(json.chart);    // ['positive','neutral','negative']
      const values = Object.values(json.chart);  // [..counts..]

      const maxVal = Math.max(5, ...values);
      // naikkan batas atas agar grid cantik (kelipatan 2)
      const suggestedMax = Math.ceil((maxVal + 2) / 2) * 2;

      if (chart) chart.destroy();
      chart = new Chart(chartCtx, {
        type: 'bar',
        data: {
          labels: labels.map(cap), // kapitalisasi
          datasets: [{
            label: 'Jumlah Komentar',
            data: values,
            // warna mirip spektrum contoh (biru tua → biru muda)
            backgroundColor: ['#3b82f6', '#60a5fa', '#93c5fd'],
            borderColor:     ['#1e40af', '#1d4ed8', '#2563eb'],
            borderWidth: 1.5,
            borderRadius: 6,       // rounded bar
            barThickness: 42,      // tebal batang
            maxBarThickness: 48,
            categoryPercentage: 0.6,
            barPercentage: 0.9,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { enabled: true }
          },
          scales: {
            x: {
              grid: { display: false },
              ticks: { color: '#4b5563', font: { size: 12 } }
            },
            y: {
              beginAtZero: true,
              suggestedMax,
              ticks: {
                stepSize: 2,
                color: '#4b5563',
                font: { size: 12 }
              },
              grid: {
                drawBorder: false,
                color: '#e5e7eb',
                borderDash: [4,4]   // putus-putus seperti contoh
              }
            }
          },
          layout: { padding: { top: 8, right: 8, bottom: 8, left: 8 } },
          animation: { duration: 600 }
        }
      });
    }

    function cap(s){ return s.charAt(0).toUpperCase()+s.slice(1); }
  </script>
</body>
</html>
