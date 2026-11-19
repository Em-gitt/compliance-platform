FROM postgres:16-bullseye

# Install cron, gnupg, rclone, curl, python3, ca-certificates, and tzdata
RUN apt-get update && apt-get install -y --no-install-recommends \
    cron \
    gnupg \
    rclone \
    curl \
    python3 \
    ca-certificates \
    tzdata \
    && rm -rf /var/lib/apt/lists/*

# Set timezone to America/Mexico_City (ADR-033: runs at 02:30 Mexico City time)
ENV TZ=America/Mexico_City
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Copy backup script
COPY infra/backup/backup.sh /usr/local/bin/backup.sh
RUN chmod +x /usr/local/bin/backup.sh

# Configure cron schedule: 02:30 daily America/Mexico_City
RUN echo "30 2 * * * /usr/local/bin/backup.sh >> /var/log/backup.log 2>&1" > /etc/cron.d/backup-cron \
    && chmod 0644 /etc/cron.d/backup-cron \
    && crontab /etc/cron.d/backup-cron

# Create log file and working directory
RUN touch /var/log/backup.log && mkdir -p /tmp/backups

# Run cron in foreground
CMD ["cron", "-f"]
