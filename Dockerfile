FROM node:22-alpine AS builder
RUN apk add --no-cache python3
WORKDIR /app
COPY package.json ./
RUN npm install --ignore-scripts
COPY . .
RUN python3 tools/build_dist.py

FROM nginx:stable
COPY nginx.conf /etc/nginx/nginx.conf
COPY --from=builder /app/dist /usr/share/nginx/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=3s CMD curl -f http://localhost/ || exit 1
CMD ["nginx", "-g", "daemon off;"]
