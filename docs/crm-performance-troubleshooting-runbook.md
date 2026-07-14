# CRM Performance Troubleshooting Runbook

## Scope
Use this runbook when CRM users report:
- Pages keep loading or feel sluggish.
- API calls timeout or take several seconds.
- The CRM works from one location but not another.
- cPanel/Site Quality says the site is up, but operators still report poor UX.

The goal is to separate:
- Client/network issues: ISP, VPN, Wi-Fi, DNS, browser, device.
- Edge/proxy issues: Cloudflare, TLS, routing.
- Server issues: CPU, memory, disk, PHP-FPM, Apache, MariaDB.
- CRM app issues: slow routes, queues, external APIs, Laravel errors.

## Fast Triage Summary
Ask IT to capture all command output with timestamps.

1. Test from the affected office network.
2. Test from another healthy network.
3. Test from the server/cPanel terminal.
4. Compare `ping`, `curl` TTFB, CPU load, PHP-FPM pools, MariaDB, queues, and Laravel logs.

If the request is slow from cPanel/server terminal too, it is not primarily an office ISP issue.

## Affected Office Machine Commands

Run these from a user machine in the office where the CRM is slow.

```bash
date
ping -c 50 crm.exotic-online.com
```

```bash
for i in {1..10}; do
  date "+%H:%M:%S"
  curl -sS -o /dev/null -w "dns=%{time_namelookup} connect=%{time_connect} tls=%{time_appconnect} ttfb=%{time_starttransfer} total=%{time_total} ip=%{remote_ip} code=%{http_code}\n" https://crm.exotic-online.com/
  sleep 2
done
```

```bash
curl -sS -o /dev/null -w "js_ttfb=%{time_starttransfer} js_total=%{time_total} size=%{size_download} speed=%{speed_download} code=%{http_code}\n" https://crm.exotic-online.com/build/assets/app-DC8jNcF4.js
```

Optional DNS comparison:

```bash
nslookup crm.exotic-online.com
```

If available:

```bash
traceroute crm.exotic-online.com
```

## Healthy Network Comparison Commands

Run the same commands from a known-good network, for example home internet or mobile hotspot.

```bash
date
ping -c 50 crm.exotic-online.com
```

```bash
for i in {1..10}; do
  date "+%H:%M:%S"
  curl -sS -o /dev/null -w "dns=%{time_namelookup} connect=%{time_connect} tls=%{time_appconnect} ttfb=%{time_starttransfer} total=%{time_total} ip=%{remote_ip} code=%{http_code}\n" https://crm.exotic-online.com/
  sleep 2
done
```

## Office Network Path Diagnosis

Use this section when the CRM works on a hotspot or another Wi-Fi network, but not on the ExoticOnline office network.
Run the office and hotspot tests close together in time from the same laptop.

Capture the current network and DNS answers:

```bash
echo "=== DNS NOW ==="
date
curl -sS https://ifconfig.me; echo
dscacheutil -q host -a name crm.exotic-online.com
dig +short crm.exotic-online.com
dig @1.1.1.1 +short crm.exotic-online.com
dig @8.8.8.8 +short crm.exotic-online.com
```

Compare the current Cloudflare path with the origin path. Replace the IPs if DNS/origin changes.

```bash
echo "=== FORCE CLOUDFLARE ==="
curl --resolve crm.exotic-online.com:443:172.67.151.11 \
  -sS -o /dev/null \
  -w "cf total=%{time_total} ttfb=%{time_starttransfer} ip=%{remote_ip} code=%{http_code}\n" \
  https://crm.exotic-online.com/

echo "=== FORCE ORIGIN ==="
curl --resolve crm.exotic-online.com:443:198.38.92.95 \
  -sS -o /dev/null \
  -w "origin total=%{time_total} ttfb=%{time_starttransfer} ip=%{remote_ip} code=%{http_code}\n" \
  https://crm.exotic-online.com/
```

Ping both paths:

