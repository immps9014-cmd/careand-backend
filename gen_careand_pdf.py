# -*- coding: utf-8 -*-
"""Care& 플랫폼 시스템 정리 PDF 생성 (reportlab + NanumGothic)."""
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_LEFT, TA_CENTER
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfbase import pdfmetrics
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table,
                                TableStyle, KeepTogether)
from reportlab.platypus.flowables import HRFlowable

pdfmetrics.registerFont(TTFont("Nanum", "/root/fonts/NanumGothic-Regular.ttf"))
pdfmetrics.registerFont(TTFont("NanumB", "/root/fonts/NanumGothic-Bold.ttf"))

NAVY = colors.HexColor("#1f3a5f")
ACCENT = colors.HexColor("#2f6f4f")
LGREY = colors.HexColor("#eef1f5")
MGREY = colors.HexColor("#d7dde5")

ss = getSampleStyleSheet()
def style(name, **kw):
    base = dict(fontName="Nanum", fontSize=9.5, leading=14, textColor=colors.HexColor("#222222"))
    base.update(kw)
    return ParagraphStyle(name, **base)

S_TITLE = style("title", fontName="NanumB", fontSize=22, leading=27, textColor=NAVY)
S_SUB = style("sub", fontSize=11, leading=15, textColor=colors.HexColor("#555555"))
S_H1 = style("h1", fontName="NanumB", fontSize=14.5, leading=19, textColor=NAVY, spaceBefore=12, spaceAfter=5)
S_H2 = style("h2", fontName="NanumB", fontSize=11, leading=15, textColor=ACCENT, spaceBefore=7, spaceAfter=3)
S_BODY = style("body", spaceAfter=3)
S_CELL = style("cell", fontSize=9, leading=12.5)
S_CELLB = style("cellb", fontName="NanumB", fontSize=9, leading=12.5, textColor=colors.white)
S_CELLH = style("cellh", fontName="NanumB", fontSize=9, leading=12.5)
S_SMALL = style("small", fontSize=8, leading=11, textColor=colors.HexColor("#666666"))
S_BOX = style("box", fontName="NanumB", fontSize=9, leading=12.5, alignment=TA_CENTER, textColor=colors.white)
S_BOX2 = style("box2", fontSize=8.5, leading=11.5, alignment=TA_CENTER)

def P(t, s=S_BODY): return Paragraph(t, s)

def tbl(header, rows, col_w, header_bg=NAVY, font_first_bold=False):
    data = [[Paragraph(h, S_CELLB) for h in header]]
    for r in rows:
        cells = []
        for i, c in enumerate(r):
            st = S_CELLH if (font_first_bold and i == 0) else S_CELL
            cells.append(Paragraph(str(c), st))
        data.append(cells)
    t = Table(data, colWidths=col_w, repeatRows=1)
    sty = [
        ("BACKGROUND", (0, 0), (-1, 0), header_bg),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ("LEFTPADDING", (0, 0), (-1, -1), 6),
        ("RIGHTPADDING", (0, 0), (-1, -1), 6),
        ("GRID", (0, 0), (-1, -1), 0.5, MGREY),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, LGREY]),
    ]
    t.setStyle(TableStyle(sty))
    return t

def box(txt, bg, w, sub=False):
    t = Table([[Paragraph(txt, S_BOX2 if sub else S_BOX)]], colWidths=[w])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), bg),
        ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
        ("TOPPADDING", (0, 0), (-1, -1), 5), ("BOTTOMPADDING", (0, 0), (-1, -1), 5),
        ("BOX", (0, 0), (-1, -1), 0.5, MGREY),
    ]))
    return t

FULL = 170 * mm
story = []

# ── 표지 헤더 ──
story.append(P("Care&amp; 통합돌봄 플랫폼", S_TITLE))
story.append(P("시스템 구조 · 서버 디렉토리 · 기능 정리", S_SUB))
story.append(Spacer(1, 4))
story.append(HRFlowable(width="100%", thickness=1.5, color=NAVY))
story.append(Spacer(1, 4))
story.append(P("서버 103.55.191.157 (Rocky Linux 8.10) · vhost caren.aiclaude.kr · "
               "작성일 2026-06-11 · APP_ENV=production", S_SMALL))
