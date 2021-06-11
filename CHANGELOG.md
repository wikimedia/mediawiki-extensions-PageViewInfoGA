# Changelog

Versions and bullets are arranged chronologically from latest to oldest.

## v1.1.0

- Add backlinks to the related articles.

## v1.0.7

- Fix Call to a member function on null (https://github.com/femiwiki/UnifiedExtensionForFemiwiki/issues/39)

## v1.0.6

- Update copied code to REL1_36.

## v1.0.5

Note: this version requires MediaWiki 1.36+. Earlier versions are no longer supported.
If you still use those versions of MediaWiki, please use REL1_35 branch instead of this release.

In addition, your LocalSettings.php must be update due to changes of [Dependency Injection] in MediaWiki 1.36:

```php
$wgSpecialPages['Whatlinkshere'] = [
	'class' => 'SpecialOrderedWhatLinksHere',
	'services' => [
		'DBLoadBalancer',
		'LinkBatchFactory',
		'ContentHandlerFactory',
		'SearchEngineFactory',
		'NamespaceInfo',
	]
];
```

ENHANCEMENTS:

- Localisation updates from https://translatewiki.net.

## Previous Releases

- [REL1_35](https://github.com/femiwiki/UnifiedExtensionForFemiwiki/blob/REL1_35/CHANGELOG.md)

[dependency injection]: https://www.mediawiki.org/wiki/Dependency_Injection
