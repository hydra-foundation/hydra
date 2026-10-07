---
section: Views
kind: added
---
`$this->image($source, 'content', alt: '…', sizes: '…')` prints a picture at the sizes a page needs, through the new `ImagesInterface` contract in `view`: an `<img>` with a `srcset`, its width and height, and lazy loading unless `eager: true`. `alt` is required, and `''` marks a picture decorative. `PhpView` takes the implementation as a new optional `images:` argument, and `image()` says what to pass when none was given; `hydrakit/image` provides one. `ImagesInterface::variants()` returns the sizes as `Variant` values, for a feed or a preview image that wants one URL.
