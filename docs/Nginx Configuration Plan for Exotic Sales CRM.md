# Nginx Configuration Plan for Exotic Sales CRM

This document outlines the recommended Nginx configuration for the **Exotic Sales CRM**, ensuring a secure, high-performance environment for the Laravel 10 API and React 19 SPA.

## 1. Overview
The CRM uses a hybrid architecture:
- **Frontend:** React SPA (Vite 6) served as static assets.
- **Backend:** Laravel 10 API (Sanctum) handling CRM and legacy Ads API logic.
- **Security:** HTTPS (SSL/TLS) termination and Sanctum-compatible cookie/header handling.

## 2. Recommended Configuration

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name crm.exotickenya.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name crm.exotickenya.com;

    root /path/to/exotic-crm/public;
    index index.php;

    # SSL Configuration (Adjust paths to your certificates)
    ssl_certificate /etc/letsencrypt/live/crm.exotickenya.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/crm.exotickenya.com/privkey.pem;
    include /etc/nginx/snippets/ssl-params.conf;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    charset utf-8;

    # 1. Main SPA & API Routing
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 2. Optimized Static Asset Serving (Vite Build)
    location /build/ {
        expires 1y;
        add_header Cache-Control "public, no-transform";
        access_log off;
        try_files $uri =404;
    }

    # 3. Laravel API PHP Processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Increase timeouts for long-running sync operations
        fastcgi_read_timeout 300;
        proxy_read_timeout 300;
    }

    # 4. WordPress Sync Rate Limiting (Optional but Recommended)
    location /api/crm/sync {
        limit_req zone=crm_sync burst=10 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 5. Deny access to sensitive files
    location ~ /\.(?!well-known).* {
        deny all;
    }

    access_log /var/log/nginx/crm-access.log;
    error_log /var/log/nginx/crm-error.log;
}
```

## 3. Key Use Cases & Benefits

### A. SPA Route Persistence
When a user navigates to `/leads/123` in the React app and refreshes, the `try_files $uri $uri/ /index.php?$query_string;` directive ensures Nginx hands the request to Laravel's `index.php`. Laravel then renders the `crm.blade.php` entry point, allowing React Router to take over and display the correct lead without a 404 error.

### B. High-Performance Asset Delivery
Vite 6 generates versioned assets in `public/build/`. By adding a specific block for `/build/` with a 1-year expiration, Nginx serves these files directly from the disk without touching PHP, significantly reducing dashboard load times.

### C. Large Sync Payload Support
Syncing client profiles from WordPress can involve large JSON payloads. The `fastcgi_read_timeout 300` and `client_max_body_size 64M` (should be added to `http` or `server` block) settings ensure that profile syncs don't time out or get rejected.

### D. CORS and Sanctum Compatibility
Nginx ensures that headers required by Laravel Sanctum (like `X-XSRF-TOKEN`) are passed correctly. By terminating SSL at Nginx, we can use `SECURE` flags on cookies, which is required for modern browser security when the CRM is live.

## 4. Implementation Steps
1. **Local Dev:** Use `laravel-valet` or `laradock`, which already use optimized Nginx templates.
2. **Staging/Prod:** 
   - Create the config file in `/etc/nginx/sites-available/`.
   - Link it to `sites-enabled`.
   - Run `nginx -t` to verify syntax.
   - Reload Nginx: `systemctl reload nginx`.

## 5. Migration Considerations
When deploying the Nginx config, ensure the `root` path points to the `public/` directory of your Laravel monorepo, NOT the project root itself. This is critical for security as it prevents direct access to `.env` and source code files.
