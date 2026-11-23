FROM mcr.microsoft.com/playwright:v1.58.2-noble

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts && npm cache clean --force
COPY --chown=pwuser:pwuser src ./src
COPY --chown=pwuser:pwuser test ./test
USER pwuser
ENV NODE_ENV=production HOST=0.0.0.0 PORT=3001
EXPOSE 3001
CMD ["npm", "start"]
