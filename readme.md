# Minnow Transmuter

Transmuting code from WordPress into something new. This is not a functional tool, at least not yet. Keep an eye on [minn.xyz](https://minn.xyz).

## Docs

Git clone repo

```
git clone https://github.com/MinnowOS/minnow-transmuter
cd minnow-transmuter/
```

Prepare a copy of WordPress

```
mkdir wordpress
cd wordpress
wp core download
cd ..
```

Run `php transmuter.php` which will generate a `build` directory. This process is memory intensive. Find your local PHP ini and increase memory to at least 8GBs.

```
php -ini | grep "php.ini"
```

Edit the found configuration file. Will look something like `/opt/homebrew/etc/php/8.2/php.ini`. Find the line `memory_limit` and change to the following.
```
memory_limit = 8G
```
Open `mappings.yaml` and change `namespace`, `class` and `method` according to your organization method then re-run `php transmuter.php` to see newly organized code.