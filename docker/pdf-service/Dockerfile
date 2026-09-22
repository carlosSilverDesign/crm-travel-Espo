FROM node:20-alpine

# Instalación de Chromium y dependencias nativas del sistema para renderizado tipográfico
RUN apk add --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    font-noto-emoji

# Desactivar descarga interna de Chromium y apuntar al binario de Alpine
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser

WORKDIR /usr/src/app

COPY package*.json ./

# Instalación limpia para producción
RUN if [ -f package-lock.json ]; then npm ci --omit=dev; else npm install --omit=dev; fi

COPY . .

# Permisos para el usuario sin privilegios 'node'
RUN chown -R node:node /usr/src/app

EXPOSE 3000

USER node

CMD ["node", "server.js"]
