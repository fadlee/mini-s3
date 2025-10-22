# Mini S3 Server

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)

A lightweight S3-compatible object storage server implemented in PHP, using local filesystem as storage backend.

## Key Features

- ✅ S3 OBJECT API compatibility (PUT/GET/DELETE/POST)
- ✅ Multipart upload support
- ✅ No database required - pure filesystem storage
- ✅ Simple AWS V4 signature authentication
- ✅ Lightweight single-file deployment


## TLDR

Simply create a new website on your virtual host, place the `index.php` file from the GitHub repository into the website's root directory, modify the password configuration at the beginning of `index.php`, then config the rewite rule set all route to index.php, and you're ready to use it.

- **Endpoint**: Your website domain
- **Access Key**: The password you configured
- **Secret Key**: Can be any value (not used in this project)
- **Region**: Can be any value (not used in this project)

For example, if an object has:
- `bucket="music"`
- `key="hello.mp3"`

It will be stored at: `./data/music/hello.mp3`

You can also combine this with Cloudflare's CDN for faster and more stable performance.



## Quick Start

### Requirements

- PHP 8.0+
- Apache/Nginx (with mod_rewrite enabled)

### Installation

1. Set up a website

2. Download `index.php` to your website root directory

3. Create data directory
Create a `data` folder in your website root directory

4. Configure URL rewriting

#### Apache Configuration

Create `.htaccess` in root directory with:
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    # If request is not for existing file/directory
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    # Redirect all requests to index.php
    RewriteRule ^(.*)$ index.php [L,QSA]
