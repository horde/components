#!/bin/bash
# Setup multiple PHPs under Ubuntu 24.04 so that we don't rely on fancy github native helpers
#

sudo add-apt-repository -y ppa:ondrej/php
sudo apt update -y
sudo apt install -y php8.2 php8.2-fpm php8.2-curl php8.2-dom php8.2-mbstring php8.2-sockets php8.2-phar
sudo apt install -y php8.3 php8.3-fpm php8.3-curl php8.3-dom php8.3-mbstring php8.3-sockets php8.3-phar
sudo apt install -y php8.4 php8.4-fpm php8.4-curl php8.4-dom php8.4-mbstring php8.4-sockets
sudo apt install -y phive composer phpstan
