FROM debian:wheezy

WORKDIR /var/www

# Basic
RUN apt-get update \
&& apt-get install -y less mc procps build-essential

# PHP
RUN apt-get update \
&& apt-get install -y php5 php5-mysql php5-curl php5-gd php5-mcrypt php5-imagick

# Apache
RUN a2enmod rewrite \
&& rm -rf "/var/lock/apache2"

ENV TERM xterm

COPY run.sh /
RUN chmod +x /run.sh
CMD /run.sh