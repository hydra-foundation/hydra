---
section: Images
kind: added
---
A new package, `hydrakit/image`, makes a picture at the sizes a page needs. `$this->image('/images/garden.jpg', 'content', alt: '…', sizes: '…')` prints an `<img>` whose `srcset` offers WebP copies at the preset's widths, with the width and height of its `src` so the text doesn't jump as it loads, and `loading="lazy"` unless `eager: true`. A source is a file under the document root or a key on the public disk. The copies are made with GD the first time a page asks for them and kept on the public disk under `variants/`, where the web server serves them from then on; a copy's name hashes the source and the preset, so an edited picture or a changed preset makes new files. Copies are upright (EXIF orientation applied), carry no metadata, GPS included, and are never wider than the source. Register `ImageServiceProvider` with an `ImageOptions` of your presets and pass `ImagesInterface` to `PhpView` as `images:`. Presets are declared once, `Preset::widths(480, 960, 1440)` or `Preset::widths(640, 1280)->crop(16, 9)` for a centred crop, in `ImageOptions` with the WebP quality (82) and a megapixel limit (40) checked before anything is decoded. A source that isn't there throws `ImageNotFound`; one that won't decode is logged and shown as it is. SVG passes through untouched. GD drops colour profiles, so a wide-gamut phone photo comes out in sRGB, and an animated GIF keeps its first frame.

### Upgrading

`hydrakit/image` needs `ext-gd` with JPEG and WebP support, and `ext-exif`. The skeleton's Dockerfile has them; an existing app rebuilds its PHP image (`./bin/prod build php`, then restart) before installing it.
