"""Шлюз АТОЛ 22 v2 Ф на стойке. Облако в LAN кассы не ходит.

Опрос:  GET  {FISCAL_API_BASE}/api/fiscal/targets?token=…&limit=1
Сдача:  POST {FISCAL_API_BASE}/api/fiscal/applied

Касса: Ethernet, TCP 5555, JSON-задания ДТО 10 через fptr10.dll.
Гостевой чек смену не открывает и бумагу не печатает.
Повтор того же external_id не пробивает второй документ: память в sqlite.

На ПК стойки (не на игровом месте):
  set FISCAL_API_BASE=https://example.club
  set FISCAL_RELAY_TOKEN=тот же, что FISCAL_RELAY_TOKEN в облаке
  set ATOL_HOST=192.168.x.x
  py -3 scripts\\fiscal-atol-agent.py status
  py -3 scripts\\fiscal-atol-agent.py open-shift
  py -3 scripts\\fiscal-atol-agent.py

ДТО 10 ставится с сайта АТОЛ. Нужна 64-битная fptr10.dll рядом с 64-битным Python.
Путь: ATOL_DLL или Program Files\\ATOL\\Drivers10\\KKT\\bin\\fptr10.dll.

Ссылка чека: FISCAL_RECEIPT_URL_TEMPLATE с полями {t} {s} {fn} {fd} {fp} {n}.
Пусто — QR с реквизитами на https://www.nalog.gov.ru/ (приложение «Проверка чека» их читает).
Свой ОФД подставляют шаблоном, например
  https://lk.platformaofd.ru/web/noauth/cheque?fn={fn}&fp={fp}&i={fd}
"""

from __future__ import annotations

import json
import os
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.request
from ctypes import CDLL, POINTER, byref, c_int, c_void_p, c_wchar_p, create_unicode_buffer
from pathlib import Path

JSON_DATA = 65645
POLL_SECONDS = float(os.environ.get("FISCAL_POLL_SECONDS", "2"))
DEVICE_TIMEOUT = float(os.environ.get("ATOL_TIMEOUT", "50"))

PAYMENT_METHOD = {
    1: "fullPrepayment",
    2: "prepayment",
    3: "advance",
    4: "fullPayment",
    5: "partialPayment",
    6: "credit",
    7: "creditPayment",
}
PAYMENT_OBJECT = {
    1: "commodity",
    4: "service",
    10: "payment",
}
TAX = {
    -1: "none",
    0: "vat0",
    5: "vat5",
    7: "vat7",
    10: "vat10",
    20: "vat20",
    22: "vat22",
    105: "vat105",
    107: "vat107",
    110: "vat110",
    120: "vat120",
    122: "vat122",
}
PAYMENTS = (
    ("cash", "cash"),
    ("electronic", "electronically"),
    ("prepaid", "prepaid"),
)


class MapError(Exception):
    pass


class DeviceOffline(Exception):
    pass


class DeviceRejected(Exception):
    pass


def money(value) -> float:
    return round(float(value or 0), 2)


