import importlib.util, pathlib, tempfile, unittest, os, json, hashlib, uuid, time, hmac, stat
spec=importlib.util.spec_from_file_location('host',pathlib.Path(__file__).with_name('host-helper.py'));host=importlib.util.module_from_spec(spec);spec.loader.exec_module(host)

class HostTest(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.base=pathlib.Path(self.temp.name);self.root=self.base/'public';self.root.mkdir();self.state=self.base/'private';self.state.mkdir(mode=0o700)
        (self.state/'signing.key').write_bytes(b'k'*32);(self.root/'wp-config.php').write_text("<?php define('DB_NAME','fixture_wp'); $table_prefix='wp_';")
        self.target=self.root/'unexpected.php';self.target.write_bytes(b'synthetic inert fixture\n');os.chmod(self.target,0o640)
        self.identity={'platform_id':1,'schema':'fixture_wp','prefix':'wp_','siteurl':'https://synthetic.test','home':'https://synthetic.test'}
        cfg={**self.identity,'path':str(self.root),'wp_config_sha256':hashlib.sha256((self.root/'wp-config.php').read_bytes()).hexdigest(),'core_paths':['index.php'],'quarantine_paths':['unexpected.php'],'quarantine_enabled':True,'wp_cli':'/missing/wp'}
        (self.state/'roots.json').write_text(json.dumps({'canary':cfg}));self.dispatch=host.Dispatcher(self.state)
    def tearDown(self):
        self.dispatch.db.close();os.close(self.dispatch.lock);self.temp.cleanup()
    def req(self,action,**kwargs):return {'root_id':'canary','site_identity':self.identity,'action':action,**kwargs}
    def envelope(self,req,request_id=None):
        stamp=int(time.time());rid=request_id or str(uuid.uuid4());sig=hmac.new(b'k'*32,str(stamp).encode()+b'\n'+rid.encode()+b'\n'+host.canonical(req),hashlib.sha256).hexdigest();return {'request':req,'timestamp':stamp,'request_id':rid,'signature':sig}
    def quarantine(self):
        original=host.identity(str(self.root),'unexpected.php');op=str(uuid.uuid4());req=self.req('quarantine',operation_id=op,identity=original);result=self.dispatch.perform(req);return op,original,req,result
    def test_exact_quarantine_restore_and_replay(self):
        op,original,req,result=self.quarantine();self.assertTrue(result['quarantined']);self.assertFalse(self.target.exists());destination=self.state/'quarantine'/op/'file';self.assertEqual(stat.S_IMODE(destination.stat().st_mode),0)
        self.assertEqual(result,self.dispatch.perform(req));restore=self.dispatch.perform(self.req('restore',operation_id=str(uuid.uuid4()),original_operation=op,identity=original));self.assertTrue(restore['restored']);actual=host.identity(str(self.root),'unexpected.php')
        for key in ('inode','dev','size','sha256','mode','uid','gid','mtime_ns'):self.assertEqual(original[key],actual[key]);self.assertNotEqual(original['ctime_ns'],actual['ctime_ns'])
    def test_symlink_and_hard_link_refuse(self):
        self.target.unlink();self.target.symlink_to(self.root/'wp-config.php')
        with self.assertRaises(OSError):host.identity(str(self.root),'unexpected.php')
        self.target.unlink();self.target.write_bytes(b'x');os.link(self.target,self.root/'second')
        with self.assertRaises(host.Refused):host.identity(str(self.root),'unexpected.php')
    def test_stale_same_inode_and_wrong_root_refuse(self):
        original=host.identity(str(self.root),'unexpected.php');self.target.write_bytes(b'changed')
        with self.assertRaisesRegex(host.Refused,'file_changed'):self.dispatch.perform(self.req('quarantine',operation_id=str(uuid.uuid4()),identity=original))
        with self.assertRaisesRegex(host.Refused,'unregistered'):self.dispatch.perform({**self.req('diagnose'),'root_id':'unknown'})
    def test_occupied_restore_never_overwrites(self):
        op,original,_,_=self.quarantine();self.target.write_bytes(b'legitimate replacement')
        with self.assertRaisesRegex(host.Refused,'occupied'):self.dispatch.perform(self.req('restore',operation_id=str(uuid.uuid4()),original_operation=op,identity=original))
        self.assertEqual(self.target.read_bytes(),b'legitimate replacement')
    def test_request_signature_replay_and_body_binding(self):
        request=self.envelope(self.req('inspect',path='unexpected.php'));result=self.dispatch.request(request);self.assertEqual(result,self.dispatch.request(request));changed=self.envelope(self.req('diagnose'),request['request_id'])
        with self.assertRaisesRegex(host.Refused,'payload_mismatch'):self.dispatch.request(changed)
        request['signature']='0'*64
        with self.assertRaisesRegex(host.Refused,'signature'):self.dispatch.request(request)
    def test_expected_core_path_and_changed_config_refuse(self):
        self.dispatch.roots['canary']['quarantine_paths']=['index.php']
        with self.assertRaisesRegex(host.Refused,'expected'):self.dispatch.perform(self.req('diagnose'))
        self.dispatch.roots['canary']['quarantine_paths']=['unexpected.php'];(self.root/'wp-config.php').write_text('changed')
        with self.assertRaisesRegex(host.Refused,'config_identity'):self.dispatch.perform(self.req('diagnose'))
    def test_crash_journal_never_replays_unknown_move(self):
        original=host.identity(str(self.root),'unexpected.php');op=str(uuid.uuid4());req=self.req('quarantine',operation_id=op,identity=original);host.synced_write(str(self.state/(op+'.json')),{'status':'intent','request_digest':hashlib.sha256(host.canonical(req)).hexdigest()})
        with self.assertRaisesRegex(host.Refused,'unknown'):self.dispatch.perform(req)
        self.assertTrue(self.target.exists())
    def test_disabled_quarantine_and_readonly_diagnostic(self):
        before=host.identity(str(self.root),'unexpected.php');result=self.dispatch.perform(self.req('diagnose'));self.assertEqual(host.identity(str(self.root),'unexpected.php'),before);self.assertFalse(result['core']['verified']);self.assertTrue(result['gaps'])
        self.dispatch.roots['canary']['quarantine_enabled']=False
        with self.assertRaisesRegex(host.Refused,'disabled'):self.dispatch.perform(self.req('quarantine',operation_id=str(uuid.uuid4()),identity=before))

if __name__=='__main__': unittest.main()