```bash
echo "=== PING BOTH ==="
ping -c 20 172.67.151.11
ping -c 20 198.38.92.95
```

Run a route comparison:

```bash
traceroute 172.67.151.11
traceroute 198.38.92.95
```

Check TCP connectivity:

```bash
nc -vz 172.67.151.11 443
nc -vz 198.38.92.95 443
nc -vz 198.38.92.95 80
```

Interpretation:
- If Cloudflare is fast but origin times out, the office path to the origin IP is broken or blocked.
- If DNS still returns the origin IP instead of Cloudflare, flush DNS and check whether the local resolver or router is caching stale records.
- If Cloudflare and origin are both slow only from the office, suspect office ISP, router, firewall, proxy, Wi-Fi, or endpoint security.
- If the same origin IP is fast from hotspot but not office, ask hosting to check whether the office public IP is blocked and ask the ISP to check routing.

### Office Path ASCII Diagram

Historical July 2026 values are shown below. Replace IPs with current values during a future incident.

```text
Good path after Cloudflare proxy

  Office laptop
      |
      |  Wi-Fi/LAN
      v
  Office router / ISP
  public IP 197.248.120.233
      |
      |  HTTPS to crm.exotic-online.com
      v
  Cloudflare Nairobi edge
  172.67.151.11 / 104.21.64.133
      |
      |  Cloudflare-to-origin network
      v
  CRM origin server
  198.38.92.95
      |
      v
  Apache -> PHP-FPM crm_exotic-online_com -> Laravel CRM


Bad path when DNS or forced testing hits origin directly

  Office laptop
      |
      v
  Office router / ISP
  public IP 197.248.120.233
      |
      |  direct HTTPS to 198.38.92.95
      |  observed: 75s connect timeout, 100% ping loss
      X
  CRM origin server
  198.38.92.95
```

## cPanel Server Commands

Run these in cPanel Terminal on the CRM server.

```bash
cd /home/d9410/crm.exotic-online.com
date
uptime
free -m
df -h
```

Test response timing from the server itself:

```bash
for i in {1..15}; do
  date "+%H:%M:%S"
  curl -sS -o /dev/null -w "dns=%{time_namelookup} connect=%{time_connect} tls=%{time_appconnect} ttfb=%{time_starttransfer} total=%{time_total} code=%{http_code}\n" https://crm.exotic-online.com/
  sleep 2
done
```

Test static asset download:

```bash
curl -sS -o /dev/null -w "js_ttfb=%{time_starttransfer} js_total=%{time_total} size=%{size_download} speed=%{speed_download} code=%{http_code}\n" https://crm.exotic-online.com/build/assets/app-DC8jNcF4.js
```

Check whether Cloudflare or another proxy is visible:

```bash
curl -sS -I https://crm.exotic-online.com/
```

Look for headers such as `cf-ray`, `cf-cache-status`, or `server: cloudflare`.

## Server Load and Process Commands

Use these when cPanel timing shows intermittent slow `ttfb` or users report timeouts.

```bash
top -b -n 1 | head -n 40
```

```bash
ps -eo user,pcpu,pmem,comm,args --sort=-pcpu | head -n 80
```

```bash
ps aux | grep -E "artisan|queue|php" | grep -v grep
```

Interpretation:
- `0.0 id` in `top` means there is no idle CPU.
- Load average much higher than CPU core count means the server is overloaded.
- Many hot `php-fpm: pool ...` rows identify which cPanel accounts/sites are consuming CPU.
- Hot `mariadbd` means database load may be slowing all sites, including the CRM.

## PHP-FPM Pool Diagnosis

Use these commands when IT needs to inspect noisy cPanel pools such as `erotictanzania_com`, `kutombana-tz_com`, `exoticghana_com`, or `exotickenya_com`.

Capture a timestamp and system load first:

```bash
date
uptime
top -b -n 1 | head -n 40
```

Show the busiest PHP-FPM/CGI processes:

