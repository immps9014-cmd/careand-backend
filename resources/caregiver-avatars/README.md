# 돌봄전문가 캐릭터(사진 대신, 2026-10-11)

- 원본: `c1.svg` ~ `c8.svg` (512×512) — `gen.py` 로 생성(`python3 gen.py <출력폴더>`).
- 배포본: `public/caregiver-avatars/{key}.jpg` — `render.js`(headless Chromium)로 PNG 를 만든 뒤 GD 로 JPEG(품질 90).
- 고르면 서버가 이 JPEG 를 `storage/app/caregiver-photos/{id}.jpg` 로 복사하고 `caregivers.avatar_key` 를 남긴다 — 사진을 보여 주는 화면은 그대로 동작.
- 키·이름표는 `App\Http\Controllers\Api\V1\CaregiverProfileExtraController::AVATARS` 와 앱 `lib/partner/avatars.dart` 두 곳.
