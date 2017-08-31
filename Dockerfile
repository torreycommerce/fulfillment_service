FROM quay.io/torreycommerce/service_platform:latest

ENV ACENDA_MODE production

USER root

COPY . /app/services/service_worker

WORKDIR /app/services/service_worker

RUN composer install --no-dev 

USER www-data

CMD ["php", "-f", "/app/services/service_worker/worker.php" ]
