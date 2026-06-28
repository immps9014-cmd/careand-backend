# -*- coding: utf-8 -*-
"""Care& 사용자별 매뉴얼 PDF — 화면 재구성 목업 + 단계별 사용법 (reportlab/NanumGothic)."""
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfbase import pdfmetrics
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table,
                                TableStyle, PageBreak, KeepTogether)
from reportlab.platypus.flowables import HRFlowable

pdfmetrics.registerFont(TTFont("Nanum", "/root/fonts/NanumGothic-Regular.ttf"))
pdfmetrics.registerFont(TTFont("NanumB", "/root/fonts/NanumGothic-Bold.ttf"))

NAVY = colors.HexColor("#1f3a5f")
BRAND = colors.HexColor("#d98324")      # Care& 따뜻한 주황(brand)
BRANDBG = colors.HexColor("#fbf0e2")
WARM800 = colors.HexColor("#3b332c")
WARM100 = colors.HexColor("#efe9e2")
WARM50 = colors.HexColor("#faf7f3")
GREY = colors.HexColor("#8a8278")
LINE = colors.HexColor("#e3ddd4")
INK = colors.HexColor("#2a2a2a")

def st(name, **kw):
    base = dict(fontName="Nanum", fontSize=9.5, leading=13.5, textColor=INK)
    base.update(kw); return ParagraphStyle(name, **base)

S_TITLE = st("t", fontName="NanumB", fontSize=24, leading=29, textColor=NAVY)
S_SUB = st("s", fontSize=12, leading=16, textColor=GREY)
S_H1 = st("h1", fontName="NanumB", fontSize=17, leading=21, textColor=colors.white)
S_H2 = st("h2", fontName="NanumB", fontSize=12.5, leading=16, textColor=BRAND, spaceBefore=4, spaceAfter=3)
S_BODY = st("b", spaceAfter=2)
S_STEP = st("step", fontSize=9.5, leading=14, leftIndent=2)
S_CAP = st("cap", fontName="NanumB", fontSize=9.5, leading=13, textColor=NAVY)
S_SMALL = st("sm", fontSize=8, leading=11, textColor=GREY)
# 목업용
M_HDR = st("mh", fontName="NanumB", fontSize=8.5, leading=11, textColor=WARM800)
M_BADGE = st("mb", fontName="NanumB", fontSize=6.5, leading=8, textColor=BRAND, alignment=TA_CENTER)
M_CARDT = st("mct", fontName="NanumB", fontSize=7.8, leading=10.5, textColor=INK)
M_CARDL = st("mcl", fontSize=7, leading=9.5, textColor=GREY)
M_TAB = st("mt", fontSize=6, leading=8, alignment=TA_CENTER, textColor=GREY)
M_TABA = st("mta", fontName="NanumB", fontSize=6, leading=8, alignment=TA_CENTER, textColor=BRAND)
A_MENU = st("am", fontSize=7.5, leading=12, textColor=colors.HexColor("#cfd6df"))
A_MENUA = st("ama", fontName="NanumB", fontSize=7.5, leading=12, textColor=colors.white)
A_SECT = st("as", fontName="NanumB", fontSize=6.5, leading=10, textColor=colors.HexColor("#7e8aa0"))
A_TITLE = st("at", fontName="NanumB", fontSize=11, leading=14, textColor=NAVY)
A_KT = st("akt", fontSize=6.8, leading=9, textColor=GREY)
A_KV = st("akv", fontName="NanumB", fontSize=12, leading=14, textColor=NAVY)
A_TH = st("ath", fontName="NanumB", fontSize=6.6, leading=9, textColor=colors.white)
A_TD = st("atd", fontSize=6.6, leading=9, textColor=INK)

def P(t, s=S_BODY): return Paragraph(t, s)

# ───────── 모바일(보호자/인력) 화면 목업 ─────────
def card(title, lines, accent=BRAND):
    rows = [[Paragraph(title, M_CARDT)]]
    for ln in lines:
        rows.append([Paragraph(ln, M_CARDL)])
    t = Table(rows, colWidths=[58*mm])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0,0), (-1,-1), colors.white),
        ("LINEABOVE", (0,0), (0,0), 2, accent),
        ("BOX", (0,0), (-1,-1), 0.5, LINE),
        ("LEFTPADDING",(0,0),(-1,-1),6),("RIGHTPADDING",(0,0),(-1,-1),6),
        ("TOPPADDING",(0,0),(-1,-1),3),("BOTTOMPADDING",(0,0),(-1,-1),3),
    ]))
    return t

