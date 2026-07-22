<p align="center">
    <a href="https://meerkatcomments.com"><img src="https://stillat.s3.us-east-1.amazonaws.com/2020/meerkat/meerkat_colored.png" width="400" alt="Meerkat" /></a>
</p>

## About Meerkat for Statamic

Meerkat is a comments and moderation addon for Statamic 6. It provides comment threads, replies, moderation, spam protection, and a Statamic control-panel experience.

## Installing

Meerkat requires PHP 8.2 or newer and Statamic 6.24.2 or newer.

```shell
composer require stillat/meerkat
php artisan meerkat:install
```

The install command publishes Meerkat's blueprint and migrations, then runs the migrations.

## Documentation

See the default [configuration](config/meerkat.php) and the [changelog](CHANGELOG.md) for the Meerkat 4 release summary.

## License

Meerkat is free to use for development, testing, CI, and staging. Production use requires a Meerkat license, including production sites using free Statamic Core. One Meerkat license covers the same sites, domains, supporting environments, and operational replicas covered by one Statamic license.

See the [Meerkat Commercial License](LICENSE.md) for the complete terms.
