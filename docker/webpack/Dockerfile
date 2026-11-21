FROM node:24-alpine

WORKDIR /app

COPY package.json package.json
COPY package-lock.json package-lock.json

RUN npm ci

# The container runs as the host user (see compose.yaml): let it write the build caches
RUN mkdir -p node_modules/.cache && chmod -R a+rwX node_modules/.cache

CMD ["npm", "run", "watch"]
