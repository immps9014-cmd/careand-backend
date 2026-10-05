<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\CaregiverProfileExtras;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * 인력 비상연락처·사진·희망사항(2026-10-05, 요구사항분석 PDF 「인력 회원가입」).
 * 본인: GET me/extras · PUT me/emergency-contact · PUT me/work-preferences · POST/DELETE me/photo.
 * 사진 보기: GET caregivers/{id}/photo — 서명 링크(signed:relative)로만. 링크는 로그인 회원 응답에만 실린다.
 */
class CaregiverProfileExtraController extends Controller
{
    private const PHOTO_MAX_PX = 600;

    /** GET /v1/caregivers/me/extras */
    public function show(Request $request): JsonResponse
    {
        $cg = $this->mine($request);

        return response()->json(['success' => true, 'data' => CaregiverProfileExtras::forOwnerOrAdmin($cg->id) + [
            'labels' => ['times' => CaregiverProfileExtras::TIMES, 'relations' => CaregiverProfileExtras::RELATIONS],
        ]]);
    }

    /** PUT /v1/caregivers/me/emergency-contact {name, relation, phone} */
    public function updateEmergency(Request $request): JsonResponse
    {
        $cg = $this->mine($request);
        $v = $request->validate(CaregiverProfileExtras::emergencyRules(), ['phone.regex' => '전화번호 형식을 확인해 주세요. 예: 010-1234-5678']);
        if (preg_replace('/\D/', '', $v['phone']) === preg_replace('/\D/', '', (string) $request->user()->phone)) {
            return response()->json(['success' => false, 'error_code' => 'SAME_AS_ME', 'message' => '본인 번호가 아닌 다른 분의 번호를 적어 주세요.'], 422);
        }
        DB::table('caregivers')->where('id', $cg->id)->update(['emergency_contact' => CaregiverProfileExtras::encryptEmergency($v), 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => '비상연락처를 저장했어요.', 'data' => CaregiverProfileExtras::forOwnerOrAdmin($cg->id)]);
    }

    /** PUT /v1/caregivers/me/work-preferences {days[], times[], regions, note} */
    public function updatePreferences(Request $request): JsonResponse
    {
        $cg = $this->mine($request);
        $v = $request->validate(CaregiverProfileExtras::preferenceRules());
        DB::table('caregivers')->where('id', $cg->id)->update([
            'work_preferences' => json_encode(CaregiverProfileExtras::normalizePreferences($v), JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => '희망사항을 저장했어요.', 'data' => CaregiverProfileExtras::forOwnerOrAdmin($cg->id)]);
    }

    /** POST /v1/caregivers/me/photo (multipart photo) — 600px 안으로 줄여 JPEG 로 저장 */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $cg = $this->mine($request);
        $request->validate(['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:15360']],
            ['photo.mimes' => 'JPG·PNG·WEBP·HEIC 사진만 올릴 수 있어요.', 'photo.max' => '사진은 15MB까지 올릴 수 있어요.']);
        $jpeg = self::toJpeg($request->file('photo')->getRealPath());
        if ($jpeg === null) {
            return response()->json(['success' => false, 'error_code' => 'BAD_IMAGE', 'message' => '사진을 읽지 못했어요. 다른 사진으로 해 주세요.'], 422);
        }
        $path = "caregiver-photos/{$cg->id}.jpg";
        Storage::disk('local')->put($path, $jpeg);
        DB::table('caregivers')->where('id', $cg->id)->update(['photo_path' => $path, 'photo_updated_at' => now(), 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => '사진을 바꿨어요.', 'data' => CaregiverProfileExtras::forOwnerOrAdmin($cg->id)]);
    }

    /** DELETE /v1/caregivers/me/photo */
    public function deletePhoto(Request $request): JsonResponse
    {
        $cg = $this->mine($request);
        if ($cg->photo_path) {
            Storage::disk('local')->delete($cg->photo_path);
        }
        DB::table('caregivers')->where('id', $cg->id)->update(['photo_path' => null, 'photo_updated_at' => null, 'updated_at' => now()]);

        return response()->json(['success' => true, 'message' => '사진을 지웠어요.', 'data' => CaregiverProfileExtras::forOwnerOrAdmin($cg->id)]);
    }

    /** GET /v1/caregivers/{id}/photo?v=&expires=&signature= — 서명 링크로만 */
    public function photo(int $id): Response
    {
        $path = DB::table('caregivers')->where('id', $id)->whereNull('deleted_at')->value('photo_path');
        abort_if(!$path || !Storage::disk('local')->exists($path), 404);

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function mine(Request $request): object
    {
        $cg = DB::table('caregivers')->where('user_id', $request->user()->id)->whereNull('deleted_at')->first(['id', 'photo_path']);
        abort_if(!$cg, 404, '인력 등록이 필요합니다.');

        return $cg;
    }

    /** 아무 사진 → 방향 바로잡고 긴 변 600px 안 JPEG(메타데이터 제거). 실패하면 null */
    private static function toJpeg(string $file): ?string
    {
        try {
            if (class_exists(\Imagick::class)) {
                $im = new \Imagick($file);
                $im->setIteratorIndex(0);
                $im->autoOrient();
                $im->thumbnailImage(self::PHOTO_MAX_PX, self::PHOTO_MAX_PX, true);
                $im->stripImage();
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(85);
                $out = $im->getImageBlob();
                $im->clear();

                return $out ?: null;
            }
            $src = @imagecreatefromstring((string) file_get_contents($file));
            if (!$src) {
                return null;
            }
            [$w, $h] = [imagesx($src), imagesy($src)];
            $scale = min(1, self::PHOTO_MAX_PX / max($w, $h));
            $dst = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            ob_start();
            imagejpeg($dst, null, 85);

            return ob_get_clean() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
