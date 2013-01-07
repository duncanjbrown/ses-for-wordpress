=== SES for Wordpress ===
Contributors: duncanjbrown
Tags: email, aws, ses
Requires at least: 3.5
Tested up to: 3.5
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Use Amazon SES to send emails with embedded images and arbitrary headers.

== Description ==

SES for WordPress
=================

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

== Installation ==

1. Upload `plugin-name.php` to the `/wp-content/plugins/` directory and activate it
2. Configure your AWS keys in Settings > SES4WP Options

== Frequently Asked Questions ==

= Do I need to change any settings to send emails using SES? =

As long as the 'override wp_mail' option is checked, everything should 'just work'.

= What's all this about image embedding? Can't I just link to an image in my HTML email? =

You can, but it won't work everywhere. For delivery to many clients, including Android GMail and iPhone Mail, you need to attach a copy of the image to the email.

There's some details on how it works [here at StackOverflow](http://stackoverflow.com/questions/4312687/how-to-embed-images-in-email). This plugin basically handles all that header creation palaver for you.

= Is it on GitHub? =

Yes: [duncanjbrown/ses-for-wordpress](https://github.com/duncanjbrown/ses-for-wordpress)

== Screenshots ==

1. The options page

== Changelog ==

= 0.1 =
* Initial release
