#!/bin/bash
# SMS Smoke Test Script
# Tests SMS endpoints with sample payloads to verify functionality

set -e

# Configuration
BASE_URL="${SMS_SMOKE_BASE_URL:-http://localhost}"
SECRET="${SMS_SMOKE_SECRET:-test_secret}"
HOST_HEADER="${SMS_SMOKE_HOST_HEADER:-}"
INSECURE="${SMS_SMOKE_INSECURE:-0}"

echo "=== SMS Smoke Test ==="
echo "Base URL: $BASE_URL"
echo "Using secret: $SECRET"
echo

# Function to make HTTP request and show response
test_endpoint() {
    local method="$1"
    local url="$2"
    local data="$3"
    local headers="$4"
    local description="$5"

    echo "Testing: $description"
    echo "URL: $url"
    echo "Method: $method"

    if [ -n "$data" ]; then
        echo "Data: $data"
    fi

    # Build curl command
    local curl_cmd="curl -s -X $method"
    if [ "$INSECURE" = "1" ]; then
        curl_cmd="$curl_cmd -k"
    fi

    # Add headers
    if [ -n "$headers" ]; then
        curl_cmd="$curl_cmd $headers"
    fi
    if [ -n "$HOST_HEADER" ]; then
        curl_cmd="$curl_cmd -H 'Host: $HOST_HEADER'"
    fi

    # Add data
    if [ -n "$data" ]; then
        curl_cmd="$curl_cmd -d '$data'"
    fi

    # Add URL
    curl_cmd="$curl_cmd '$url'"

    echo "Command: $curl_cmd"
    echo "Response:"
    eval "$curl_cmd" | head -20
    echo
    echo "---"
    echo
}

# Test 1: Inbound SMS (TwiML response)
test_endpoint "POST" "$BASE_URL/api/sms" \
    "From=%2B15551234567&Body=Mon+10k+Tue+12k&MessageSid=SM123456789" \
    "-H 'X-Internal-Secret: $SECRET'" \
    "Inbound SMS - Happy Path"

# Test 2: Inbound SMS (JSON response)
test_endpoint "POST" "$BASE_URL/api/sms?format=json" \
    "From=%2B15551234567&Body=Mon+10k+Tue+12k&MessageSid=SM123456789" \
    "-H 'X-Internal-Secret: $SECRET'" \
    "Inbound SMS - JSON Response"

# Test 3: Outbound SMS
test_endpoint "POST" "$BASE_URL/api/send-sms" \
    "to=%2B15551234567&body=Test+message+from+smoke+test" \
    "-H 'X-Internal-Secret: $SECRET'" \
    "Outbound SMS Send"

# Test 4: Status Callback - Delivered
test_endpoint "POST" "$BASE_URL/api/sms/status" \
    "MessageSid=SM123456789&MessageStatus=delivered&To=%2B15551234567&From=%2B15559876543" \
    "-H 'X-Internal-Secret: $SECRET'" \
    "Status Callback - Delivered"

# Test 5: Status Callback - Failed
test_endpoint "POST" "$BASE_URL/api/sms/status" \
    "MessageSid=SM123456789&MessageStatus=failed&To=%2B15551234567&From=%2B15559876543&ErrorCode=30001" \
    "-H 'X-Internal-Secret: $SECRET'" \
    "Status Callback - Failed"

# Test 6: Rate Limited Request (simulate by sending multiple requests quickly)
echo "Testing rate limiting (sending multiple requests)..."
for i in {1..3}; do
    echo "Request $i:"
    curl -s -X POST \
        -d "From=%2B15551234567&Body=Rate+limit+test+$i&MessageSid=SM123456789$i" \
        -H "X-Twilio-Signature: test_signature" \
        -H "X-Internal-Secret: $SECRET" \
        "$BASE_URL/api/sms.php" | head -5
    echo
    sleep 0.1
done

echo "=== Smoke Test Complete ==="
echo "Check responses above for expected behavior:"
echo "- TwiML responses should contain <Response> tags"
echo "- JSON responses should be valid JSON objects"
echo "- Status callbacks should return minimal responses"
echo "- Rate limiting should eventually block requests"
