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
`ae0fcadb-5f9c-4b9e-a144-d706a8d17e72`: choose **Open Street Default**
(`OpenStreetMap.Mapnik`) instead of Light All (`CartoDB.Positron`).
Keep latitude `45.455807`, longitude `-75.733371`, zoom `13`, attribution and
zoom controls enabled, dragging enabled, and mouse-wheel zoom disabled.
This setting is stored in Joomla's database, not deployed by the component ZIP.

Insert `openstreetmap-provider.html` immediately before `</body>` in the Selixo
`index.php`. It runs after the Leaflet provider script but before SP Page Builder's
`window.load` map initialization. It replaces legacy a/b/c subdomains with the
canonical HTTPS endpoint and adds the full visible attribution. It does nothing
on pages without Leaflet and does not change other providers.

Follow https://operations.osmfoundation.org/policies/tiles/: normal browser
caching/referrer, no bulk downloading, offline storage or prefetch. The community
tile service is best-effort, without an availability guarantee.
