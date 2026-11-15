FROM node:18-alpine

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY docker/echo/laravel-echo-server.json ./laravel-echo-server.json

EXPOSE 6001
CMD ["./node_modules/.bin/laravel-echo-server", "start"]
