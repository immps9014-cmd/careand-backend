# 케어네이션 벤치마킹 · Care& 비교 · 가격 레이어(역경매) 구현

> 작성일 2026-06-27 · 대상: Care& 통합돌봄 플랫폼
> 전체 설계 원문은 별도 보관(scratchpad `careand-price-layer-impl.md`).

---

## 1. 케어네이션(Carenation) 분석

| 항목 | 내용 |
|---|---|
| 운영사 | (주)케어네이션 (구 HMC네트웍스), 대표 서대건 |
| 규모 | 누적 투자 약 305억, 6년 연속 소비자만족도 1위, 회원 50만+, 앱 점유율 91.8% |
| 서비스 | 병원간병·방문요양·산후돌봄·병원동행·가사돌봄 + 요양센터 B2B SaaS(homecare) + DATA LAB |
| 주요 투자사 | 삼성벤처투자, 하나벤처스, HB인베스트먼트, LSK인베스트먼트, 신용보증기금 |

### 핵심 해자 3가지
1. **적정 간병비 산출 알고리즘**(업계 최초) — 진단명·상황·빅데이터 기반 적정가 가이드
2. **역경매 입찰** — 케어메이트가 경력·후기·난이도 기반으로 보수를 직접 제시, 보호자가 비교 선택
3. **결제/신뢰 인프라** — 가상 앱 통장(정산), 배상책임보험 의무가입, 카드 할부

> 약점/리스크: 요금·수수료 비공개(투명성), 내부 만족도 낮음(잡플래닛 2.4), 역경매發 단가 경쟁 가능성.

---

## 2. Care& ↔ 케어네이션 기능 갭

> ✅ 제공 · 🟡 부분/스텁 · ❌ 미구현

### 2.1 서비스·매칭
| 기능 | 케어네이션 | Care& (가격레이어 작업 전) | Care& (작업 후) |
|---|---|---|---|
| 서비스 도메인 | 6종 + B2B/데이터 | 시니어/간병/가사/방문목욕 등 | 동일(+동성매칭 정교) |
| 매칭 방식 | AI + 역경매 | 규칙기반 rule-v3 | 규칙기반 + **역경매 + 가성비 재랭킹** |
| 가격 산출 | ✅ 적정 간병비 알고리즘 | ❌ 카테고리 정액(base_rate) | ✅ **지역·시간대·난이도 보정 + 과거 합의가 베이지안 블렌딩** |
| 역경매 입찰 | ✅ | ❌ | ✅ **입찰=확약, 보호자 선택 즉시 확정 + 자동입찰** |
| 가성비 추천 | (비공개) | ❌ | ✅ **AI 점수에 가성비 소프트 가산** |
| 성별·동성 매칭 | (비공개) | ✅ BATH 동성 하드/소프트 권장 | ✅ (Care& 우위) |

### 2.2 결제·인프라 (남은 갭)
| 기능 | 케어네이션 | Care& |
|---|---|---|
| PG 실결제 | ✅ | 🟡 스텁(EXTERNAL_STUB) |
| 정산(가상계좌) | ✅ | 🟡 정산 플로우 O, 가상계좌 ❌ |
| 배상책임보험 | ✅ 의무 | ❌ |
| 네이티브 앱 | ✅ 양면 분리 | ❌ 웹 대체(/app) |

> Care&가 앞선 부분: 동성/성별 매칭 정교함, 매칭 reason 투명성, 카테고리 세분화, 가격 산출 로직 투명성.

---

## 3. 가격 레이어(역경매) 구현 — 3 Phase 전부 배포 완료

케어네이션 갭 1순위(가격 레이어)를 Care&에 이식. 합의가는 `matches.hourly_rate`로 귀결.

### Phase 1 — 적정 간병비 산출 (`ea96dd6`)
- **`pricing_rules`** 테이블: 카테고리(×지역)별 `region_index / night_mult(1.3) / holiday_mult(1.5) / emergency_mult(1.2) / acuity_addons / min_hourly`. 활성 카테고리에 전국 기본행 시드.
- **`PricingService::estimate()`**: `base × 배수 + 난이도가산`에 과거 `matches.hourly_rate` 백분위(p25/p50/p75)를 **베이지안 블렌딩**(표본 적으면 룰 우세) → `[floor, suggested, ceil]`.
- `match_requests.price_estimate`(스냅샷)·`budget_hourly` 추가. `GET /v1/matching/pricing/estimate`(생성 전 미리보기).
- ⚠️ 함정: Laravel `datetime` 캐스트가 미저장 모델의 오프셋을 버려 야간/주간 판정 반전 → `store()`처럼 `->utc()` 선정규화로 해결.

