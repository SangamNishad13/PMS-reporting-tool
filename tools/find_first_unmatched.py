import sys,re

def extract_script_blocks(text):
    pattern = re.compile(r"<script[^>]*>(.*?)</script>", re.DOTALL | re.IGNORECASE)
    return [m.group(1) for m in pattern.finditer(text)]


def find_first_unmatched(js):
    stack=[]
    pairs={'{':'}','(':')','[':']'}
    inv={v:k for k,v in pairs.items()}
    i=0; L=len(js)
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
        if c=='/' and i+1<L:
            j=i-1
            while j>=0 and js[j].isspace(): j-=1
            prev = js[j] if j>=0 else ''
            if prev in '(=:[,!?{;':
                i+=1
                while i<L:
                    if js[i]=='\\': i+=2; continue
                    if js[i]=='/': i+=1; break
                    i+=1
                continue
        if c in pairs:
            stack.append((c,i))
        elif c in inv:
            if stack and stack[-1][0]==inv[c]: stack.pop()
            else:
                return ('unmatched-closing', c, i)
        i+=1
    if stack:
        return ('unmatched-opening', stack[-1][0], stack[-1][1])
    return ('ok', None, None)


if __name__=='__main__':
    if len(sys.argv)<2:
        print('Usage: find_first_unmatched.py <file>')
        sys.exit(1)
    txt=open(sys.argv[1],'r',encoding='utf-8',errors='ignore').read()
    blocks=extract_script_blocks(txt)
    for n,js in enumerate(blocks, start=1):
        res=find_first_unmatched(js)
        print('Block',n,':',res[0],res[1],res[2])
        if res[0]!='ok':
            pos=res[2]
            start=max(0,pos-120)
            end=min(len(js), pos+120)
            snippet=js[start:end]
            print('---context---\n'+snippet+'\n---end---')
            break
