from pathlib import Path
import json, subprocess

root = Path('/Users/ian/Projects/exotic-crm')
work = Path.home()/'.cache/exotic-crm-reporting-perf-2026-11-14'
release = work/'release'
for f in ['app/Services/Mcp/RevenueReportingQuery.php','app/Services/Mcp/RevenueReportingService.php','app/Services/Mcp/McpServer.php','tests/Feature/Mcp/McpHourlyRevenueReportingTest.php']:
    (release/f).write_bytes((root/f).read_bytes())
port = subprocess.check_output(['docker','port','exotic-reporting-perf114-20261114','3306'],text=True).strip().split(':')[-1]
target = work/'mariadb-tests'
target.mkdir(exist_ok=True)
for name in ['McpHourlyRevenueReportingTest','ContactUnlockCountryDemandTest']:
    src = release/'tests/Feature'/('Mcp/'+name+'.php' if name.startswith('Mcp') else name+'.php')
    text = src.read_text()
    setup = """
        $reportingDb = 'reporting_perf_'.bin2hex(random_bytes(6));
        $reportingPdo = new \\PDO('mysql:host=127.0.0.1;port=PORT', 'root', '');
        $reportingVersion = $reportingPdo->query('SELECT VERSION()')->fetchColumn();
        if ($reportingPdo->getAttribute(\\PDO::ATTR_DRIVER_NAME) !== 'mysql' || !str_starts_with($reportingVersion, '11.4.13-MariaDB')) throw new \\RuntimeException('Disposable MariaDB identity mismatch');
        static $reportingPrinted = false;
        if (!$reportingPrinted) { fwrite(STDERR, 'Verified fixture driver=mysql server='.$reportingVersion."\\n"); $reportingPrinted = true; }
        $reportingPdo->exec('CREATE DATABASE `'.$reportingDb.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
""".replace('PORT',port)
    text=text.replace('        parent::setUp();','        parent::setUp();'+setup,1)
    before="'driver' => 'sqlite', 'database' => ':memory:'"
    assert before in text
    text=text.replace(before,"'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => "+port+", 'database' => $reportingDb, 'username' => 'root', 'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true",1)
    (target/(name+'.php')).write_text(text)
