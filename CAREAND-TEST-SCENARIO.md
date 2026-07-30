# CareAnd 테스트 시나리오 — 온톨로지 기반 특기 매칭(`ontology_match`) + STT hotwords

대상: `careand-ai-service` `/ai/match/recommend`(케이스1-6), `/ai/voice/transcribe`(케이스7-8).
배포 커밋 `835ef03`→`1a26127`, 2026-07-30 배포 완료(`careand-deploy ai`, `careand-ai` systemd, health OK).

의존: Fuseki `caren` 데이터셋 (`http://localhost:3030/caren`, `moai-fuseki` 컨테이너 공유,
`careand-ai-service/ontology/care-domain.ttl` 적재됨, 271 트리플 — 질병/특기/도메인 + 케어 관찰
용어(CareTerm) 36개).

## 사전조건

- `systemctl is-active careand-ai` → `active`
- `curl http://127.0.0.1:8001/health` → `"status":"ok"`
- `curl http://localhost:3030/$/datasets` → `/moai`, `/caren` 둘 다 존재
- `AI_SERVICE_TOKEN`은 `careand-ai-service/.env`에서 확인

## 테스트 케이스

| # | 시나리오 | 입력 | 기대 결과 | 실측(2026-07-30) |
|---|---|---|---|---|
| 1 | 온톨로지 근접매치 | 대상자 질병=`["치매"]`, 후보 특기=`["인지자극"]`(정확문자열 불일치) | `reasons`에 `"온톨로지 근접 특기: 인지자극"` 포함, `_matched`는 빈 집합 | ✅ `score=0.567`, reasons에 포함 확인 |
| 2 | 정확 문자열 일치(회귀) | 질병=`["치매"]`, 특기=`["치매"]` | `reasons`에 `"특기 일치: 치매"`만(온톨로지 문구는 안 붙음 — `_onto_matched`가 exact match 제외) | ✅ `['특기 일치: 치매', '거리 0.0km', '평점 4.00/5 (3건)']` |
| 3 | 온톨로지 미보유 질병 폴백 | 질병=`["희귀질환X"]`(TTL에 없는 값), 특기=`["인지자극"]` | 에러 없이 정상 점수 산출, 온톨로지 근거 문구 없음(`ontology_match`가 조용히 `specialty_score`로 대체) | ✅ `score=0.508`, reasons에 온톨로지/특기 문구 없음 |
| 4 | Fuseki 장애 시 서비스 가용성 | `moai-fuseki` 컨테이너 중지 상태에서 신규(미캐시) 질병으로 요청, 복구 후 재조회 | 다운 중: 500/타임아웃 없이 200 응답 + 온톨로지 근거 없이 폴백(~2.2초, 타임아웃 반영). 복구 후: 다음 호출부터 바로 온톨로지 근거 재개 | ✅ 실행함(2026-07-30) — **버그 발견**: 최초 구현(`lru_cache`)은 장애 중 실패까지 캐시해서 Fuseki 복구 후에도 프로세스 재시작 전까진 계속 폴백 상태로 굳어있었음(`뇌졸중`으로 재현). `ontology.py` 수정(성공한 조회만 캐시, 실패는 캐시 안 함) 후 재배포(`c973946`)해 복구 즉시 정상화 확인 |
| 5 | 캐시 무효화 | TTL에 신규 특기(`정서지원`, 치매 requiresSpecialty) 추가 → 재적재 → `reset_cache()` 없이 재조회 vs 호출 후 재조회 | `reset_cache()` 전엔 재적재해도 캐시된 구 결과(`인지자극,치매케어`) 유지, 호출 후엔 신규(`정서지원` 포함) 즉시 반영 | ✅ 실행함(2026-07-30) — 정확히 예상대로 동작: R1=R2=`{인지자극,치매케어}`(재적재해도 불변), reset_cache() 후 R3=`{인지자극,정서지원,치매케어}`. `caren` 데이터셋 162→165 트리플(신규 개체+라벨+관계 3건) |
| 6 | 배포 안전성(feature 확장 회귀) | 기존 학습모델(`models/l2r/meta.json`, feature 6개) 로드 시도 | `feature_names` 불일치로 게이트오프 → `scoring_method: "rule-v3"` 유지, 크래시 없음 | ✅ 배포 후 `scoring_method: "rule-v3"` 응답으로 확인(모델 미사용, 룰 폴백 정상) |
| 7 | STT hotwords 연동 | `/ai/voice/transcribe`로 합성 wav(1.5초 무음성 톤) 전송 | 온톨로지 어휘 36개(173자)가 `hotwords`로 whisper에 전달, 200 OK, 크래시 없음 | ✅ 어휘 수 36, hotwords 문자열 173자 확인. 라이브 호출 `HTTP=200`, `model:"faster-whisper-small-ct2-int8"` |
| 8 | Fuseki 장애 시 STT 가용성 | `moai-fuseki` 중지 + `careand-ai` 재시작(콜드 캐시) 상태에서 STT·매칭 동시 요청, 복구 후 재시작 없이 재확인 | `care_term_vocabulary()`가 빈 집합 → `hotwords=None`으로 조용히 폴백, STT/매칭 둘 다 200. 복구 후엔 재시작 없이 바로 정상 어휘(36개) 재개 | ✅ 실행함(2026-07-30) — STT `HTTP=200`(2.4초, Fuseki 타임아웃 1.5초 반영), 매칭도 `HTTP=200`(온톨로지 근거 없이 거리/평점만). 복구 후 프로세스 재시작 없이 바로 STT 200 + 새 프로세스로 어휘 36개 재확인(케이스4 수정 덕에 실패 캐시 안 남음) |

