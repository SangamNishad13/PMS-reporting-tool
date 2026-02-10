import sys, re

def extract_script_blocks(text):
    blocks = []
    pattern = re.compile(r"<script[^>]*>(.*?)</script>", re.DOTALL | re.IGNORECASE)
    for m in pattern.finditer(text):
        blocks.append((m.start(1), m.group(1)))
    return blocks

# simple stateful parser to ignore strings and comments

def analyze_js(js):
    stack = []
    pairs = {'{': '}', '(': ')', '[': ']'}
    inv = {v: k for k,v in pairs.items()}
    i = 0
    L = len(js)
    errors = []
    while i < L:
        c = js[i]
        # handle strings
        if c == '"' or c == "'" or c == '`':
            q = c
            i += 1
            while i < L:
                if js[i] == '\\':
                    i += 2
                    continue
                if js[i] == q:
                    i += 1
                    break
                i += 1
            continue
        # line comment
        if c == '/' and i+1 < L and js[i+1] == '/':
            i += 2
            while i < L and js[i] != '\n': i += 1
            continue
        # block comment
        if c == '/' and i+1 < L and js[i+1] == '*':
            i += 2
            while i+1 < L and not (js[i] == '*' and js[i+1] == '/'):
                i += 1
            i += 2
            continue
        # regex literal detection is hard; naive heuristic: if previous non-space char is one of (=, :, (, , , [, !, ?, {, ;)
        if c == '/' and i+1 < L:
            # find previous non-space
            j = i-1
            while j >=0 and js[j].isspace(): j -= 1
            prev = js[j] if j>=0 else ''
            if prev in '(=:[,!?{;':
                # treat as regex literal, skip until next unescaped /
                i += 1
                while i < L:
                    if js[i] == '\\': i += 2; continue
                    if js[i] == '/': i += 1; break
                    i += 1
                continue
        # braces
        if c in pairs:
            stack.append((c, i))
        elif c in inv:
            if stack and stack[-1][0] == inv[c]:
                stack.pop()
            else:
                errors.append((i, 'unmatched closing ' + c))
        i += 1
    if stack:
        for s in stack:
            errors.append((s[1], 'unmatched opening ' + s[0]))
    return errors


def main():
    if len(sys.argv) < 2:
        print('Usage: check_js_braces.py <file>')
        return
    path = sys.argv[1]
    txt = open(path, 'r', encoding='utf-8', errors='ignore').read()
    blocks = extract_script_blocks(txt)
    if not blocks:
        print('No <script> blocks found in', path)
        return
    any_err = False
    for idx, (pos, js) in enumerate(blocks):
        errs = analyze_js(js)
        print('\nScript block #%d (approx file offset %d): %d chars, %d issues' % (idx+1, pos, len(js), len(errs)))
        if errs:
            any_err = True
            for (p, msg) in errs[:10]:
                # show context around p
                start = max(0, p-40)
                end = min(len(js), p+40)
                snippet = js[start:end].replace('\n','\\n')
                print('  At pos %d: %s -- ...%s...' % (p, msg, snippet))
    if not any_err:
        print('\nNo obvious unmatched braces/parentheses/brackets found in script blocks.')

if __name__ == '__main__':
    main()
