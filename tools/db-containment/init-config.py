#!/usr/bin/env python3
"""Generate independent private signing material locally; all action switches stay Off."""
import argparse, pathlib, json, secrets, os, base64
parser=argparse.ArgumentParser();parser.add_argument('directory');parser.add_argument('--platform',required=True,type=int);args=parser.parse_args()
folder=pathlib.Path(args.directory).absolute()
if folder.exists(): raise SystemExit('Use a new private directory; existing material will not be overwritten.')
folder.mkdir(mode=0o700,parents=True)
def write(name,data):
    fd=os.open(folder/name,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(fd,'w') as stream:stream.write(data)
cache=secrets.token_hex(32);ssh=secrets.token_hex(32)
write('crm-env.txt','DB_CONTAINMENT_ENABLED=false\nDB_CONTAINMENT_FILESYSTEM_ENABLED=false\nDB_CONTAINMENT_QUARANTINE_ENABLED=false\nDB_CONTAINMENT_KEY_VERSION=1\nDB_CONTAINMENT_BACKUP_KEY=base64:'+base64.b64encode(secrets.token_bytes(32)).decode()+'\n')
write('market.json',json.dumps({'enabled':False,'filesystem_enabled':False,'quarantine_enabled':False,'configuration':{'cache_secret':cache,'cache_key_version':'1','cache_runtime_trusted':False,'session_canary_verified_at':None,'protected_emails':[],'catalog_user':'','catalog_password':'','ssh':{'host':'','user':'','key_path':'','known_hosts':'','root_id':'','signing_secret':ssh,'site_identity':{}}}},indent=2))
write('wp-signing.php',"<?php\ndefine('EXOTIC_DB_CONTAINMENT_PLATFORM_ID', "+str(args.platform)+");\ndefine('EXOTIC_DB_CONTAINMENT_KEY_VERSION', '1');\ndefine('EXOTIC_DB_CONTAINMENT_KEY', '"+cache+"');\n")
write('signing.key',ssh+'\n')
print('Private configuration generated. No secret values printed; no services changed. Copy only the intended site/account material.')