def map_fiscalize(payload: dict, taxation: str | None) -> dict:
    type_check = int(payload.get("type_check", 0))
    if type_check == 0:
        task_type = "sell"
    elif type_check == 1:
        task_type = "sellReturn"
    else:
        raise MapError(f"Неизвестный type_check {type_check}")

    items = []
    for raw in payload.get("items") or []:
        price = money(raw.get("price"))
        amount = money(raw.get("amount", price))
        tax_code = int(raw.get("tax", payload.get("tax", -1)))
        if tax_code not in TAX:
            raise MapError(f"Неизвестная ставка налога {tax_code}")
        method = int(raw.get("payment_method", 4))
        obj = int(raw.get("payment_object", 4))
        if method not in PAYMENT_METHOD or obj not in PAYMENT_OBJECT:
            raise MapError(f"Неизвестный признак расчёта {method}/{obj}")
        items.append({
            "type": "position",
            "name": str(raw.get("name") or "Услуга")[:128],
            "price": price,
            "quantity": float(raw.get("quantity") or 1),
            "amount": amount,
            "paymentMethod": PAYMENT_METHOD[method],
            "paymentObject": PAYMENT_OBJECT[obj],
            "tax": {"type": TAX[tax_code]},
        })
    if not items:
        raise MapError("В задании нет позиций")

    payments = []
    raw_payments = payload.get("payments") or {}
    for source, atol_type in PAYMENTS:
        value = money(raw_payments.get(source))
        if value > 0:
            payments.append({"type": atol_type, "sum": value})
    if not payments:
        raise MapError("В задании нет суммы оплаты")

    task = {
        "type": task_type,
        "electronically": True,
        "ignoreNonFiscalPrintErrors": True,
        "operator": {"name": str(payload.get("cashier_name") or "Касса")[:64]},
        "items": items,
        "payments": payments,
        "total": money(sum(row["sum"] for row in payments)),
    }
    if taxation:
        task["taxationType"] = taxation
    address = str(payload.get("client_address") or "").strip()
    if address:
        task["clientInfo"] = {"emailOrPhone": address}
    return task


def map_print_copy(payload: dict) -> dict:
    fd = str(payload.get("fd") or "").strip()
    if not fd.isdigit():
        raise MapError("В задании копии нет номера фискального документа")
    return {"type": "printFnDocument", "fiscalDocumentNumber": int(fd)}


def expected_amount(payload: dict) -> float:
    payments = payload.get("payments") or {}
    return money(sum(money(payments.get(key)) for key, _atol in PAYMENTS))


def qr_stamp(iso: str) -> str:
    digits = re.sub(r"\D", "", str(iso or ""))
    if len(digits) < 12:
        return ""
    return digits[:8] + "T" + digits[8:12]


def receipt_url(proof: dict, template: str) -> str:
    fields = {
        "t": proof.get("t") or "",
        "s": proof.get("s") or "",
        "fn": proof.get("fn") or "",
        "fd": proof.get("fd") or "",
        "fp": proof.get("fp") or "",
        "n": proof.get("n") or "1",
    }
    if template.strip():
        return template.format(**fields)
    # Реквизиты QR из 54-ФЗ: i — номер фискального документа.
    return (
        "https://www.nalog.gov.ru/?t={t}&s={s}&fn={fn}&i={fd}&fp={fp}&n={n}"
    ).format(**fields)


def proof_from_result(result: dict, operation_n: str) -> dict | None:
    params = result.get("fiscalParams") if isinstance(result, dict) else None
    if not isinstance(params, dict):
        params = _tagged_proof(result)
    if not isinstance(params, dict):
        return None
    fn = str(params.get("fnNumber") or params.get("1041") or "")
    fd = str(params.get("fiscalDocumentNumber") or params.get("1040") or "")
    fp = str(params.get("fiscalDocumentSign") or params.get("1077") or "")
    if not (fn and fd and fp):
        return None
    total = params.get("total", params.get("1020"))
    return {
        "fn": fn,
        "fd": fd,
        "fp": fp,
        "shift": str(params.get("shiftNumber") or params.get("1038") or ""),
        "receipt_number": str(params.get("fiscalReceiptNumber") or params.get("1042") or ""),
        "s": f"{money(total):.2f}" if total not in (None, "") else "",
        "t": qr_stamp(str(params.get("fiscalDocumentDateTime") or params.get("1012") or "")),
        "n": operation_n,
    }


def _tagged_proof(result: dict) -> dict | None:
    if not isinstance(result, dict):
        return None
    for key in ("documentTLV", "document", "fnDocument"):
        nested = result.get(key)
        if isinstance(nested, dict):
            merged = dict(nested)
            merged.update(nested.get("fiscalParams") or {})
            return merged
    return None


def operation_n(payload: dict) -> str:
    return "2" if int(payload.get("type_check", 0)) == 1 else "1"


