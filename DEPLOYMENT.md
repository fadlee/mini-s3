# Deployment Guide

## Zero-Install Deployment ✨

This project includes `vendor/` in git, so you can deploy **without running `composer install`**!

### Quick Deploy

```bash
# 1. Clone repository
git clone <your-repo-url> mini-s3
cd mini-s3

# 2. Create config (copy from example)
cp config/config.example.php config/config.php

# 3. Edit config (set your access keys)
nano config/config.php

# 4. Done! Point your web server to public/ directory
```

## Web Server Configuration

### Apache

Point document root to `public/` directory:

```apache
DocumentRoot /path/to/mini-s3/public
```

The `.htaccess` files are already configured.

### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    
    root /path/to/mini-s3/public;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass_header Authorization;
    }
    
    # Deny access to data directory
    location ~ ^/data/ {
        deny all;
        return 403;
    }
}
```

## Update Deployment

```bash
git pull origin main
```

That's it! No `composer install` needed.

## Directory Permissions

```bash
# Make data directory writable by web server
chmod 755 data/
chown -R www-data:www-data data/
```

## Environment Files

Files **NOT** included in git (you need to create):
- `config/config.php` - Your actual config (copy from config.example.php)
- `.env` - Environment variables (if needed)
- `data/` - Storage directory (auto-created)

## Why vendor/ is committed?

- Fat-Free Framework is lightweight (only 588KB)
- Zero-install deployment = simpler CI/CD
- No need for Composer on production servers
- Faster deployment (no dependency resolution)

## If you want to use Composer instead

1. Remove `vendor/` from git:
   ```bash
   git rm -r vendor/
   echo "vendor/" >> .gitignore
   git commit -m "exclude vendor from git"
   ```

2. Run `composer install` on each deployment

## Testing

```bash
# Run integration tests
./test-s5cmd.sh
```

All tests should pass:
- ✅ Upload
- ✅ List
- ✅ Download
- ✅ Delete
