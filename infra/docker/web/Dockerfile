# Imagem do frontend Next.js para desenvolvimento.
# Debian (glibc), não Alpine: binários nativos (SWC, Tailwind, lightningcss) ficam
# compatíveis com o Ubuntu do WSL, já que node_modules é compartilhado pelo bind mount.
FROM node:25-bookworm-slim AS development

ARG HOST_UID=1000
ARG HOST_GID=1000

# O usuário "node" da imagem passa a ter o UID/GID do usuário do WSL.
RUN if [ "$HOST_GID" != "1000" ]; then groupmod -o -g "$HOST_GID" node; fi \
 && if [ "$HOST_UID" != "1000" ]; then usermod -o -u "$HOST_UID" -g "$HOST_GID" node; fi \
 && corepack enable \
 && mkdir -p /app /home/node/.cache /home/node/.local/share/pnpm \
 && chown -R node:node /app /home/node

ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0 \
    NEXT_TELEMETRY_DISABLED=1 \
    NPM_CONFIG_UPDATE_NOTIFIER=false \
    NPM_CONFIG_STORE_DIR=/home/node/.local/share/pnpm/store

USER node
WORKDIR /app

EXPOSE 3000

HEALTHCHECK --interval=10s --timeout=5s --start-period=90s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:3000/').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"

CMD ["pnpm", "dev", "--hostname", "0.0.0.0", "--port", "3000"]
