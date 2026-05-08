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

// SPA fallback — API ve statik asset olmayan tüm GET'leri index.html'e yönlendir.
// /api/* zaten api.php'de tanımlı ve burayı tetiklemez (Route::fallback API'leri yakalamaz).
Route::fallback(function () {
    $index = public_path('index.html');
    if (file_exists($index)) {
        return response()->file($index);
    }
    abort(404);
});
