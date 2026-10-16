"""Conservative hygiene gate. Manual review remains mandatory."""
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
source=[p for p in root.rglob('*') if p.is_file() and p.suffix in {'.php','.js','.css'}]
patterns={
 'embedded private storefront':r'(?i)https?://[a-z0-9.-]+\.se(?=/|\b)',
 'embedded real email':r'(?i)\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b',
 'hard-coded Telegram token':r'\b[0-9]{8,12}:[A-Za-z0-9_-]{35,}\b',
 'private key material':r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
 'sensitive environment name':r'(?i)(?:TELEGRAM_BOT_TOKEN|WC_CONSUMER_SECRET)\s*[=:]\s*["\']?[A-Za-z0-9_-]{15,}',
}
errors=[]
for p in source:
 s=p.read_text(encoding='utf-8')
 for label,pattern in patterns.items():
  if re.search(pattern,s):errors.append(f'{p.relative_to(root)}: {label}')
if errors: raise SystemExit('Source hygiene failure:\n'+'\n'.join(errors))
print(f'Hygiene checks passed for {len(source)} source files')
