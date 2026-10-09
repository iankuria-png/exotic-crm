#!/usr/bin/env python3
"""Forced-command containment dispatcher. Fixed JSON catalog; no arbitrary shell.
Provision state/key/root manifest OUTSIDE every docroot. Run as the cPanel account.
"""
import ctypes, re
import os, sys, json, time, hashlib, hmac, stat, sqlite3, pathlib, subprocess, uuid, fcntl

class Refused(Exception): pass

def canonical(value): return json.dumps(value, separators=(',', ':'), sort_keys=True).encode()
def synced_write(path, value):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        blob = canonical(value)
        with os.fdopen(fd, 'wb', closefd=False) as stream: stream.write(blob); stream.flush(); os.fsync(stream.fileno())
    finally: os.close(fd)
    fd = os.open(os.path.dirname(path), os.O_RDONLY | os.O_DIRECTORY);os.fsync(fd);os.close(fd)

def rename_noreplace(source, destination, source_fd=None, destination_fd=None):
    # Linux renameat2 is atomic and refuses an occupied destination, including symlinks.
    libc=ctypes.CDLL(None, use_errno=True)
    function=getattr(libc,'renameat2',None)
    if function is None: raise Refused('atomic_noreplace_rename_unavailable')
    if function(source_fd if source_fd is not None else -100, os.fsencode(source), destination_fd if destination_fd is not None else -100, os.fsencode(destination), 1)!=0:
        raise OSError(ctypes.get_errno(), 'Atomic move refused')

def sync_dir(path):
    fd=os.open(path,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)

def fd_identity(fd, relative):
    before=os.fstat(fd)
    if not stat.S_ISREG(before.st_mode) or before.st_nlink!=1: raise Refused('regular_single_link_file_required')
    if before.st_size>32*1024*1024: raise Refused('file_size_limit')
    os.lseek(fd,0,os.SEEK_SET);digest=hashlib.sha256()
    while True:
        block=os.read(fd,65536)
        if not block: break
        digest.update(block)
    after=os.fstat(fd)
    if (before.st_dev,before.st_ino,before.st_nlink,before.st_size,before.st_mtime_ns,before.st_ctime_ns)!=(after.st_dev,after.st_ino,after.st_nlink,after.st_size,after.st_mtime_ns,after.st_ctime_ns): raise Refused('file_changed_while_reading')
    return {'path':relative,'dev':before.st_dev,'inode':before.st_ino,'size':before.st_size,'mode':stat.S_IMODE(before.st_mode),'uid':before.st_uid,'gid':before.st_gid,'mtime_ns':before.st_mtime_ns,'ctime_ns':before.st_ctime_ns,'sha256':digest.hexdigest(),'links':before.st_nlink}

def safe_path(path):
    parts = path.split('/')
    if not path or path.startswith('/') or any(p in ('', '.', '..') for p in parts): raise Refused('unsafe_relative_path')
    return parts

def open_parent(root, relative):
    parts = safe_path(relative); fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd);os.close(fd);fd = child
        return fd, parts[-1]
    except: os.close(fd);raise

def identity(root, relative):
    parent, name = open_parent(root, relative)
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        try:
            return fd_identity(fd, relative)
        finally: os.close(fd)
    finally: os.close(parent)

