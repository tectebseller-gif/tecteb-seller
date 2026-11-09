# all head <script src> moved to end of body with defer — attribution only (jQuery-dependent inline code would break).
import sys,re; h=sys.stdin.read(); head,sep,body=h.partition("</head>")
scripts=re.findall(r'<script[^>]*\bsrc=[^>]*></script>\n?',head)
for s in scripts: head=head.replace(s,'')
sys.stdout.write(head+sep+body.replace('</body>',''.join(scripts)+'</body>'))
