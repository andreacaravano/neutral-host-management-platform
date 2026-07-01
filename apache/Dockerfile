#
#                  Politecnico di Milano
#
#         Student: Caravano Andrea
#
#   Last modified: 16/05/2026
#
#     Description: Dockerfile for customized Apache + PHP Webserver for the 5G Neutral Host management platform
#

FROM php:8-apache

#### INSTALL SOME BASE SHELL UTILITIES ####
RUN /usr/bin/apt-get update && export DEBIAN_FRONTEND=noninteractive \
    && /usr/bin/apt-get dist-upgrade -y \
    && /usr/bin/apt-get -y install --no-install-recommends libpq-dev \
    && /usr/bin/apt-get clean \
    && /usr/bin/rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

#### INSTALL AND ENABLE PHP EXTENSIONS (POSTGRES AND REDIS) ####
RUN /usr/local/bin/docker-php-ext-install pdo pdo_pgsql pgsql

RUN /usr/bin/yes '' | /usr/local/bin/pecl install redis
RUN /usr/local/bin/docker-php-ext-enable redis

RUN /usr/local/bin/docker-php-source delete

#### CONFIGURE SOME BASE APACHE PARAMETER ####
RUN /usr/bin/sed -i 's/Options Indexes FollowSymLinks/Options FollowSymLinks/' /etc/apache2/apache2.conf
RUN /usr/bin/echo '<Files "xmlrpc.php">' >> /etc/apache2/apache2.conf
RUN /usr/bin/echo '  Require all denied' >> /etc/apache2/apache2.conf
RUN /usr/bin/echo '</Files>' >> /etc/apache2/apache2.conf
RUN /usr/bin/sed -i s/'ServerTokens.*'/'ServerTokens Prod'/g /etc/apache2/conf-enabled/security.conf
RUN /usr/bin/sed -i s/'ServerSignature On'/'ServerSignature Off'/g /etc/apache2/conf-enabled/security.conf

RUN /usr/bin/echo 'upload_max_filesize=30M' > /usr/local/etc/php/conf.d/kubernetes.ini
RUN /usr/bin/echo 'post_max_size=50M' >> /usr/local/etc/php/conf.d/kubernetes.ini
RUN /usr/bin/echo 'memory_limit=1024M' >> /usr/local/etc/php/conf.d/kubernetes.ini

RUN /usr/bin/ln -snf /usr/share/zoneinfo/Europe/Rome /etc/localtime && /usr/bin/echo Europe/Rome > /etc/timezone

# ENTRYPOINT ["docker-php-entrypoint"]