class Ledger:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self.conn = sqlite3.connect(path)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute(
            """
            CREATE TABLE IF NOT EXISTS receipts (
                external_id TEXT PRIMARY KEY,
                kind TEXT NOT NULL,
                state TEXT NOT NULL,
                fd_before INTEGER,
                amount TEXT,
                fn TEXT, fd TEXT, fp TEXT, shift TEXT,
                receipt_number TEXT, receipt_url TEXT,
                error TEXT, updated_at TEXT NOT NULL
            )
            """
        )
        self.conn.commit()

    def get(self, external_id: str) -> sqlite3.Row | None:
        return self.conn.execute(
            "SELECT * FROM receipts WHERE external_id = ?", (external_id,)
        ).fetchone()

    def save(self, external_id: str, kind: str, state: str, **fields) -> None:
        current = self.get(external_id)
        row = {
            "kind": kind,
            "state": state,
            "fd_before": None,
            "amount": None,
            "fn": None,
            "fd": None,
            "fp": None,
            "shift": None,
            "receipt_number": None,
            "receipt_url": None,
            "error": None,
        }
        if current:
            row.update({key: current[key] for key in row})
        row["kind"] = kind
        row["state"] = state
        for key, value in fields.items():
            if key in row:
                row[key] = value
        self.conn.execute(
            """
            INSERT INTO receipts (
                external_id, kind, state, fd_before, amount, fn, fd, fp, shift,
                receipt_number, receipt_url, error, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(external_id) DO UPDATE SET
                kind = excluded.kind,
                state = excluded.state,
                fd_before = excluded.fd_before,
                amount = excluded.amount,
                fn = excluded.fn,
                fd = excluded.fd,
                fp = excluded.fp,
                shift = excluded.shift,
                receipt_number = excluded.receipt_number,
                receipt_url = excluded.receipt_url,
                error = excluded.error,
                updated_at = excluded.updated_at
            """,
            (
                external_id, row["kind"], row["state"], row["fd_before"], row["amount"],
                row["fn"], row["fd"], row["fp"], row["shift"], row["receipt_number"],
                row["receipt_url"], row["error"], time.strftime("%Y-%m-%dT%H:%M:%S"),
            ),
        )
        self.conn.commit()


def result_from_row(job_id: int, row, outcome: str | None = None) -> dict:
    return {
        "id": job_id,
        "outcome": outcome or ("success" if row["state"] == "success" else row["state"]),
        "error": row["error"],
        "fn": row["fn"],
        "fd": row["fd"],
        "fp": row["fp"],
        "shift": row["shift"],
        "receipt_number": row["receipt_number"],
        "receipt_url": row["receipt_url"],
    }


def handle_job(device, ledger: Ledger, job: dict, taxation: str | None, url_template: str) -> dict:
    external_id = str(job.get("external_id") or "")
    payload = job.get("payload") or {}
    kind = str(job.get("kind") or payload.get("kind") or "")
    job_id = int(job["id"])
    if not external_id:
        return {"id": job_id, "outcome": "error", "error": "В задании нет external_id"}

    row = ledger.get(external_id)
    if row and row["state"] == "success":
        return result_from_row(job_id, row)
    if row and row["state"] in ("uncertain", "in_progress"):
        return recover(device, ledger, job_id, external_id, kind, payload, url_template)

    try:
        if kind == "print_copy":
            task = map_print_copy(payload)
        elif kind == "fiscalize":
            task = map_fiscalize(payload, taxation)
            state = device.shift_state()
            if state != "opened":
                return {
                    "id": job_id,
                    "outcome": "error",
                    "error": "Смена на кассе не открыта. На стойке: fiscal-atol-agent.py open-shift",
                }
        else:
            return {"id": job_id, "outcome": "error", "error": f"Неизвестный kind {kind}"}
    except MapError as exc:
        return {"id": job_id, "outcome": "error", "error": str(exc)}

    try:
        fd_before = device.fn_number()
    except DeviceOffline as exc:
        return {"id": job_id, "outcome": "error", "error": str(exc)}

    ledger.save(
        external_id,
        kind,
        "in_progress",
        fd_before=fd_before,
        amount=f"{expected_amount(payload):.2f}",
        error=None,
    )
    try:
        answer = device.run(task)
    except (DeviceRejected, DeviceOffline) as exc:
        return finish_reject(device, ledger, job_id, external_id, kind, payload, url_template, str(exc))

    if kind == "print_copy":
        ledger.save(external_id, kind, "success", error=None)
        return result_from_row(job_id, ledger.get(external_id))

    proof = proof_from_result(answer, operation_n(payload))
    if not proof:
        return finish_reject(
            device, ledger, job_id, external_id, kind, payload, url_template,
            "Касса не вернула фискальный признак",
        )
    return store_success(ledger, job_id, external_id, kind, proof, url_template)


