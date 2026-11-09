import sys,re; h=sys.stdin.read(); n=len(re.findall(r'<link rel="preload"[^>]*as="font"[^>]*>\n?',h)); assert n==1,n; sys.stdout.write(re.sub(r'<link rel="preload"[^>]*as="font"[^>]*>\n?','',h))
