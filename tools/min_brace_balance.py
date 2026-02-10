import sys,re

def extract_script_blocks(text):
    pattern = re.compile(r"<script[^>]*>(.*?)</script>", re.DOTALL | re.IGNORECASE)
    return [m.group(1) for m in pattern.finditer(text)]


def compute(js):
    i=0; L=len(js)
    count=0
    events=[]
    while i<L:
        c=js[i]
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
        if c=='{': count+=1; events.append((i,'{',count))
        elif c=='}': count-=1; events.append((i,'}',count))
        i+=1
    return events

if __name__=='__main__':
    if len(sys.argv)<2:
        print('Usage: min_brace_balance.py <file>')
        sys.exit(1)
    text=open(sys.argv[1],'r',encoding='utf-8',errors='ignore').read()
    blocks=extract_script_blocks(text)
    js=blocks[2]
    events=compute(js)
    if not events:
        print('no events')
        sys.exit(0)
    min_count=min(e[2] for e in events)
    print('min_count',min_count)
    spots=[e for e in events if e[2]==min_count]
    for pos,ch,cnt in spots[:10]:
        print('pos',pos,'char',ch,'cnt',cnt)
    # show around first minimal occurrence
    pos=spots[0][0]
    start=max(0,pos-120); end=min(len(js),pos+120)
    print('\ncontext around first min:\n')
    print(js[start:end])