def finish_reject(device, ledger, job_id, external_id, kind, payload, url_template, error: str) -> dict:
    try:
        adopted = adopt_new_document(device, ledger, external_id, kind, payload, url_template)
    except DeviceOffline:
        adopted = None
    if adopted:
        return result_from_row(job_id, ledger.get(external_id))
    try:
        current = device.fn_number()
        previous = ledger.get(external_id)
        if previous and previous["fd_before"] is not None and current == previous["fd_before"]:
            ledger.save(external_id, kind, "error", error=error)
            return result_from_row(job_id, ledger.get(external_id), "error")
    except DeviceOffline:
        pass
    ledger.save(external_id, kind, "uncertain", error=error)
    return result_from_row(job_id, ledger.get(external_id), "uncertain")


def recover(device, ledger, job_id, external_id, kind, payload, url_template) -> dict:
    if kind == "print_copy":
        row = ledger.get(external_id)
        if row["state"] != "uncertain":
            ledger.save(external_id, kind, "uncertain", error="Печать копии прервана, ленту повторно не крутим")
        return result_from_row(job_id, ledger.get(external_id), "uncertain")
    try:
        adopted = adopt_new_document(device, ledger, external_id, kind, payload, url_template)
    except DeviceOffline as exc:
        ledger.save(external_id, kind, "uncertain", error=str(exc))
        return result_from_row(job_id, ledger.get(external_id), "uncertain")
    if adopted:
        return result_from_row(job_id, ledger.get(external_id))
    row = ledger.get(external_id)
    try:
        current = device.fn_number()
    except DeviceOffline as exc:
        ledger.save(external_id, kind, "uncertain", error=str(exc))
        return result_from_row(job_id, ledger.get(external_id), "uncertain")
    if row and row["fd_before"] is not None and current == row["fd_before"]:
        ledger.save(external_id, kind, "error", error="ФН не записал документ, повтор безопасен")
        return result_from_row(job_id, ledger.get(external_id), "error")
    ledger.save(external_id, kind, "uncertain", error="После команды в ФН есть новый документ, признак не сошёлся")
    return result_from_row(job_id, ledger.get(external_id), "uncertain")


def adopt_new_document(device, ledger, external_id, kind, payload, url_template) -> bool:
    row = ledger.get(external_id)
    if not row or row["fd_before"] is None:
        return False
    current = device.fn_number()
    if current != int(row["fd_before"]) + 1:
        return False
    proof = proof_from_result(device.read_document(current), operation_n(payload))
    if not proof or proof["fd"] != str(current) or not proof.get("s"):
        return False
    if row["amount"] and abs(money(proof["s"]) - money(row["amount"])) > 0.02:
        return False
    store_success(ledger, 0, external_id, kind, proof, url_template)
    return True


def store_success(ledger, job_id, external_id, kind, proof, url_template) -> dict:
    url = receipt_url(proof, url_template)
    ledger.save(
        external_id,
        kind,
        "success",
        fn=proof["fn"],
        fd=proof["fd"],
        fp=proof["fp"],
        shift=proof.get("shift") or None,
        receipt_number=proof.get("receipt_number") or None,
        receipt_url=url,
        error=None,
    )
    return result_from_row(job_id, ledger.get(external_id))


