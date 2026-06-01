<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Rotaları
|--------------------------------------------------------------------------
|
| Geliştirmede `php artisan serve` + Vite dev server (`npm run dev`)
| ayrı çalıştığı için bu dosya esasen masaüstü/portable build için var.
|
| Portable build'de Laravel hem API'yi hem de React SPA'yı (build çıktısı
| public/'a kopyalanır) tek origin'den serve eder. Aşağıdaki fallback,
| BrowserRouter URL'lerinin (örn. /dashboard, /student/login) F5 ile
| yenilendiğinde 404 yememesi için index.html'i döndürür.
*/

Route::get('/', function () {
    $index = public_path('index.html');
    if (file_exists($index)) {
        return response()->file($index);
    }
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| GEÇİCİ — Mega demo seeder tetikleyici (sunum verisi)
|--------------------------------------------------------------------------
| Render free tier'da Shell yok; seeder'ı bu gizli URL ile çalıştırıyoruz.
| Kullanım:  /__seed-mega?key=13d1da1af80be8d3549a90d619e403f9
| GÜVENLİK: Seed bittikten sonra bu route SİLİNECEK. Anahtar olmadan 404.
*/
Route::get('/__seed-mega', function (\Illuminate\Http\Request $request) {
    if ($request->query('key') !== '13d1da1af80be8d3549a90d619e403f9') {
        abort(404);
    }

    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $started = microtime(true);
    try {
        \Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => 'MegaDemoSeeder',
            '--force' => true,
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $elapsed = round(microtime(true) - $started, 1);

        return response(
            "MEGA SEED TAMAMLANDI ({$elapsed}s)\n"
            ."============================================\n"
            .$output
            ."\nNOT: Bu route'u guvenlik icin silmeyi unutma.\n",
            200
        )->header('Content-Type', 'text/plain; charset=utf-8');
    } catch (\Throwable $e) {
        return response(
            "SEED HATASI:\n".$e->getMessage()."\n\n".$e->getTraceAsString(),
            500
        )->header('Content-Type', 'text/plain; charset=utf-8');
    }
});

// SPA fallback — API ve statik asset olmayan tüm GET'leri index.html'e yönlendir.
// /api/* zaten api.php'de tanımlı ve burayı tetiklemez (Route::fallback API'leri yakalamaz).
Route::fallback(function () {
    $index = public_path('index.html');
    if (file_exists($index)) {
        return response()->file($index);
    }
    abort(404);
});
