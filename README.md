# OneMinuteExperienceApiV2

Backend stuff for the GIFT One Minute Experience.

This is implemented as a Directus extension, namely a web hook.

## Installation

Make a symlink from `directus/public/extensions/custom/hooks` to the directory where *hooks.php* resides.

## Configuration

A number of things need to be configured, and currently this is just read from n .ini file from filesystem with `parse_ini_file` in *hooks.php*.

The file structure is.

```
[project]
endpoint        = "https://northeurope.api.cognitive.microsoft.com"
id              = "project-uuid-here"

[training]
key             = "your-training-key-here"

[prediction]
key             = "your-prediction-key-here"
resource_id     = "your-prediction-reseource-id-here-it-starts-with-subscription-and-is-a-approximately-as-long-as-this-template-string-here"
production_model = "production"
```

## Testing

To run automatic tests on this thing, the the extend that they've been written, do the following

1. `composer install` to get dependencies, importantly PHPUnit.
1. Get Directus into `lib/` with `git clone https://github.com/directus/directus.git` or by symlinking or whatever.
1. `./vendor/phpunit/phpunit/phpunit` runs the tests with setting from `phpunit.xml`.

Or to be explicit about it without the config file, `./vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php --testdox --colors tests`.
