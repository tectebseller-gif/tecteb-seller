import sys,re; h=sys.stdin.read(); pat=r"<link[^>]*id='woocommerce-(layout|smallscreen|general)[^']*'[^>]*>\n?"; n=len(re.findall(pat,h)); assert n==3,n; sys.stdout.write(re.sub(pat,'',h))
