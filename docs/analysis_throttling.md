# Analysis: Throttling Strategy defense & Alternatives

## 1. The Defense of the Current Design (File-based + Flock)

**"We throttle to save the Inbox, not the Server."**

The primary critique of PHP-level throttling is that it fails to protect the valid server resources (CPU/RAM). By the time the plugin checks a file lock, the heavy PHP-FPM process has already spawned, consumed memory, and bootstrapped WordPress. A 5000 RPS attack will take down the server regardless of whether the plugin returns 429 or 200.

**However, the defense works if the goal is preventing Side-Side Effects:**
1.  **Mail Reputation:** Sending 5,000 emails in a minute will get the domain blacklisted by Gmail/Outlook immediately.
2.  **Quota Protection:** Prevents burning through SMTP API quotas (SendGrid, SES).
3.  **Drive-by Spam:** Most spam isn't DDoS; it's a script hitting the form 5 times a second. File-based throttling explicitly stops this effectively.

**Why Files?**
*   **Constraint:** "No Database Writes" + "No External Dependencies (Redis)".
*   **Solution:** The filesystem is the only shared state available in a standard LAMP stack. `flock` is the standard POSIX mechanism for atomic coordination.
*   **Robustness:** With the move to WP-Cron for GC, the file method is safe (no request-time O(N) cost).

**Verdict:** It is the "Toyota Camry" solution. Boring, slightly heavy (I/O), but runs reliably in every environment without configuration.

---

## 2. More Elegant Alternatives

### A. Proof of Work (PoW) / Hashcash (Depressingly Elegant)
Instead of tracking state on the server ("How many times has IP X visited?"), force the client to "pay" for the submission.

*   **Mechanism:**
    1.  Server sends a `challenge_prefix` (e.g., `sha256(secret + date + round(time/600))`) in the form.
    2.  Client JS must find a number `nonce` such that `sha256(challenge_prefix + nonce)` starts with `0000` (4 zeros).
    3.  Browser takes ~200-500ms to calculate this.
    4.  Server verifies in 0.01ms.
*   **Pros:**
    *   **Stateless:** Server stores nothing. No files, no `flock`.
    *   **Asymmetric:** Cheap for server, expensive for bot.
    *   **DDoS Resistant:** Bots cannot generate nonces fast enough to overwhelm the inbox without burning massive CPU.
*   **Cons:**
    *   **Mobile Battery:** heavy calculation drains battery.
    *   **Accessibility:** Users with disabled JS cannot submit (we already have JS-mint mode though).
    *   **Latency:** Adds ~500ms to submission time.