story.append(Spacer(1, 10))

# ── 1. 개요 ──
story.append(P("1. 개요", S_H1))
story.append(P("사업계획서 「Care&amp;」 기반 <b>통합돌봄 플랫폼</b>. 노인 방문요양 + 산후조리(Phase 2) + "
               "요양보호사 교육/프랜차이즈를 포괄한다. 단일 서버에 백엔드·관리자웹·회원웹·AI서비스·큐워커·"
               "DB·Redis가 전부 systemd 상시 가동된다.", S_BODY))
story.append(P("환경 제약: 아웃바운드 egress가 화이트리스트로 제한된다. Google·AWS·GitHub HTTPS는 통과하지만 "
               "SMTP 및 일반 외부는 차단된다.", S_SMALL))

# ── 2. 아키텍처 ──
story.append(P("2. 시스템 아키텍처", S_H1))
story.append(box("인터넷 사용자 — inbound 443/80 정상", colors.HexColor("#8a93a3"), FULL))
story.append(Spacer(1, 2))
story.append(box("Apache httpd (:443 / :80) — vhost caren.aiclaude.kr · DocumentRoot=/var/www/careand-backend/public", NAVY, FULL))
story.append(Spacer(1, 2))
three = Table([[box("/api/v1/* →<br/>PHP-FPM · Laravel 11<br/>:9000", ACCENT, 54*mm),
                box("/admin →<br/>관리자 웹 · Next.js<br/>:3105", ACCENT, 54*mm),
                box("/app →<br/>회원 웹 · Next.js<br/>:3106", ACCENT, 54*mm)]],
               colWidths=[56*mm, 56*mm, 56*mm])
three.setStyle(TableStyle([("LEFTPADDING",(0,0),(-1,-1),1),("RIGHTPADDING",(0,0),(-1,-1),1),
                           ("TOPPADDING",(0,0),(-1,-1),1),("BOTTOMPADDING",(0,0),(-1,-1),1)]))
story.append(three)
story.append(Spacer(1, 2))
data4 = Table([[box("MariaDB<br/>careand_platform<br/>:3306", colors.HexColor("#6b7a8f"), 40*mm),
                box("Redis<br/>캐시·큐·세션<br/>:6379", colors.HexColor("#6b7a8f"), 40*mm),
                box("AI 서비스<br/>FastAPI<br/>:8001", colors.HexColor("#6b7a8f"), 40*mm),
                box("큐 워커<br/>queue:work<br/>careand-queue", colors.HexColor("#6b7a8f"), 40*mm)]],
               colWidths=[42*mm,42*mm,42*mm,42*mm])
data4.setStyle(TableStyle([("LEFTPADDING",(0,0),(-1,-1),1),("RIGHTPADDING",(0,0),(-1,-1),1),
                           ("TOPPADDING",(0,0),(-1,-1),1),("BOTTOMPADDING",(0,0),(-1,-1),1)]))
story.append(data4)
story.append(Spacer(1, 8))

# ── 3. 디렉토리 ──
story.append(P("3. 서버 디렉토리 구조", S_H1))
story.append(tbl(["경로", "크기", "내용"], [
    ["/var/www/careand-backend", "128M", "Laravel 11 백엔드 API (PHP-FPM, public이 vhost 루트)"],
    ["/root/careand-admin-web", "753M", "관리자 웹 (Next.js, :3105, /admin)"],
    ["/root/careand-member-web", "901M", "회원 웹 (보호자/인력, Next.js, :3106, /app)"],
    ["/root/careand-ai-service", "660M", "AI 마이크로서비스 (FastAPI, :8001, faster-whisper 모델 포함)"],
    ["/usr/local/bin/careand-*", "—", "운영 스크립트 4종 (deploy/backup/offsite/monitor)"],
    ["/etc/cron.d/careand", "—", "운영 크론 (백업·모니터·오프사이트)"],
    ["/root/careand-hardening-snap-20260611", "—", "롤백 스냅샷 (DB덤프·.env·모니터백업·신규비번)"],
], [62*mm, 16*mm, FULL-78*mm], font_first_bold=True))

