# همان، برای هر دو CSS ِ تک‌طب (header.css و tecteb-home.css).
import sys,re; h=sys.stdin.read(); n=0
def f(m):
    global n; n+=1; return m.group(1)+"media='print' onload=\"this.media='all'\""
h2=re.sub(r"(<link[^>]*id='(?:tecteb-home|tecteb-header-base)-css'[^>]*)media='all'",f,h); assert n==2,n; sys.stdout.write(h2)