### B. The "Traffic Cop" Minting Strategy
We already have `/eforms/mint`.
*   **Idea:** Only throttle the *Minting* endpoint.
*   **Theory:** You can't POST without a token. If we rate-limit the token distribution, we effectively rate-limit valid submissions.
*   **Flaw:** Attackers can just cache one valid token and replay the POST (if we don't invalidate tokens). If we do invalidate tokens (which we do), they just hit `/mint` 100 times.
*   **Result:** We still need to throttle `/mint`. We are back to the file locking problem.

### C. Signed "Leaky Bucket" Cookies
*   **Mechanism:** Server sets a cookie `rate_limit_token` containing `encrypted_json({ "count": 1, "reset_at": time + 60, "ip": "1.2.3.4" })`.
*   **Logic:** On request, decrypt cookie, increment count, fail if > max, re-encrypt and set-cookie.
*   **Pros:** Stateless on server (state drives in the client cookie).
*   **Cons:**
    *   **Bypass:** Attacker simply clears cookies (requires IP binding inside the encrypted payload to mitigate).
    *   **Replay:** Attacker records a "count: 0" cookie and replays it (requires nonces/timestamps in the cookie to prevent replay, which gets complex).
    *   **Spec violation:** User spec says "No cookies".

---

## 3. Delegating to Linux (Out-of-the-Box)

**"Can't we just use Fail2ban?"**

Actually, **YES**. We can invert the control.

**The Strategy: "Log & Punish" (Passive Defense)**
Instead of actively tracking counts in PHP, we just **Log** the event to a specific file, and let a standard Linux tool read it and block the IP at the firewall (iptables/nftables) or web server (Nginx/Apache) level.

### Fail2ban Integration (Existing Feature)
*   **Mechanism:**
    1.  Eforms writes a log line: `[eforms] IP: 1.2.3.4 Action: ValidatedSubmission` (or just rely on access logs).
    2.  Fail2ban (daemon) watches the log.
    3.  If `Action: ValidatedSubmission` appears > 5 times in 60s for `1.2.3.4` -> **BAN**.
    4.  Ban action adds an `iptables` rule or updates an Nginx map.

*   **Pros:**
    *   **Zero PHP Overhead:** PHP just writes a log line (fast).
    *   **Real Protection:** Banning happens at the Network layer (iptables) or Web Server layer. The request never even *reaches* PHP. This protects CPU/RAM, not just the inbox.
    *   **Standard:** Every sysadmin knows Fail2ban.

*   **Cons:**
    *   **Not "Out of the Box" for the Plugin:** The plugin cannot install or configure Fail2ban. The *Operator* must do it.
    *   **Shared Hosting:** Most shared hosts (GoDaddy, Bluehost) **do not** give you Fail2ban access or `iptables` privileges.
    *   **Feedback Loop:** The user doesn't get a nice "Please wait 60s" message; they just get a connection refused or 403.

### Verdict on Linux Delegation
It is a **Deployment Choice**, not a Software Feature.
*   **The Spec already supports this**: We have the "Fail2ban emission" channel (Section 16).
*   **We cannot rely on it**: We cannot assume the host OS has these tools or that the user has root.
*   **Conclusion**: We **MUST** keep the PHP-level file throttle as the "default defense" for shared hosting compatibility. However, we should explicitly document how to disable it (`throttle.enable=false`) and rely on Fail2ban for "Pro" deployments.

Provide example Fail2ban jail.

```ini
# /etc/fail2ban/jail.d/eforms.conf
[eforms]
enabled = true
filter = eforms
logpath = /var/www/wp-content/uploads/eforms-private/logs/*.jsonl
maxretry = 10
findtime = 60
bantime = 600
```

---

## 4. Delegating to Cloudflare (The Modern Standard)

**"Why build it if the WAF does it better?"**

If the site is behind Cloudflare, you can use **Rate Limiting Rules** (WAF).

*   **Mechanism:**
    1.  Operator configures CF Rule: `Map URI Path` matching `/contact-us` (or wherever the form is).
    2.  Rule: `If requests > 5 in 1 minute per IP` -> `Block` or `Managed Challenge`.

*   **Pros:**
    *   **Ultimate Protection:** Blocks traffic at the **Edge**. The request never hits your origin server. Perfect CPU/RAM protection.
    *   **Zero Code:** No plugin logic required.
    *   **Smart:** Cloudflare handles distributed attacks, IP rotation, and false positives better than any PHP script.

*   **Cons:**
    *   **Configuration Gap:** The plugin cannot configure Cloudflare for you. The user must manually set up the WAF rules.
    *   **Cost:** While basic protection is free, granular Rate Limiting rules often require paid plans or have limits on the free tier.
    *   **Specificity:** A generic WAF rule usually rate-limits the *Page Load*, not the *Form Submission*. If a user reloads the page 10 times to fix a typo, they get blocked.
        *   *Workaround:* Target the POST request specifically. But many contact forms POST to the same URL as the page.

### Verdict on Cloudflare
Excellent for **High-Traffic / Attack-Prone sites**.
*   It is strictly superior to both PHP-level and Fail2ban-level throttling for DDoS protection.
*   However, like Fail2ban, it is an **external dependency**. We cannot assume every user has it.
*   **Recommendation:** Document this as the "Platinum" defense tier. "If you are under attack, turn on Cloudflare Rate Limiting." But keep the PHP throttle as the built-in safety net.

---

## 5. Option E: Byte-Counter Logic (The Elegant Hybrid)

**"Filesize = Count"**

This proposal strips away the last bits of over-engineering (JSON, Cooldowns) and relies purely on filesystem metadata.
**Refinement:** Rejected requests do **not** write to the file. This ensures the file is bounded.

### The Design
*   **Throttle file:** `throttle/{h2}/{ip_hash}.tally`
*   **Mechanism:**
    1.  Request comes in.
    2.  Open file (append mode) + `flock` (exclusive).
    3.  `fstat` to get `mtime` and `size`.
    4.  **Reset:** If `mtime < window_start` (start of current minute) -> `ftruncate(0)`, `size = 0`.
    5.  **Check:** If `size >= max_per_minute` -> 
        *   Unlock/Close.
        *   Hard Fail (429).
        *   **Do NOT write.**
    6.  **Allow:**
        *   `fwrite(1 byte)`.
        *   Unlock/Close.
        *   Proceed.

### Why this is superior
1.  **Bounded Growth:** Even under a 100k RPS attack, the files on disk never exceed `max_per_minute` bytes (e.g., 60 bytes).
2.  **Shared Mental Model:** It aligns perfectly with how we treat tokens and ledger entries. "One file per entity. Timestamps matter. Size matters."
3.  **Performance:** `filesize()` and `mtime` calls are often cached by the OS/PHP. Writing 1 byte is infinitesimally cheaper than reading+parsing+writing JSON.
4.  **Simplicity:**
    *   No `json_decode` / `json_encode`.
    *   No `cooldown` files or logic.
    *   No per-form fanout (IP-global is safer/simpler).
5.  **Robustness:** It is just as atomic (`flock`) as the JSON version but with fewer moving parts.

---

## 6. Option F: The Full Hybrid (Byte-Counter + Minimal Cooldown)

**"Sentinel Cooldown"**

The critique of Option E: During a sustained attack (5k RPS), `flock` contention is high. Every blocked request still locks the file.
The fix: **A Sentinel File** for the cooldown period.

### Optimized Design
*   **Files:**
    *   `.tally`: Byte counter.
    *   `.cooldown`: Sentinel, empty, mtime-driven.

*   **Mechanism:**
    1.  **Fast Path:** If `.cooldown` exists AND `filemtime` is fresh (> now - cooldown_secs):
        *   **Abort 429** (No flock, no open). Cost: O(1) `stat` call.
    2.  Open `.tally` + `flock`.
    3.  `fstat` → `mtime`, `size`.
    4.  **Reset:** If `mtime < window_start` → `ftruncate(0)`, `size = 0`.
    5.  **Trigger:** If `size >= max` →
        *   `touch(.cooldown)`.
        *   Unlock/Close.
        *   Return 429.
    6.  **Allow:**
        *   `fwrite(1 byte)`.
    7.  Unlock/Close, proceed.

### Why Add Cooldown Back?
*   **Complexity vs. Cost:** It adds ~5 lines of code and 1 file per spammer.
*   **Benefit:** It reduces the attack cost from `O(n) flock` (serializing PHP workers) to `O(1) stat` (parallel OS calls).
*   **Real World:** Under a heavy "dumb script" attack, this prevents the request queue from backing up waiting for locks.

### Final Verdict: Option F
This strikes the perfect balance.
*   **Byte-counter** simplicity for the state.
*   **Sentinel** efficiency for the block.
*   **Mental Model:** Consistent (files = state).

**Recommendation:** Adopt Option F.