```bash
ps -eo pid,user,pcpu,pmem,etime,stat,comm,args --sort=-pcpu | head -n 120
```

Summarize CPU by PHP-FPM pool/account:

```bash
ps -eo user,pcpu,pmem,args | awk '/php-fpm: pool/ {pool=$0; sub(/^.*php-fpm: pool /,"",pool); cpu[pool]+=$2; mem[pool]+=$3; count[pool]++} END {printf "%-35s %8s %8s %8s\n","pool","procs","cpu%","mem%"; for (p in cpu) printf "%-35s %8d %8.1f %8.1f\n",p,count[p],cpu[p],mem[p]}' | sort -k3 -nr | head -n 30
```

Inspect only the known hot pools:

```bash
ps -eo pid,user,pcpu,pmem,etime,stat,args --sort=-pcpu | grep -E "php-fpm: pool (erotictanzania_com|kutombana-tz_com|exoticghana_com|exotickenya_com)" | grep -v grep
```

Count PHP workers by pool:

```bash
ps -eo args | awk '/php-fpm: pool/ {pool=$0; sub(/^.*php-fpm: pool /,"",pool); count[pool]++} END {for (p in count) printf "%-35s %d\n",p,count[p]}' | sort -k2 -nr | head -n 30
```

If available, check recent Apache access volume by domain. The exact log path depends on host/cPanel config:

```bash
ls -lah /usr/local/apache/domlogs 2>/dev/null | head
```

Root/hosting support can run:

```bash
for domain in erotictanzania.com kutombana-tz.com exoticghana.com exotickenya.com; do
  echo "===== $domain ====="
  tail -n 2000 "/usr/local/apache/domlogs/$domain" 2>/dev/null | awk '{print $7}' | sort | uniq -c | sort -nr | head -n 20
done
```

Interpretation:
- High PHP-FPM CPU with high request counts points to traffic/crawlers or expensive PHP routes.
- High PHP-FPM CPU with low request counts points to very slow PHP code, cron, plugin/theme loops, or blocked DB queries.
- Many processes stuck for a long `etime` means requests are hanging, not just busy.

## MariaDB Commands

Try:

```bash
mysqladmin processlist
```

If `mysqladmin` is unavailable:

```bash
mysql -e "SHOW FULL PROCESSLIST;"
```

If access is denied, ask hosting/root support to run:

```sql
SHOW FULL PROCESSLIST;
SHOW ENGINE INNODB STATUS\G
```

Ask them to identify:
- Long-running queries.
- Locked queries.
- Repeated WordPress queries from other hosted pools.
- CRM queries waiting behind global database load.

## MariaDB Slow Query and Processlist Diagnosis

Run these from cPanel first. They may fail if the cPanel MySQL user does not have process privileges.

```bash
date
mysql -e "SHOW FULL PROCESSLIST;"
```

If access is denied, hosting/root support should run:

```bash
mariadb -e "SHOW FULL PROCESSLIST;"
```

Summarize active queries by database/user:

```bash
mariadb -e "SHOW FULL PROCESSLIST;" | awk 'NR>1 {user[$2]++; db[$4]++; state[$7]++} END {print "== users =="; for (u in user) print user[u],u; print "== databases =="; for (d in db) print db[d],d; print "== states =="; for (s in state) print state[s],s}' | sort -nr
```

Show slow currently-running queries:

```bash
mariadb -e "SHOW FULL PROCESSLIST;" | awk 'NR==1 || $6 >= 5'
```

Check InnoDB locks/deadlocks:

```bash
mariadb -e "SHOW ENGINE INNODB STATUS\G" | sed -n '/LATEST DETECTED DEADLOCK/,+80p;/TRANSACTIONS/,+120p'
```

Check whether slow query logging is enabled:

```bash
mariadb -e "SHOW VARIABLES LIKE 'slow_query%'; SHOW VARIABLES LIKE 'long_query_time';"
```

Locate slow-query logs if enabled:

```bash
mariadb -N -e "SHOW VARIABLES LIKE 'slow_query_log_file';"
```

