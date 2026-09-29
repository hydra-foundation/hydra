---
section: The skeleton
kind: added
---
Administration → Access lists every API token across all users: owner, name, whether it has expired, when it was created, last used and expires. Paste a leaked token into its search to find exactly that token and its owner. Each row can revoke the token, or every token its owner has. Both go through the token store, as Settings does, and both are audited by owner and token name. The hash never reaches a page or the audit table. The activity log now cuts any API token in a request's query or referer back to `hyd_…`, so a token pasted into a search isn't kept there.

### Upgrading

Copy `AccessModule`, `AccessTokenSource` and `Actions/RevokeOwnerTokens.php`, add `AccessModule` to `AppServiceProvider::MODULES` after `UsersModule`, and add `'access' => ['owner', 'name']` to `AuditAdminEventsListener::AUDITED`. `RecordActivityMiddleware` passes the query and referer through `ApiTokens::redact()`. Your web server's access log still records the query string: if it's kept where others can read it, leave the query out of its log format or redact it there too.