def phone(role, cards, active_tab, tabs):
    # 헤더
    head = Table([[Paragraph(f'<font name="NanumB" color="#d98324">C</font>  '
                             f'<font name="NanumB" color="#3b332c">Care&amp;</font>', M_HDR),
                   Table([[Paragraph(role, M_BADGE)]], colWidths=[13*mm])]],
                 colWidths=[47*mm, 15*mm])
    head.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),colors.white),
        ("BACKGROUND",(1,0),(1,0),BRANDBG),("VALIGN",(0,0),(-1,-1),"MIDDLE"),
        ("LEFTPADDING",(0,0),(0,0),7),("TOPPADDING",(0,0),(-1,-1),5),("BOTTOMPADDING",(0,0),(-1,-1),5),
        ("LINEBELOW",(0,0),(-1,-1),0.5,LINE)]))
    # 본문 카드 스택
    body_rows = [[c] for c in cards]
    body = Table(body_rows, colWidths=[60*mm])
    body.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),WARM50),
        ("TOPPADDING",(0,0),(-1,-1),4),("BOTTOMPADDING",(0,0),(-1,-1),4),
        ("LEFTPADDING",(0,0),(-1,-1),5),("RIGHTPADDING",(0,0),(-1,-1),5)]))
    # 하단 탭
    tabcells = []
    for i,t in enumerate(tabs):
        tabcells.append(Paragraph(t, M_TABA if i==active_tab else M_TAB))
    tabbar = Table([tabcells], colWidths=[12.4*mm]*5)
    tstyle=[("BACKGROUND",(0,0),(-1,-1),colors.white),("VALIGN",(0,0),(-1,-1),"MIDDLE"),
        ("TOPPADDING",(0,0),(-1,-1),5),("BOTTOMPADDING",(0,0),(-1,-1),5),
        ("LINEABOVE",(0,0),(-1,-1),0.5,LINE)]
    tstyle.append(("LINEABOVE",(active_tab,0),(active_tab,0),1.6,BRAND))
    tabbar.setStyle(TableStyle(tstyle))
    frame = Table([[head],[body],[tabbar]], colWidths=[62*mm])
    frame.setStyle(TableStyle([("BOX",(0,0),(-1,-1),1,colors.HexColor("#cbc3b8")),
        ("LEFTPADDING",(0,0),(-1,-1),0),("RIGHTPADDING",(0,0),(-1,-1),0),
        ("TOPPADDING",(0,0),(-1,-1),0),("BOTTOMPADDING",(0,0),(-1,-1),0)]))
    return frame

GUARD_TABS = ["홈","어르신","케어일지","알림","내정보"]
CARE_TABS = ["홈","일정","정산","알림","내정보"]

# ───────── 관리자 화면 목업 ─────────
ADMIN_MENU = [("메인",[("대시보드",0),("회원 관리",0),("매칭 관리",0),("케어 모니터링",0),("정산",0)]),
              ("운영 관리",[("인력 자격검증",0),("계약·일정",0),("AI 일지 검수",0),("공지·푸시",0)]),
              ("AI 운영",[("AI 모델",0),("CS / 분쟁",0),("리포트",0)])]

