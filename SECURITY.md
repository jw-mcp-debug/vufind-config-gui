# Security

**vufind-config-gui can change every setting of a VuFind installation.** VuFind's
configuration contains database passwords, ILS credentials and API keys. Anyone
who can use the GUI can read them and can, for example, point VuFind to another
database or switch off authentication. Treat access to the GUI like shell
access to the VuFind server.

## Recommended operation

- **Bind to 127.0.0.1** (the default in all examples) and reach it through an
  SSH tunnel if needed:

  ```bash
  ssh -L 8181:127.0.0.1:8181 vufind-server
  ```

- If several people need access, put it behind a reverse proxy that enforces
  authentication (SSO, client certificates, VPN) **and** set `auth` and
  `allowed_hosts` in the configuration.
- **Never expose it to the internet** without such a layer, and not on a
  production server during opening hours if you can avoid it.
- Mount VuFind's original files read-only. The GUI only needs write access to
  `VUFIND_LOCAL_DIR`.
- Run it as an unprivileged user that owns the local directory, not as root.

## What the GUI protects against by itself

Binding to localhost does not stop attacks that run through the
administrator's own browser. The GUI therefore also does the following:

| Threat | Countermeasure |
|---|---|
| **Cross-site request forgery.** Any web page can send a "simple" POST to `http://localhost:…`. | Every API call must carry the header `X-VuFind-Config-Gui: 1` and, for writes, a JSON content type. Browsers only allow a foreign page to send that header after a CORS preflight, and the GUI never approves one. `Sec-Fetch-Site` is checked where the browser sends it. |
| **DNS rebinding.** A foreign domain that resolves to 127.0.0.1 could read the API as "same origin". | The `Host` header must be on `allowed_hosts` (default: `localhost`, `127.0.0.1`, `[::1]`). |
| **Clickjacking.** | `X-Frame-Options: DENY` and `frame-ancestors 'none'`. |
| **Script injection** through configuration values. | All values are inserted as text, never as HTML. A strict Content Security Policy allows no inline scripts or styles. |
| **Path traversal.** | Only `config/vufind/**` and `import/**` with `.ini`, `.yaml` or `.properties` can be read or written, and `..` is rejected. |
| **Broken configuration.** | ini files that PHP cannot parse are never written. Every write is preceded by a backup. |

The optional `auth` setting adds HTTP Basic authentication with a
`password_hash()` hash. Basic authentication sends the password with every
request, so use it only over HTTPS or on localhost.

## What it does not do

- There are no user roles. Everyone who gets in can change everything.
- Password fields are masked in the form, but their values are sent to the
  browser so they can be edited.
- No audit log beyond the backup copies (file name = time of change).

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting ("Security" tab → "Report a
vulnerability") instead of a public issue.
