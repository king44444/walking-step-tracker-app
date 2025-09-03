# /Users/michaelking/Documents/projects/king-walk-week/api/sign.py
import base64, hashlib, hmac
auth = "71883e6161a91b33bc6163a5670db921"
url  = "https://mikebking.com/dev/html/walk/api/sms_status.php"
post = {
  "From": "+18015550123",
  "MessageSid": "SM_test123",
  "MessageStatus": "delivered",
  "To": "+13855032310",
}
to_sign = url + "".join(k + post[k] for k in sorted(post.keys()))
sig = base64.b64encode(hmac.new(auth.encode(), to_sign.encode(), hashlib.sha1).digest()).decode()
print(sig)