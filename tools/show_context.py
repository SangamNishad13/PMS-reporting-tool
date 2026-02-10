import sys
if len(sys.argv)<2:
    print('Usage: show_context.py <pos>')
    sys.exit(1)
pos=int(sys.argv[1])
js=open('c:\\\\xampp\\\\htdocs\\\\PMS\\\\tools\\\\block3.js','r',encoding='utf-8',errors='ignore').read()
start=max(0,pos-120)
end=min(len(js),pos+120)
print('Context around',pos, '...')
print('\n---BEGIN---\n')
print(js[start:end])
print('\n---END---\n')
