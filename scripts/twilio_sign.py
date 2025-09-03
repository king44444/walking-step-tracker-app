#!/usr/bin/env python3
import os, base64, hmac, hashlib

# Environment inputs
URL  = os.environ["URL"]
AUTH = os.environ["AUTH"]

# Use the exact POST values the server will see after form decoding.
# curl --data with '+' in number becomes a leading space when decoded.
POST = {
  "From": " 18015550123",
  "MessageSid": "SM_test123",
  "MessageStatus": "delivered",
  "To": " 13855032310",
}

joined = URL + "".join(k + POST[k] for k in sorted(POST))
sig = base64.b64encode(hmac.new(AUTH.encode(), joined.encode(), hashlib.sha1).digest()).decode()
print(sig)
