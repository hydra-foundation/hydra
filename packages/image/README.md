# Hydra Image

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/image` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/image`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

One picture at the sizes a page needs. It implements `hydrakit/view`'s
`ImagesInterface`, so a view says
`<?= $this->image('/images/garden.jpg', 'content', alt: 'The garden') ?>` and
gets an `<img>` with a `srcset` of WebP copies, its width and height, and lazy
loading.

The copies are made with GD the first time a page asks for them and kept on the
public disk, where the web server serves them from then on. They are upright
(EXIF orientation applied), carry no metadata (no GPS), and are never wider
than the source. A source over the megapixel limit is refused before it is
decoded. Needs `ext-gd` with JPEG and WebP support, and `ext-exif`.
