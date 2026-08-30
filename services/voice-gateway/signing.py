from __future__ import annotations

import hashlib
import hmac
import json
import time
import uuid
from typing import Any


def canonical_json(payload: dict[str, Any]) -> bytes:
    return json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def signed_headers(gateway_id: str, secret: str, body: bytes, *, timestamp: int | None = None, event_id: str | None = None) -> dict[str, str]:
    stamp = int(timestamp if timestamp is not None else time.time())
    eid = event_id or uuid.uuid4().hex
    message = str(stamp).encode() + b"\n" + eid.encode() + b"\n" + body
    signature = hmac.new(secret.encode("utf-8"), message, hashlib.sha256).hexdigest()
    return {
        "Content-Type": "application/json",
        "X-Voice-Gateway-Id": gateway_id,
        "X-Voice-Timestamp": str(stamp),
        "X-Voice-Event-Id": eid,
        "X-Voice-Signature": signature,
    }
