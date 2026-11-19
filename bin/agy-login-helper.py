#!/usr/bin/env python3
"""Jalankan login/logout Antigravity CLI (agy) di terminal virtual untuk halaman AI Model.

Pemakaian: agy-login-helper.py <login|logout> <folder-sesi> <binary-agy>

Status ditulis ke <folder-sesi>/state.json. Untuk login, halaman web menulis
kode otorisasi ke <folder-sesi>/code; helper meneruskannya ke agy lalu
menghapus file itu. Helper berhenti sendiri setelah selesai atau batas waktu.
"""
import ctypes
import fcntl
import json
import os
import pty
import re
import select
import signal
import struct
import sys
import termios
import time

ANSI = re.compile(r"\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(\x07|\x1b\\)|\x1b[@-Z\\-_]")
URL = re.compile(r"https://[^\s\"'<>\x1b]+")
CODE_WAIT_SECONDS = 600


def write_state(folder, status, **extra):
    data = {"status": status, "updated_at": int(time.time())}
    data.update(extra)
    tmp = os.path.join(folder, "state.json.tmp")
    with open(tmp, "w") as handle:
        json.dump(data, handle)
    os.replace(tmp, os.path.join(folder, "state.json"))


def clean(raw):
    return ANSI.sub("", raw.decode("utf-8", "replace"))


class Session:
    def __init__(self, binary):
        self.pid, self.fd = pty.fork()
        if self.pid == 0:
            # agy ikut mati bila helper mati (mis. dibunuh timeout), agar tidak
            # ada proses agy yatim yang tertinggal.
            try:
                ctypes.CDLL("libc.so.6", use_errno=True).prctl(1, signal.SIGKILL)  # PR_SET_PDEATHSIG
            except OSError:
                pass
            os.environ["TERM"] = "xterm-256color"
            os.execv(binary, [binary])
        # Terminal sangat lebar agar URL OAuth tidak dipecah ke baris baru.
        fcntl.ioctl(self.fd, termios.TIOCSWINSZ, struct.pack("HHHH", 60, 1000, 0, 0))
        self.screen = ""

    def pump(self, seconds):
        end = time.time() + seconds
        while time.time() < end:
            ready, _, _ = select.select([self.fd], [], [], 0.2)
            if not ready:
                continue
            try:
                chunk = os.read(self.fd, 65536)
            except OSError:
                return False
            if not chunk:
                return False
            self.screen = (self.screen + clean(chunk))[-200000:]
        return True

    def wait_for(self, predicate, seconds):
        end = time.time() + seconds
        while time.time() < end:
            if predicate(self.screen):
                return True
            if not self.pump(0.5):
                return predicate(self.screen)
        return predicate(self.screen)

    def send(self, text):
        os.write(self.fd, text.encode())

    def close(self):
        for sig in (signal.SIGTERM, signal.SIGKILL):
            try:
                os.kill(self.pid, sig)
            except ProcessLookupError:
                break
            time.sleep(1)
        try:
            os.waitpid(self.pid, os.WNOHANG)
        except ChildProcessError:
            pass


def is_login_menu(screen):
    # "not signed in" juga muncul sesaat sebelum agy login otomatis dengan
    # kredensial tersimpan, jadi hanya menu pilihan metode yang dipakai.
    return "select login method" in screen.lower()


def is_onboarding(screen):
    return "choose your color scheme" in screen.lower()


def is_ready(screen):
    """Sudah login: layar onboarding pertama atau status bar mode di TUI."""
    lower = screen.lower()
    if is_onboarding(screen):
        return True
    return bool(re.search(r"\b(auto|default|accept-edits|plan) ·", lower))


def finish_onboarding(session, steps=8):
    """Terima pilihan bawaan onboarding pertama sampai prompt chat siap."""
    for _ in range(steps):
        if not is_onboarding(session.screen[-4000:]) and "[next]" not in session.screen[-4000:].lower():
            return
        session.send("\r")
        session.pump(2)


def login(folder, binary):
    session = Session(binary)
    try:
        write_state(folder, "starting")
        session.wait_for(lambda s: is_login_menu(s) or is_ready(s), 40)
        if not is_login_menu(session.screen):
            write_state(folder, "already_logged_in" if is_ready(session.screen) else "error",
                        message="Menu login tidak muncul." if not is_ready(session.screen) else "")
            return
        session.send("\r")  # opsi 1: Google OAuth
        if not session.wait_for(lambda s: URL.search(s) is not None, 40):
            write_state(folder, "error", message="URL login Google tidak muncul dari agy.")
            return
        url = URL.findall(session.screen)[-1]
        write_state(folder, "waiting_code", url=url)

        code_file = os.path.join(folder, "code")
        deadline = time.time() + CODE_WAIT_SECONDS
        while time.time() < deadline and not os.path.exists(code_file):
            session.pump(1)
        if not os.path.exists(code_file):
            write_state(folder, "timeout", message="Kode tidak ditempel dalam 10 menit.")
            return
        with open(code_file) as handle:
            code = handle.read().strip()
        os.remove(code_file)

        write_state(folder, "submitting")
        before = len(session.screen)
        session.send(code + "\r")
        session.pump(20)
        tail = session.screen[before:].lower()
        if re.search(r"invalid|failed|error|expired", tail) and not is_ready(session.screen[before:]):
            write_state(folder, "error", message="agy menolak kode otorisasi. Mulai login ulang dan salin kode terbaru.")
            return
        write_state(folder, "submitted")
    finally:
        session.close()


def logout(folder, binary):
    session = Session(binary)
    try:
        write_state(folder, "starting")
        session.wait_for(lambda s: is_login_menu(s) or is_ready(s), 40)
        if is_login_menu(session.screen):
            write_state(folder, "submitted")
            return
        finish_onboarding(session)
        session.send("/logout\r")
        session.pump(10)
        write_state(folder, "submitted")
    finally:
        session.close()


def main():
    if len(sys.argv) != 4 or sys.argv[1] not in ("login", "logout"):
        print(__doc__)
        return 2
    mode, folder, binary = sys.argv[1:]
    os.makedirs(folder, exist_ok=True)
    try:
        (login if mode == "login" else logout)(folder, binary)
    except Exception as exc:  # noqa: BLE001 - status harus selalu tertulis
        write_state(folder, "error", message=str(exc)[:300])
    return 0


if __name__ == "__main__":
    sys.exit(main())
