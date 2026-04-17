# ED:CS API

ED:CS API. View documentation here: https://api.edcs.app/api/documentation

## Dev Requirements
- Docker

## Server Requirements

- Nginx or Apache:
    - (If using Nginx): php-fpm
    - (If using Apache): mod_php
- PHP 8.4 with extensions:
    - Ctype
    - cURL
    - DOM
    - Fileinfo
    - Filter
    - Hash
    - Mbstring
    - OpenSSL
    - PCRE
    - PDO
    - Session
    - Tokenizer
    - XML
    - Zip
    - ZMQ
- MySQL
- Redis
- Supervisor

### Quick Start

1. Clone this repository:

    ```sh
    git clone --recurse-submodules -j8 git@gitub.com:sentrychris/edcs.git
    ```

2. Install dependencies:

    ```sh
    docker run --rm \
         -u "$(id -u):$(id -g)" \
         -v "$(pwd):/var/www/html" \
         -w /var/www/html \
         laravelsail/php84-composer:latest \
         composer install --ignore-platform-reqs
    ```

3. Configure environment variables:

    ```sh
    ...
    DB_CONNECTION=mysql
    DB_HOST=edcs-mysql-1 # name of the running db container
    DB_PORT=3306
    DB_DATABASE=edcs
    DB_USERNAME=sail
    DB_PASSWORD=password

    FRONTEND_URL=http://localhost:4201 # used to redirect oauth

    FRONTIER_AUTH_URL=https://auth.frontierstore.net
    FRONTIER_CLIENT_ID=<your-frontier-oauth-client-id>
    FRONTIER_CLIENT_KEY=<your-frontier-oauth-client-key>
    FRONTIER_CAPI_URL=https://companion.orerve.net

    BROADCAST_DRIVER=log
    CACHE_DRIVER=database
    FILESYSTEM_DISK=local
    QUEUE_CONNECTION=database
    SESSION_DRIVER=database
    SESSION_LIFETIME=120

    REDIS_HOST=edcs-redis-1
    REDIS_PASSWORD=null
    REDIS_PORT=6379
    ... # the rest should be fine
    ```

4. Start the containers:

    ```sh
    ./vendor/bin/sail up -d
    ```

5. Create the database tables:

    ```sh
    ./vendor/bin/sail artisan migrate:fresh
    ```

6. Seed populated systems, so that you have some data:

    1. Download the `systemsPopulated.json.gz` dump archive [from EDSM](https://www.edsm.net/dump/systemsPopulated.json.gz)
    2. Extract it to `storage/dumps/`
    3. Run the import command:
        ```sh
        ./vendor/bin/sail artisan import:dumpfile --type systems --file systemsPopulated.json
        ```
    
    Please note, the `systemsPopulated.json` file is larger than 1GB. The `import:dumpfile` command will therefore split the file into parts and dispatch them as jobs to the worker queue.

7. Start the queue worker:
    ```sh
    ./vendor/bin/sail artisan queue:work
    ```

8. Seed Galnet news articles:

    ```sh
    ./vendor/bin/sail artisan import:galnet
    ```

9. Cache system statistics:

    ```sh
    ./vendor/bin/sail artisan cache:stats
    ```

## Credits

ED:CS wouldn't be possible without the work of hundreds of talented members of the Elite: Dangerous community.

_"Standing on the shoulders of giants"_.

Special thanks to:

- [EDSM](https://github.com/EDSM-NET) - for the wonderful data and API.
- [Spansh](https://www.spansh.co.uk) - for the wonderful data and API.
- All the other talented members of [ED:CD](https://edcd.github.io/), for EDDN and third-party tools.
- The players, for exploring the galaxy and sharing data.

## Legal

"Elite", the Elite logo, the Elite: Dangerous logo, "Frontier" and the Frontier logo are registered trademarks of Frontier Developments plc. All rights reserved. All other trademarks and copyrights are acknowledged as the property of their respective owners.

ED:CS is free, open source software released under the MIT License.
