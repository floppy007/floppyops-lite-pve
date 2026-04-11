#!/usr/bin/env python3
import argparse
import json
import re
import sys

PAM_SERVICE = "floppyops-lite"
PAM_BACKEND = None

try:
    import pam as pam_backend
    PAM_BACKEND = "pam"
except ImportError:
    try:
        import PAM as pam_backend
        PAM_BACKEND = "PAM"
    except ImportError:
        print(json.dumps({"ok": False, "error": "python3-pam ist nicht installiert"}))
        sys.exit(2)


def main() -> int:
    parser = argparse.ArgumentParser(description="FloppyOps Lite PAM helper")
    parser.add_argument("--user", required=True)
    args = parser.parse_args()

    if not re.fullmatch(r"[a-zA-Z0-9_.@-]{1,64}", args.user):
        print(json.dumps({"ok": False, "error": "ungueltiger Benutzername"}))
        return 3

    password = sys.stdin.read()
    if password.endswith("\n"):
        password = password[:-1]

    if PAM_BACKEND == "pam":
        auth = pam_backend.pam()
        success = auth.authenticate(args.user, password, service=PAM_SERVICE)
        result = {"ok": bool(success)}
        if not success:
            result["error"] = auth.reason or "PAM-Authentifizierung fehlgeschlagen"
    else:
        def conv(_auth, query_list, _user_data):
            responses = []
            for _prompt, msg_type in query_list:
                if msg_type == pam_backend.PAM_PROMPT_ECHO_OFF:
                    responses.append((password, 0))
                elif msg_type == pam_backend.PAM_PROMPT_ECHO_ON:
                    responses.append(("", 0))
                else:
                    responses.append(("", 0))
            return responses

        auth = pam_backend.pam()
        try:
            auth.start(PAM_SERVICE)
            auth.set_item(pam_backend.PAM_USER, args.user)
            auth.set_item(pam_backend.PAM_CONV, conv)
            auth.authenticate()
            auth.acct_mgmt()
            result = {"ok": True}
            success = True
        except pam_backend.error as exc:
            result = {"ok": False, "error": str(exc) or "PAM-Authentifizierung fehlgeschlagen"}
            success = False

    print(json.dumps(result))
    return 0 if success else 1


if __name__ == "__main__":
    raise SystemExit(main())