If slow logs are enabled and readable, inspect the latest entries:

```bash
SLOW_LOG=$(mariadb -N -e "SHOW VARIABLES LIKE 'slow_query_log_file';" | awk '{print $2}')
echo "$SLOW_LOG"
tail -n 200 "$SLOW_LOG"
```

If `mysqldumpslow` is available:

```bash
mysqldumpslow -s t -t 20 "$SLOW_LOG"
```

If `pt-query-digest` is available:

```bash
pt-query-digest --limit=20 "$SLOW_LOG"
```

If slow logging is off, do not enable it during peak traffic without approval. Hosting/root support can temporarily enable it for a short window:

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;
```

Then disable it after the sample window:

```sql
SET GLOBAL slow_query_log = 'OFF';
```

Interpretation:
- `State` values like `Locked`, `Waiting for table metadata lock`, or long `Sending data` need immediate review.
- Repeated long queries against WordPress `postmeta`, `posts`, or options tables usually indicate plugin/theme/query problems.
- If multiple databases/users are slow at the same time while CPU idle is near 0%, the issue is host-wide saturation.
- If only one database/user dominates, focus on that site/pool.

## Laravel Logs

Find log files:

```bash
ls -lah storage/logs
```

Tail today's log:

```bash
tail -n 200 storage/logs/laravel-$(date +%F).log
```

If the date-based filename is different:

```bash
tail -n 200 storage/logs/*.log
```

Filter high-value errors:

```bash
grep -iE "production.ERROR|production.WARNING|QueryException|timeout|timed out|SQLSTATE|deadlock|lock wait|Too many connections|Maximum execution|Allowed memory|cURL error|HTTP request returned status" storage/logs/laravel-$(date +%F).log | tail -n 160
```

Known patterns to watch:
- `SQLSTATE[42000]` or `QueryException`: broken SQL or incompatible MariaDB syntax.
- `SQLSTATE[42S02] Base table or view not found`: CRM is querying a missing platform table.
- `cURL error`, `timed out`, or `HTTP request returned status`: slow/failing external services.
- `Route [login] not defined`: unauthenticated API flow is hitting the wrong redirect behavior.

## Queue and Scheduler Commands

```bash
php artisan queue:failed
```

```bash
php artisan queue:monitor default,alerts,push,sync-clients,sync-clients-reconcile,auto_optimize,kyc-fanout --max=100
```

```bash
php artisan schedule:list
```

```bash
ps aux | grep -E "artisan queue:work|artisan schedule:run" | grep -v grep
```

Interpretation:
- Any queue above the threshold is a backlog.
- A large `push` queue can delay other background work if it shares one worker with critical queues.
- `Has Mutex` means a scheduled task is already running or locked.
- Empty `failed_jobs` does not mean the queue is healthy; a queue can be backlogged without failures.

## Data Pulls and Schedule Reference

Use this section when IT asks what pulls data into the CRM and how often it runs.

### Main CRM Profile Sync

The main client/profile cache is pulled from WordPress into the CRM `clients` table by:

```bash
php artisan crm:sync-clients
```

Schedule:
- Delta sync: every 15 minutes.
- Full reconciliation: daily at 02:05.

Mechanism:
- The command selects active platforms with configured `wp_api_url`, `wp_api_user`, and `wp_api_password`.
- It queues `RunClientSyncJob` on `sync-clients` for delta runs and `sync-clients-reconcile` for full runs.
- The job uses `WpSyncService` to call each platform's WordPress sync REST API, not a direct SQL dump.
- V2-capable sites use `/clients/sync` plus `/clients/tombstones`.
- Legacy sites use `/clients` with pagination and optional `modified_after`.
- Page size is 100 records per job invocation.

### Direct Platform Database Access

The CRM can open dynamic MySQL connections named `platform_{id}` using DB credentials stored on each `platforms` row.

Direct platform DB access is used by:
- `subscriptions:check`, daily at 00:05, to update expired WordPress posts/postmeta.
- Client URL search/repair paths, on demand, to resolve URLs through `escort_live_urls` and `posts`.
- Some provisioning/payment/credential flows, on demand, to read or write WordPress users/posts/postmeta.

These are not all periodic imports, but they can contribute to database load when invoked.

### Other Scheduled Integrations

| Process | Schedule | Pulls From / Talks To | Notes |
|---|---:|---|---|
| `crm:check-market-health` | every 5 minutes | public domains + WordPress sync `/stats` | HTTP probe, short timeouts |
| `crm:sync-sb-users` | minute 2, 17, 32, 47 hourly | Support Board API + CRM clients | queues `RunSupportBoardSyncJob` |
| `crm:reconcile-pending-payments` | every 15 minutes | CRM DB + payment provider APIs | checks stale pending hosted checkout payments |
| `crm:dispatch-scheduled-pushes` | every 15 minutes | CRM DB | queues scheduled push campaign work |
| `crm:maintain-auto-push` | every 15 minutes | CRM DB | maintenance for auto-push |
| `crm:sync-push-subscribers` | daily | push providers | subscriber counts only |
| `crm:reconcile-expired-subscriptions` | daily at 00:25 | CRM DB + WordPress REST | safety net after subscription check |
| `crm:import-leads` | manual only | WordPress-backed import | intentionally not scheduled; previously every 15 minutes and contributed to resource exhaustion |

### Queue Workers

Queue workers are started every minute by the scheduler when `QUEUE_CONNECTION` is not `sync`.

Main worker:

```bash
php artisan queue:work <connection> --queue=push,sync-clients,sync-clients-reconcile,alerts,default,kyc-fanout --max-time=55 --max-jobs=100 --tries=3 --sleep=3
```

Auto-optimize worker:

```bash
php artisan queue:work <connection> --queue=auto_optimize --max-time=55 --max-jobs=30 --tries=3 --sleep=3
```

Because the CRM currently uses the database queue on production, high queue depth means additional reads/writes on the CRM database.

## Laravel Pulse Checks

In the CRM UI, check Laravel Pulse for:
- Top users making requests.
- Slow endpoints.
- Slow outgoing requests.
- Slow jobs.
- Exceptions.
- Cache miss rate.
- Queue depth.

Record:
- Route name or URI.
- Slowest time.
- Count.
- User affected.
- Time window.

Routes that take more than 1 second repeatedly should be reviewed. Routes taking 30-60 seconds are incident-level.

## Browser Checks

On the affected machine:
1. Open Chrome DevTools.
2. Go to Network.
3. Enable Disable cache.
4. Reload the CRM.
5. Sort by Duration.
6. Capture screenshots of requests taking more than 2 seconds.

Record:
- URL/path.
- Status code.
- Duration.
- Waiting/TTFB.
- Size.
- Whether the request is document, JS, XHR/fetch, image, or font.

If all API calls are fast but rendering is slow, suspect device/browser. If API calls have high waiting/TTFB, suspect server/app/backend.

## Decision Matrix

### Office only is slow
Likely causes:
- ISP routing or packet loss.
- VPN/proxy/antivirus TLS inspection.
- DNS resolver issue.
- Weak Wi-Fi.
- Old laptop/browser extensions.
- Office public IP blocked or rate-limited at hosting firewall/WAF.
- Stale DNS sending office clients to the origin IP instead of Cloudflare.

Evidence:
- Office `ping` has loss or high jitter.
- Office `curl total` is high but cPanel curl is consistently fast.
- Static JS download is slow only from office.
- `curl --resolve` to Cloudflare is fast while `curl --resolve` to origin times out.
- Office DNS, router, or browser cache returns a different IP than healthy networks.

### Server/cPanel curl is slow
Likely causes:
- PHP-FPM saturation.
- MariaDB contention.
- Laravel route doing slow work.
- External API call blocking the request.
- Queue/scheduler/database contention.

Evidence:
- cPanel `curl` has high `ttfb`.
- `top` shows low or zero CPU idle.
- `mariadbd` or PHP-FPM pools are hot.
- Pulse shows slow endpoints.

### Ping is normal but TTFB is high
Likely causes:
- Backend/app/database delay.

This is usually not raw internet latency.

### Static assets are fast but API is slow
Likely causes:
- Laravel/PHP/database/external service issue.

### Static assets are also slow
Likely causes:
- Network throughput problem.
- Server saturation.
- Web server congestion.
- Large bundle affecting older machines.

## Hosting Support Escalation Template

Send this to hosting/root support:

```text
CRM users are reporting intermittent timeouts and sluggish UX.

Time observed:
[UTC timestamp]

Domain:
crm.exotic-online.com

Symptoms:
- [Example: high TTFB from cPanel curl]
- [Example: office users see loading/timeouts]
- [Example: Laravel Pulse shows slow API routes]

Server evidence:
- load average: [paste]
- CPU idle: [paste]
- top CPU processes: [paste]
- hot PHP-FPM pools: [paste]
- MariaDB CPU: [paste]

Please inspect:
- MariaDB processlist and slow queries.
- PHP-FPM pools consuming CPU.
- Apache/PHP-FPM saturation.
- Any account causing noisy-neighbor load.
- Whether limits can be applied or CRM can be isolated.
```

## CRM App Follow-Up Checklist

After server load is understood, check for CRM-side fixes:
- Slow Pulse routes need route-level profiling.
- External HTTP calls should have short timeouts and should not block normal page loads.
- Push, sync, alert, and default queues should not all depend on one worker.
- Missing platform tables should be detected before querying.
- SQL aliases should avoid reserved words such as `rows`.
- Heavy dashboard summaries should be cached.
- Large media endpoints should paginate and avoid returning unnecessary fields.

## Incident Evidence Template

```text
Date/time UTC:
Reporter:
Location/network:
Device/browser:
Affected CRM page:

Office ping summary:
Office curl summary:
Healthy network curl summary:
Cloudflare forced curl summary:
Origin forced curl summary:
Office public IP:
CRM resolved IPs:
cPanel curl summary:

Server load:
Top CPU processes:
MariaDB status:
Queue depths:
Pulse slow routes:
Laravel errors:

Conclusion:
Immediate action:
Long-term action:
```

## July 2026 Example Finding

On 2026-07-09, CRM reports from the office initially looked like an ISP issue because the CRM worked from another location. Server-side checks showed otherwise:
- cPanel curl had intermittent high TTFB.
- Server CPU idle was 0%.
- Load average was about 20.
- MariaDB was above 100% CPU.
- Multiple non-CRM PHP-FPM pools were consuming high CPU.
- CRM had a `push` queue backlog and repeated Laravel warnings/errors.

Conclusion: the main issue was server-side saturation/noisy neighbors, with CRM backend issues as secondary contributors. Office network conditions could amplify symptoms but were not the primary root cause.

On 2026-07-10, a second office-only investigation isolated a network path problem:
- Office public IP was `197.248.120.233`.
- Current Cloudflare DNS for `crm.exotic-online.com` returned `172.67.151.11` and `104.21.64.133`.
- Forced Cloudflare request completed in about `0.878s`.
- Forced origin request to `198.38.92.95:443` timed out after `75s`.
- Ping to Cloudflare had low latency with minor loss: about `79ms` average and `5%` loss.
- Ping to origin had `100%` loss.
- Earlier office tests against the origin showed `26%` packet loss, very slow TLS handshakes, HTTP/2 framing errors, resets, and large-asset timeouts.

Conclusion: at that time the CRM app and server were healthy through Cloudflare, but the office network path to the origin IP was broken or blocked. Keep `crm.exotic-online.com` proxied through Cloudflare, flush stale DNS on affected clients/routers, and ask hosting to check whether the office public IP is blocked before escalating routing to the ISP.