benchmark = r'''
    public function test_production_volume_dashboard_performance_and_financial_partitions(): void
    {
        Schema::create('billing_routing_decisions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('payment_id'); $t->string('billing_surface',64)->nullable(); $t->unsignedBigInteger('provider_profile_id')->nullable(); $t->index(['payment_id','billing_surface']);
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->index(['platform_id','status','created_at']); $t->index(['platform_id','completed_at']); $t->index('reconciliation_state');
        });
        for ($i=1;$i<=7;$i++) DB::table('manual_payment_bundles')->insert(['id'=>$i,'status'=>'committed']);
        $expectedCash=0; $expectedEarned=0; $expectedUsdMinor=0; $expectedCount=0; $chunk=[]; $routing=[];
        $excluded=[11,44,88,45,91];
        for ($i=0;$i<42000;$i++) {
            $selected=$i<1557; $date=$selected ? CarbonImmutable::parse('2026-08-01 09:00:00')->addDays($i%106)->format('Y-m-d H:i:s') : '2026-01-10 09:00:00';
            $live=$i%37!==0; $successful=$i%29!==0; $wallet=$i%10===0; $unknown=$i%113===0;
            $amount=(($i%50)+1)*100; $ref='PERF-'.$i; $account=$i%5===0 ? null : 'receiver-'.($i%4);
            $bundle=$i%43===0 ? ($i%7)+1 : null; $seq=$i%3;
            if (in_array($i,[11,20011],true)) { $ref='CROSS-SITE-OUTSIDE-WINDOW'; $account='same-receiver'; }
            if (in_array($i,[12,20012],true)) { $ref='DIFFERENT-RECEIVER'; $account='different-'.$i; }
            if (in_array($i,[43,86],true)) { $ref='VALID-SPLIT'; $account='bundle-receiver'; $bundle=1; $seq=$i===43?1:2; }
            if (in_array($i,[44,88],true)) { $ref='REPEATED-SEQUENCE'; $account='bundle-receiver'; $bundle=2; $seq=1; }
            if (in_array($i,[45,91],true)) { $ref='NULL-SEQUENCE'; $account='bundle-receiver'; $bundle=3; $seq=$i===45?null:2; }
            $chunk[]=['id'=>$i+1,'platform_id'=>$selected?5:($i%2===0?16:1),'amount'=>$amount,'currency'=>'UGX','status'=>$successful?'completed':'refunded','purpose'=>'subscription','source'=>$wallet?'wallet':($unknown?'unproven':'gateway'),'provider_key'=>$unknown?null:'test_provider','provider_environment'=>'live','record_classification'=>$live?'live':'test','completed_at'=>$date,'created_at'=>$date,'transaction_reference'=>$ref,'payment_data'=>$account===null?'{}':json_encode(['collection'=>['account_id'=>$account]]),'raw_payload'=>$wallet?'{"method":"wallet"}':'{}','wallet_transaction_id'=>$wallet?$i+100000:null,'manual_payment_bundle_id'=>$bundle,'reference_sequence'=>$seq];
            if ($i%6===0) $routing[]=['payment_id'=>$i+1,'provider_profile_id'=>($i%5)+1];
            if ($selected&&$live&&$successful&&!in_array($i,$excluded,true)) {
                $expectedEarned+=$amount;
                if (!$wallet&&!$unknown) { $expectedCash+=$amount; $expectedCount++; $expectedUsdMinor+=(int)round(round($amount*0.00025,2)*100); }
            }
            if (count($chunk)===500) { DB::table('payments')->insert($chunk); $chunk=[]; }
            if (count($routing)===500) { DB::table('billing_routing_decisions')->insert($routing); $routing=[]; }
        }
        if ($chunk) DB::table('payments')->insert($chunk);
        if ($routing) DB::table('billing_routing_decisions')->insert($routing);
        $rates=[];
        for($i=0;$i<106;$i++) $rates[]=['provider'=>'manual','source_currency'=>'UGX','target_currency'=>'USD','rate_date'=>CarbonImmutable::parse('2026-08-01')->addDays($i)->toDateString(),'rate'=>0.00025];
        DB::table('reporting_fx_rates')->insert($rates);
        DB::select('ANALYZE TABLE payments,billing_routing_decisions');
        DB::statement('SET SESSION max_statement_time=8');
        $query=app(RevenueReportingQuery::class)->rows()->where('reported.platform_id',5)->where('reported.occurred_at','>=','2026-07-31 21:00:00')->where('reported.occurred_at','<','2026-11-14 21:00:00')->orderBy('reported.id')->limit(50001);
        $candidate=app(RevenueReportingQuery::class)->candidates();
        $bindings=array_map(fn($v)=>DB::connection()->getPdo()->quote((string)$v),$candidate->getBindings());
        DB::statement('CREATE VIEW benchmark_candidate_types AS '.\Illuminate\Support\Str::replaceArray('?', $bindings, $candidate->toSql()));
        file_put_contents(getenv('REPORTING_BENCHMARK_OUTPUT').'.types.json',json_encode(DB::select('SHOW FULL COLUMNS FROM benchmark_candidate_types'),JSON_PRETTY_PRINT));
        $plan=DB::select('EXPLAIN '.$query->toSql(),$query->getBindings());
        file_put_contents(getenv('REPORTING_BENCHMARK_OUTPUT').'.plan.json',json_encode($plan,JSON_PRETTY_PRINT));
        $this->assertTrue(collect($plan)->contains(fn($p)=>$p->type==='ref'&&str_starts_with((string)$p->table,'<derived')&&$p->key!==null),json_encode($plan));
        $start=microtime(true);$rows=$query->get();$queryMs=(microtime(true)-$start)*1000;
        $this->assertLessThan(5000,$queryMs);
        DB::flushQueryLog(); DB::enableQueryLog();
        $start=microtime(true);
        $dashboard=app(RevenueReportingService::class)->dashboard(['from'=>'2026-08-01','to'=>'2026-11-14','platform_id'=>5,'bucket'=>'month'],$this->admin);
        $dashboardMs=(microtime(true)-$start)*1000;
        $candidateReads=collect(DB::getQueryLog())->filter(fn($q)=>str_contains($q['query'],'other_receipt')&&str_contains($q['query'],'disposition'))->count();
        DB::disableQueryLog();
        $this->assertLessThan(15000,$dashboardMs);$this->assertSame(1,$candidateReads);
        $cash=$dashboard['summary']['collections']['new_cash'];$earned=$dashboard['summary']['collections']['earned_services'];
        $this->assertEquals($expectedCash,$cash['source_breakdown']['UGX']);$this->assertEquals($expectedEarned,$earned['source_breakdown']['UGX']);
        $this->assertSame($expectedCount,$cash['payments_count']);$this->assertEquals($expectedUsdMinor/100,$cash['normalized_total']);
        $this->assertCount(4,$dashboard['trend']['points']);$this->assertSame((int)round($cash['normalized_total']*100),collect($dashboard['trend']['points'])->sum(fn($point)=>(int)round($point['value']*100)));
        $this->assertEquals($cash['normalized_total'],$dashboard['market_breakdown']['total']);Http::assertNothingSent();
        file_put_contents(getenv('REPORTING_BENCHMARK_OUTPUT'),json_encode(['driver'=>DB::connection()->getDriverName(),'server'=>DB::selectOne('SELECT VERSION() AS v')->v,'retained_rows'=>42000,'requested_all_status_rows'=>1557,'core_query_ms'=>round($queryMs,2),'full_dashboard_ms'=>round($dashboardMs,2),'candidate_reads'=>$candidateReads,'cash'=>$cash,'earned'=>$earned,'plan'=>array_map(fn($p)=>['table'=>$p->table,'type'=>$p->type,'key'=>$p->key,'rows'=>$p->rows],$plan)],JSON_PRETTY_PRINT)."\n");
    }
'''
p=target/'McpHourlyRevenueReportingTest.php'
text=p.read_text(); text=text.rstrip(); assert text.endswith('}')
p.write_text(text[:-1]+benchmark+'}\n')
print(json.dumps({'port':port,'test_directory':str(target),'server_required':'11.4.13-MariaDB','retained_rows':42000,'requested_rows':1557}))