def admin_screen(active, title, subtitle, body):
    # 사이드바
    srows = [[Paragraph('<font name="NanumB" color="white">C  Care&amp;</font>', A_MENUA)]]
    for sect, items in ADMIN_MENU:
        srows.append([Paragraph(sect, A_SECT)])
        for label,_ in items:
            srows.append([Paragraph(("● " if label==active else "○ ")+label,
                                    A_MENUA if label==active else A_MENU)])
    side = Table(srows, colWidths=[40*mm])
    sstyle=[("BACKGROUND",(0,0),(-1,-1),WARM800),("LEFTPADDING",(0,0),(-1,-1),7),
        ("RIGHTPADDING",(0,0),(-1,-1),4),("TOPPADDING",(0,0),(-1,-1),2.4),("BOTTOMPADDING",(0,0),(-1,-1),2.4)]
    side.setStyle(TableStyle(sstyle))
    # 메인
    main = Table([[Paragraph(title, A_TITLE)],[Paragraph(subtitle, S_SMALL)],[Spacer(1,3)],[body]],
                 colWidths=[123*mm])
    main.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),WARM50),
        ("LEFTPADDING",(0,0),(-1,-1),9),("RIGHTPADDING",(0,0),(-1,-1),9),
        ("TOPPADDING",(0,0),(0,0),8),("BOTTOMPADDING",(0,0),(-1,-1),4),("VALIGN",(0,0),(-1,-1),"TOP")]))
    frame = Table([[side, main]], colWidths=[40*mm,123*mm])
    frame.setStyle(TableStyle([("BOX",(0,0),(-1,-1),1,colors.HexColor("#cbc3b8")),
        ("VALIGN",(0,0),(-1,-1),"TOP"),("LEFTPADDING",(0,0),(-1,-1),0),("RIGHTPADDING",(0,0),(-1,-1),0),
        ("TOPPADDING",(0,0),(-1,-1),0),("BOTTOMPADDING",(0,0),(-1,-1),0)]))
    return frame

def kpi_row(items):
    cells=[]
    for label,val,sub in items:
        cells.append(Table([[Paragraph(label,A_KT)],[Paragraph(val,A_KV)],[Paragraph(sub,A_KT)]],
                           colWidths=[27*mm]))
    for c in cells:
        c.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),colors.white),("BOX",(0,0),(-1,-1),0.5,LINE),
            ("LEFTPADDING",(0,0),(-1,-1),6),("TOPPADDING",(0,0),(-1,-1),4),("BOTTOMPADDING",(0,0),(-1,-1),4),
            ("LINEABOVE",(0,0),(0,0),2,BRAND)]))
    row = Table([cells], colWidths=[29*mm]*len(cells))
    row.setStyle(TableStyle([("LEFTPADDING",(0,0),(-1,-1),0),("RIGHTPADDING",(0,0),(-1,-1),3),
        ("TOPPADDING",(0,0),(-1,-1),0),("BOTTOMPADDING",(0,0),(-1,-1),0),("VALIGN",(0,0),(-1,-1),"TOP")]))
    return row

def admin_table(header, rows, widths):
    data=[[Paragraph(h,A_TH) for h in header]]
    for r in rows: data.append([Paragraph(str(c),A_TD) for c in r])
    t=Table(data,colWidths=widths,repeatRows=1)
    t.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,0),NAVY),("GRID",(0,0),(-1,-1),0.4,LINE),
        ("ROWBACKGROUNDS",(0,1),(-1,-1),[colors.white,WARM50]),("VALIGN",(0,0),(-1,-1),"MIDDLE"),
        ("LEFTPADDING",(0,0),(-1,-1),4),("RIGHTPADDING",(0,0),(-1,-1),4),
        ("TOPPADDING",(0,0),(-1,-1),3),("BOTTOMPADDING",(0,0),(-1,-1),3)]))
    return t

def steps(items):
    fl=[]
    for i,t in enumerate(items,1):
        fl.append(Paragraph(f'<font name="NanumB" color="#d98324">{i}.</font>  {t}', S_STEP))
    return fl

def screen_block(caption, mockup, usage):
    inner=[P(caption,S_CAP), Spacer(1,3), mockup, Spacer(1,4)]
    inner += usage
    return KeepTogether(inner)

def role_header(num, title, desc, color):
    bar = Table([[Paragraph(f"{num}. {title}", S_H1)]], colWidths=[170*mm])
    bar.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),color),("LEFTPADDING",(0,0),(-1,-1),10),
        ("TOPPADDING",(0,0),(-1,-1),7),("BOTTOMPADDING",(0,0),(-1,-1),7)]))
    return [bar, Spacer(1,2), P(desc, S_SMALL), Spacer(1,6)]

