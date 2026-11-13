#!/usr/bin/env python3
"""Independent, loopback-only ChatGPT-style OAuth/MCP acceptance client.

Never contacts bambodia.com or chatgpt.com. Their URLs are *asserted* as
protocol metadata / redirect destinations; every actual request goes to
127.0.0.1 on the disposable QA server. Never print credentials or bearer tokens.
"""
from __future__ import annotations

import base64
import hashlib
import html
from html.parser import HTMLParser
import json
import os
import re
import secrets
import sys
from http.cookies import SimpleCookie
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit, parse_qs
from urllib.request import HTTPRedirectHandler, Request, build_opener

RESOURCE = "https://bambodia.com/mcp"
ISSUER = "https://bambodia.com"
CALLBACK = "https://chatgpt.com/connector/oauth/qa-callback"
WRITE_NAMES = {
    "bambodia_create_draft", "bambodia_update_draft", "bambodia_delete_draft",
    "bambodia_update_own_profile", "bambodia_manage_site", "bambodia_draft_media",
}


def require(value: bool, label: str) -> None:
    if not value:
        raise RuntimeError("CHATGPT_LOOPBACK_BLOCKED=" + label)


class Forms(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.inputs: dict[str, str] = {}
        self.csrf_meta: str | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        if tag == "input" and values.get("name"):
            name = str(values["name"])
            self.inputs.setdefault(name, str(values.get("value") or ""))
        if tag == "meta" and values.get("name") == "csrf-token":
            self.csrf_meta = str(values.get("content") or "")


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Loopback:
    def __init__(self) -> None:
        port = os.environ.get("BAMBODIA_LOOPBACK_PORT", "")
        require(port.isascii() and port.isdecimal() and 1024 <= int(port or 0) <= 65535,
                "INVALID_LOOPBACK_PORT")
        self.base = "http://127.0.0.1:" + port
        self.opener = build_opener(NoRedirect)
        self.cookies: dict[str, str] = {}

    def request(self, method: str, path: str, *, form=None, payload=None, bearer=None):
        require(path.startswith("/") and not path.startswith("//") and
                "\r" not in path and "\n" not in path, "UNSAFE_REQUEST_PATH")
        require((form is None) or (payload is None), "AMBIGUOUS_BODY")
        body = None
        headers = {
            "Host": "bambodia.com",
            "Accept": "application/json, text/html, text/event-stream",
            "X-Forwarded-Proto": "https",
            "Connection": "close",
        }
        if form is not None:
            body = urlencode(form).encode("utf-8")
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        if payload is not None:
            body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
            headers["Content-Type"] = "application/json"
        if bearer:
            headers["Authorization"] = "Bearer " + bearer
        if self.cookies:
            headers["Cookie"] = "; ".join(k + "=" + v for k, v in self.cookies.items())
        req = Request(self.base + path, data=body, headers=headers, method=method)
        try:
            r = self.opener.open(req, timeout=12)
        except HTTPError as error:
            r = error
        except URLError as error:
            raise RuntimeError("CHATGPT_LOOPBACK_BLOCKED=LOCAL_HTTP_UNREACHABLE") from error
        with r:
            status = r.status
            location = r.headers.get("Location", "")
            for item in (r.headers.get_all("Set-Cookie") or []):
                parsed = SimpleCookie()
                parsed.load(item)
                for key, cookie in parsed.items():
                    self.cookies[key] = cookie.value
            data = r.read(1024 * 1024)
            require(len(data) < 1024 * 1024, "HTTP_RESPONSE_TOO_LARGE")
        return status, location, data

    def json(self, method: str, path: str, *, form=None, payload=None, bearer=None):
        status, location, body = self.request(method, path, form=form,
                                               payload=payload, bearer=bearer)
        try:
            result = json.loads(body)
        except (ValueError, UnicodeDecodeError):
            raise RuntimeError("CHATGPT_LOOPBACK_BLOCKED=INVALID_JSON_RESPONSE") from None
        require(isinstance(result, dict), "INVALID_JSON_SHAPE")
        return status, location, result

    def rpc(self, bearer: str, name: str, params=None):
        status, _location, data = self.json("POST", "/mcp", bearer=bearer, payload={
            "jsonrpc": "2.0", "id": secrets.randbelow(100000) + 1,
            "method": name, "params": params or {},
        })
        require(status == 200 and "result" in data and "error" not in data,
                "MCP_RPC_REQUEST_FAILED")
        return data["result"]


def parse_html(body: bytes) -> Forms:
    obj = Forms()
    obj.feed(body.decode("utf-8", "replace"))
    return obj


def redirect_query(status: int, location: str) -> dict[str, str]:
    require(status in (302, 303) and location, "OAUTH_MISSING_CALLBACK_REDIRECT")
    uri = urlsplit(html.unescape(location))
    require(uri.scheme == "https" and uri.netloc == "chatgpt.com"
            and uri.path == "/connector/oauth/qa-callback",
            "OAUTH_REDIRECT_NOT_CHATGPT_CALLBACK")
    return {k: v[0] for k, v in parse_qs(uri.query).items() if v}


def register(client: Loopback, label: str) -> str:
    status, _location, data = client.json("POST", "/oauth/register", payload={
        "client_name": "Bambodia ChatGPT Loopback " + label,
        "redirect_uris": [CALLBACK],
    })
    require(status == 201 and isinstance(data.get("client_id"), str)
            and data["client_id"], "CHATGPT_DCR_REJECTED")
    return data["client_id"]


def authorize(client: Loopback, client_id: str, scopes: str, *, deny=False):
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(
        hashlib.sha256(verifier.encode()).digest()
    ).rstrip(b"=").decode()
    state = secrets.token_urlsafe(24)
    params = {
        "client_id": client_id, "redirect_uri": CALLBACK,
        "response_type": "code", "scope": scopes, "state": state,
        "code_challenge": challenge, "code_challenge_method": "S256",
        "resource": RESOURCE,
    }
    status, _location, page = client.request("GET", "/oauth/authorize?" + urlencode(params))
    require(status == 200 and b"Connect Bambodia Manager" in page,
            "ADMIN_CONSENT_PAGE_NOT_RENDERED")
    fields = parse_html(page).inputs
    require(all(fields.get(k) for k in ("_token", "client_id", "auth_token", "state")),
            "CONSENT_FORM_INCOMPLETE")
    require(fields["state"] == state and fields["client_id"] == client_id,
            "CONSENT_STATE_OR_CLIENT_MISMATCH")
    form = {k: fields[k] for k in ("_token", "state", "client_id", "auth_token")}
    if deny:
        form["_method"] = "DELETE"
    result, loc, _data = client.request("POST", "/oauth/authorize", form=form)
    callback = redirect_query(result, loc)
    require(callback.get("state") == state, "OAUTH_CALLBACK_STATE_MISMATCH")
    if deny:
        require(callback.get("error") == "access_denied" and "code" not in callback,
                "OAUTH_DENIAL_NOT_ENFORCED")
        return None
    require(callback.get("code") and "error" not in callback,
            "OAUTH_CONSENT_NO_AUTHORIZATION_CODE")
    return callback["code"], verifier


def exchange(client: Loopback, client_id: str, code: str, verifier: str):
    status, _location, result = client.json("POST", "/oauth/token", form={
        "grant_type": "authorization_code", "client_id": client_id,
        "redirect_uri": CALLBACK, "code": code,
        "code_verifier": verifier, "resource": RESOURCE,
    })
    require(status == 200 and result.get("token_type", "").lower() == "bearer"
            and isinstance(result.get("access_token"), str)
            and isinstance(result.get("refresh_token"), str),
            "PKCE_TOKEN_EXCHANGE_FAILED")
    return result


def jwt_claims(access: str) -> dict:
    try:
        encoded = access.split(".")[1]
        return json.loads(base64.urlsafe_b64decode(encoded + "=" * (-len(encoded) % 4)))
    except (IndexError, ValueError):
        raise RuntimeError("CHATGPT_LOOPBACK_BLOCKED=MALFORMED_ACCESS_TOKEN") from None


def check_bearer(client: Loopback, bearer: str, client_id: str):
    claims = jwt_claims(bearer)
    require(claims.get("iss") == ISSUER and claims.get("aud") == [client_id, RESOURCE]
            and isinstance(claims.get("jti"), str), "SIGNED_TOKEN_WRONG_ISSUER_AUDIENCE")
    status, _loc, result = client.json("POST", "/mcp", bearer=bearer, payload={
        "jsonrpc": "2.0", "id": 3, "method": "initialize", "params": {},
    })
    require(status == 200 and result.get("result", {}).get("serverInfo", {}).get("name")
            == "bambodia", "OAUTH_ACCESS_TOKEN_NOT_ACCEPTED_AT_MCP")
    return claims["jti"]


def revoke(client: Loopback, token_id: str, password: str, access: str, refresh: str,
           client_id: str) -> None:
    status, _loc, page = client.request("GET", "/developer/oauth-connections")
    require(status == 200 and b"OAuth Connections" in page,
            "DEVELOPER_CONNECTION_MANAGEMENT_UNAVAILABLE")
    parsed = parse_html(page)
    csrf = parsed.csrf_meta or parsed.inputs.get("_token")
    require(bool(csrf), "DEVELOPER_CSRF_MISSING")
    status, _loc, _body = client.request("POST",
                                        "/developer/oauth-connections/" + token_id,
                                        form={"_token": csrf, "_method": "DELETE",
                                              "password": password})
    require(status in (302, 303), "CONNECTION_REVOCATION_FAILED")
    status, _loc, _data = client.json("POST", "/mcp", bearer=access, payload={
        "jsonrpc": "2.0", "id": 99, "method": "tools/list", "params": {},
    })
    require(status == 401, "REVOKED_ACCESS_TOKEN_STILL_USABLE")
    status, _loc, _data = client.json("POST", "/oauth/token", form={
        "grant_type": "refresh_token", "client_id": client_id,
        "refresh_token": refresh, "resource": RESOURCE,
    })
    require(status == 400, "REVOKED_REFRESH_TOKEN_STILL_USABLE")


def run() -> None:
    client = Loopback()
    password = os.environ.get("BAMBODIA_QA_ADMIN_PASSWORD", "")
    email = os.environ.get("BAMBODIA_QA_ADMIN_EMAIL", "")
    foreign_id = os.environ.get("BAMBODIA_QA_FOREIGN_ID", "")
    require(bool(email and password and foreign_id.isdecimal()), "QA_FIXTURE_NOT_CONFIGURED")

    status, _loc, root = client.json("GET", "/.well-known/oauth-protected-resource")
    require(status == 200 and root.get("resource") == RESOURCE
            and root.get("authorization_servers") == [ISSUER],
            "ROOT_RESOURCE_METADATA_WRONG")
    status, _loc, sub = client.json("GET", "/.well-known/oauth-protected-resource/mcp")
    require(status == 200 and sub.get("resource") == RESOURCE,
            "NESTED_RESOURCE_METADATA_WRONG")
    status, _loc, auth_server = client.json("GET", "/.well-known/oauth-authorization-server")
    require(status == 200 and "authorization_endpoint" in auth_server
            and "token_endpoint" in auth_server, "AUTH_SERVER_METADATA_MISSING")
    status, _loc, _body = client.request("POST", "/mcp", payload={
        "jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {},
    })
    require(status == 401, "ANONYMOUS_MCP_NOT_REJECTED")
    print("CHATGPT_LOOPBACK_DISCOVERY_AND_BEARER_CHALLENGE=PASS")

    read_client = register(client, "read-only")
    status, _loc, page = client.request("GET", "/login")
    require(status == 200, "MAIN_SITE_LOGIN_FORM_MISSING")
    csrf = parse_html(page).inputs.get("_token")
    require(bool(csrf), "MAIN_SITE_LOGIN_CSRF_MISSING")
    status, _loc, _body = client.request("POST", "/login", form={
        "_token": csrf, "email": email, "password": password,
    })
    require(status in (302, 303), "MAIN_SITE_ADMIN_SIGNIN_FAILED")
    authorize(client, read_client, "mcp:use", deny=True)
    print("CHATGPT_LOOPBACK_ADMIN_SIGNIN_AND_DENIAL=PASS")
    code, verifier = authorize(client, read_client, "mcp:use")
    read_tokens = exchange(client, read_client, code, verifier)
    read_access = read_tokens["access_token"]
    check_bearer(client, read_access, read_client)
    defs = client.rpc(read_access, "tools/list")["tools"]
    names = {x["name"] for x in defs}
    require(len(defs) == 4 and not names.intersection(WRITE_NAMES),
            "READ_ONLY_TOOL_DISCOVERY_INCLUDES_WRITE")
    for item in defs:
        schemes = item.get("_meta", {}).get("securitySchemes", [])
        require(schemes and schemes[0].get("scopes") == ["mcp:use"],
                "READ_TOOL_SECURITY_METADATA_INVALID")
    require(client.rpc(read_access, "tools/call", {
        "name": "bambodia_overview", "arguments": {}
    }).get("isError") is False, "READ_TOOL_EXECUTION_FAILED")
    blocked = client.rpc(read_access, "tools/call", {
        "name": "bambodia_create_draft",
        "arguments": {"entity": "service", "fields": {
            "title": "Forbidden loopback draft",
            "description": "No read-only client may write to Bambodia records.",
        }},
    })
    require(blocked.get("isError") is True, "READ_TOKEN_PERMITTED_WRITE")
    print("CHATGPT_LOOPBACK_PKCE_READ_ONLY_TOOLS_AND_WRITE_DENIAL=PASS")
    status, _loc, renewed = client.json("POST", "/oauth/token", form={
        "grant_type": "refresh_token", "client_id": read_client,
        "refresh_token": read_tokens["refresh_token"],
        "scope": "mcp:use", "resource": RESOURCE,
    })
    require(status == 200 and renewed.get("access_token") and renewed.get("refresh_token"),
            "REFRESH_TOKEN_ROTATION_FAILED")
    new_access = renewed["access_token"]
    new_refresh = renewed["refresh_token"]
    jti = check_bearer(client, new_access, read_client)
    status, _loc, _data = client.json("POST", "/oauth/token", form={
        "grant_type": "refresh_token", "client_id": read_client,
        "refresh_token": read_tokens["refresh_token"], "resource": RESOURCE,
    })
    require(status == 400, "REPLAYED_REFRESH_TOKEN_ACCEPTED")
    revoke(client, jti, password, new_access, new_refresh, read_client)
    print("CHATGPT_LOOPBACK_REFRESH_ROTATION_AND_REVOCATION=PASS")

    write_client = register(client, "write-scope")
    code, verifier = authorize(client, write_client, "mcp:use mcp.write")
    write_tokens = exchange(client, write_client, code, verifier)
    write_access = write_tokens["access_token"]
    write_id = check_bearer(client, write_access, write_client)
    tools = client.rpc(write_access, "tools/list")["tools"]
    names = {x["name"] for x in tools}
    require(len(tools) == 10 and names.issuperset(WRITE_NAMES),
            "SCOPED_WRITE_TOOL_DISCOVERY_FAILED")
    for entry in tools:
        if entry["name"] in WRITE_NAMES:
            require(entry.get("_meta", {}).get("securitySchemes", [{}])[0].get("scopes")
                    == ["mcp:use", "mcp.write"], "WRITE_SECURITY_METADATA_INVALID")
    attempt = client.rpc(write_access, "tools/call", {
        "name": "bambodia_update_draft",
        "arguments": {"entity": "service", "id": int(foreign_id),
                      "fields": {"title": "Forbidden foreign draft update"}},
    })
    require(attempt.get("isError") is True, "CROSS_OWNER_UPDATE_ALLOWED")
    attempt = client.rpc(write_access, "tools/call", {
        "name": "bambodia_delete_draft",
        "arguments": {"entity": "service", "id": int(foreign_id),
                      "confirm_title": "Another QA admin draft"},
    })
    require(attempt.get("isError") is True, "CROSS_OWNER_DELETION_ALLOWED")
    result = client.rpc(write_access, "tools/call", {
        "name": "bambodia_create_draft",
        "arguments": {"entity": "service", "fields": {
            "title": "Temporary ChatGPT loopback QA service",
            "description": "This disposable service exists only in an isolated OAuth QA SQLite database.",
        }},
    })
    created = result.get("structuredContent", {})
    require(result.get("isError") is False and created.get("status") == "draft"
            and isinstance(created.get("id"), int),
            "OWN_DRAFT_CREATION_FAILED")
    draft_id = created["id"]
    invalid = client.rpc(write_access, "tools/call", {
        "name": "bambodia_update_draft",
        "arguments": {"entity": "service", "id": draft_id,
                      "fields": {"status": "published"}},
    })
    require(invalid.get("isError") is True, "DRAFT_PUBLICATION_BYPASS_ALLOWED")
    updated = client.rpc(write_access, "tools/call", {
        "name": "bambodia_update_draft",
        "arguments": {"entity": "service", "id": draft_id,
                      "fields": {"title": "Updated disposable loopback service"}},
    })
    require(updated.get("structuredContent", {}).get("title")
            == "Updated disposable loopback service", "OWN_DRAFT_UPDATE_FAILED")
    deleted = client.rpc(write_access, "tools/call", {
        "name": "bambodia_delete_draft",
        "arguments": {"entity": "service", "id": draft_id,
                      "confirm_title": "Updated disposable loopback service"},
    })
    require(deleted.get("structuredContent", {}).get("deleted") is True,
            "OWN_DRAFT_CLEANUP_FAILED")
    # The foreign record remains untouched after both denied mutations.
    foreign = client.rpc(write_access, "tools/call", {
        "name": "bambodia_get", "arguments": {"entity": "service", "id": int(foreign_id)}
    })
    require(foreign.get("structuredContent", {}).get("title")
            == "Another QA admin draft", "FOREIGN_DRAFT_CHANGED")
    revoke(client, write_id, password, write_access,
           write_tokens["refresh_token"], write_client)
    print("CHATGPT_LOOPBACK_OWNER_RESTRICTED_DRAFTS_AND_REVOKE=PASS")
    print("CHATGPT_LOOPBACK_INDEPENDENT_HTTP_OAUTH_ACCEPTANCE=PASS")
    print("EXTERNAL_CHATGPT_ACCOUNT_LINKING=NOT_ATTEMPTED")


if __name__ == "__main__":
    try:
        run()
    except (RuntimeError, KeyError, TypeError, ValueError, AssertionError) as exc:
        # Never echo HTTP bodies, cookies, access tokens, refresh credentials
        # or user passwords into terminal logs.
        msg = str(exc)
        if not msg.startswith("CHATGPT_LOOPBACK_BLOCKED="):
            msg = "CHATGPT_LOOPBACK_BLOCKED=UNEXPECTED_HTTP_CONTRACT_ERROR"
        print(msg, file=sys.stderr)
        sys.exit(1)