class AtolDevice:
    def __init__(self, dll_path: str, host: str, port: int, password: str):
        if sys.platform == "win32":
            self.dll = CDLL(dll_path, winmode=0)
        else:
            self.dll = CDLL(dll_path)
        self.host = host
        self.port = port
        self.password = password
        self.handle = c_void_p()
        self._bind()

    def _bind(self) -> None:
        self.dll.libfptr_create.argtypes = [POINTER(c_void_p)]
        self.dll.libfptr_create.restype = c_int
        self.dll.libfptr_destroy.argtypes = [POINTER(c_void_p)]
        self.dll.libfptr_set_single_setting.argtypes = [c_void_p, c_wchar_p, c_wchar_p]
        self.dll.libfptr_apply_single_settings.argtypes = [c_void_p]
        self.dll.libfptr_apply_single_settings.restype = c_int
        self.dll.libfptr_open.argtypes = [c_void_p]
        self.dll.libfptr_open.restype = c_int
        self.dll.libfptr_close.argtypes = [c_void_p]
        self.dll.libfptr_close.restype = c_int
        self.dll.libfptr_set_param_str.argtypes = [c_void_p, c_int, c_wchar_p]
        self.dll.libfptr_set_param_str.restype = c_int
        self.dll.libfptr_process_json.argtypes = [c_void_p]
        self.dll.libfptr_process_json.restype = c_int
        self.dll.libfptr_get_param_str.argtypes = [c_void_p, c_int, c_wchar_p, c_int]
        self.dll.libfptr_get_param_str.restype = c_int
        self.dll.libfptr_error_code.argtypes = [c_void_p]
        self.dll.libfptr_error_code.restype = c_int
        self.dll.libfptr_error_description.argtypes = [c_void_p, c_wchar_p, c_int]
        self.dll.libfptr_error_description.restype = c_int

    def connect(self) -> None:
        rc = self.dll.libfptr_create(byref(self.handle))
        if rc != 0 or not self.handle:
            raise DeviceOffline(f"libfptr_create вернул {rc}")
        self._setting("Model", "500")
        self._setting("Port", "2")
        self._setting("IPAddress", self.host)
        self._setting("IPPort", str(self.port))
        if self.password:
            self._setting("UserPassword", self.password)
        if self.dll.libfptr_apply_single_settings(self.handle) != 0:
            raise DeviceOffline(self._error("Настройки кассы не применились"))
        if self.dll.libfptr_open(self.handle) != 0:
            raise DeviceOffline(self._error("Нет связи с кассой"))

    def close(self) -> None:
        if self.handle:
            self.dll.libfptr_close(self.handle)
            self.dll.libfptr_destroy(byref(self.handle))
            self.handle = c_void_p()

    def run(self, task: dict) -> dict:
        self._ensure()
        raw = json.dumps(task, ensure_ascii=False)
        self.dll.libfptr_set_param_str(self.handle, JSON_DATA, raw)
        rc = self.dll.libfptr_process_json(self.handle)
        parsed = self._read_json()
        if rc != 0 or (isinstance(parsed, dict) and parsed.get("error") and "fiscalParams" not in parsed):
            message = _error_message(parsed) or self._error("Касса отклонила задание")
            if rc != 0 and not parsed:
                raise DeviceOffline(message)
            raise DeviceRejected(message)
        return parsed

    def shift_state(self) -> str:
        answer = self.run({"type": "getShiftStatus"})
        return str((answer.get("shiftStatus") or {}).get("state") or "")

    def fn_number(self) -> int | None:
        answer = self.run({"type": "getFnStatus"})
        number = (answer.get("fnStatus") or {}).get("fiscalDocumentNumber")
        return int(number) if number is not None else None

    def read_document(self, fd: int) -> dict:
        return self.run({"type": "getFnDocument", "fiscalDocumentNumber": int(fd)})

    def _ensure(self) -> None:
        if not self.handle:
            raise DeviceOffline("Касса не открыта")

    def _setting(self, key: str, value: str) -> None:
        self.dll.libfptr_set_single_setting(self.handle, key, value)

    def _read_json(self) -> dict:
        buf = create_unicode_buffer(65536)
        self.dll.libfptr_get_param_str(self.handle, JSON_DATA, buf, len(buf))
        text = buf.value.strip()
        if not text:
            return {}
        try:
            parsed = json.loads(text)
        except json.JSONDecodeError:
            return {}
        return parsed if isinstance(parsed, dict) else {}

    def _error(self, fallback: str) -> str:
        buf = create_unicode_buffer(1024)
        self.dll.libfptr_error_description(self.handle, buf, len(buf))
        code = self.dll.libfptr_error_code(self.handle)
        text = buf.value.strip()
        if text:
            return text
        if code:
            return f"{fallback} ({code})"
        return fallback


