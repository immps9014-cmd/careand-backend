# Care& Backend - Phase 0 + Phase 1

> AI 기반 어르신 돌봄 서비스 플랫폼 — Laravel 10 백엔드
> **Phase 0** (1주차): DB 스키마 + Eloquent 모델 + JWT 인증
> **Phase 1** (2주차): 핵심 비즈니스 컨트롤러 6개 + 외부 시스템 연동 5개

## 📦 산출물 구성 (Phase 0 + Phase 1)

| 영역 | 개수 | 설명 |
|---|---|---|
| Migrations | 32 | MariaDB 6개 도메인 스키마 |
| Eloquent Models | 32 | 관계 + 캐스팅 + 스코프 |
| Controllers | 7 | Auth, Senior, Caregiver, MatchRequest, CareSession, Payment, Settlement |
| FormRequests | 12 | 입력 검증 |
| API Resources | 8 | 응답 변환 |
| External Services | 5 | MOHW, NHIS, PG, Hometax, AI |
| Policies | 6 | 도메인별 접근 제어 |
| Jobs | 1 | GenerateMatchCandidatesJob |
| Tests | 1 | AuthTest (8 케이스) |
| **합계** | **117 PHP 파일** | |

## 🚀 설치 가이드

### 1. 사전 요구사항

```bash
# CentOS 7.x 기준
- PHP 8.1+ (8.2 권장)
- Composer 2.x
- MariaDB 10.5+
- Redis 6.x
- AWS S3 (또는 호환 스토리지)
```

### 2. 초기 설치

```bash
unzip 케어앤_백엔드_2주차_v0.2.zip
cd careand-backend

composer install
php artisan jwt:secret

cp .env.example .env
php artisan key:generate

# DB 생성
mysql -u root -p -e "CREATE DATABASE careand CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'careand'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD';"
mysql -u root -p -e "GRANT ALL ON careand.* TO 'careand'@'localhost';"

# .env 수정 후
php artisan migrate --seed

# 서버 실행
php artisan serve
```

### 3. 동작 확인

```bash
# Health Check
curl http://localhost:8000/api/health

# 회원가입 → 로그인 → 어르신 등록 → 매칭 요청 흐름
# (Postman Collection 사용 권장 - 02_API_데이터_자산/careand-api.postman_collection.json)
```

## 📡 API 엔드포인트 (Phase 0 + Phase 1)

### Phase 0 (Auth)

| Method | Path | 설명 | 인증 |
|---|---|---|---|
| GET | `/api/health` | Health check | - |
| POST | `/api/v1/auth/otp/send` | OTP 발송 | - |
| POST | `/api/v1/auth/otp/verify` | OTP 검증 | - |
| POST | `/api/v1/auth/signup` | 회원가입 | - |
| POST | `/api/v1/auth/login` | 로그인 | - |
| POST | `/api/v1/auth/refresh` | 토큰 갱신 | - |
| POST | `/api/v1/auth/logout` | 로그아웃 | JWT |
| GET | `/api/v1/auth/me` | 내 정보 | JWT |

### Phase 1 (Business Logic)

#### 어르신 (Senior) - 보호자 전용
| Method | Path | 설명 |
|---|---|---|
| GET | `/api/v1/seniors` | 내 어르신 목록 |
| POST | `/api/v1/seniors` | 어르신 등록 (NHIS 자동 조회) |
| GET | `/api/v1/seniors/{id}` | 상세 |
| PATCH | `/api/v1/seniors/{id}` | 수정 |
| DELETE | `/api/v1/seniors/{id}` | 삭제 |
| POST | `/api/v1/seniors/{id}/refresh-voucher` | 바우처 NHIS 재조회 |

#### 인력 (Caregiver)
| Method | Path | 설명 |
|---|---|---|
| GET | `/api/v1/caregivers/me` | 내 정보 |
| POST | `/api/v1/caregivers/register` | 등록 (보건복지부 자격 진위확인) |
| PATCH | `/api/v1/caregivers/me/profile` | 프로필 수정 |
| GET | `/api/v1/caregivers/{id}` | 상세 (보호자가 후보 클릭 시) |
| POST | `/api/v1/caregivers/me/leave` | 휴직 |
| POST | `/api/v1/caregivers/me/return` | 복귀 |

#### 매칭 (Matching)
| Method | Path | 설명 |
|---|---|---|
| GET | `/api/v1/matching/requests` | 매칭 요청 목록 |
| POST | `/api/v1/matching/requests` | AI 매칭 요청 |
| GET | `/api/v1/matching/requests/{id}/candidates` | AI 추천 후보 (폴링) |
| POST | `/api/v1/matching/requests/{id}/select` | 후보 선택 (보호자) |
| POST | `/api/v1/matching/candidates/{id}/accept` | 매칭 수락 (인력) |
| POST | `/api/v1/matching/candidates/{id}/reject` | 매칭 거절 (인력) |

#### 케어 세션 (Care Session)
| Method | Path | 설명 |
|---|---|---|
| GET | `/api/v1/care-sessions/{id}` | 세션 상세 |
| POST | `/api/v1/care-sessions/{id}/checkin` | GPS 체크인 (자택 200m 검증) |
| POST | `/api/v1/care-sessions/{id}/checkout` | 체크아웃 |
| POST | `/api/v1/care-sessions/{id}/activities` | 활동 기록 |
| POST | `/api/v1/care-sessions/{id}/voice-log` | 음성 일지 업로드 (STT/LLM) |
| POST | `/api/v1/care-sessions/{id}/photos` | 사진 업로드 |
| GET | `/api/v1/care-sessions/{id}/ai-summary` | AI 요약 조회 |