# ── 4. 구성요소 ──
story.append(P("4. 구성요소별 상세", S_H1))
story.append(P("① 백엔드 API — Laravel 11 (PHP-FPM :9000)", S_H2))
story.append(P("· 라우트 총 116개 / api 111개, JWT 인증(access_token)", S_BODY))
story.append(P("· 주요 그룹: admin(28) · postpartum 산후(19) · seniors(11) · caregivers(8) · auth(7) · "
               "care-sessions(7) · matching(7) · anomaly-alerts(5) · chatbot(5) · notifications(4) · "
               "payments(4) · settlements(3)", S_BODY))
story.append(P("· 비동기 Job 3종: GenerateCareLogJob(LLM 일지생성) · GenerateMatchCandidatesJob(AI 매칭후보) · "
               "ProcessVoiceLogJob(음성 STT, 이번 신설)", S_BODY))
story.append(P("· 외부연동(스텁): OTP/SMS · PG결제 · FCM푸시 · NHIS건보 · 홈택스 · 복지부", S_BODY))
story.append(P("② 관리자 웹 — Next.js (:3105 → /admin) : 12화면, 사업계획서 #17~#26 전부 실API 연동", S_H2))
story.append(P("③ 회원 웹 — Next.js (:3106 → /app) : 보호자/인력 매칭요청·후보선택·수락거절·출퇴근·정산·알림 "
               "(Flutter 앱 대체 웹)", S_H2))
story.append(P("④ AI 서비스 — FastAPI (:8001)", S_H2))
story.append(tbl(["AI 엔진", "상태"], [
    ["matching (매칭 추천)", "rule-v1 (규칙기반 점수화)"],
    ["STT (음성→텍스트)", "faster-whisper-base-ct2-int8 실모델 (lazy 로드)"],
    ["forecast (수요예측)", "seasonal-naive-v1 (DB 이력)"],
    ["anomaly (이상징후)", "rule-v1"],
    ["LLM / RAG (챗봇·일지·요약)", "fallback — ANTHROPIC_API_KEY 미설정(키 충전 필요)"],
], [60*mm, FULL-60*mm], header_bg=ACCENT, font_first_bold=True))
story.append(Spacer(1, 2))
story.append(P("엔드포인트: /ai/match/recommend · /ai/voice/transcribe · /ai/voice/summarize · /ai/forecast/demand · "
               "/ai/anomaly/score · /ai/chatbot/answer · /care-log/generate · /matching/postpartum · /chatbot/postpartum", S_SMALL))
story.append(P("⑤ 큐 워커 / DB / Redis", S_H2))
story.append(P("· careand-queue: queue:work redis (Job 코드 변경 시 restart 필수) · MariaDB careand_platform 66 테이블 · "
               "Redis: 캐시·큐·세션 백엔드", S_BODY))

# ── 5. 데이터 모델 ──
story.append(P("5. 데이터 모델 (66 테이블, 도메인별)", S_H1))
story.append(tbl(["도메인", "주요 테이블"], [
    ["사용자/조직", "users, admins, guardians, caregivers, seniors, organizations, branches"],
    ["매칭", "match_requests, match_candidates, matches"],
    ["케어세션", "care_sessions, attendance_logs, care_activities, care_photos, voice_logs"],
    ["AI", "ai_models, ai_inference_logs, ai_recommendations, ai_log_summaries, anomaly_alerts, health_timeseries, vital_records"],
    ["챗봇", "chatbot_sessions/messages, postpartum_chatbot_*"],
    ["산후(Phase2)", "postpartum_clients, newborns, newborn_daily_logs, newborn_anomaly_alerts, epds_assessments, ltc_vouchers, voucher_transactions"],
    ["결제/정산", "payments, payment_items, settlements, settlement_items"],
    ["교육", "courses, course_lessons/sessions, enrollments, instructors, mock_interviews, certifications, career_milestones, mentor_pairings"],
    ["프랜차이즈", "franchise_applications/contracts/settlements/data_policies"],
    ["시스템", "notifications, audit_logs, failed_jobs(신설), personal_access_tokens, migrations"],
], [28*mm, FULL-28*mm], font_first_bold=True))