def _error_message(parsed: dict) -> str:
    err = parsed.get("error") if isinstance(parsed, dict) else None
    if isinstance(err, dict):
        return str(err.get("description") or err.get("error") or "")
    return ""


def find_dll() -> str:
    env = os.environ.get("ATOL_DLL", "").strip()
    if env:
        return env
    candidates = [
        r"C:\Program Files\ATOL\Drivers10\KKT\bin\fptr10.dll",
        r"C:\Program Files (x86)\ATOL\Drivers10\KKT\bin\fptr10.dll",
    ]
    for path in candidates:
        if Path(path).exists():
            return path
    raise SystemExit("Не найден fptr10.dll. Поставьте ДТО 10 или задайте ATOL_DLL")


def cloud_get(base: str, token: str) -> dict:
    url = f"{base}/api/fiscal/targets?token={urllib.request.quote(token)}&limit=1"
    req = urllib.request.Request(url, method="GET")
    with urllib.request.urlopen(req, timeout=20) as resp:
        return json.loads(resp.read().decode("utf-8"))


def cloud_post(base: str, token: str, results: list, device: dict | None) -> None:
    body = json.dumps({"token": token, "results": results, "device": device}, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        f"{base}/api/fiscal/applied",
        data=body,
        headers={"Content-Type": "application/json; charset=utf-8"},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=20) as resp:
        resp.read()


def open_device() -> AtolDevice:
    host = os.environ.get("ATOL_HOST", "").strip()
    if not host:
        raise SystemExit("Задайте ATOL_HOST — IP кассы в LAN")
    port = int(os.environ.get("ATOL_PORT", "5555"))
    device = AtolDevice(find_dll(), host, port, os.environ.get("ATOL_PASSWORD", "").strip())
    device.connect()
    return device


def cmd_status() -> None:
    device = open_device()
    try:
        info = device.run({"type": "getDeviceInfo"})
        shift = device.run({"type": "getShiftStatus"})
        fn = device.run({"type": "getFnStatus"})
        print(json.dumps({"device": info, "shift": shift, "fn": fn}, ensure_ascii=False, indent=2))
    finally:
        device.close()


def cmd_shift(task_type: str) -> None:
    device = open_device()
    try:
        name = os.environ.get("ATOL_OPERATOR_NAME", "Касса")
        answer = device.run({"type": task_type, "operator": {"name": name}})
        print(json.dumps(answer, ensure_ascii=False, indent=2))
    finally:
        device.close()


