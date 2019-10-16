# OneMinuteExperienceApiV2
Backend stuff for the GIFT One Minute Experience

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
