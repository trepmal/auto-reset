auto-reset
==========

WordPress plugin to reset a site to defaults

Query Arg Shortcuts
-------------------

Add these key-value pairs to the URL to trigger certain changes.

*?delay*

(No value necessary) Delay the next auto-reset by the interval value (default one hour).

*?onehour*

(No value necessary) Auto-reset in 1 hour, regardless of time remaining or inverval value.

*?hours=X*

Auto-reset in X hours.

*?minutes=X*

Auto-reset in X minutes.

*?resetnow*

Reset right now.


Installation
------------
```shell
# go to wp-content folder
cd wp-content
# create mu-plugins directory if you don't have it
mkdir mu-plugins
# go to mu-plugins folder
cd mu-plugins
# clone this repo
git clone https://github.com/trepmal/auto-reset.git
# mu-plugins doesn't work with directories, create small plugin to include it
echo "<?php require_once( 'auto-reset/auto-reset.php' );" > auto-reset.inc.php
```

`auto-reset.inc.php` is also a handy place to add any customizations

```php
<?php

function custom_auto_reset( $settings ) {
	$settings['hide_default_plugins'] = false;
	$settings['show_feature_pointers'] = false;
	return $settings;
}
add_filter( 'auto_reset', 'custom_auto_reset' );

require_once( 'auto-reset/auto-reset.php' );
```