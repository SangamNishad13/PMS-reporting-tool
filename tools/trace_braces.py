import sys,re

def extract_script_blocks(text):
    pattern = re.compile(r"<script[^>]*>(.*?)</script>", re.DOTALL | re.IGNORECASE)
    return [m.group(1) for m in pattern.finditer(text)]


def trace(js):
    i=0; L=len(js)
    count=0
    out=[]
    while i<L:
        c=js[i]
        # skip strings
        if c in ('"', "'", '`'):
            q=c; i+=1
            while i<L:
                if js[i]=='\\': i+=2; continue
                if js[i]==q: i+=1; break
                i+=1
            continue
        if c=='/' and i+1<L and js[i+1]=='/':
            i+=2
            while i<L and js[i]!='\n': i+=1
            continue
        if c=='/' and i+1<L and js[i+1]=='*':
            i+=2
            while i+1<L and not (js[i]=='*' and js[i+1]=='/'): i+=1
            i+=2
            continue
        if c=='{':
            count+=1
            out.append((i,'{',count))
        elif c=='}':
            count-=1
            out.append((i,'}',count))
        i+=1
    return out

if __name__=='__main__':
    if len(sys.argv)<2:
        print('Usage: trace_braces.py <file>')
        sys.exit(1)
    txt=open(sys.argv[1],'r',encoding='utf-8',errors='ignore').read()
    blocks=extract_script_blocks(txt)
    if len(blocks)<3:
        print('need at least 3 script blocks, found',len(blocks))
        sys.exit(1)
    js=blocks[2]
    events=trace(js)
    # find first event where count<0
    for idx,(pos,ch,cnt) in enumerate(events):
        if cnt<0:
            print('Negative balance at event',idx,'pos',pos,'char',ch,'count',cnt)
            start=max(0,pos-120); end=min(len(js),pos+120)
            print('---context---')
            print(js[start:end])
            print('---end---')
            break
    else:
        print('No negative balance found; final count', events[-1][2] if events else 0)
    # also print last 20 events
    print('\nLast 20 brace events:')
    for pos,ch,cnt in events[-20:]:
        print(pos, ch, cnt)
