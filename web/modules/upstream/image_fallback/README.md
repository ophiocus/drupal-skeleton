# Image fallback

When an entity is rendered and one of the listed image or media fields is
empty, a fallback image is rendered in that field's place: by default the
front-end theme's logo, or a file in the codebase. It happens at render time;
the module stores no file entity and writes no field configuration, so every
environment shows the same thing without seeding anything.

Every site carries the module (`web/modules/upstream/`); enabling it changes
nothing until the site lists a field.

## Configuration

`image_fallback.settings`, in the site's `config/sync`:

```yaml
default:              # the fallback of every listed field that names no source
  source: logo        # logo | path | none
  path: ''            # for source: path, relative to the Drupal root
  alt: ''             # empty: decorative (alt="")
fields:
  - field: commerce_product.default.field_image   # entity_type.bundle.field_name
    source: ''        # empty: the default, whole; else logo | path | none
    path: ''
    alt: ''
  - field: node.article.field_media_image
    source: path
    path: themes/custom/example_theme/images/no-photo.png
    alt: ''
```

- **logo**: the default theme's logo as its theme settings give it: the
  theme's own `logo.svg` when "use the logo supplied by the theme" is on,
  else the uploaded logo. A theme whose settings show no logo has none to
  lend, and the field stays empty. Olivero ships with its logo off.
- **path**: a file relative to the Drupal root, typically an asset of the
  site's theme. A path that does not exist renders nothing.
- **none**: nothing; useful to switch one field off while the default stays.
- A field with an empty `source` takes the default's source, path and alt
  together. A field that names a source uses its own path and alt.
- `core.extension.yml`: `image_fallback: 0`.

## What is rendered

The field's own wrapper, through the field template (`field--name-…`,
`field--type-…` and whatever the theme adds), with an `image-fallback` class,
holding one `<img>`. A theme sizes it as it sizes a real photo, or styles
`.image-fallback` apart. No image style is applied (a logo is usually an SVG).
When the display's formatter links the image to the content
(`image_link: content`, as the image and media thumbnail formatters offer),
the fallback links there too.

It applies wherever a display is built: an entity in a view mode, a Views
field, a Layout Builder field block. A field the display hides, a field the
viewer may not see, and a field with a value are left alone.

## Cacheability

Only a render the fallback touched (a listed, shown, empty field) carries
`config:image_fallback.settings`, and with the logo source the theme settings'
tags (`config:system.theme`, `config:system.theme.global`,
`config:<theme>.settings`). No tag is added to every page (DEPLOY.md §7,
"whole-page tags").

A render cached before its field was listed carries none of those tags, and a
deploy keeps the render cache. So a settings save that lists a new field
invalidates that entity type's renders (`<entity_type>_view`) once; any other
change reaches the touched renders through the settings' tag.

## Why not core's default image

An image field's `default_image` references a managed file by uuid, so every
environment needs a seeded copy of that file, and the copy drifts from the
logo. Media reference fields have no render-time default at all.