</IfModule>
```

#### Nginx Configuration

Add this to your server block:
```nginx
server {
    listen 80;
    server_name your-domain.com;

    root /path/to/mini-s3;
    index index.php;

    # Increase upload size limits
    client_max_body_size 100M;
    client_body_buffer_size 128k;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;  # Adjust PHP version as needed
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;

        # Required for AWS signature authentication
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

For HTTPS (recommended):
```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;

    ssl_certificate /path/to/ssl/cert.pem;
    ssl_certificate_key /path/to/ssl/key.pem;

    root /path/to/mini-s3;
    index index.php;

    client_max_body_size 100M;
    client_body_buffer_size 128k;

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

    location ~ ^/data/ {
        deny all;
        return 403;
    }
}
```

### Configuration

#### Option 1: Edit index.php directly
Edit the configuration at the beginning of `index.php`:
```php
define('DATA_DIR', __DIR__ . '/data');
define('ALLOWED_ACCESS_KEYS', ['your-access-key-here']);
define('MAX_REQUEST_SIZE', 100 * 1024 * 1024); // 100MB
```

#### Option 2: Use separate config.php (recommended)
Create a `config.php` file in the same directory:
```php
<?php
define('DATA_DIR', __DIR__ . '/data');
define('ALLOWED_ACCESS_KEYS', ['minioadmin', 'another-key']);
define('MAX_REQUEST_SIZE', 500 * 1024 * 1024); // 500MB
```

> **Note**: When using third-party S3 tools, only the access key is validated. Secret key and region can be any non-empty values.

## Usage Examples

### AWS CLI

Configure credentials:
```bash
aws configure
# AWS Access Key ID: minioadmin
# AWS Secret Access Key: any-value
# Default region name: us-east-1
# Default output format: json
```

Basic operations:
```bash
# Upload a file
aws s3 cp file.txt s3://mybucket/file.txt --endpoint-url https://your-domain.com

# List bucket contents
aws s3 ls s3://mybucket/ --endpoint-url https://your-domain.com

# Download a file
aws s3 cp s3://mybucket/file.txt downloaded.txt --endpoint-url https://your-domain.com

# Delete a file
aws s3 rm s3://mybucket/file.txt --endpoint-url https://your-domain.com

# Sync directory
aws s3 sync ./local-folder s3://mybucket/folder/ --endpoint-url https://your-domain.com
```

### s5cmd (High-performance S3 tool)

```bash
# Set environment variables
export AWS_ACCESS_KEY_ID="minioadmin"
export AWS_SECRET_ACCESS_KEY="minioadmin"
export AWS_REGION="us-east-1"
export AWS_S3_FORCE_PATH_STYLE=1  # Required for path-style URLs

# Upload file
s5cmd --endpoint-url https://your-domain.com cp file.txt s3://mybucket/file.txt

# List bucket
s5cmd --endpoint-url https://your-domain.com ls s3://mybucket/

# Download file
s5cmd --endpoint-url https://your-domain.com cp s3://mybucket/file.txt ./downloaded.txt

# Delete file
s5cmd --endpoint-url https://your-domain.com rm s3://mybucket/file.txt

# Batch operations
s5cmd --endpoint-url https://your-domain.com cp '*.jpg' s3://mybucket/images/
```

### Python (Boto3)

```python
import boto3
from botocore.client import Config

# Initialize client
s3_client = boto3.client(
    's3',
    endpoint_url='https://your-domain.com',
    aws_access_key_id='minioadmin',
    aws_secret_access_key='minioadmin',
    region_name='us-east-1',
    config=Config(signature_version='s3v4')
)

# Upload file
s3_client.upload_file('local-file.txt', 'mybucket', 'remote-file.txt')

# Download file
s3_client.download_file('mybucket', 'remote-file.txt', 'downloaded-file.txt')

# List objects
response = s3_client.list_objects_v2(Bucket='mybucket')
for obj in response.get('Contents', []):
    print(f"{obj['Key']} - {obj['Size']} bytes")

# Delete object
s3_client.delete_object(Bucket='mybucket', Key='remote-file.txt')

# Generate presigned URL
url = s3_client.generate_presigned_url(
    'get_object',
    Params={'Bucket': 'mybucket', 'Key': 'file.txt'},
    ExpiresIn=3600
)
print(f"Download URL: {url}")
```

### Python (MinIO SDK)

```python
from minio import Minio

# Initialize client
client = Minio(
    "your-domain.com",
    access_key="minioadmin",
    secret_key="minioadmin",
    secure=True  # Use HTTPS
)

# Upload file
client.fput_object("mybucket", "remote-file.txt", "local-file.txt")

# Download file
client.fget_object("mybucket", "remote-file.txt", "downloaded-file.txt")

# List objects
objects = client.list_objects("mybucket", recursive=True)
for obj in objects:
    print(f"{obj.object_name} - {obj.size} bytes")

# Delete object
client.remove_object("mybucket", "remote-file.txt")
```

### JavaScript (AWS SDK v3)

```javascript
import { S3Client, PutObjectCommand, GetObjectCommand, ListObjectsV2Command, DeleteObjectCommand } from "@aws-sdk/client-s3";
import { readFileSync, writeFileSync } from "fs";

// Initialize client
const s3Client = new S3Client({
    endpoint: "https://your-domain.com",
    region: "us-east-1",
    credentials: {
        accessKeyId: "minioadmin",
        secretAccessKey: "minioadmin"
    },
    forcePathStyle: true
});

// Upload file
const uploadFile = async () => {
    const fileContent = readFileSync("file.txt");
    await s3Client.send(new PutObjectCommand({
        Bucket: "mybucket",
        Key: "file.txt",
        Body: fileContent
    }));
    console.log("File uploaded successfully");
};

// List objects
const listObjects = async () => {
    const response = await s3Client.send(new ListObjectsV2Command({
        Bucket: "mybucket"
    }));
    response.Contents?.forEach(obj => {
        console.log(`${obj.Key} - ${obj.Size} bytes`);
    });
};

// Download file
const downloadFile = async () => {
    const response = await s3Client.send(new GetObjectCommand({
        Bucket: "mybucket",
        Key: "file.txt"
    }));
    const content = await response.Body.transformToString();
    writeFileSync("downloaded.txt", content);
};

// Delete object
const deleteFile = async () => {
    await s3Client.send(new DeleteObjectCommand({
        Bucket: "mybucket",
        Key: "file.txt"
    }));
    console.log("File deleted successfully");
};
```

### cURL Examples

```bash
# Note: These examples use simplified authentication.
# For production, implement proper AWS Signature V4 signing.

# Upload file
curl -X PUT \
  -H "Authorization: AWS4-HMAC-SHA256 Credential=minioadmin/..." \
  --data-binary @file.txt \
  https://your-domain.com/mybucket/file.txt

# Download file
curl https://your-domain.com/mybucket/file.txt \
  -H "Authorization: AWS4-HMAC-SHA256 Credential=minioadmin/..." \
  -o downloaded.txt

# List bucket contents (XML response)
curl https://your-domain.com/mybucket/ \
  -H "Authorization: AWS4-HMAC-SHA256 Credential=minioadmin/..."

# Delete file
curl -X DELETE \
  https://your-domain.com/mybucket/file.txt \
  -H "Authorization: AWS4-HMAC-SHA256 Credential=minioadmin/..."
```

## Testing

Run the included test script to verify your installation:
```bash
# Edit test-s5cmd.sh to set your endpoint and credentials
./test-s5cmd.sh
```

The test script validates:
- ✅ File upload
- ✅ Bucket listing
- ✅ File download and content verification
- ✅ File deletion
