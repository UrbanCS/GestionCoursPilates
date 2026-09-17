# Selixo production overrides

Upload `css/custom.css` to `/templates/selixo/css/custom.css`.

LiteSpeed serves that file with a seven-day browser cache. Keep Helix's normal
call in `templates/selixo/index.php`:

```php
$theme->add_css('custom.css');
```

Then add this link after the PHP block that registers the Helix styles and
before the optional `containerMaxWidth` inline style:

```php
<link data-memi-custom-css rel="stylesheet" href="<?php echo Uri::root(true) . '/templates/' . $template->template . '/css/custom.css?release=20260917e'; ?>">
```

Increment the `release` value whenever `custom.css` changes so returning
visitors receive the new responsive fixes immediately.

## Contact map

SP Page Builder page 21 (`Nous joindre`), Open Street Map addon
`ae0fcadb-5f9c-4b9e-a144-d706a8d17e72`: use **Light All**
(`CartoDB.Positron`). The original style was restored at the user's request
on 2026-09-17, accepting the existing "API KEY REQUIRED" watermark for now.
Keep latitude `45.455807`, longitude `-75.733371`, zoom `13`, attribution and
zoom controls enabled, dragging enabled, and mouse-wheel zoom disabled.
This setting is stored in Joomla's database, not deployed by the component ZIP.

`openstreetmap-provider.html` is retained only as an inactive historical snippet.
Do not insert it into the production theme: its `data-memi-osm-provider` script
was removed when CARTO was restored. All unrelated CSS and theme changes remain.

If OpenStreetMap is selected again, follow https://operations.osmfoundation.org/policies/tiles/: normal browser
caching/referrer, no bulk downloading, offline storage or prefetch. The community
tile service is best-effort, without an availability guarantee.