def loop() -> None:
    base = os.environ.get("FISCAL_API_BASE", "").rstrip("/")
    token = os.environ.get("FISCAL_RELAY_TOKEN", "")
    if not base or not token:
        raise SystemExit("Задайте FISCAL_API_BASE и FISCAL_RELAY_TOKEN")
    taxation = os.environ.get("ATOL_TAXATION", "").strip() or None
    template = os.environ.get("FISCAL_RECEIPT_URL_TEMPLATE", "")
    ledger_path = Path(os.environ.get("FISCAL_LEDGER", str(Path(os.environ.get("LOCALAPPDATA", ".")) / "club-fiscal" / "ledger.sqlite")))
    ledger = Ledger(ledger_path)
    device_info = {"model": "ATOL 22 v2 F", "host": os.environ.get("ATOL_HOST", ""), "port": int(os.environ.get("ATOL_PORT", "5555"))}
    print(f"fiscal agent: {base} -> {device_info['host']}:{device_info['port']}", flush=True)
    while True:
        try:
            payload = cloud_get(base, token)
            if not payload.get("enabled", True):
                print("касса на сервере выключена (FISCAL_ENABLED=false)", flush=True)
                time.sleep(max(10, POLL_SECONDS))
                continue
            jobs = payload.get("jobs") or []
            if not jobs:
                time.sleep(POLL_SECONDS)
                continue
            device = open_device()
            try:
                outcome = handle_job(device, ledger, jobs[0], taxation, template)
            finally:
                device.close()
            cloud_post(base, token, [outcome], device_info)
            print(f"job {outcome.get('id')} {outcome.get('outcome')} {outcome.get('error') or ''}", flush=True)
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            print(f"poll error: {exc}", flush=True)
            time.sleep(max(5, POLL_SECONDS))
        except DeviceOffline as exc:
            print(f"касса недоступна: {exc}", flush=True)
            time.sleep(max(5, POLL_SECONDS))


