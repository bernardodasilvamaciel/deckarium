# Tempo real da Mesa de teste compartilhada (WebSocket em /realtime/mesa).
# Build a partir desta pasta: docker build realtime/
FROM node:22-alpine

ENV NODE_ENV=production
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force
COPY server.js rooms.js ./

USER node
EXPOSE 8090
CMD ["node", "server.js"]
