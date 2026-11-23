FROM node:22-alpine

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY tsconfig.json ./
COPY src ./src

RUN npm run build

ENV NODE_ENV=production
ENV PORTA=3000
ENV PASTA_UPLOADS=/app/uploads

RUN mkdir -p /app/uploads

EXPOSE 3000

CMD ["npm", "start"]