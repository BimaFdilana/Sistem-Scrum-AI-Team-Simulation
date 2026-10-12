# ---------------------------------------------------------------------------
# CLIProxyAPI — gateway OAuth (Antigravity/Google, Codex, Claude, ...)
# Binary resmi diunduh dari GitHub releases router-for-me/CLIProxyAPI.
# Build : docker build --build-arg CLIPROXY_VERSION=8.0.23 -t cliproxy .
# ---------------------------------------------------------------------------
FROM debian:bookworm-slim

ARG CLIPROXY_VERSION=8.0.23
ARG TARGETARCH=amd64

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gettext-base \
    && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL -o /tmp/cliproxy.tar.gz \
        "https://github.com/router-for-me/CLIProxyAPI/releases/download/v${CLIPROXY_VERSION}/CLIProxyAPI_${CLIPROXY_VERSION}_linux_${TARGETARCH}.tar.gz" \
    && tar -xzf /tmp/cliproxy.tar.gz -C /usr/local/bin cli-proxy-api \
    && chmod +x /usr/local/bin/cli-proxy-api \
    && rm /tmp/cliproxy.tar.gz

COPY config.yaml /etc/cliproxy/config.yaml.template
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh && mkdir -p /data

# /data = config.yaml + auths/ (token OAuth) — dipersist via volume
VOLUME ["/data"]
EXPOSE 8317
ENTRYPOINT ["/entrypoint.sh"]
