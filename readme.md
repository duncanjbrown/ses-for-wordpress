SES for WordPress
=================

---

Introduction
------------

This plugin provides support for AWS's Simple Email Service for your WP site. It supports arbitrary custom headers and handles image embedding for you. It sends HTML messages with an automatically-generated plain-text counterpart.

By default, it overrides the native wp_mail function, so all you need to do is configure your AWS Keys and sender in Settings > SES4WP Options.

Components
----------

1. PEAR Mime_mail to create the message
2. Jevon Wright's [html2text](http://journals.jevon.org/users/jevon-phd/entry/19818) for the plain-text part
3. AWS SDK v1.5

Embedding images
----------------

Before your call to wp_mail(), call `ses4wp_embed_image` with an image handle and the path to your image. It will return a content_id for you to use in your src attribute.

Paths should be given relative to your webserver root. So for instance, to embed `wp-content/themes/twentyeleven/images/wordpress.png`, you would *omit* the slash at the beginning.

eg

    $content_id = ses4wp_embed_image( 'my_image', 'wp-content/path/to/image.jpg' );
    $mail_body = "This is an image. <img src='cid:$content_id' />";
    wp_mail( 'bob@example.com', 'My Subject', $mail_body );

The image will be attached to the email and delivered inline.