### Phase 2 — 역경매 입찰 (`a505106`)
- `match_candidates.bid_*`(입찰가/메모/상태/시각), `caregivers.default_rate/auto_bid`.
- **`BiddingService`**: 자동입찰(권장밴드 클램프), 가드레일(최저시급 하한·밴드초과 경고).
- 후보 생성 시 `bid_status='invited'` → 자동입찰 적용.
- `POST /v1/matching/candidates/{id}/bid`: 입찰 제시/수정(최저시급 미만 **422**, 밴드 밖 `warn_out_of_band`).
- **입찰=확약**: `selectCandidate`가 입찰 후보 선택 시 즉시 `confirmMatch()`(합의가=입찰가). 입찰 없으면 기존 2단계 유지 — `config services.pricing.auction_enabled`.

### Phase 3 — 가성비 재랭킹 (ai `74170b9` / backend `fd83dad`)
- AI **`POST /matching/value-rank`**: `value = ai_score + W_PRICE × clamp((suggested − bid)/suggested, −0.10, +0.15)`, `W_PRICE=0.10`. 적합도 비슷한 후보 간 **가격 타이브레이크**(적합도 격차 크면 순위 유지 — 소프트).
- 백엔드 `candidates()`가 입찰 있는 미확정 요청에 한해 `applyValueRank`로 재정렬, AI 실패 시 rank 유지(graceful).
- reason: `가성비 좋음`(vfm≥0.05) / `권장가 대비 높음`(≤−0.05).

### 프론트(member-web) 연동 (member `9471ebf` / `48eb1e2`)
| 화면 | 추가 |
|---|---|
| 보호자 `request/new` | 적정 간병비 실시간(권장 시급·범위·예상 총액) + 희망 상한 입력 |
| 보호자 `request/[id]` | 후보별 제시 시급·권장대비 뱃지(저렴/적정/높음)·메모, 정렬(추천/낮은입찰가/평점), **가성비 뱃지**, 입찰후보 "즉시 확정" |
| 인력 `home` | 권장 시급 + 입찰 입력/수정, 입찰 뱃지, 범위밖 경고 |
| 인력 `mypage` | 표준 희망 시급(default_rate)·자동입찰(auto_bid) 설정 |

---

## 4. 역경매 전체 플로우

```
보호자 요청 (권장가 표시 · 희망 상한 입력)
   └─> AI 후보 shortlist (bid_status='invited', 자동입찰 적용)
        └─> 돌봄전문가 입찰 (가드레일: 최저시급 하한 / 밴드 경고)
             └─> 보호자 조회 (가성비 재랭킹된 추천순 · 입찰가 비교)
                  └─> 후보 선택 = 즉시 확정 (matches.hourly_rate = 입찰가)
```

가드레일: 최저시급 미만 **422 차단** · 권장밴드 밖 **경고**(차단 X) · 자동입찰 밴드 **클램프**.

---

## 5. 검증 & 운영 메모
- 전 Phase E2E 검증 완료(라이브 HTTP, 검증용 요청 95~99 정리됨).
- 토글: `services.pricing.auction_enabled`(기본 true), `AI_W_PRICE`(기본 0.10), `PRICING_MIN_HOURLY`(10030), `PRICING_HOLIDAYS`.
- 배포: backend `migrate --force && route:cache && config:cache && reload php-fpm`, AI `systemctl restart careand-ai`, member `npm run build && restart careand-member-web`.

## 6. 향후 과제
- 지역별 `pricing_rules` 시드(현재 전국 기본만 — region_index=1.0 고정).
- 결제(PG 실연동)·가상계좌 정산·배상책임보험.
- ML 매칭(ALS/KoSimCSE) — 박스 제약으로 보류.
- 네이티브 앱.
