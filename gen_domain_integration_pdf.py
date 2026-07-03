#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""CAREAND-DOMAIN-INTEGRATION.md → PDF (reportlab + NanumGothic).
자체 미니 마크다운 파서: # 헤딩 / > 인용 / - 불릿(중첩) / | 표 | / ``` 코드 / [ ] 체크 / --- 구분 / **bold** `code`."""
import re, html
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfbase import pdfmetrics
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table,
                                TableStyle, HRFlowable, KeepTogether)
from reportlab.lib.styles import ParagraphStyle

pdfmetrics.registerFont(TTFont("Nanum", "/root/fonts/NanumGothic-Regular.ttf"))
pdfmetrics.registerFont(TTFont("NanumB", "/root/fonts/NanumGothic-Bold.ttf"))

NAVY = colors.HexColor("#1f3a5f")
ACCENT = colors.HexColor("#2f6f4f")
CODEBG = colors.HexColor("#f2f2f0")
BOXBG = colors.HexColor("#eef3f8")
GREY = colors.HexColor("#666666")

SRC = "/root/CAREAND-DOMAIN-INTEGRATION.md"
OUT = "/root/CAREAND-DOMAIN-INTEGRATION.pdf"

def st(name, **kw):
    base = dict(fontName="Nanum", fontSize=9.3, leading=13.6, textColor=colors.HexColor("#222222"), alignment=TA_LEFT)
    base.update(kw); return ParagraphStyle(name, **base)

S_TITLE = st("title", fontName="NanumB", fontSize=21, leading=26, textColor=NAVY, spaceAfter=4)
S_H1 = st("h1", fontName="NanumB", fontSize=14, leading=18, textColor=NAVY, spaceBefore=13, spaceAfter=5)
S_H2 = st("h2", fontName="NanumB", fontSize=10.8, leading=14.5, textColor=ACCENT, spaceBefore=8, spaceAfter=3)
S_H3 = st("h3", fontName="NanumB", fontSize=9.8, leading=13.5, textColor=NAVY, spaceBefore=6, spaceAfter=2)
S_BODY = st("body", spaceAfter=2)
S_QUOTE = st("quote", fontName="Nanum", fontSize=8.8, leading=12.8, textColor=GREY, leftIndent=8, borderPadding=0)
S_BULLET = st("bullet", leftIndent=12, bulletIndent=2, spaceAfter=1.5)
S_BULLET2 = st("bullet2", leftIndent=26, bulletIndent=16, spaceAfter=1.5, textColor=colors.HexColor("#333333"))
S_CODE = st("code", fontName="Nanum", fontSize=8.4, leading=12, textColor=colors.HexColor("#333333"))
S_CELL = st("cell", fontSize=8.4, leading=11.6)
S_CELLB = st("cellb", fontName="NanumB", fontSize=8.4, leading=11.6, textColor=colors.white)
S_META = st("meta", fontSize=8.5, leading=12, textColor=GREY)

def inline(t):
    """마크다운 인라인 → reportlab 마크업. 이스케이프 후 **bold**/`code` 처리."""
    t = html.escape(t)
    t = re.sub(r"\*\*(.+?)\*\*", r'<font name="NanumB">\1</font>', t)
    t = re.sub(r"`([^`]+?)`", r'<font name="Nanum" backColor="#f2f2f0">\1</font>', t)
    return t

def col_widths(ncol, total=170*mm):
    # 첫 열 살짝 좁게, 마지막 열 넓게 (설계서 표 특성)
    if ncol == 2: return [42*mm, total-42*mm]
    if ncol == 3: return [40*mm, 30*mm, total-70*mm]
    return [total/ncol]*ncol

def make_table(header, rows):
    ncol = len(header)
    cw = col_widths(ncol)
    data = [[Paragraph(inline(c), S_CELLB) for c in header]]
    for r in rows:
        data.append([Paragraph(inline(c), S_CELL) for c in r])
    tb = Table(data, colWidths=cw, repeatRows=1)
    sty = [("BACKGROUND", (0,0), (-1,0), NAVY),
           ("VALIGN", (0,0), (-1,-1), "MIDDLE"),
           ("TOPPADDING", (0,0), (-1,-1), 3.5), ("BOTTOMPADDING", (0,0), (-1,-1), 3.5),
           ("LEFTPADDING", (0,0), (-1,-1), 5), ("RIGHTPADDING", (0,0), (-1,-1), 5),
           ("LINEBELOW", (0,0), (-1,-1), 0.4, colors.HexColor("#cccccc")),
           ("GRID", (0,0), (-1,-1), 0.3, colors.HexColor("#dddddd"))]
    for i in range(1, len(data)):
        if i % 2 == 0:
            sty.append(("BACKGROUND", (0,i), (-1,i), colors.HexColor("#f6f8fa")))
    tb.setStyle(TableStyle(sty))
    return tb

