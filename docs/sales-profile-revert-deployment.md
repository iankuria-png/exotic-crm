# Sales profile Revert deployment

Sales and field-sales staff can revert a linked Expired/Archived advertiser in their assigned markets from the client page. The profile becomes private in WordPress, the CRM displays Reverted, and a durable removal request prevents automatic recovery from republishing it. Active subscriptions remain protected. A successful new subscription or renewal can return the advertiser to the site.

The release includes the CRM source, tests and a matching frontend build. No WordPress theme/plugin upload, seeder or configuration change is required.

Apply the new migration before web requests or refreshed workers use the removal-hold queries. From the normal CRM cPanel terminal:

```sh
cd ~/crm.exotic-online.com
php artisan down --retry=60
git pull --ff-only origin main
php artisan migrate --path=database/migrations/2026_10_12_000001_add_profile_removal_timestamps_to_clients.php --force
php artisan optimize:clear
php artisan queue:restart
php artisan up
git log -1 --oneline
```

After deployment, sign in as an assigned-market sales user. Use an agreed disposable expired advertiser to verify Revert profile, the required reason, the Reverted badge, a private WordPress readback and anonymous absence from homepage, Unlockable Profiles, location listings and the direct public URL. Verify active paid profiles remain protected and a later recovery run cannot republish the removed advertiser.

If WordPress cannot confirm removal, the request remains recorded, the UI shows removal pending, and staff can retry. A retry checks entitlement again. Ordinary sync and duplicate payment callbacks do not lift the hold.

Rollback may remove the client-page action, but must retain the timestamp columns and automatic publisher hold checks while removal requests exist. Export and reconcile held requests before any schema downgrade.
