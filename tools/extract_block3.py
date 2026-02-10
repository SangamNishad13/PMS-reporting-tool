import re,sys
p='c:\\xampp\\htdocs\\PMS\\modules\\projects\\view.php'
t=open(p,'r',encoding='utf-8',errors='ignore').read()
blocks=list(re.finditer(r'<script[^>]*>(.*?)</script>', t, re.DOTALL|re.IGNORECASE))
if len(blocks)<3:
    print('less than 3')
    sys.exit(1)
js=blocks[2].group(1)
open('c:\\xampp\\htdocs\\PMS\\tools\\block3.js','w',encoding='utf-8').write(js)
print('wrote block3.js, %d chars' % len(js))
