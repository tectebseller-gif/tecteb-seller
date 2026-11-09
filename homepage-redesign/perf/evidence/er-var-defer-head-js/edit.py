# defer on every head <script src> except Tecteb's own (order kept; header.js already defer). Attribution only.
import sys,re; h=sys.stdin.read(); head,sep,body=h.partition("</head>"); n=0
def f(m):
    global n
    t=m.group(0)
    if 'tecteb' in t or ' defer' in t or ' async' in t: return t
    n+=1; return t.replace('<script ','<script defer ',1)
head=re.sub(r'<script[^>]*\bsrc=[^>]*>',f,head); assert n==2,n; sys.stdout.write(head+sep+body)