story=[]
# ════ 표지 ════
story.append(Spacer(1,40))
story.append(P("Care&amp; 통합돌봄 플랫폼", S_TITLE))
story.append(Spacer(1,4))
story.append(P("사용자별 사용 매뉴얼", st("x",fontName="NanumB",fontSize=15,textColor=BRAND)))
story.append(Spacer(1,10))
story.append(HRFlowable(width="100%",thickness=1.5,color=NAVY))
story.append(Spacer(1,8))
story.append(P("대상: 관리자 · 보호자 · 인력(요양보호사)", S_SUB))
story.append(P("접속: 관리자 https://careand.aiclaude.kr/admin · 회원 https://careand.aiclaude.kr/app", S_BODY))
story.append(Spacer(1,16))
story.append(P("※ 본 매뉴얼의 화면은 실제 화면 구성요소(메뉴·항목·버튼)를 충실히 재구성한 "
               "와이어프레임입니다. 색상·아이콘 등 세부 스타일은 실제 서비스와 다를 수 있습니다.", S_SMALL))
story.append(PageBreak())

# ════════════════ 1. 보호자 ════════════════
story += role_header("1","보호자(가디언) 매뉴얼","돌봄 대상 어르신을 등록하고 AI 매칭을 요청하며, "
                     "케어 일지를 확인하는 모바일 화면입니다. (하단 탭: 홈·어르신·케어일지·알림·내정보)", BRAND)

# 1-1 로그인
login_cards=[card("회원 로그인",["보호자·인력 회원 계정으로 로그인","",
    "이메일  [ example@careand.kr ]","비밀번호 [ •••••••• ]","","[  로그인  ]"])]
story.append(screen_block("화면 1. 로그인",
    phone("보호자", login_cards, 0, GUARD_TABS),
    steps(["https://careand.aiclaude.kr/app 접속",
           "가입한 이메일·비밀번호 입력 후 [로그인]",
           "역할(보호자)에 따라 자동으로 보호자 홈으로 이동"])))
story.append(Spacer(1,8))

# 1-2 홈
home_cards=[card("내 매칭 요청",["진행 중인 돌봄 매칭을 확인하세요","· 요청별 상태/AI 후보 진행상황"]),
    card("새 매칭 제안 · 1순위",["AI가 추천한 인력 후보","· 후보 프로필 확인 → 수락/거절"], accent=NAVY),
    card("오늘의 케어 · 내 케어 일정",["예정된 방문 일정과 담당 인력","· 출퇴근/진행 상태 표시"])]
story.append(screen_block("화면 2. 홈",
    phone("보호자", home_cards, 0, GUARD_TABS),
    steps(["[내 매칭 요청]에서 요청 진행상황 확인",
           "[새 매칭 제안]의 AI 추천 후보를 보고 수락 또는 거절",
           "[내 케어 일정]에서 예정된 방문과 담당 인력 확인"])))
story.append(PageBreak())

# 1-3 매칭 요청
req_cards=[card("새 매칭 요청",["돌봄 대상과 일정을 선택하면 AI가 인력을 추천","",
    "돌봄 대상  [ 대상자를 선택하세요 ▾ ]","서비스 종류 [ 서비스를 선택하세요 ▾ ]",
    "유형 / 시작 일시  [ ___ ]","요청사항(선택) [ ___________ ]","","[  AI 매칭 요청  ]"])]
story.append(screen_block("화면 3. 새 매칭 요청 (어르신 탭 → 매칭 요청)",
    phone("보호자", req_cards, 1, GUARD_TABS),
    steps(["[어르신] 탭에서 돌봄 대상 등록(이름·요양등급·질환)",
           "[새 매칭 요청]에서 대상자·서비스 종류·유형·시작 일시 선택",
           "[AI 매칭 요청] → 30초 내 AI 후보가 산출되어 홈에 표시",
           "추천 후보를 확인하고 1순위부터 수락"])))
story.append(Spacer(1,8))

# 1-4 케어일지
log_cards=[card("케어 일지",["진행 중인 돌봄의 AI 케어 일지를 확인","· 매칭완료 세션별 일지"]),
    card("AI 일지 · 보호자 버전",["방문 인력의 음성 기록을 AI가 요약","· 보호자용 친화 톤 / 활동 분류"], accent=NAVY)]
story.append(screen_block("화면 4. 케어일지",
    phone("보호자", log_cards, 2, GUARD_TABS),
    steps(["[케어일지] 탭에서 완료된 케어 세션 선택",
           "AI가 음성기록을 요약한 보호자용 일지 확인",
           "[알림] 탭에서 일지 생성·이상징후 알림 수신"])))