def self_test() -> None:
    import tempfile

    advance = map_fiscalize({
        "type_check": 0,
        "tax": -1,
        "cashier_name": "Касса",
        "items": [{
            "name": "Пополнение",
            "price": 100,
            "amount": 100,
            "quantity": 1,
            "tax": -1,
            "payment_method": 3,
            "payment_object": 10,
        }],
        "payments": {"cash": 0, "electronic": 100, "prepaid": 0},
        "client_address": "+79990000000",
    }, None)
    assert advance["type"] == "sell"
    assert advance["electronically"] is True
    assert advance["items"][0]["paymentMethod"] == "advance"
    assert advance["items"][0]["paymentObject"] == "payment"
    assert advance["items"][0]["tax"]["type"] == "none"
    assert advance["payments"] == [{"type": "electronically", "sum": 100.0}]
    assert advance["clientInfo"]["emailOrPhone"] == "+79990000000"

    settlement = map_fiscalize({
        "type_check": 0,
        "tax": -1,
        "items": [{
            "name": "ПК-01",
            "price": 50,
            "amount": 50,
            "payment_method": 4,
            "payment_object": 4,
            "tax": -1,
        }],
        "payments": {"prepaid": 50},
    }, "usnIncome")
    assert settlement["taxationType"] == "usnIncome"
    assert settlement["items"][0]["paymentMethod"] == "fullPayment"
    assert settlement["items"][0]["paymentObject"] == "service"
    assert settlement["payments"][0]["type"] == "prepaid"

    refund = map_fiscalize({
        "type_check": 1,
        "tax": 22,
        "items": [{
            "name": "Возврат",
            "price": 10,
            "amount": 10,
            "payment_method": 3,
            "payment_object": 10,
            "tax": 22,
        }],
        "payments": {"electronic": 10},
    }, None)
    assert refund["type"] == "sellReturn"
    assert refund["items"][0]["tax"]["type"] == "vat22"

    copy = map_print_copy({"fd": "42"})
    assert copy == {"type": "printFnDocument", "fiscalDocumentNumber": 42}
    try:
        map_print_copy({})
        raise SystemExit("копия без ФД не должна собираться")
    except MapError:
        pass

    proof = proof_from_result({
        "fiscalParams": {
            "fiscalDocumentDateTime": "2026-10-13T22:05:00+03:00",
            "fiscalDocumentNumber": 42,
            "fiscalDocumentSign": "1234567890",
            "fiscalReceiptNumber": 3,
            "fnNumber": "9999078900001234",
            "shiftNumber": 7,
            "total": 100,
        }
    }, "1")
    assert proof is not None
    url = receipt_url(proof, "")
    assert url.startswith("https://www.nalog.gov.ru/?")
    assert "t=20261013T2205" in url
    assert "fn=9999078900001234" in url
    assert "i=42" in url
    assert "fp=1234567890" in url

    root = Path(tempfile.mkdtemp())
    ledger = Ledger(root / "ledger.sqlite")

    class Fake:
        def __init__(self):
            self.fd = 10
            self.shift = "opened"
            self.fail = None
            self.sells = 0

        def shift_state(self):
            if self.fail == "offline":
                raise DeviceOffline("нет связи")
            return self.shift

        def fn_number(self):
            if self.fail == "offline":
                raise DeviceOffline("нет связи")
            return self.fd

        def run(self, task):
            if self.fail == "reject":
                raise DeviceRejected("Смена превысила 24 часа")
            if self.fail == "lost":
                self.fd += 1
                self.fail = None
                raise DeviceOffline("обрыв после команды")
            if task["type"] in ("sell", "sellReturn"):
                self.sells += 1
                self.fd += 1
                return {"fiscalParams": self._params(self.fd)}
            if task["type"] == "printFnDocument":
                return {}
            raise AssertionError(task["type"])

        def read_document(self, fd):
            return {"fiscalParams": self._params(fd)}

        def _params(self, fd):
            return {
                "fiscalDocumentDateTime": "2026-10-13T22:10:00+03:00",
                "fiscalDocumentNumber": fd,
                "fiscalDocumentSign": "555",
                "fiscalReceiptNumber": 1,
                "fnNumber": "9999078900001234",
                "shiftNumber": 1,
                "total": 100,
            }

    fake = Fake()
    job = {
        "id": 7,
        "external_id": "uuid-1",
        "kind": "fiscalize",
        "payload": {
            "kind": "fiscalize",
            "type_check": 0,
            "tax": -1,
            "cashier_name": "Касса",
            "items": [{
                "name": "Пополнение", "price": 100, "amount": 100,
                "payment_method": 3, "payment_object": 10, "tax": -1,
            }],
            "payments": {"electronic": 100},
        },
    }
    first = handle_job(fake, ledger, job, None, "")
    assert first["outcome"] == "success", first
    assert first["fd"] == "11"
    assert fake.sells == 1
    again = handle_job(fake, ledger, job, None, "")
    assert again["outcome"] == "success" and fake.sells == 1

    fake.shift = "closed"
    closed = handle_job(fake, ledger, {**job, "external_id": "uuid-2", "id": 8}, None, "")
    assert closed["outcome"] == "error" and fake.sells == 1
    assert ledger.get("uuid-2") is None

    fake.shift = "opened"
    fake.fail = "lost"
    lost = handle_job(fake, ledger, {**job, "external_id": "uuid-3", "id": 9}, None, "")
    assert lost["outcome"] == "success", lost
    assert fake.sells == 1

    fake.fail = "reject"
    rejected = handle_job(fake, ledger, {**job, "external_id": "uuid-4", "id": 10}, None, "")
    assert rejected["outcome"] == "error", rejected
    fake.fail = None
    retried = handle_job(fake, ledger, {**job, "external_id": "uuid-4", "id": 10}, None, "")
    assert retried["outcome"] == "success" and fake.sells == 2

    paper = handle_job(fake, ledger, {
        "id": 11,
        "external_id": "uuid-paper",
        "kind": "print_copy",
        "payload": {"fd": "11"},
    }, None, "")
    assert paper["outcome"] == "success"
    print("fiscal-atol-agent self-test ok")


def main() -> None:
    command = sys.argv[1] if len(sys.argv) > 1 else "run"
    if command == "--self-test":
        self_test()
    elif command == "status":
        cmd_status()
    elif command == "open-shift":
        cmd_shift("openShift")
    elif command == "close-shift":
        cmd_shift("closeShift")
    elif command == "run":
        loop()
    else:
        raise SystemExit("Команды: run, status, open-shift, close-shift, --self-test")


if __name__ == "__main__":
    main()
