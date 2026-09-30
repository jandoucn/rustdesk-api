# PHP 8.3 FPM Alpine runtime
FROM php:8.3-fpm-alpine AS php

# 设置工作目录
WORKDIR /var/www/
ENV TZ="Asia/Shanghai"
USER root

# 安装nginx,修改配置文件
RUN set -eux; \
    apk add --no-cache \
    nginx libmaxminddb-libs libmaxminddb-dev $PHPIZE_DEPS; \
    pecl install maxminddb; \
    docker-php-ext-enable maxminddb; \
    docker-php-ext-install mysqli pdo_mysql; \
    apk del libmaxminddb-dev $PHPIZE_DEPS; \
    sed  -i  '$a listen.owner = nginx' /usr/local/etc/php-fpm.d/zz-docker.conf; \
    sed  -i  '$a listen.group = nginx' /usr/local/etc/php-fpm.d/zz-docker.conf; \
    sed  -i  '$a clear_env = no' /usr/local/etc/php-fpm.d/zz-docker.conf; \
    sed  -i  '8i php-fpm -D' /usr/local/bin/docker-php-entrypoint;

# 复制自定义的Nginx配置文件到容器中
COPY ./config/nginx.conf /etc/nginx/nginx.conf
COPY ./config/docker-entrypoint.sh /usr/local/bin/rustdesk-entrypoint
RUN chmod 0755 /usr/local/bin/rustdesk-entrypoint

# GeoLite is an image seed. The entrypoint copies it into the persistent data
# volume only when the volume does not already contain a database.
COPY ./geoip/GeoLite2-City.mmdb /usr/share/rustdesk-api/GeoLite2-City.mmdb

# 复制应用代码到容器中
COPY ./sqlite /var/www/html
ENV RUSTDESK_DB=/var/www/data/rustdesk.db \
    RUSTDESK_ADMIN_PATH=/ops-console \
    RUSTDESK_GEOIP_DATABASE=/var/www/data/GeoLite2-City.mmdb \
    RUSTDESK_GEOIP_SEED=/usr/share/rustdesk-api/GeoLite2-City.mmdb

# 暴露端口
EXPOSE 80

# 启动Nginx服务器
ENTRYPOINT ["rustdesk-entrypoint"]
CMD ["nginx", "-g", "daemon off;"]