def code_block(lines):
    txt = "<br/>".join(html.escape(l).replace(" ", "&nbsp;") or "&nbsp;" for l in lines)
    p = Paragraph(txt, S_CODE)
    tb = Table([[p]], colWidths=[170*mm])
    tb.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),CODEBG),
                            ("LEFTPADDING",(0,0),(-1,-1),8),("RIGHTPADDING",(0,0),(-1,-1),8),
                            ("TOPPADDING",(0,0),(-1,-1),6),("BOTTOMPADDING",(0,0),(-1,-1),6),
                            ("BOX",(0,0),(-1,-1),0.4,colors.HexColor("#dddddd"))]))
    return tb

def quote_block(lines):
    txt = "<br/>".join(inline(l) for l in lines)
    p = Paragraph(txt, S_QUOTE)
    tb = Table([[p]], colWidths=[170*mm])
    tb.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),BOXBG),
                            ("LEFTPADDING",(0,0),(-1,-1),9),("RIGHTPADDING",(0,0),(-1,-1),9),
                            ("TOPPADDING",(0,0),(-1,-1),5),("BOTTOMPADDING",(0,0),(-1,-1),5),
                            ("LINEBEFORE",(0,0),(0,-1),2,NAVY)]))
    return tb

def parse(md):
    flow = []
    lines = md.split("\n")
    i, n = 0, len(lines)
    def flush_gap(): flow.append(Spacer(1, 3))
    while i < n:
        ln = lines[i]
        s = ln.rstrip()
        # code fence
        if s.strip().startswith("```"):
            i += 1; buf = []
            while i < n and not lines[i].strip().startswith("```"):
                buf.append(lines[i]); i += 1
            i += 1
            flow.append(code_block(buf)); flow.append(Spacer(1,4)); continue
        # table
        if s.lstrip().startswith("|") and i+1 < n and re.match(r"^\s*\|[\s:\-|]+\|\s*$", lines[i+1]):
            header = [c.strip() for c in s.strip().strip("|").split("|")]
            i += 2; rows = []
            while i < n and lines[i].lstrip().startswith("|"):
                rows.append([c.strip() for c in lines[i].strip().strip("|").split("|")]); i += 1
            flow.append(make_table(header, rows)); flow.append(Spacer(1,5)); continue
        # blockquote (연속 > 묶기)
        if s.lstrip().startswith(">"):
            buf = []
            while i < n and lines[i].lstrip().startswith(">"):
                buf.append(re.sub(r"^\s*>\s?", "", lines[i])); i += 1
            flow.append(quote_block(buf)); flow.append(Spacer(1,4)); continue
        # hr
        if re.match(r"^\s*---+\s*$", s):
            flow.append(Spacer(1,3)); flow.append(HRFlowable(width="100%", thickness=0.6, color=colors.HexColor("#cccccc"))); flow.append(Spacer(1,3)); i += 1; continue
        # headings
        m = re.match(r"^(#{1,6})\s+(.*)$", s)
        if m:
            lvl = len(m.group(1)); txt = m.group(2)
            if lvl == 1:
                flow.append(Paragraph(inline(txt), S_TITLE))
            elif lvl == 2:
                flow.append(Paragraph(inline(txt), S_H1))
            elif lvl == 3:
                flow.append(Paragraph(inline(txt), S_H2))
            else:
                flow.append(Paragraph(inline(txt), S_H3))
            i += 1; continue
        # bullets / checklist (indent 감지)
        mb = re.match(r"^(\s*)([-*])\s+(.*)$", ln)
        if mb:
            indent = len(mb.group(1)); body = mb.group(3)
            body = re.sub(r"^\[ \]\s*", "☐ ", body); body = re.sub(r"^\[[xX]\]\s*", "☑ ", body)
            style = S_BULLET2 if indent >= 2 else S_BULLET
            flow.append(Paragraph(inline(body), style, bulletText="◦" if indent>=2 else "•"))
            i += 1; continue
        # blank
        if s.strip() == "":
            flush_gap(); i += 1; continue
        # meta lines right after title (작성/운영/상태) already handled by quote if >, else body
        flow.append(Paragraph(inline(s), S_BODY)); i += 1
    return flow

def footer(canvas, doc):
    canvas.saveState()
    canvas.setFont("Nanum", 7.5); canvas.setFillColor(GREY)
    canvas.drawString(20*mm, 12*mm, "Care& 멀티도메인 통합 설계서")
    canvas.drawRightString(190*mm, 12*mm, "p.%d" % doc.page)
    canvas.restoreState()

md = open(SRC, encoding="utf-8").read()
doc = SimpleDocTemplate(OUT, pagesize=A4, leftMargin=20*mm, rightMargin=20*mm,
                        topMargin=16*mm, bottomMargin=18*mm, title="Care& 멀티도메인 통합 설계서")
doc.build(parse(md), onFirstPage=footer, onLaterPages=footer)
print("wrote", OUT)
