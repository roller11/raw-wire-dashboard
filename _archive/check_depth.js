const fs = require('fs');
const c = fs.readFileSync('d:/00-EQUALIZER/raw-wire-equalizer/wordpress-plugins/raw-wire-dashboard/templates/news-aggregator.template.json', 'utf8');

let depth = 0;
let inString = false;

for (let i = 0; i < c.length; i++) {
    const ch = c[i];
    const prev = c[i - 1];
    
    if (ch === '"' && prev !== '\\') {
        inString = !inString;
        continue;
    }
    
    if (!inString) {
        if (ch === '{' || ch === '[') {
            depth++;
        } else if (ch === '}' || ch === ']') {
            depth--;
            if (depth === 0 && i < c.length - 10) {
                console.log(`Depth hit 0 at position ${i}, char: ${JSON.stringify(ch)}`);
                console.log(`Context: ${JSON.stringify(c.substring(Math.max(0, i - 30), i + 30))}`);
            }
        }
    }
}

console.log('Final depth:', depth);
