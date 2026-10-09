#!/usr/bin/env python3
"""Prepare an exact registered-root manifest from trusted disk/config and official core checksums."""
import argparse,pathlib,json,hashlib,re,urllib.request,urllib.parse,os
parser=argparse.ArgumentParser();parser.add_argument('--state',required=True);parser.add_argument('--root',required=True);parser.add_argument('--root-id',required=True);parser.add_argument('--platform',required=True,type=int);parser.add_argument('--siteurl',required=True);parser.add_argument('--home',required=True);parser.add_argument('--path',action='append',default=[]);parser.add_argument('--wp-cli',default='/usr/local/bin/wp');parser.add_argument('--apply',action='store_true');args=parser.parse_args()
root=pathlib.Path(args.root);state=pathlib.Path(args.state)
if str(root.resolve(strict=True))!=str(root) or str(state.resolve(strict=True))!=str(state) or state.is_relative_to(root):raise SystemExit('Canonical private state outside the served root required.')
if os.stat(state).st_mode&0o077:raise SystemExit('State must be 0700.')
for url in (args.siteurl,args.home):
    parsed=urllib.parse.urlparse(url)
    if parsed.scheme not in ('http','https') or parsed.path not in ('','/') or not parsed.hostname:raise SystemExit('Exact single-site root URLs required.')
config=(root/'wp-config.php').read_text();db=re.search(r"define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([A-Za-z0-9_]+)['\"]",config);prefix=re.search(r"\$table_prefix\s*=\s*['\"]([A-Za-z0-9_]+)['\"]",config)
version=re.search(r"\$wp_version\s*=\s*['\"]([0-9.]+)['\"]",(root/'wp-includes/version.php').read_text())
if not db or not prefix or not version:raise SystemExit('Static schema/prefix/core identity required; no PHP will be evaluated.')
url='https://api.wordpress.org/core/checksums/1.0/?'+urllib.parse.urlencode({'version':version[1],'locale':'en_US'})
with urllib.request.urlopen(url,timeout=15) as response:checks=json.load(response).get('checksums',{})
if not checks:raise SystemExit('Official core checksum inventory unavailable; no registration written.')
for path in args.path:
    parts=path.split('/')
    if path.startswith('/') or any(p in ('','..','.') for p in parts) or path in checks or path=='wp-config.php' or path.startswith('wp-content/'):raise SystemExit('Expected core/config or unsupported path refused.')
manifest=state/'roots.json';roots=json.loads(manifest.read_text()) if manifest.exists() else {}
if args.root_id in roots:raise SystemExit('Existing registration will not be overwritten; review an explicit root update separately.')
roots[args.root_id]={'path':str(root),'platform_id':args.platform,'schema':db[1],'prefix':prefix[1],'siteurl':args.siteurl,'home':args.home,'wp_config_sha256':hashlib.sha256(config.encode()).hexdigest(),'core_paths':sorted(checks),'core_version':version[1],'quarantine_paths':args.path,'quarantine_enabled':False,'wp_cli':args.wp_cli}
if not args.apply:print('DRY-RUN: exact registered root and approved unexpected paths validated. Nothing written.');raise SystemExit(0)
file=state/'roots.json.new';fd=os.open(file,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
with os.fdopen(fd,'w') as stream:json.dump(roots,stream,indent=2);stream.flush();os.fsync(stream.fileno())
os.replace(file,manifest);fd=os.open(state,os.O_RDONLY|os.O_DIRECTORY);os.fsync(fd);os.close(fd)
print('Registered exact root. Quarantine stays Off; no site files were changed.')
