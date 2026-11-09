# every head stylesheet that is not Tecteb's loads without blocking (media=print + onload) — the upper bound of what
# RUCSS / async CSS could ever give. Attribution only: unstyled first paint for theme/Woo/Elementor parts is expected.
import sys,re; h=sys.stdin.read(); head,sep,body=h.partition("</head>"); n=0
def f(m):
    global n
    t=m.group(0)
    if 'tecteb' in t: return t
    n+=1; return re.sub(r"media='all'","media='print' onload=\"this.media='all'\"",t)
head=re.sub(r"<link[^>]*rel='stylesheet'[^>]*>",f,head); assert n>=10,n; sys.stdout.write(head+sep+body)
