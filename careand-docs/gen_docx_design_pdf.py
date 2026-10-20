#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""개별 Care& 설계서 docx → PDF (reportlab + NanumGothic). 범용.
표지=세로중앙(초록 C& 로고 + Title + 첫 Heading 전 Normal문단들[부제/메타]) + 본문(첫 Heading부터).
헤딩=네이비 H1/그린 H2, 표=네이비헤더+교대음영, '•'접두=불릿.
사용: python gen_docx_design_pdf.py <docx> <out.pdf> [footer라벨]"""
import sys, html
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

NAVY = colors.HexColor("#20304f"); GREEN = colors.HexColor("#3f9e7f")
GREEN_D = colors.HexColor("#2f7d63"); GREY = colors.HexColor("#666666")

def st(name, **kw):
    base = dict(fontName="Nanum", fontSize=9.5, leading=14, textColor=colors.HexColor("#222222"), alignment=TA_LEFT)
    base.update(kw); return ParagraphStyle(name, **base)

S_H1 = st("h1", fontName="NanumB", fontSize=14.5, leading=19, textColor=NAVY, spaceBefore=13, spaceAfter=6)
S_H2 = st("h2", fontName="NanumB", fontSize=11, leading=15, textColor=GREEN_D, spaceBefore=8, spaceAfter=3)
S_BODY = st("body", spaceAfter=3)
S_BULLET = st("bullet", leftIndent=13, bulletIndent=2, spaceAfter=2.5)
S_CELL = st("cell", fontSize=8.7, leading=12)
S_CELLB = st("cellb", fontName="NanumB", fontSize=8.7, leading=12, textColor=colors.white)
S_CTITLE = st("ctitle", fontName="NanumB", fontSize=25, leading=31, textColor=NAVY, alignment=TA_CENTER)
S_CSUB = st("csub", fontName="NanumB", fontSize=12, leading=17, textColor=GREEN_D, alignment=TA_CENTER)
S_CMETA = st("cmeta", fontName="Nanum", fontSize=8.5, leading=12.5, textColor=GREY, alignment=TA_CENTER)

def runs_markup(p):
    out=[]
    for r in p.runs:
        t=html.escape(r.text)
        if r.bold: t='<font name="NanumB">%s</font>'%t
        out.append(t)
    return "".join(out) or html.escape(p.text)

class Logo(Flowable):
    def __init__(self, size=66): self.size=size; self.width=size; self.height=size; self.hAlign="CENTER"
    def draw(self):
        c=self.canv; s=self.size
        c.setFillColor(GREEN); c.roundRect(0,0,s,s,12,fill=1,stroke=0)
        c.setFillColor(colors.white); c.setFont("NanumB",26); c.drawCentredString(s/2,s/2-9,"C&")

def col_widths(ncol, total=170*mm):
    if ncol==2: return [45*mm, total-45*mm]
    if ncol==3: return [38*mm, 26*mm, total-64*mm]
    if ncol>=5: return [total/ncol]*ncol
    return [total/ncol]*ncol

def make_table(dxt):
    rows=[[c.text.strip() for c in r.cells] for r in dxt.rows]
    if not rows: return Spacer(0,0)
    ncol=len(rows[0]); cw=col_widths(ncol)
    data=[[Paragraph(html.escape(c),S_CELLB) for c in rows[0]]]
    for r in rows[1:]: data.append([Paragraph(html.escape(c),S_CELL) for c in r])
    tb=Table(data, colWidths=cw, repeatRows=1)
    sty=[("BACKGROUND",(0,0),(-1,0),NAVY),("VALIGN",(0,0),(-1,-1),"MIDDLE"),
         ("TOPPADDING",(0,0),(-1,-1),4),("BOTTOMPADDING",(0,0),(-1,-1),4),
         ("LEFTPADDING",(0,0),(-1,-1),6),("RIGHTPADDING",(0,0),(-1,-1),6),
         ("GRID",(0,0),(-1,-1),0.3,colors.HexColor("#dddddd"))]
    for i in range(1,len(data)):
        if i%2==0: sty.append(("BACKGROUND",(0,i),(-1,i),colors.HexColor("#eef1f5")))
    tb.setStyle(TableStyle(sty)); return tb

def build(docx):
    d=Document(docx); flow=[]
    # 표지 문단 수집: 첫 Title + 이후 첫 Heading 전까지 Normal(비어있지 않은 것)
    title=None; cover_norms=[]; seen_title=False
    for p in d.paragraphs:
        sn=p.style.name
        if sn=="Title" and title is None: title=p.text.strip(); seen_title=True; continue
        if sn.startswith("Heading"): break
        if seen_title and p.text.strip(): cover_norms.append(p.text.strip())
    flow.append(Spacer(1,285)); flow.append(Logo()); flow.append(Spacer(1,16))
    flow.append(Paragraph(html.escape(title or ""), S_CTITLE)); flow.append(Spacer(1,4))
    if cover_norms:
        flow.append(Paragraph(html.escape(cover_norms[0]), S_CSUB))
        if len(cover_norms)>1:
            flow.append(Spacer(1,3))
            flow.append(Paragraph(html.escape(" · ".join(cover_norms[1:])), S_CMETA))
    flow.append(PageBreak())
    # 본문: 첫 Heading 1부터
    started=False
    for child in d.element.body.iterchildren():
        if child.tag.endswith('}p'):
            p=DxP(child,d); sn=p.style.name; t=p.text
            if not started:
                if sn=="Heading 1": started=True
                else: continue
            if sn=="Heading 1": flow.append(Paragraph(runs_markup(p),S_H1))
            elif sn=="Heading 2": flow.append(Paragraph(runs_markup(p),S_H2))
            elif sn=="Title": continue
            else:
                s=t.strip()
                if s=="": flow.append(Spacer(1,3))
                elif s.startswith("•"): flow.append(Paragraph(html.escape(s.lstrip("• ").strip()),S_BULLET,bulletText="•"))
                else: flow.append(Paragraph(runs_markup(p),S_BODY))
        elif child.tag.endswith('}tbl'):
            if started: flow.append(make_table(DxT(child,d))); flow.append(Spacer(1,5))
    return flow

def main():
    docx=sys.argv[1]; out=sys.argv[2]; label=sys.argv[3] if len(sys.argv)>3 else "Care& 설계 문서"
    def footer(canvas, doc):
        if doc.page==1: return
        canvas.saveState(); canvas.setFont("Nanum",7.5); canvas.setFillColor(GREY)
        canvas.drawString(20*mm,12*mm,label); canvas.drawRightString(190*mm,12*mm,"p.%d"%doc.page); canvas.restoreState()
    doc=SimpleDocTemplate(out, pagesize=A4, leftMargin=20*mm, rightMargin=20*mm,
                          topMargin=16*mm, bottomMargin=18*mm, title=label)
    doc.build(build(docx), onFirstPage=footer, onLaterPages=footer)
    print("wrote", out)

if __name__=="__main__": main()