# ── 6. 운영 인프라 ──
story.append(P("6. 운영 인프라 (자동화)", S_H1))
story.append(tbl(["스크립트", "스케줄", "기능"], [
    ["careand-deploy", "수동", "git+빌드 배포 (롤백 빌드 보존)"],
    ["careand-backup", "매일 03:30", "DB/설정/git 로컬 백업"],
    ["careand-offsite", "매일 04:10", "103.55.190.159로 오프사이트 복제"],
    ["careand-monitor", "5분마다", "systemd 8유닛+API+웹2 헬스, 상태변화 시 로그 + 메일 알림(이번 추가)"],
], [34*mm, 22*mm, FULL-56*mm], font_first_bold=True))

# ── 7. 이번 세션 변경 ──
story.append(P("7. 이번 세션 변경사항", S_H1))
story.append(P("A. 운영 전환 하드닝 (커밋 e63e373, APP_ENV=production)", S_H2))
for t in [
    "외부연동 6서비스 스텁을 APP_ENV에서 분리(config services.external.stub) → 전환해도 로그인/결제/푸시 안 깨짐",
    "음성 STT production 미동작 버그 → ProcessVoiceLogJob 큐 신설",
    "failed_jobs 테이블 생성(큐 실패 추적)",
    "APP_DEBUG=off, LOG_LEVEL=warning, SESSION_SECURE_COOKIE=true, APP_URL=https",
    "데모 비번 31계정 강비번 교체(admin1234/test1234 폐기) → 스냅샷 폴더 보관",
    "검증: 신규비번 200 / 구비번 401 / 인증API 200 / 웹앱 200",
]:
    story.append(P("· " + t, S_BODY))
story.append(P("B. 모니터 메일 알림 (진행 중)", S_H2))
for t in [
    "egress 진단으로 SMTP 차단 / Google·AWS HTTPS 허용 확인",
    "발송 경로를 Google Apps Script webhook으로 전환, careand-monitor에 webhook/smtp 분기 + test 명령 추가",
    "/etc/careand-alert.conf(0600) 구성 완료 — Apps Script /exec URL + TOKEN 입력만 남음",
]:
    story.append(P("· " + t, S_BODY))

# ── 8. 현재 상태 ──
story.append(P("8. 현재 상태 / 미해결", S_H1))
story.append(tbl(["항목", "상태"], [
    ["코어 서비스 8종", "✓ 전부 active"],
    ["운영 전환 하드닝", "✓ 완료·검증"],
    ["모니터 메일 알림", "진행 — Apps Script URL/TOKEN 대기"],
    ["LLM 챗봇·일지생성", "코드완료, Anthropic 키 크레딧 소진으로 fallback"],
    ["careand-ml 실매칭", "미적용(egress+무거운 의존성), 현재 rule-v1"],
    ["카카오맵", "디벨로퍼스 승인 대기"],
    ["PG/FCM/NHIS/홈택스/복지부", "스텁(실 자격증명·연동 미준비)"],
], [55*mm, FULL-55*mm], font_first_bold=True))

story.append(Spacer(1, 10))
story.append(HRFlowable(width="100%", thickness=0.5, color=MGREY))
story.append(P("© 2026 Care&amp; · 자동 생성(reportlab) · caren.aiclaude.kr", S_SMALL))

def footer(canvas, doc):
    canvas.saveState()
    canvas.setFont("Nanum", 8)
    canvas.setFillColor(colors.HexColor("#999999"))
    canvas.drawCentredString(A4[0]/2, 10*mm, f"Care& 시스템 정리 — {doc.page} 페이지")
    canvas.restoreState()

doc = SimpleDocTemplate("/root/CAREAND-SYSTEM.pdf", pagesize=A4,
                        leftMargin=20*mm, rightMargin=20*mm, topMargin=18*mm, bottomMargin=16*mm,
                        title="Care& 통합돌봄 플랫폼 시스템 정리", author="careand-ops")
doc.build(story, onFirstPage=footer, onLaterPages=footer)
print("PDF 생성 완료")
