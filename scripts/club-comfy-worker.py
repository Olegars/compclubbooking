"""FACE-01: очередь стилизации аватара → ComfyUI на 127.0.0.1:8188.

Служба NSSM club-comfy-worker (Restart on crash, 5–10 с). Не общий процесс с метками и лицами.
Env: VIDEO_API_BASE, AVATAR_RELAY_TOKEN (или VIDEO_MARKER_TOKEN / VIDEO_MARKER_RELAY_TOKEN),
COMFYUI_URL=http://127.0.0.1:8188, COMFYUI_TIMEOUT=90, COMFYUI_WORKFLOW, AVATAR_POLL_SECONDS.
Граф: C:\\club-agent\\avatar_workflow.json (Save API). Первый LoadImage — фото, второй — образец.
"""

import json
import os
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

API = os.environ.get("VIDEO_API_BASE", "").rstrip("/")
TOKEN = (
    os.environ.get("AVATAR_RELAY_TOKEN")
    or os.environ.get("VIDEO_MARKER_TOKEN")
    or os.environ.get("VIDEO_MARKER_RELAY_TOKEN")
    or ""
)
COMFY = os.environ.get("COMFYUI_URL", "http://127.0.0.1:8188").rstrip("/")
TIMEOUT = float(os.environ.get("COMFYUI_TIMEOUT", "90"))
POLL = float(os.environ.get("AVATAR_POLL_SECONDS", "5"))
WORKFLOW = os.environ.get("COMFYUI_WORKFLOW", r"C:\club-agent\avatar_workflow.json")
CHECKPOINT = os.environ.get("COMFYUI_CHECKPOINT", "")


def http_bytes(url, data=None, headers=None, timeout=30):
    req = urllib.request.Request(url, data=data, headers=headers or {}, method="POST" if data is not None else "GET")
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read(), resp.headers.get("Content-Type", "")


def get_json(url):
    body, _ = http_bytes(url, timeout=30)
    return json.loads(body.decode("utf-8"))


def token_url(path):
    sep = "&" if "?" in path else "?"
    return API + path + sep + "token=" + urllib.parse.quote(TOKEN)


def inject(workflow, names, prompt, negative):
    load_idx = 0
    prompt_set = False
    for node in workflow.values():
        if not isinstance(node, dict) or "class_type" not in node:
            continue
        kind = node.get("class_type")
        inputs = node.setdefault("inputs", {})
        if kind == "LoadImage" and load_idx < len(names):
            inputs["image"] = names[load_idx]
            load_idx += 1
        if kind == "CheckpointLoaderSimple" and CHECKPOINT:
            inputs["ckpt_name"] = CHECKPOINT
        if kind not in ("CLIPTextEncode", "CLIPTextEncodeSDXL"):
            continue
        title = str((node.get("_meta") or {}).get("title", "")).lower()
        text = str(inputs.get("text", ""))
        if "negative" in title or "{{NEGATIVE}}" in text or "NEGATIVE_PROMPT" in text:
            inputs["text"] = negative or "photo, selfie, blurry, extra face, watermark"
            continue
        if not prompt_set or "{{PROMPT}}" in text:
            inputs["text"] = prompt
            prompt_set = True
    return {k: v for k, v in workflow.items() if isinstance(v, dict) and "class_type" in v}


def upload_image(png, name):
    boundary = "clubavatarboundary"
    body = (
        f"--{boundary}\r\n"
        f'Content-Disposition: form-data; name="image"; filename="{name}"\r\n'
        "Content-Type: image/png\r\n\r\n"
    ).encode("utf-8") + png + (
        f"\r\n--{boundary}\r\n"
        'Content-Disposition: form-data; name="type"\r\n\r\ninput\r\n'
        f"--{boundary}\r\n"
        'Content-Disposition: form-data; name="overwrite"\r\n\r\ntrue\r\n'
        f"--{boundary}--\r\n"
    ).encode("utf-8")
    raw, _ = http_bytes(
        COMFY + "/upload/image",
        body,
        {"Content-Type": f"multipart/form-data; boundary={boundary}"},
        timeout=min(30, TIMEOUT),
    )
    parsed = json.loads(raw.decode("utf-8"))
    return parsed.get("name") or name