story.append(PageBreak())

# ════════════════ 2. 인력 ════════════════
story += role_header("2","인력(요양보호사) 매뉴얼","배정된 케어 세션을 수행하고 출퇴근·음성 케어기록을 "
                     "남기며 정산을 확인하는 모바일 화면입니다. (하단 탭: 홈·일정·정산·알림·내정보)", colors.HexColor("#2f6f4f"))

care_home=[card("새 매칭 제안",["보호자 요청에 대한 AI 추천 배정","· 수락 시 케어 세션 생성"], accent=NAVY),
    card("오늘의 케어",["오늘 방문할 어르신과 시간","· [출근 체크인] (자택 200m 이내)"]),
    card("내 케어 일정",["예정된 케어 세션 목록"])]
story.append(screen_block("화면 1. 홈",
    phone("인력", care_home, 0, CARE_TABS),
    steps(["[새 매칭 제안] 수락 → 케어 세션 배정",
           "방문지 도착 후 [출근 체크인](자택 반경 200m 이내 GPS 확인)",
           "케어 종료 시 [퇴근] → 활동기록·음성일지 업로드"])))
story.append(Spacer(1,8))

sch=[card("내 일정",["배정된 케어 세션 일정","· 날짜별 방문 어르신/시간/주소"]),
    card("케어 진행",["체크인 → 활동기록 → 음성 케어일지 → 체크아웃","· 음성은 AI가 STT+요약 처리"], accent=NAVY)]
story.append(screen_block("화면 2. 일정 / 케어 수행",
    phone("인력", sch, 1, CARE_TABS),
    steps(["[일정] 탭에서 배정 세션 확인",
           "세션 진입 → 출근 체크인 → 활동 기록 입력",
           "음성으로 케어 내용 녹음 → AI가 일지 자동 생성",
           "퇴근 체크아웃으로 세션 종료"])))
story.append(PageBreak())

settle=[card("정산 내역",["주간 정산 명세와 입금 내역을 확인","",
    "세전 금액      1,200,000원","원천징수(3.3%)  -39,600원","─────────────────",
    "실지급액      1,160,400원"])]
story.append(screen_block("화면 3. 정산",
    phone("인력", settle, 2, CARE_TABS),
    steps(["[정산] 탭에서 주간 정산 명세 확인",
           "세전 금액·원천징수(3.3%)·실지급액 자동 계산",
           "입금 상태(예정/완료) 확인"])))
story.append(Spacer(1,6))
story.append(P("· [알림] 탭: 신규 매칭 제안·일정 변경·정산 완료 푸시 / [내정보] 탭: 자격증·특기·프로필 관리", S_SMALL))
story.append(PageBreak())

# ════════════════ 3. 관리자 ════════════════
story += role_header("3","관리자 매뉴얼","회원·매칭·케어·정산·AI를 운영하는 데스크탑 콘솔입니다. "
                     "(좌측 사이드바: 메인 / 운영 관리 / AI 운영)", NAVY)

# 3-1 대시보드
dash_body=Table([[kpi_row([("진행중 매칭","37","전주 대비 +12%"),
                           ("이번 주 매출","8.4M","전주 대비 +5%"),
                           ("검수 대기 인력","6","승인 필요")])],
                 [Spacer(1,4)],
                 [admin_table(["지역","예측 수요","대기 인력","충족률","상태"],
                    [["강남구","24","18","75%","적정"],
                     ["송파구","19","9","47%","부족 우려"],
                     ["관악구","15","4","27%","심각"]],
                    [22*mm,22*mm,22*mm,18*mm,22*mm])]],
                colWidths=[107*mm])
dash_body.setStyle(TableStyle([("LEFTPADDING",(0,0),(-1,-1),0),("RIGHTPADDING",(0,0),(-1,-1),0),
    ("TOPPADDING",(0,0),(-1,-1),0),("BOTTOMPADDING",(0,0),(-1,-1),0)]))