#### 결제 (Payment) - 보호자
| Method | Path | 설명 |
|---|---|---|
| GET | `/api/v1/payments` | 결제 내역 |
| POST | `/api/v1/payments/calculate` | 본인부담금 자동 분리 |
| POST | `/api/v1/payments/approve` | PG 승인 (Idempotency-Key) |
| POST | `/api/v1/payments/{id}/cancel` | 결제 취소 + 바우처 환원 |
| POST | `/api/webhooks/pg` | PG 웹훅 |

#### 정산 (Settlement)
| Method | Path | 설명 |
|---|---|---|
| GET | `/api/v1/settlements` | 정산 이력 (인력) |
| GET | `/api/v1/settlements/{id}` | 상세 |
| POST | `/api/v1/settlements/preview` | 이번 주 미리보기 |
| POST | `/api/v1/admin/settlements/run` | 주간 정산 일괄 (관리자) |
| POST | `/api/v1/admin/settlements/file-tax` | 홈택스 일괄 신고 |
| POST | `/api/v1/admin/settlements/{id}/confirm` | 정산 확정 (송금) |

## 🔌 외부 시스템 연동 (5종)

### 1. 보건복지부 자격관리시스템 (`MohwService`)
- 요양보호사 자격증 진위 확인
- 인력 등록 시 자동 호출 → status=pending → verified

### 2. 국민건강보험공단 (`NhisService`)
- 장기요양 등급/한도/사용액 조회 (1일 캐시)
- 본인부담금 자동 분리 (15:85)
- 등급별 한도: 1등급 1,985,200원 ~ 5등급 1,151,600원

### 3. PG사 (`PgService`)
- KG이니시스 / 토스 결제
- approve / cancel / verifyWebhookSignature (HMAC-SHA256)

### 4. 국세청 홈택스 (`HometaxService`)
- 사업소득 원천징수 일괄 신고 (3.3%)
- 매주 정산 후 자동 신고

### 5. AI 마이크로서비스 (`AiService`)
- 매칭 추천 (top-k 0.85+)
- 음성 STT (Whisper-ko)
- LLM 일지 요약 (보호자/의료진 두 톤)
- 이상징후 점수 산정
- 챗봇 RAG
- 수요 예측

> **로컬/테스트 환경에서는 모든 외부 서비스가 mock 응답을 반환합니다.**

## 🔒 핵심 비즈니스 로직

### GPS 체크인 (Haversine 거리 계산)
```php
// 자택 200m 이내 검증
private const CHECKIN_RADIUS_METERS = 200;
$distance = $this->calculateDistance($lat, $lng, $home_lat, $home_lng);
$isValid = $distance <= self::CHECKIN_RADIUS_METERS;
```

### 본인부담금 자동 분리
```php
// 15:85 (본인:장기요양) - copay_rate 기준
$split = $nhisService->calculateSplit($total, $careGrade, $copayRate);
// → ['self_pay' => N, 'ltc_pay' => N]
```

### 음성 일지 처리 파이프라인
```
1. 음성 업로드 (S3) → VoiceLog (status=uploaded)
2. AiService::transcribe() → STT (status=transcribed)
3. AiService::summarizeCareLog() → 보호자/의료진 두 버전 (status=summarized)
4. AiLogSummary 저장 → 보호자에게 FCM 푸시
```

### 주간 정산 흐름
```
1. 매주 월요일 cron → SettlementController::runWeekly()
2. 완료 세션 집계 → gross_amount 계산
3. 야간 할증 30% 자동 적용
4. 3.3% 원천징수 차감 → net_amount
5. 관리자 confirm → 송금 큐
6. 홈택스 일괄 신고 → filing_no 기록
```

## 🧪 테스트 실행

```bash
php artisan test
# AuthTest: 8개 테스트 케이스 모두 통과 예상
```

## 📁 디렉토리 구조

```
careand-backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   ├── AuthController.php           # Phase 0
│   │   │   ├── SeniorController.php         # Phase 1
│   │   │   ├── CaregiverController.php
│   │   │   ├── MatchRequestController.php
│   │   │   ├── CareSessionController.php
│   │   │   ├── PaymentController.php
│   │   │   └── SettlementController.php
│   │   ├── Requests/                        # 12개 FormRequest
│   │   ├── Resources/                       # 8개 API Resource
│   │   └── Middleware/CheckRole.php
│   ├── Models/                              # 32개 Eloquent
│   ├── Services/
│   │   ├── OtpService.php
│   │   └── External/
│   │       ├── MohwService.php
│   │       ├── NhisService.php
│   │       ├── PgService.php
│   │       ├── HometaxService.php
│   │       └── AiService.php
│   ├── Policies/                            # 6개 Policy
│   ├── Jobs/
│   │   └── GenerateMatchCandidatesJob.php
│   └── Providers/
│       ├── AppServiceProvider.php
│       └── AuthServiceProvider.php
├── config/
├── database/
│   ├── migrations/                          # 32개
│   └── seeders/
│       └── DatabaseSeeder.php
├── routes/
│   └── api.php
├── tests/Feature/AuthTest.php
├── .env.example
├── composer.json
└── README.md
```

## 🎯 다음 단계 (Phase 2 - 3주차)

1. **Health Monitoring** - VitalRecordController + AnomalyAlertController
2. **Chatbot** - ChatbotController (RAG)
3. **Admin Web Backend** - 관리자 대시보드 API
4. **Notifications** - FCM 푸시 통합
5. **Python AI Service** - FastAPI 5종 모델 골격

---

© 2026 Care& Inc. Phase 0 + Phase 1 완성 — 2026년 5월