def queue_prompt(graph):
    payload = json.dumps({"prompt": graph, "client_id": "club-avatar"}).encode("utf-8")
    raw, _ = http_bytes(
        COMFY + "/prompt",
        payload,
        {"Content-Type": "application/json"},
        timeout=TIMEOUT,
    )
    prompt_id = json.loads(raw.decode("utf-8")).get("prompt_id") or ""
    if not prompt_id:
        raise RuntimeError("comfy prompt_id empty")
    return prompt_id


def wait_png(prompt_id, timeout):
    deadline = time.time() + timeout
    while time.time() < deadline:
        raw, _ = http_bytes(COMFY + "/history/" + urllib.parse.quote(prompt_id), timeout=15)
        history = json.loads(raw.decode("utf-8"))
        entry = history.get(prompt_id) or {}
        status = ((entry.get("status") or {}).get("status_str"))
        if status == "error":
            raise RuntimeError("comfy error")
        outputs = entry.get("outputs") or {}
        for node in outputs.values():
            for image in node.get("images") or []:
                filename = image.get("filename") or ""
                if not filename:
                    continue
                query = urllib.parse.urlencode({
                    "filename": filename,
                    "subfolder": image.get("subfolder") or "",
                    "type": image.get("type") or "output",
                })
                png, _ = http_bytes(COMFY + "/view?" + query, timeout=30)
                if png.startswith(b"\x89PNG"):
                    return png
        time.sleep(0.4)
    raise TimeoutError("comfy timeout")


def run_job(job):
    photo, _ = http_bytes(token_url("/api/avatar/stylize-sources/" + str(job["job_id"])), timeout=30)
    sample, _ = http_bytes(job["sample_url"], timeout=30)
    if not photo.startswith(b"\x89PNG"):
        raise RuntimeError("photo is not png")
    workflow_path = Path(WORKFLOW)
    if not workflow_path.is_file():
        here = Path(__file__).resolve().parent.parent / "resources" / "comfyui" / "avatar_workflow.json"
        workflow_path = here
    workflow = json.loads(workflow_path.read_text(encoding="utf-8"))
    face_name = upload_image(photo, "club_face.png")
    names = [face_name]
    if sample.startswith(b"\x89PNG") or sample.startswith(b"\xff\xd8"):
        names.append(upload_image(sample, "club_style.png"))
    graph = inject(workflow, names, job.get("prompt") or "", job.get("negative") or "")
    prompt_id = queue_prompt(graph)
    return wait_png(prompt_id, float(job.get("timeout_sec") or TIMEOUT))


def post_applied(job_id, png=None, error=None):
    if error is not None:
        payload = urllib.parse.urlencode({
            "token": TOKEN,
            "job_id": str(job_id),
            "status": "failed",
            "error": error[:500],
        }).encode("utf-8")
        http_bytes(
            API + "/api/avatar/stylize-applied",
            payload,
            {"Content-Type": "application/x-www-form-urlencoded"},
            timeout=30,
        )
        return
    boundary = "clubappliedboundary"
    body = (
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"token\"\r\n\r\n{TOKEN}\r\n"
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"job_id\"\r\n\r\n{job_id}\r\n"
        f"--{boundary}\r\nContent-Disposition: form-data; name=\"image\"; filename=\"avatar.png\"\r\n"
        "Content-Type: image/png\r\n\r\n"
    ).encode("utf-8") + png + f"\r\n--{boundary}--\r\n".encode("utf-8")
    http_bytes(
        API + "/api/avatar/stylize-applied",
        body,
        {"Content-Type": f"multipart/form-data; boundary={boundary}"},
        timeout=60,
    )


def main():
    if not API or not TOKEN:
        raise SystemExit("VIDEO_API_BASE and AVATAR_RELAY_TOKEN are required")
    while True:
        try:
            payload = get_json(token_url("/api/avatar/stylize-targets"))
            for job in payload.get("jobs") or []:
                job_id = job.get("job_id")
                try:
                    png = run_job(job)
                    post_applied(job_id, png=png)
                except Exception as exc:
                    post_applied(job_id, error=str(exc) or "timeout")
        except urllib.error.URLError:
            pass
        time.sleep(POLL)


if __name__ == "__main__":
    main()