story.append(screen_block("화면 1. 대시보드",
    admin_screen("대시보드","대시보드","진행중 매칭·매출·검수 대기 현황과 지역별 수요예측",dash_body),
    steps(["상단 KPI로 진행중 매칭·이번 주 매출·검수 대기 인력 즉시 파악",
           "지역별 예측 수요/대기 인력/충족률로 인력 수급 의사결정",
           "상태가 '부족 우려/심각'인 지역은 인력 모집·재배치 검토"])))
story.append(PageBreak())

# 3-2 매칭 관리
match_body=admin_table(["요청ID","대상자","도메인","모드","일정","AI후보","상태","액션"],
    [["#1042","김○자","방문요양","AI","06-12 09:00","3명","후보산출","[배정]"],
     ["#1041","박○순","산후조리","AI","06-12 13:00","2명","대기","[보기]"],
     ["#1039","이○희","방문요양","수동","06-13 10:00","후보 없음","미충족","[활성 인력 선택]"]],
    [16*mm,16*mm,18*mm,12*mm,22*mm,14*mm,17*mm,24*mm])
story.append(screen_block("화면 2. 매칭 관리",
    admin_screen("매칭 관리","매칭 관리","보호자 매칭 요청과 AI 후보를 검토·배정",match_body),
    steps(["[매칭 요청] 목록에서 요청별 AI 후보 수 확인",
           "[보기]로 후보 프로필·점수 검토",
           "[배정]으로 인력 확정, 후보 없으면 [활성 인력 선택]으로 수동 배정"])))
story.append(Spacer(1,8))

# 3-3 인력 자격검증
appr_body=admin_table(["이름","연락처","특기","자격증번호","신청일","상태","액션"],
    [["정○호","010-1234-5678","치매케어","2-2024-00123","06-09","검토중","[승인][반려]"],
     ["최○영","010-2345-6789","재활보조","2-2023-09876","06-08","승인","—"]],
    [16*mm,28*mm,18*mm,26*mm,14*mm,14*mm,22*mm])
story.append(screen_block("화면 3. 인력 자격검증",
    admin_screen("인력 자격검증","인력 자격검증","요양보호사 자격 진위확인 후 승인·반려",appr_body),
    steps(["신규 인력 신청 목록에서 자격증번호 확인",
           "보건복지부 자격 진위확인 결과 검토",
           "[승인]으로 활성화 또는 [반려] 처리"])))
story.append(PageBreak())

# 3-4 정산
set_body=admin_table(["인력","정산 기간","세전","원천징수","실지급","상태"],
    [["정○호","06-02~06-08","1,200,000","-39,600","1,160,400","완료"],
     ["최○영","06-02~06-08","980,000","-32,340","947,660","예정"]],
    [18*mm,28*mm,22*mm,20*mm,22*mm,15*mm])
story.append(screen_block("화면 4. 정산",
    admin_screen("정산","정산","인력별 주간 정산 명세와 원천징수(3.3%)·입금 관리",set_body),
    steps(["인력별 정산 기간·세전 금액 확인",
           "원천징수(3.3%) 자동 계산된 실지급액 검토",
           "입금 처리 후 상태를 [완료]로 갱신 (홈택스 신고 연계)"])))
story.append(Spacer(1,8))
story.append(P("· 그 외: 케어 모니터링(실시간 세션·이상징후) · AI 일지 검수 · 공지/푸시 · AI 모델 · CS/분쟁 · 리포트", S_SMALL))

story.append(Spacer(1,12))
story.append(HRFlowable(width="100%",thickness=0.5,color=LINE))
story.append(P("© 2026 Care&amp; · 사용자 매뉴얼 v1.0 · careand.aiclaude.kr", S_SMALL))

def footer(c, d):
    c.saveState(); c.setFont("Nanum",8); c.setFillColor(GREY)
    c.drawCentredString(A4[0]/2, 9*mm, f"Care& 사용자 매뉴얼 — {d.page}")
    c.restoreState()

doc=SimpleDocTemplate("/root/CAREAND-USER-MANUAL.pdf", pagesize=A4,
    leftMargin=20*mm,rightMargin=20*mm,topMargin=16*mm,bottomMargin=15*mm,
    title="Care& 사용자별 매뉴얼", author="careand-ops")
doc.build(story, onFirstPage=footer, onLaterPages=footer)
print("매뉴얼 PDF 생성 완료")