class Dispatcher:
    def __init__(self, directory):
        supplied=pathlib.Path(directory).absolute()
        self.state = supplied.resolve(strict=True)
        if supplied!=self.state: raise Refused('canonical_private_state_required')
        if stat.S_IMODE(self.state.stat().st_mode) & 0o077: raise Refused('private_state_permissions_required')
        self.secret = (self.state/'signing.key').read_bytes().strip()
        if len(self.secret) < 32: raise Refused('independent_signing_key_required')
        self.roots = json.loads((self.state/'roots.json').read_text())
        self.lock = os.open(self.state/'dispatcher.lock', os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW, 0o600);fcntl.flock(self.lock, fcntl.LOCK_EX)
        self.db = sqlite3.connect(self.state/'requests.sqlite')
        os.chmod(self.state/'requests.sqlite', 0o600)
        self.db.execute('PRAGMA synchronous=FULL')
        self.db.execute('CREATE TABLE IF NOT EXISTS ledger (id TEXT PRIMARY KEY, digest TEXT NOT NULL, status TEXT NOT NULL, result TEXT)')

    def root(self, identifier):
        cfg = self.roots.get(str(identifier))
        if not cfg: raise Refused('unregistered_root')
        path = pathlib.Path(cfg['path'])
        if path.is_symlink() or str(path.resolve(strict=True)) != str(path): raise Refused('canonical_root_required')
        for root in self.roots.values():
            if not isinstance(root,dict) or 'path' not in root: continue
            if self.state == pathlib.Path(root['path']) or self.state.is_relative_to(pathlib.Path(root['path'])): raise Refused('state_inside_document_root')
        for field in ('platform_id','schema','prefix','siteurl','home','wp_config_sha256','core_paths'):
            if not cfg.get(field): raise Refused('registered_identity_incomplete')
        config=path/'wp-config.php'
        if config.is_symlink() or hashlib.sha256(config.read_bytes()).hexdigest()!=cfg['wp_config_sha256']: raise Refused('registered_config_identity_changed')
        for target in cfg.get('quarantine_paths',[]):
            safe_path(target)
            if target in cfg['core_paths'] or target in ('wp-config.php','index.php') or target.startswith('wp-content/'): raise Refused('expected_or_unsupported_path')
        return str(path), cfg

    def request(self, envelope):
        req = envelope['request'];stamp = int(envelope['timestamp']);request_id = envelope['request_id'];uuid.UUID(request_id)
        signature = hmac.new(self.secret, str(stamp).encode()+b'\n'+request_id.encode()+b'\n'+canonical(req), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(signature, envelope['signature']): raise Refused('invalid_signature')
        digest = hashlib.sha256(canonical(req)).hexdigest()
        row = self.db.execute('SELECT digest,status,result FROM ledger WHERE id=?', (request_id,)).fetchone()
        if row:
            if row[0] != digest: raise Refused('request_id_payload_mismatch')
            if row[1] == 'complete': return json.loads(row[2])
            raise Refused('request_outcome_unknown_use_operation_status')
        if abs(time.time()-stamp) > 90: raise Refused('request_expired')
        self.db.execute('INSERT INTO ledger VALUES (?, ?, ?, NULL)', (request_id,digest,'pending'));self.db.commit()
        try: result = self.perform(req)
        except (Refused, OSError, ValueError, KeyError) as exc:
            result = {'ok': False, 'reason': str(exc) if isinstance(exc, Refused) else 'host_operation_refused'}
        self.db.execute('UPDATE ledger SET status=?,result=? WHERE id=?', ('complete',json.dumps(result),request_id));self.db.commit()
        return result

    @staticmethod
    def stat_tag(value):
        return {'dev':value.st_dev,'inode':value.st_ino,'size':value.st_size,'mode':stat.S_IMODE(value.st_mode),'links':value.st_nlink,'mtime_ns':value.st_mtime_ns,'ctime_ns':value.st_ctime_ns}

    def verify_journal(self,root,journal):
        expected=journal['original'];result=journal['result']
        if result.get('quarantined'):
            if os.path.lexists(os.path.join(root,expected['path'])): raise Refused('quarantine_original_reappeared')
            current=os.stat(journal['destination'],follow_symlinks=False)
            if self.stat_tag(current)!=result['quarantine_stat'] or not stat.S_ISREG(current.st_mode): raise Refused('quarantine_verification_changed')
        elif result.get('restored'):
            actual=identity(root,expected['path'])
            if any(actual[k]!=expected[k] for k in ('dev','inode','size','sha256','mode','uid','gid','mtime_ns')): raise Refused('restore_verification_changed')

    def perform(self, req):
        action = req['action'];root,cfg = self.root(req['root_id']);operation = req.get('operation_id')
        expected=req.get('site_identity',{})
        actual={k:cfg[k] for k in ('platform_id','schema','prefix','siteurl','home')}
        if expected!=actual: raise Refused('registered_site_identity_mismatch')
        if action == 'inspect':
            path = req['path']
            if path not in cfg.get('quarantine_paths', []): raise Refused('path_not_approved_in_root_manifest')
            return {'ok': True, 'identity': identity(root,path), 'root_id': req['root_id']}
        if action == 'diagnose':
            findings = [];gaps = []
            for path in cfg.get('quarantine_paths', []):
                try:
                    if len(findings)>=200: raise Refused('diagnostic_file_cap')
                    findings.append(identity(root,path))
                except FileNotFoundError: pass
                except (Refused,OSError): gaps.append({'path':path,'reason':'inspection_refused'})
            inspected=0;start=time.monotonic()
            for folder,dirs,files in os.walk(os.path.join(root,'wp-content','uploads'),followlinks=False):
                dirs[:]=[d for d in dirs if not os.path.islink(os.path.join(folder,d))]
                inspected+=len(files)
                if inspected>10000 or time.monotonic()-start>10:
                    gaps.append({'reason':'uploads_inventory_cap'});break
                for filename in files:
                    if filename.lower().endswith(('.php','.phtml','.phar')):
                        path=os.path.relpath(os.path.join(folder,filename),root)
                        gaps.append({'path':path,'reason':'uploads_executable_manual_review'})
                        if len(gaps)>100: break
                if len(gaps)>100: break
            # wp-cli checksum verification occurs before WP code loads. No shell evaluation.
            command = [cfg.get('wp_cli','/usr/local/bin/wp'), 'core', 'verify-checksums', '--path='+root, '--include-root', '--skip-plugins', '--skip-themes']
            try:
                import tempfile, resource
                def cap_output(): resource.setrlimit(resource.RLIMIT_FSIZE,(65536,65536))
                with tempfile.TemporaryFile() as capture:
                    completed=subprocess.run(command,stdout=capture,stderr=capture,timeout=45,env={**os.environ,'TZ':'UTC'},preexec_fn=cap_output)
                    capture.seek(0);text=capture.read(32768).decode(errors='replace')
                checksum = {'verified': completed.returncode == 0, 'extra_paths': []}
                import re
                for match in re.finditer(r'File should not exist: (\S+)',text):
                    path = match.group(1)
                    if path.endswith('.php') and path in cfg.get('quarantine_paths',[]): checksum['extra_paths'].append(path)
                if completed.returncode != 0: gaps.append({'reason':'core_checksum_failed_or_unavailable'})
            except (OSError,subprocess.TimeoutExpired): checksum = {'verified':False};gaps.append({'reason':'core_checksum_unavailable'})
            return {'ok':True,'root_id':req['root_id'],'files':findings,'core':checksum,'gaps':gaps,'discovered_unregistered':self.roots.get('_discoveries',[]),'tool_version':'1'}
        if action == 'operation_status':
            uuid.UUID(operation);path=self.state/(operation+'.json');journal=json.loads(path.read_text()) if path.exists() else None
            if journal and journal.get('root_id')!=req['root_id']: raise Refused('journal_root_mismatch')
            if journal and journal.get('status')=='complete': self.verify_journal(root,journal)
            return {'ok':True,'journal':journal}
        if action not in ('quarantine','restore'): raise Refused('unknown_catalog_action')
        if not cfg.get('quarantine_enabled',False): raise Refused('quarantine_disabled')
        uuid.UUID(operation);journal=self.state/(operation+'.json')
        if journal.exists():
            data=json.loads(journal.read_text())
            if data.get('request_digest') != hashlib.sha256(canonical(req)).hexdigest(): raise Refused('operation_id_payload_mismatch')
            if data['status']=='complete':
                self.verify_journal(root,data);return data['result']
            raise Refused('operation_outcome_unknown_manual_reconciliation')
        if action=='quarantine':
            expected=req['identity'];path=expected['path']
            if path not in cfg.get('quarantine_paths',[]) or path in ('wp-config.php','index.php'): raise Refused('path_not_approved_in_root_manifest')
            if identity(root,path) != expected: raise Refused('file_changed_since_preview')
            destdir=self.state/'quarantine'/operation;destdir.mkdir(parents=True,mode=0o700,exist_ok=False)
            os.chmod(destdir.parent,0o700);os.chmod(destdir,0o700)
            sync_dir(destdir.parent);sync_dir(destdir);sync_dir(self.state)
            if os.stat(destdir).st_dev != expected['dev']: raise Refused('same_filesystem_required')
            parent,name=open_parent(root,path);destination=str(destdir/'file')
            try:
                fd=os.open(name,os.O_RDONLY|os.O_NOFOLLOW,dir_fd=parent)
                try:
                    fresh=os.fstat(fd);entry=os.stat(name,dir_fd=parent,follow_symlinks=False)
                    if fd_identity(fd,path)!=expected or entry.st_dev!=fresh.st_dev or entry.st_ino!=fresh.st_ino or entry.st_nlink!=1: raise Refused('file_swapped_before_move')
                    synced_write(str(journal),{'root_id':req['root_id'],'site_identity':req['site_identity'],'action':action,'status':'intent','request_digest':hashlib.sha256(canonical(req)).hexdigest(),'original':expected,'destination':destination})
                    # Repeat full descriptor identity after journal fsync, immediately before rename.
                    if fd_identity(fd,path)!=expected: raise Refused('file_changed_before_move')
                    rename_noreplace(name,destination,source_fd=parent)
                    moved=os.stat(destination,follow_symlinks=False)
                    if moved.st_dev!=fresh.st_dev or moved.st_ino!=fresh.st_ino or moved.st_nlink!=1 or os.fstat(fd).st_nlink!=1: raise Refused('quarantine_inode_mismatch')
                    moved_identity=fd_identity(fd,path)
                    if any(moved_identity[k]!=expected[k] for k in ('dev','inode','size','sha256','mtime_ns','mode','uid','gid')): raise Refused('quarantine_content_changed')
                    os.fchmod(fd,0);os.fsync(fd);os.fsync(parent)
                    dfd=os.open(destdir,os.O_RDONLY|os.O_DIRECTORY);os.fsync(dfd);os.close(dfd)
                finally: os.close(fd)
            finally: os.close(parent)
            result={'ok':True,'operation_id':operation,'quarantined':True,'path':path,'sha256':expected['sha256'],'mode':0,'quarantine_stat':self.stat_tag(os.stat(destination,follow_symlinks=False))}
        else:
            original_id=req['original_operation'];uuid.UUID(original_id)
            original=json.loads((self.state/(original_id+'.json')).read_text());expected=original['original'];path=expected['path'];destination=original['destination']
            if original.get('root_id')!=req['root_id'] or original.get('site_identity')!=req['site_identity']: raise Refused('journal_root_mismatch')
            if original.get('status')!='complete' or not original.get('result',{}).get('quarantined') or req['identity']!=expected: raise Refused('verified_quarantine_required')
            if pathlib.Path(destination).parent!=self.state/'quarantine'/original_id: raise Refused('invalid_quarantine_destination')
            # Quarantine file contents are preserved; original destination must be genuinely absent.
            parent,name=open_parent(root,path)
            try:
                try: os.stat(name,dir_fd=parent,follow_symlinks=False);raise Refused('restore_destination_occupied')
                except FileNotFoundError: pass
                # Quarantined mode 000 needs a descriptor-pinned read permission to Restore.
                pathfd=os.open(destination,os.O_PATH|os.O_NOFOLLOW)
                pinned=os.fstat(pathfd)
                if pinned.st_ino!=expected['inode'] or pinned.st_dev!=expected['dev'] or pinned.st_nlink!=1 or not stat.S_ISREG(pinned.st_mode):
                    os.close(pathfd);raise Refused('quarantine_identity_changed')
                os.chmod('/proc/self/fd/'+str(pathfd),0o400)
                qfd=os.open('/proc/self/fd/'+str(pathfd),os.O_RDONLY)
                os.close(pathfd)
                try:
                    current=os.fstat(qfd)
                    if current.st_ino!=expected['inode'] or current.st_dev!=expected['dev'] or current.st_nlink!=1: raise Refused('quarantine_identity_changed')
                    if hashlib.sha256(os.read(qfd,32*1024*1024+1)).hexdigest()!=expected['sha256']: raise Refused('quarantine_content_changed')
                    synced_write(str(journal),{'root_id':req['root_id'],'site_identity':req['site_identity'],'action':action,'status':'intent','request_digest':hashlib.sha256(canonical(req)).hexdigest(),'original':expected,'destination':destination})
                    fresh=fd_identity(qfd,path)
                    if any(fresh[k]!=expected[k] for k in ('dev','inode','size','sha256','mtime_ns','uid','gid')): raise Refused('quarantine_identity_changed')
                    rename_noreplace(destination,name,destination_fd=parent)
                    moved=os.stat(name,dir_fd=parent,follow_symlinks=False)
                    if moved.st_ino!=current.st_ino or moved.st_dev!=current.st_dev or moved.st_nlink!=1 or os.fstat(qfd).st_nlink!=1: raise Refused('restore_inode_mismatch')
                    sync_dir(os.path.dirname(destination))
                    if (current.st_uid,current.st_gid)!=(expected['uid'],expected['gid']): os.fchown(qfd,expected['uid'],expected['gid'])
                    os.fchmod(qfd,expected['mode']);os.utime(qfd,ns=(expected['mtime_ns'],expected['mtime_ns']));os.fsync(qfd);os.fsync(parent)
                finally:
                    if os.path.exists(destination): os.fchmod(qfd,0)
                    os.close(qfd)
            finally: os.close(parent)
            now=identity(root,path)
            if any(now[k]!=expected[k] for k in ('size','sha256','mode','uid','gid','mtime_ns')): raise Refused('restore_metadata_verification_failed')
            result={'ok':True,'operation_id':operation,'restored':True,'path':path,'sha256':now['sha256'],'ctime_ns':now['ctime_ns']}
        data=json.loads(journal.read_text());data.update(status='complete',result=result)
        temp=str(journal)+'.complete';synced_write(temp,data);os.replace(temp,journal)
        dfd=os.open(self.state,os.O_RDONLY|os.O_DIRECTORY);os.fsync(dfd);os.close(dfd)
        return result

def main():
    if os.environ.get('SSH_ORIGINAL_COMMAND','') not in ('','containment-v1'): raise Refused('forced_command_required')
    state=sys.argv[1] if len(sys.argv)==2 else os.path.expanduser('~/.exotic-containment')
    raw=sys.stdin.buffer.read(262145)
    if len(raw)>262144: raise Refused('request_limit')
    dispatcher=Dispatcher(state);result=dispatcher.request(json.loads(raw));output=json.dumps(result,separators=(',',':'))
    if len(output)>262144: raise Refused('output_cap')
    print(output)

if __name__=='__main__':
    try: main()
    except Exception as exc: print(json.dumps({'ok':False,'reason':str(exc) if isinstance(exc,Refused) else 'request_refused'}));sys.exit(1)
