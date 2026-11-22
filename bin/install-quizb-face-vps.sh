#!/usr/bin/env bash
# VPS-only installation. Adds a separate QuizB worker; does not restart LPPAI.
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "Run through sudo on the VPS." >&2; exit 1; }
revision="${1:?Git revision required}"
[[ "$revision" =~ ^[a-f0-9]{7,40}$ ]] || exit 1
owner=lppai-face-engine-48b63bb9
base=/opt/quizb-face-engine
release="$base/releases/$revision"
python=/home/ubuntu/lppai-face-engine-20261113-48b63bb9/.venv/bin/python
models=/home/ubuntu/lppai-face-engine-20261113-48b63bb9/models
private_env=/home/ubuntu/lppai-face-engine-20261113-48b63bb9/runtime.env
site=/etc/nginx/sites-available/lppai-face-engine-48b63bb9
[ -x "$python" ] && [ -f "$models/sface.onnx" ] && [ -f "$private_env" ] && [ -f "$site" ]
install -d -m 755 "$base" "$base/releases"
install -d -o root -g "$owner" -m 750 "$release"
for file in app.py engine.py; do
  curl --fail --silent --show-error --location "https://raw.githubusercontent.com/danbelajardanbelajar-blip/up.quizb.my.id/$revision/services/quizb-face-engine/$file" -o "$release/$file"
  chown root:"$owner" "$release/$file"; chmod 640 "$release/$file"
done
[ -e "$release/models" ] || ln -s "$models" "$release/models"
cat > /etc/systemd/system/quizb-face-engine.service <<EOF
[Unit]
Description=QuizB isolated face verification with easier head motion
After=network.target
[Service]
User=$owner
Group=$owner
WorkingDirectory=$release
EnvironmentFile=$private_env
Environment=OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 MKL_NUM_THREADS=1 PYTHONDONTWRITEBYTECODE=1
ExecStart=$python -m uvicorn app:app --host 127.0.0.1 --port 8018 --no-access-log --limit-concurrency 8
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=read-only
MemoryMax=768M
CPUQuota=100%
TasksMax=32
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now quizb-face-engine.service
# A service already active on a later rerun needs a restart to load the new release.
if [ "$(systemctl show -p MainPID --value quizb-face-engine.service)" != 0 ]; then systemctl restart quizb-face-engine.service; fi
ready=0
for attempt in {1..15}; do
  if curl --fail --silent http://127.0.0.1:8018/health > "$base/health.json"; then ready=1; break; fi
  sleep 1
done
[ "$ready" = 1 ] || { systemctl stop quizb-face-engine.service; echo "QuizB health failed; LPPAI unaffected." >&2; exit 1; }
cat > /etc/nginx/conf.d/quizb-face-rate.conf <<'EOF'
limit_req_zone $binary_remote_addr zone=quizb_face_requests:1m rate=10r/s;
EOF
cat > /etc/nginx/snippets/quizb-face-engine.conf <<'EOF'
location = /quizb/health {
    proxy_pass http://127.0.0.1:8018/health;
    proxy_set_header Host $host;
    proxy_read_timeout 20s;
}
location = /quizb/verify {
    limit_req zone=quizb_face_requests burst=8 nodelay;
    limit_req_status 429;
    proxy_pass http://127.0.0.1:8018/verify;
    proxy_set_header Host $host;
    proxy_read_timeout 20s;
    proxy_connect_timeout 3s;
}
EOF
backup="$base/nginx-before-$(date +%Y%m%d-%H%M%S).conf"
cp -p "$site" "$backup"
SITE_FILE="$site" "$python" - <<'PY'
import os
from pathlib import Path
p=Path(os.environ['SITE_FILE']);s=p.read_text()
line='    include /etc/nginx/snippets/quizb-face-engine.conf;\n'
if line not in s:
    marker='    location = /health {'
    if s.count(marker)!=1: raise SystemExit('Unexpected proxy config; no route modified')
    p.write_text(s.replace(marker,line+marker,1))
PY
if ! nginx -t; then cp -p "$backup" "$site"; exit 1; fi
systemctl reload nginx
curl --fail --silent --show-error https://face.lppai.quizb.my.id/health
printf '\n'
curl --fail --silent --show-error https://face.lppai.quizb.my.id/quizb/health
printf '\n'
systemctl is-active lppai-face-engine-48b63bb9.service quizb-face-engine.service
