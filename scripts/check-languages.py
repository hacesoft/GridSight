#!/usr/bin/env python3
"""Audit all shipped Nextcloud catalogs and literal frontend/PHP translation calls."""
from pathlib import Path
import ast,json,re,subprocess,sys
root=Path(__file__).resolve().parents[1]
src=root/'src';locales=['cs','en','de','es','fr','it','nl','pl','pt','sk','uk']
app_id=re.search(r'<id>(.*?)</id>',(src/'appinfo/info.xml').read_text())[1]
tables={}
for locale in locales:
 p=src/'l10n'/f'{locale}.json';obj=json.loads(p.read_text());tables[locale]=obj['translations']
 assert obj.get('pluralForm'),f'{locale}: missing plural form'
source=tables['en'];keys=set(source)
placeholder=re.compile(r'%(?:\d+\$)?[sd]|\{[A-Za-z_]\w*\}')
for locale,table in tables.items():
 assert set(table)==keys,f'{locale}: missing/extra keys {keys-set(table)} {set(table)-keys}'
 for key,value in table.items():
  assert isinstance(value,str) and value.strip(),f'{locale}: empty {key}'
  assert sorted(placeholder.findall(key))==sorted(placeholder.findall(value)),f'{locale}: changed placeholders in {key}: {value}'
 # Nextcloud JS registration must preserve every JSON value (including emoji and escaping).
 js=src/'l10n'/f'{locale}.js'
 code="const fs=require('node:fs'),vm=require('node:vm');let result;vm.runInNewContext(fs.readFileSync(process.argv[1],'utf8'),{OC:{L10N:{register:(app,translations,pluralForm)=>{result={app,translations,pluralForm}}}}});process.stdout.write(JSON.stringify(result));"
 registered=json.loads(subprocess.check_output(['node','-e',code,str(js)],text=True))
 assert registered['app']==app_id,f'{locale}: wrong registration domain'
 assert registered['translations']==table,f'{locale}: JS/JSON mismatch'
 assert registered['pluralForm']==json.loads((src/'l10n'/f'{locale}.json').read_text())['pluralForm']
# English source keys are the Nextcloud fallback when a language/entry is missing.
patterns=[re.compile(r"\bt\(\s*(?:APP|['\"]"+re.escape(app_id)+r"['\"])\s*,\s*(['\"])((?:\\.|(?!\1).)*?)\1"),re.compile(r"->t\(\s*(['\"])((?:\\.|(?!\1).)*?)\1"),re.compile(r"\bfnGuardTranslate\(\s*(['\"])((?:\\.|(?!\1).)*?)\1")]
used=set()
for p in src.rglob('*'):
 if not p.is_file() or p.suffix not in {'.php','.js'} or 'l10n' in p.parts:continue
 text=p.read_text()
 for pattern in patterns:
  for match in pattern.finditer(text):
   key=ast.literal_eval(match[1]+match[2]+match[1]);used.add(key)
   assert key in source,f'{p.relative_to(root)}: untranslated source key {key}'
assert 'Save' in source
assert tables['de']['Save']=='Speichern'
assert tables['pt']['Save']=='Guardar'
assert tables['uk']['Save']=='Зберегти'
print(f'{app_id}: PASS — {len(locales)} locales, {len(keys)} keys each, {len(used)} literal source keys; JS registration and placeholders checked')
