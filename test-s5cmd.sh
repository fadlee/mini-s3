#!/bin/bash
set -e

# Configuration
export AWS_ACCESS_KEY_ID="minioadmin"
export AWS_SECRET_ACCESS_KEY="minioadmin"
export AWS_REGION="us-east-1"
export AWS_S3_FORCE_PATH_STYLE=1
ENDPOINT="https://mini-s3.test"
TEST_BUCKET="testbucket"
TEST_FILE="/tmp/mini-s3-test-$(date +%s).txt"
TEST_CONTENT="hello from s5cmd automated test - $(date)"
DOWNLOAD_FILE="/tmp/mini-s3-downloaded-$(date +%s).txt"

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "=================================="
echo "Mini-S3 s5cmd Integration Test"
echo "=================================="
echo ""

# Cleanup function
cleanup() {
    echo ""
    echo -e "${YELLOW}Cleaning up...${NC}"
    rm -f "$TEST_FILE" "$DOWNLOAD_FILE"
    if [ -d "data/$TEST_BUCKET" ]; then
        echo "  Removing test bucket directory: data/$TEST_BUCKET"
        rm -rf "data/$TEST_BUCKET"
    fi
    echo -e "${GREEN}Cleanup complete${NC}"
}

trap cleanup EXIT

# Test 1: Create test file
echo -e "${YELLOW}[1/4] Creating test file...${NC}"
echo "$TEST_CONTENT" > "$TEST_FILE"
echo "  ✓ Created: $TEST_FILE"
echo ""

# Test 2: Upload file
echo -e "${YELLOW}[2/4] Uploading file to s3://$TEST_BUCKET/hello.txt...${NC}"
if s5cmd --endpoint-url "$ENDPOINT" cp "$TEST_FILE" "s3://$TEST_BUCKET/hello.txt"; then
    echo -e "  ${GREEN}✓ Upload successful${NC}"
else
    echo -e "  ${RED}✗ Upload failed${NC}"
    exit 1
fi
echo ""

# Test 3: List bucket contents
echo -e "${YELLOW}[3/4] Listing bucket contents...${NC}"
if s5cmd --endpoint-url "$ENDPOINT" ls "s3://$TEST_BUCKET/"; then
    echo -e "  ${GREEN}✓ List successful${NC}"
else
    echo -e "  ${RED}✗ List failed${NC}"
    exit 1
fi
echo ""

# Test 4: Download and verify
echo -e "${YELLOW}[4/4] Downloading and verifying file...${NC}"
if s5cmd --endpoint-url "$ENDPOINT" cp "s3://$TEST_BUCKET/hello.txt" "$DOWNLOAD_FILE"; then
    echo -e "  ${GREEN}✓ Download successful${NC}"
    
    # Verify content
    if diff -q "$TEST_FILE" "$DOWNLOAD_FILE" > /dev/null; then
        echo -e "  ${GREEN}✓ Content verified - files match${NC}"
    else
        echo -e "  ${RED}✗ Content mismatch${NC}"
        echo "Expected:"
        cat "$TEST_FILE"
        echo "Got:"
        cat "$DOWNLOAD_FILE"
        exit 1
    fi
else
    echo -e "  ${RED}✗ Download failed${NC}"
    exit 1
fi
echo ""

# Test 5: Delete object
echo -e "${YELLOW}[5/5] Deleting object...${NC}"
if s5cmd --endpoint-url "$ENDPOINT" rm "s3://$TEST_BUCKET/hello.txt"; then
    echo -e "  ${GREEN}✓ Delete successful${NC}"
else
    echo -e "  ${RED}✗ Delete failed${NC}"
    exit 1
fi
echo ""

echo "=================================="
echo -e "${GREEN}All tests passed!${NC}"
echo "=================================="
