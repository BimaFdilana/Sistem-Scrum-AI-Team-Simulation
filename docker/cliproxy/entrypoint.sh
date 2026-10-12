#!/bin/sh
# Entrypoint CLIProxyAPI: render config pertama kali, lalu jalankan binary.
set -eu

mkdir -p /data/auths

if [ ! -f /data/config.yaml ]; then
  : "${CLIPROXY_MANAGEMENT_KEY:?Set CLIPROXY_MANAGEMENT_KEY di .env}"
  : "${CLIPROXY_API_KEY:?Set CLIPROXY_API_KEY di .env}"
  export CLIPROXY_MANAGEMENT_KEY CLIPROXY_API_KEY
  envsubst < /etc/cliproxy/config.yaml.template > /data/config.yaml
  chmod 600 /data/config.yaml
  echo ">>> /data/config.yaml dibuat dari template."
fi

exec /usr/local/bin/cli-proxy-api -config /data/config.yaml
