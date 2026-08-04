# Gateway reachability from this container — DO NOT MISREAD THIS (2026-08-01)

## The finding

`api.mwbmpartners.ltd` is **NOT reachable from this container**, and that tells us
**NOTHING about whether the gateway is deployed.**

```
https://api.mwbmpartners.ltd/v1/health   -> 000
https://api.mwbmpartners.ltd/v1/status   -> 000
https://api.mwbmpartners.ltd/heartbeat   -> 000
```

## Why this is NOT evidence the gateway is down

The agent proxy denied the CONNECT:

```
{"ts":"2026-08-01T07:06:16Z","kind":"connect_rejected",
 "detail":"gateway answered 403 to CONNECT (policy denial or upstream failure)",
 "host":"api.mwbmpartners.ltd:443"}
```

And the control proves it is a policy denial, not host absence:

| host | result | known state |
|---|---|---|
| `https://api.github.com` | **200** | reachable — proxy allows it |
| `https://ihymns.app` | **000** | **definitely live** (production site) |
| `https://mwbmpartners.ltd` | **000** | the company's own site |
| `https://api.mwbmpartners.ltd` | **000** | unknown |

`ihymns.app` is the owner's production deployment and certainly up, yet it returns
`000` through this proxy. The network policy blocks the `mwbmpartners.ltd` /
`ihymns.app` domains wholesale.

## The rule this enforces

**Do not conclude "the gateway is not deployed" from a failed request here.** That
is precisely the confident-negative class that cost this session repeatedly — the
"there is no browser" (Chromium 141 was installed) and "the wiki is not in this
container" (it is at `wiki/`, 18 pages) errors. A tooling restriction reads
identically to an absence.

Deployment status must be established from the repo's own docs/CHANGELOG, or from
the owner directly. It CANNOT be established from this container.

## Consequence for the integration

Any HMAC signature, endpoint shape, or error-code behaviour we implement is
verifiable ONLY against the gateway's source code, never against the live service,
from here. The canonical-string off-by-one that produces a silent 401 is exactly
the kind of defect that source-reading alone does not catch.

=> The plan must treat "verified against the real gateway" as an explicitly
   DEFERRED verification step requiring either the owner to run it, a CI job with
   network access, or a local stub of the gateway. A stub built from the gateway's
   own middleware source is the strongest thing achievable in this container.
