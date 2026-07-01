# AGENTS.md — osTicket

## Project Overview

osTicket is an open-source PHP support ticket system. It manages customer inquiries via web, email, phone, and API into a multi-user web interface.

- **Language**: PHP (8.2–8.4), jQuery, MySQL/MariaDB
- **No build step**: raw PHP served by Apache/IIS — no transpilation, bundling, or package manager install needed for development
- **No automated test suite**: there is no PHPUnit or equivalent configured in this repo

## Repository Structure

```
├── include/              # Core business logic, ORM, and class files
│   ├── class.auth.php    # Authentication backends (staff + client)
│   ├── class.plugin.php  # Plugin system (Plugin, PluginConfig, PluginManager)
│   ├── class.forms.php   # Form/Field API (TextboxField, PasswordField, BooleanField, etc.)
│   ├── class.ticket.php  # Ticket model
│   ├── class.staff.php   # Staff/agent model
│   ├── class.config.php  # Configuration base class
│   ├── plugins/          # Plugin directory — each plugin is a subdirectory
│   ├── staff/            # Staff panel template fragments (.tpl.php)
│   └── client/           # Client portal template fragments
├── scp/                  # Staff Control Panel entry points
│   ├── login.php         # Staff login page
│   └── index.php         # Staff dashboard
├── login.php             # Client portal login
├── api/                  # API entry points
├── setup/                # Installation/upgrade wizard
├── js/, css/, images/    # Static assets
└── manage.php            # CLI deployment tool
```

## Coding Conventions

- **Style**: 4-space indentation, opening brace on same line for classes/functions, PHP short array syntax (`array()` not `[]` — this codebase uses `array()` consistently)
- **ORM**: Custom Django-inspired `VerySimpleModel` — no Eloquent/Doctrine. Query with `::lookup()`, `::objects()->filter()`
- **Strings**: Use `__(...)` and `_S(...)` for translatable strings
- **Config**: Use osTicket's `Config` table via `PluginConfig`, not `.env` files
- **Secrets**: Use `PasswordField` for encrypted storage (uses `Crypto::encrypt` with `SECRET_SALT`). Never store secrets in plaintext
- **HTTP**: Use `Http::redirect()` and `Http::response()` — don't use raw `header()` calls
- **Sessions**: Direct `$_SESSION` access with namespaced keys (e.g. `$_SESSION['_auth']['staff']`)
- **No composer autoloader**: Classes are loaded via explicit `require_once` at file top

## Plugin Development

Plugins live in `include/plugins/{plugin-name}/` with three files:

1. **`plugin.php`** — Returns an info array (`id`, `version`, `name`, `author`, `description`, `url`, `ost_version`, `plugin`). The `plugin` key points to `filename.php:ClassName`.

2. **`config.php`** — A `PluginConfig` subclass with `getOptions()` returning an array of Field objects. These render as the admin config form.

3. **Main file** (e.g. `authentication.php`) — Contains the `Plugin` subclass with `bootstrap()` that registers backends/hooks, plus all business logic.

### Authentication Plugins Specifically

- Extend `ExternalStaffAuthenticationBackend` (staff) and/or `ExternalUserAuthenticationBackend` (client)
- Set static `$id`, `$name`, `$service_name`, `$fa_icon`
- Implement `triggerAuth()` to redirect to the IdP
- Register callback routes via `Signal::connect('api', function($dispatcher) { ... })`
- Login buttons render automatically on login pages — no template edits needed
- Staff lookup: `StaffSession::lookup()` by email or username
- Client lookup: `User::lookup(array('emails__address' => $email))`, wrap in `ClientSession(new EndUser($user))`
- `UserAuthenticationBackend::login()` throws `AccessDenied` for locked/unconfirmed accounts — always catch it

### Reference Implementation

See `include/plugins/auth-okta/` for a complete OIDC authentication plugin example.

## Verification

- **Syntax check**: `php -l <file>` on all modified PHP files (no PHPUnit or linter configured)
- **No CI pipeline**: verify manually with `php -l`; the repo has no GitHub Actions or equivalent

## Security

- Always set `CURLOPT_SSL_VERIFYPEER => true` and `CURLOPT_SSL_VERIFYHOST => 2` on cURL handles
- Use `hash_equals()` for timing-safe comparisons of tokens/state parameters
- Guard `$_GET`/`$_POST` values with `is_string()` before passing to `hash_equals()` — PHP superglobals can contain arrays from malformed query params
- Never disable CSRF protection or skip osTicket's `checkCSRFToken()` flow
- Use `Misc::randCode()` or `bin2hex(random_bytes())` for generating state/nonce values
