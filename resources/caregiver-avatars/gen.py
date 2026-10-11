# 돌봄전문가 캐릭터 8종 SVG (512x512) — 둥근 배경 + 유니폼 상반신 + 얼굴 + 머리 모양
import os, sys
OUT = sys.argv[1]
SKIN = {'a': ('#F6D3B8', '#E9B896'), 'b': ('#E8B892', '#D49A72'), 'c': ('#C98E66', '#B07650'), 'd': ('#FBE0CC', '#F0C7A9')}
CH = [
  # key, label, bg, uniform, collar, skin, hair color, hair style, extra
  ('c1', '단발 · 코랄', '#FDEBE4', '#D5603E', '#FFFFFF', 'a', '#3B2A22', 'bob', 'heart'),
  ('c2', '숏컷 · 초록', '#E6F2EA', '#3F7D52', '#FFFFFF', 'b', '#2B211C', 'short', 'cross'),
  ('c3', '묶은 머리 · 하늘', '#E7F0FB', '#2F64B5', '#FFFFFF', 'd', '#5A3A28', 'bun', 'heart'),
  ('c4', '곱슬 · 보라', '#EEEAFB', '#5B4BC4', '#FFFFFF', 'c', '#1F1714', 'curly', 'cross'),
  ('c5', '은발 · 베이지', '#F6F0E6', '#9A7B4F', '#FFFFFF', 'a', '#B9B4AE', 'silverbun', 'heart'),
  ('c6', '포니테일 · 민트', '#E3F1F1', '#2E7D80', '#FFFFFF', 'b', '#3B2A22', 'pony', 'cross'),
  ('c7', '가르마 · 남색', '#E8ECF5', '#33456B', '#FFFFFF', 'd', '#2B211C', 'side', 'heart'),
  ('c8', '안경 · 주황', '#FFF1DE', '#C77A1F', '#FFFFFF', 'c', '#2B211C', 'shortglasses', 'cross'),
]
def hair_back(style, col):
  if style == 'bob': return f'<path d="M150 250 Q150 120 256 112 Q362 120 362 250 L362 330 Q330 350 300 330 L212 330 Q182 350 150 330 Z" fill="{col}"/>'
  if style == 'pony': return f'<path d="M330 190 Q420 230 380 340 Q360 300 340 260 Z" fill="{col}"/>'
  if style == 'curly': return ''.join(f'<circle cx="{x}" cy="{y}" r="44" fill="{col}"/>' for x, y in [(170,210),(200,160),(256,140),(312,160),(342,210),(160,262),(352,262)])
  return ''
def hair_front(style, col):
  if style == 'bob': return f'<path d="M168 220 Q176 140 256 136 Q336 140 344 220 Q300 176 256 184 Q220 190 190 214 Z" fill="{col}"/>'
  if style == 'short' or style == 'shortglasses': return f'<path d="M166 232 Q160 136 256 126 Q352 136 346 232 Q330 180 296 172 Q256 196 216 178 Q182 190 166 232 Z" fill="{col}"/>'
  if style == 'bun': return f'<circle cx="256" cy="112" r="40" fill="{col}"/><path d="M168 226 Q172 144 256 138 Q340 144 344 226 Q318 178 256 176 Q194 178 168 226 Z" fill="{col}"/>'
  if style == 'silverbun': return f'<circle cx="256" cy="110" r="38" fill="{col}"/><path d="M166 236 Q170 142 256 136 Q342 142 346 236 Q324 184 256 182 Q188 184 166 236 Z" fill="{col}"/>'
  if style == 'curly': return ''.join(f'<circle cx="{x}" cy="{y}" r="28" fill="{col}"/>' for x, y in [(196,186),(232,170),(270,168),(306,180),(330,206)])
  if style == 'pony': return f'<path d="M168 228 Q170 140 256 134 Q342 140 344 228 Q316 172 250 178 Q200 186 168 228 Z" fill="{col}"/>'
  if style == 'side': return f'<path d="M166 236 Q166 134 262 130 Q350 136 346 226 Q316 168 232 176 Q196 190 166 236 Z" fill="{col}"/><path d="M232 176 Q240 150 262 132" stroke="{col}" stroke-width="10" fill="none"/>'
  return ''
def badge(kind, uni):
  if kind == 'heart': return f'<g transform="translate(300 418)"><rect x="-26" y="-20" width="52" height="40" rx="8" fill="#FFFFFF"/><path d="M0 12 C-18 0 -14 -14 -5 -12 C-1 -11 0 -7 0 -6 C0 -7 1 -11 5 -12 C14 -14 18 0 0 12 Z" fill="{uni}"/></g>'
  return f'<g transform="translate(300 418)"><rect x="-26" y="-20" width="52" height="40" rx="8" fill="#FFFFFF"/><rect x="-4" y="-12" width="8" height="24" rx="2" fill="{uni}"/><rect x="-12" y="-4" width="24" height="8" rx="2" fill="{uni}"/></g>'
def svg(c):
  key, label, bg, uni, collar, sk, hc, style, bdg = c
  skin, shade = SKIN[sk]
  glasses = '<g fill="none" stroke="#3A352B" stroke-width="6"><circle cx="222" cy="246" r="22"/><circle cx="290" cy="246" r="22"/><path d="M244 246 L268 246"/></g>' if style == 'shortglasses' else ''
  return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
<title>{label}</title>
<rect width="512" height="512" fill="{bg}"/>
<circle cx="256" cy="256" r="230" fill="#FFFFFF" opacity="0.55"/>
{hair_back(style, hc)}
<path d="M96 512 Q100 380 200 352 L312 352 Q412 380 416 512 Z" fill="{uni}"/>
<path d="M214 352 L256 404 L298 352 Z" fill="{collar}"/>
<rect x="228" y="300" width="56" height="60" rx="20" fill="{shade}"/>
<ellipse cx="256" cy="244" rx="92" ry="104" fill="{skin}"/>
<ellipse cx="166" cy="252" rx="16" ry="22" fill="{skin}"/><ellipse cx="346" cy="252" rx="16" ry="22" fill="{skin}"/>
{hair_front(style, hc)}
<path d="M206 222 Q222 212 238 222" stroke="#3A352B" stroke-width="6" stroke-linecap="round" fill="none"/>
<path d="M274 222 Q290 212 306 222" stroke="#3A352B" stroke-width="6" stroke-linecap="round" fill="none"/>
<ellipse cx="222" cy="248" rx="9" ry="11" fill="#3A352B"/><ellipse cx="290" cy="248" rx="9" ry="11" fill="#3A352B"/>
<circle cx="225" cy="244" r="3" fill="#FFFFFF"/><circle cx="293" cy="244" r="3" fill="#FFFFFF"/>
{glasses}
<ellipse cx="200" cy="284" rx="18" ry="11" fill="#F29C8A" opacity="0.55"/><ellipse cx="312" cy="284" rx="18" ry="11" fill="#F29C8A" opacity="0.55"/>
<path d="M232 292 Q256 314 280 292" stroke="#B5523A" stroke-width="7" stroke-linecap="round" fill="none"/>
{badge(bdg, uni)}
</svg>'''
os.makedirs(OUT, exist_ok=True)
for c in CH:
  open(f'{OUT}/{c[0]}.svg', 'w', encoding='utf-8').write(svg(c))
print('\n'.join(f'{c[0]}\t{c[1]}' for c in CH))
