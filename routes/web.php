<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', fn () => file_get_contents(public_path('portal.html')));

// Android 앱 링크 확인(2026-10-07) — 구글 로그인 뒤 /app/auth/app/{guardian|caregiver}/... 를 앱이 바로 열게.
// 지문은 스토어 앱 서명 키 SHA-256(Play Console 「앱 무결성」) — 없으면 빈 목록이라 확인은 실패하고,
// 그때는 회원웹 대체 화면의 「앱으로 돌아가기」 버튼으로 복귀한다.
Route::get('/.well-known/assetlinks.json', function () {
    $out = [];
    foreach (config('services.android_app_links', []) as $pkg => $fps) {
        $fps = array_values(array_filter(array_map('trim', explode(',', (string) $fps))));
        if ($fps) {
            $out[] = ['relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => ['namespace' => 'android_app', 'package_name' => $pkg, 'sha256_cert_fingerprints' => $fps]];
        }
    }
    return response()->json($out)->header('Cache-Control', 'public, max-age=3600');
});
