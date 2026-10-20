#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""CareAnd-AI-설계문서-통합본.docx → PDF (reportlab + NanumGothic).
커스텀 표지(초록 C& 로고) + docx 본문(문단/표) 순차 렌더. Title 뒤 표지 문단(3~6)은 표지로,
본문은 '문서 소개'부터 렌더. 헤딩=네이비/그린, 표=네이비헤더+교대음영, '•'접두=불릿."""
import html
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.enums import TA_LEFT, TA_CENTER
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfbase import pdfmetrics
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table,
                                TableStyle, PageBreak, Flowable)
from reportlab.lib.styles import ParagraphStyle
from docx import Document
from docx.text.paragraph import Paragraph as DxP
from docx.table import Table as DxT

pdfmetrics.registerFont(TTFont("Nanum", "/root/fonts/NanumGothic-Regular.ttf"))
pdfmetrics.registerFont(TTFont("NanumB", "/root/fonts/NanumGothic-Bold.ttf"))

NAVY = colors.HexColor("#20304f")
GREEN = colors.HexColor("#3f9e7f")
GREEN_D = colors.HexColor("#2f7d63")
GREY = colors.HexColor("#666666")
SRC = "/root/CareAnd-AI-설계문서-통합본.docx"
OUT = "/root/CareAnd-AI-설계문서-통합본.pdf"

def st(name, **kw):
    base = dict(fontName="Nanum", fontSize=9.5, leading=14, textColor=colors.HexColor("#222222"), alignment=TA_LEFT)
    base.update(kw); return ParagraphStyle(name, **base)

S_H1 = st("h1", fontName="NanumB", fontSize=14.5, leading=19, textColor=NAVY, spaceBefore=13, spaceAfter=6)
S_H2 = st("h2", fontName="NanumB", fontSize=11, leading=15, textColor=GREEN_D, spaceBefore=8, spaceAfter=3)
S_BODY = st("body", spaceAfter=3)
S_BULLET = st("bullet", leftIndent=13, bulletIndent=2, spaceAfter=2.5)
S_CELL = st("cell", fontSize=8.7, leading=12)
S_CELLB = st("cellb", fontName="NanumB", fontSize=8.7, leading=12, textColor=colors.white)
# 표지
S_CTITLE = st("ctitle", fontName="NanumB", fontSize=25, leading=31, textColor=NAVY, alignment=TA_CENTER)
S_CSUB = st("csub", fontName="NanumB", fontSize=12, leading=17, textColor=GREEN_D, alignment=TA_CENTER)
S_CDESC = st("cdesc", fontName="Nanum", fontSize=10, leading=15, textColor=colors.HexColor("#444444"), alignment=TA_CENTER)
S_CMETA = st("cmeta", fontName="Nanum", fontSize=8.5, leading=12, textColor=GREY, alignment=TA_CENTER)

def runs_markup(p):
    out = []
    for r in p.runs:
        t = html.escape(r.text)
        if r.bold: t = '<font name="NanumB">%s</font>' % t
        out.append(t)
    s = "".join(out) or html.escape(p.text)
    return s

class Logo(Flowable):
    def __init__(self, size=64):
        self.size=size; self.width=size; self.height=size; self.hAlign="CENTER"
    def draw(self):
        c=self.canv; s=self.size
        c.setFillColor(GREEN); c.roundRect(0,0,s,s,12,fill=1,stroke=0)
        c.setFillColor(colors.white); c.setFont("NanumB", 26)
        c.drawCentredString(s/2, s/2-9, "C&")

def col_widths(ncol, total=170*mm):
    if ncol==2: return [45*mm, total-45*mm]
    if ncol==3: return [38*mm, 26*mm, total-64*mm]
    return [total/ncol]*ncol

def make_table(dxt):
    rows=[]
    for r in dxt.rows:
        rows.append([c.text.strip() for c in r.cells])
    ncol=len(rows[0]); cw=col_widths(ncol)
    data=[[Paragraph(html.escape(c), S_CELLB) for c in rows[0]]]
    for r in rows[1:]:
        data.append([Paragraph(html.escape(c), S_CELL) for c in r])
    tb=Table(data, colWidths=cw, repeatRows=1)
    sty=[("BACKGROUND",(0,0),(-1,0),NAVY),("VALIGN",(0,0),(-1,-1),"MIDDLE"),
         ("TOPPADDING",(0,0),(-1,-1),4),("BOTTOMPADDING",(0,0),(-1,-1),4),
         ("LEFTPADDING",(0,0),(-1,-1),6),("RIGHTPADDING",(0,0),(-1,-1),6),
         ("GRID",(0,0),(-1,-1),0.3,colors.HexColor("#dddddd"))]
    for i in range(1,len(data)):
        if i%2==0: sty.append(("BACKGROUND",(0,i),(-1,i),colors.HexColor("#eef1f5")))
    tb.setStyle(TableStyle(sty)); return tb

def build():
    d=Document(SRC)
    flow=[]
    # 표지
    flow.append(Spacer(1,120)); flow.append(Logo(66)); flow.append(Spacer(1,18))
    flow.append(Paragraph("Care&amp; AI 시스템 설계 문서", S_CTITLE)); flow.append(Spacer(1,4))
    flow.append(Paragraph("통합본 — 표지 · 목차 · 요약", S_CSUB)); flow.append(Spacer(1,3))
    flow.append(Paragraph("플랫폼 시스템 개요 + 서비스 도메인 통합(멀티도메인) + 돌봄 플랫폼 AI 서비스(FastAPI) 5개 모듈 설계 모음", S_CDESC))
    flow.append(Spacer(1,3))
    flow.append(Paragraph("caren.aiclaude.kr · 2026-06-27 · v1.0", S_CMETA))
    flow.append(PageBreak())
    # 본문: '문서 소개'부터
    started=False
    for child in d.element.body.iterchildren():
        if child.tag.endswith('}p'):
            p=DxP(child,d); t=p.text
            if not started:
                if t.strip()=="문서 소개": started=True
                else: continue
            sn=p.style.name
            if sn=="Heading 1": flow.append(Paragraph(runs_markup(p), S_H1))
            elif sn=="Heading 2": flow.append(Paragraph(runs_markup(p), S_H2))
            elif sn=="Title": continue
            else:
                s=t.strip()
                if s=="": flow.append(Spacer(1,3))
                elif s.startswith("•"):
                    flow.append(Paragraph(html.escape(s.lstrip("• ").strip()), S_BULLET, bulletText="•"))
                else:
                    flow.append(Paragraph(runs_markup(p), S_BODY))
        elif child.tag.endswith('}tbl'):
            if started:
                flow.append(make_table(DxT(child,d))); flow.append(Spacer(1,5))
    return flow

def footer(canvas, doc):
    if doc.page==1: return
    canvas.saveState(); canvas.setFont("Nanum",7.5); canvas.setFillColor(GREY)
    canvas.drawString(20*mm,12*mm,"Care& AI 시스템 설계 문서 — 통합본")
    canvas.drawRightString(190*mm,12*mm,"p.%d"%doc.page); canvas.restoreState()

doc=SimpleDocTemplate(OUT, pagesize=A4, leftMargin=20*mm, rightMargin=20*mm,
                      topMargin=16*mm, bottomMargin=18*mm, title="Care& AI 시스템 설계 문서 (통합본)")
doc.build(build(), onFirstPage=footer, onLaterPages=footer)
print("wrote", OUT)
