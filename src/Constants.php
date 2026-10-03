<?php

namespace MediaWiki\Extension\PageViewInfoGA;

final class Constants {

	// These are tightly coupled to extension.json's config.
	/**
	 * @var string
	 */
	public const CONFIG_KEY_TRACKING_ID = 'PageViewInfoGATrackingID';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_CREDENTIALS_FILE = 'PageViewInfoGACredentialsFile';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_PROPERTY_ID = 'PageViewInfoGAPropertyId';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_WRITE_CUSTOM_DIMENSIONS = 'PageViewInfoGAWriteCustomDimensions';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_READ_CUSTOM_DIMENSIONS = 'PageViewInfoGAReadCustomDimensions';

	/**
	 * @var string GA4 event parameter the Google tag sends when custom dimensions are written.
	 *   Register it as an event-scoped custom dimension under this name to read it back.
	 */
	public const EVENT_PARAM_PAGE_ID = 'mw_page_id';
}
