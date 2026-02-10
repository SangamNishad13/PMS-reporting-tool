import sys,re
if len(sys.argv)<2:
    print('Usage: dump_positions.py <file>')
    sys.exit(1)
text=open(sys.argv[1],'r',encoding='utf-8',errors='ignore').read()
blocks = list(re.finditer(r'<script[^>]*>(.*?)</script>', text, re.DOTALL|re.IGNORECASE))
if len(blocks)<3:
    print('Need at least 3 script blocks')
    sys.exit(1)
js = blocks[2].group(1)
for p in range(58200,58220):
    ch = js[p]
    print(p, repr(ch), ord(ch))
print('\nSNIPPET:\n')
print(js[58190:58220])
