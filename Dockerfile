# The ComplyRobin rotation runner as a container, for running it on a schedule outside GitHub (cron, Kubernetes
# CronJob, ECS scheduled task, Cloud Run job). PHP's curl, sodium and openssl extensions are built into this image.
#
#   docker run --rm -e COMPLYROBIN_TOKEN -e OPENAI_ADMIN_KEY -e LARAVEL_CLOUD_API_TOKEN ghcr.io/complyrobin/runner
#   docker run --rm -e COMPLYROBIN_TOKEN … ghcr.io/complyrobin/runner check
FROM php:8.4-cli-alpine

WORKDIR /runner
COPY bin ./bin
COPY src ./src
COPY config ./config
COPY composer.json LICENSE ./

USER nobody

ENTRYPOINT ["php", "/runner/bin/complyrobin-runner"]
CMD ["run"]