## 실행 방법

**케이스 1–3, 6** (이미 실행·확인됨, 재현용):
```bash
TOKEN=$(grep '^AI_SERVICE_TOKEN=' /root/caren/careand-ai-service/.env | cut -d= -f2-)
curl -s -X POST http://127.0.0.1:8001/ai/match/recommend \
  -H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json" \
  -d '{
    "request_id": 999001,
    "senior": {"diseases": ["치매"], "lat": 37.5, "lng": 127.0},
    "caregivers": [
      {"id": 1, "specialties": ["인지자극"], "rating_avg": 4.5, "rating_count": 10, "completed_sessions": 80, "lat": 37.5, "lng": 127.0, "gender": "F"}
    ],
    "service_domain": "senior"
  }' | python3 -m json.tool
```

**케이스 4** (실행 완료, 재현용 — `moai-fuseki` 중지는 moai 테넌트에도 영향을 주므로 재실행 시 사전 공지 권장):
```bash
docker stop moai-fuseki
# 캐시 안 된(=이전에 조회한 적 없는) 질병으로 요청 → 200 + 온톨로지 근거 없이 폴백 확인
docker start moai-fuseki
sleep 3
# 같은 질병 재조회 → 온톨로지 근거 다시 붙는지 확인(수정 전엔 프로세스 재시작 전까지 안 붙었음)
```

**케이스 5** (실행 완료, 재현용 — 한 프로세스 안에서 재적재 스크립트를 subprocess로 호출해 순서 보장):
```bash
cd /root/caren/careand-ai-service
./venv/bin/python3 -c "
import subprocess, ontology
r1 = ontology.related_specialty_labels(frozenset(['치매']))       # 재적재 전 → 캐시됨
subprocess.run(['./ontology/setup-caren-dataset.sh'])              # TTL 재적재(별도 프로세스)
r2 = ontology.related_specialty_labels(frozenset(['치매']))       # reset_cache() 전 → 캐시된 r1과 동일해야 정상
ontology.reset_cache()
r3 = ontology.related_specialty_labels(frozenset(['치매']))       # 캐시 무효화 후 → 신규 데이터 반영
print(r1, r2 == r1, r3)
"
```

**케이스 7** (실행 완료, 재현용 — 스모크용 wav는 `wave` 표준 라이브러리로 즉석 생성 가능, 무음성이라 STT 결과는 빈 문자열이 정상):
```bash
cd /root/caren/careand-ai-service
./venv/bin/python3 -c "
import wave, struct, math
path = '/tmp/smoke.wav'
sr = 16000; n = int(sr*1.5)
with wave.open(path, 'w') as w:
    w.setnchannels(1); w.setsampwidth(2); w.setframerate(sr)
    w.writeframes(b''.join(struct.pack('<h', int(3000*math.sin(2*math.pi*440*t/sr))) for t in range(n)))
"
TOKEN=$(grep '^AI_SERVICE_TOKEN=' .env | cut -d= -f2-)
curl -s -X POST http://127.0.0.1:8001/ai/voice/transcribe \
  -H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json" \
  -d '{"audio_url": "/tmp/smoke.wav"}' -w '\nHTTP=%{http_code}\n'
```

**케이스 8** (실행 완료, 재현용 — 케이스4처럼 `moai-fuseki` 중지가 필요하므로 재실행 시 사전 공지 권장):
```bash
docker stop moai-fuseki
systemctl restart careand-ai   # 콜드 캐시 상태로 만들어 실제 장애 시나리오에 더 가깝게
# 케이스7의 curl(STT) + 케이스1의 curl(매칭) 재실행 → 둘 다 200, 온톨로지 근거만 빠짐
docker start moai-fuseki
# 재시작 없이 바로 재확인 → 실패를 캐시하지 않으므로 다음 호출부터 정상 어휘/온톨로지 근거 복원
```

## 남은 작업

- 8개 케이스 전부 실행 완료
- `train_l2r.py` 재학습 전이라 `ontology_match`는 아직 `rule-v3` 점수·랭킹에는 영향 없음(reasons 문구로만 노출) — 표본이 쌓여 L2R이 활성화되면 실제 랭킹에도 반영됨
- STT hotwords는 환자별 맞춤이 아니라 전체 어휘 36개를 매 호출 고정 전달(TranscribeRequest에 환자 컨텍스트 없음) — associatedTerm 관계는 이미 있어 나중에 확장 가능

## 발견/수정 이력

- 2026-07-30 케이스4 실행 중 캐시 버그 발견 → `ontology.py` 수정(커밋 `c973946`) → 재배포 → 재검증 완료
- 2026-07-30 케이스5 실행 중 `care-domain.ttl`에 실제 콘텐츠 추가(치매 requiresSpecialty 정서지원) → `setup-caren-dataset.sh`로 재적재, 캐시 무효화 동작 검증에 재사용
- 2026-07-30 `care-domain.ttl`에 `CareTerm`(건강/정신/생활상태) 36개 어휘 추가, `main.py`가 whisper `hotwords`로 연동(커밋 `1a26127`) → 케이스7로 검증
